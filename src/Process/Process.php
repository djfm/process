<?php

namespace djfm\Process;

class Process
{
	private $cwd;
	private $env;
	
	private $executable;
	private $arguments;
	private $options;

	private $process;
	private $upid;

	public function __construct($executable, array $arguments = array(), array $options = array())
	{
		$this->executable = $executable;
		$this->arguments = $arguments;
		$this->options = $options;
	}

	private static function windows()
	{
		return preg_match('/^WIN/', PHP_OS);
	}

	public static function getChildPID($pid)
	{
		if (self::windows())
		{
			$command = "wmic process where (ParentProcessId=$pid) get ProcessId 2>NUL";
			$output = [];
			exec($command, $output);

			if (!preg_match('/^\d+$/', $output[1]))
				throw new Exception\ChildProcessNotFound();

			return (int)$output[1];
		}
		else
		{
			$command = "pgrep -P $pid";
			$output = [];

			$ret = 1;
			exec($command, $output, $ret);

			if ($ret !== 0)
				throw new Exception\ChildProcessNotFound();

			return (int)$output[0];
		}
	}

	public static function getProcessCreationDate($pid)
	{
		if (self::windows())
		{
			$command = "wmic process where (ProcessId=$pid) get CreationDate 2>NUL";
			$output = [];
			exec($command, $output);

			if (preg_match('/^\s*$/', $output[1]))
				throw new Exception\ProcessNotFound();

			return $output[1];
		}
		else
		{
			$command = "ps -p $pid -wo lstart";
			$output = [];

			$ret = 1;
			exec($command, $output, $ret);

			if ($ret !== 0)
				throw new Exception\ProcessNotFound();

			return $output[1];
		}
	}

	public static function getProcessCommand($pid)
	{
		if (self::windows())
		{
			$command = "wmic process where (ProcessId=$pid) get Name";
			$output = [];
			exec($command, $output);

			if (preg_match('/^\s*$/', $output[1]))
				throw new Exception\ProcessNotFound();

			return $output[1];
		}
		else
		{
			$command = "ps -p $pid -wo cmd";
			$output = [];

			$ret = 1;
			exec($command, $output, $ret);
			
			if ($ret !== 0)
				throw new Exception\ProcessNotFound();
			
			return $output[1];
		}
	}

	public static function runningByUPID($upid)
	{
		list($pid, $creation_date, $cmd) = explode('@', $upid, 3);

		try {
			return 	self::getProcessCreationDate($pid) === $creation_date &&
					self::getProcessCommand($pid) === $cmd;
		} catch (Exception\ChildProcessNotFound $e) {
			return false;
		} catch (Exception\ProcessNotFound $e) {
			return false;
		}
	}

	public static function killByUPID($upid)
	{
		list($pid, $creation_date, $cmd) = explode('@', $upid, 3);

		if (self::runningByUPID($upid))
		{
			if (self::windows())
				exec("tskill $pid");
			else
				exec("kill $pid");
		}
	}

	private function descriptor($spec, $mode = 'w')
	{
		if ($spec === STDIN || $spec === STDOUT)
			return $spec;
		else if (is_string($spec))
			return ['file', $spec, $mode];
		else if ($spec)
			return $spec;
		else {
			if (self::windows())
				return ['file', 'NUL', $mode];
			else
				return ['file', '/dev/null', $mode];
		}

	}

	public function run($stdin = STDIN, $stdout = null, $stderr = null)
	{
		$cmd = escapeshellarg($this->executable);

		$parts = array_map('escapeshellarg', $this->arguments);

		foreach ($this->options as $key => $value)
		{
			$parts[] = escapeshellcmd($key);
			$parts[] = escapeshellarg($value);
		}

		$cmd .= ' ' . implode(' ', $parts);

		$dspec = [
			$stdin,
			$this->descriptor($stdout, 'w'),
			$this->descriptor($stderr, 'w')
		];

		$pipes = [];

		$this->process = proc_open($cmd, $dspec, $pipes, $this->cwd, $this->env);

		if ($this->process === false)
			throw new Exception\CouldNotStartProcess();

		$pid = proc_get_status($this->process)['pid'];
		$child_pid = self::getChildPID($pid);
		$creation_date = self::getProcessCreationDate($child_pid);
		$command = self::getProcessCommand($child_pid);

		$this->upid = "$child_pid@$creation_date@$command";

		return $this->upid;
	}

	public function kill()
	{
		if ($this->running())
		{
			proc_terminate($this->process);
			$this->process = null;

			try {
				self::killByUPID($this->upid);
			} catch (Exception\ChildProcessNotFound $e) {
				// maybe terminate killed it
			} catch (Exception\ProcessNotFound $e) {
				// maybe terminate killed it
			}
		}
	}

	public function running()
	{
		if (!$this->process)
			return false;

		return proc_get_status($this->process)['running'];
	}
}