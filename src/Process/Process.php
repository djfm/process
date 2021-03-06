<?php

namespace djfm\Process;

use djfm\Process\Exception\ChildProcessNotFound;
use djfm\Process\Exception\CouldNotStartProcess;
use djfm\Process\Exception\ProcessNotFound;

class Process
{
	private $cwd = null;
	private $env = [];

	private $executable;
	private $arguments;
	private $options;

	private $process;
	private $upid;
	private $settings;

	public function __construct($executable, array $arguments = array(), array $options = array(), array $settings = array())
	{
		$this->executable = $executable;
		$this->arguments = $arguments;
		$this->options = $options;
		$this->settings = array_merge([
			'upid' => false
		], $settings);
	}

	public function setWorkingDir($wd)
	{
		$this->cwd = $wd;
		return $this;
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

			if (!preg_match('/^\d+$/', $output[1])) {
				throw new ChildProcessNotFound();
			}

			return (int)$output[1];
		}
		else
		{
			$command = "pgrep -P $pid";
			$output = [];

			$ret = 1;
			exec($command, $output, $ret);

			if ($ret !== 0) {
				throw new ChildProcessNotFound();
			}

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
				throw new ProcessNotFound();

			return $output[1];
		}
		else
		{
			$command = "ps -p $pid -wo lstart";
			$output = [];

			$ret = 1;
			exec($command, $output, $ret);

			if ($ret !== 0)
				throw new ProcessNotFound();

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
				throw new ProcessNotFound();

			return $output[1];
		}
		else
		{
			$command = "ps -p $pid -wo cmd";
			$output = [];

			$ret = 1;
			exec($command, $output, $ret);

			if ($ret !== 0)
				throw new ProcessNotFound();

			return $output[1];
		}
	}

	public static function runningByUPID($upid)
	{
		list($pid, $creation_date, $cmd) = explode('@', $upid, 3);

		try {
			return 	self::getProcessCreationDate($pid) === $creation_date &&
					self::getProcessCommand($pid) === $cmd;
		} catch (ChildProcessNotFound $e) {
			return false;
		} catch (ProcessNotFound $e) {
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
		{
			if ($mode === 'w' && !file_exists($spec))
				touch($spec);
			return ['file', $spec, $mode];
		}
		else if ($spec)
			return $spec;
		else {
			if (self::windows())
				return ['file', 'NUL', $mode];
			else
				return ['file', '/dev/null', $mode];
		}

	}

	public function getCommand()
	{
		$cmd = escapeshellcmd($this->executable);

		$parts = [];

		foreach ($this->options as $key => $value)
		{
			if (preg_match('/^-\w$/', $key) && $value !== '')
				$parts[] = escapeshellcmd($key).escapeshellcmd($value);
			elseif (preg_match('/^--\w+/', $key))
				$parts[] = escapeshellcmd($key)."=".escapeshellcmd($value);
			else
			{
				$parts[] = escapeshellcmd($key);
				if ($value !== '') {
					$parts[] = escapeshellcmd($value);
				}
			}
		}

		foreach ($this->arguments as $a) {
			if ($a === '<' || $a === '>') {
				$parts[] = $a;
			} else {
				$parts[] = escapeshellarg($a);
			}
		}

		$cmd .= ' ' . implode(' ', $parts);

		return $cmd;
	}

	public function run($stdin = STDIN, $stdout = null, $stderr = null, $settings = array())
	{
		$settings = array_merge($this->settings, $settings);

		$cmd = $this->getCommand();

		$dspec = [
			$this->descriptor($stdin, 'r'),
			$this->descriptor($stdout, 'w'),
			$this->descriptor($stderr, 'w')
		];

		$this->pipes = [];

		$env = array_merge($_SERVER, $_ENV, $this->env);
		foreach ($env as $k => $v) {
			if (!is_scalar($v)) {
				unset($env[$k]);
			}
		}

		$this->process = proc_open($cmd, $dspec, $this->pipes, $this->cwd, $env);

		if ($this->process === false) {
			throw new CouldNotStartProcess();
		}

		if (!empty($settings['wait'])) {
			return proc_close($this->process);
		}

		if (empty($settings['upid'])) {
			return $this;
		}

		$pid = proc_get_status($this->process)['pid'];

		$child_pid = self::getChildPID($pid);
		$creation_date = self::getProcessCreationDate($child_pid);
		$command = self::getProcessCommand($child_pid);

		$this->upid = "$child_pid@$creation_date@$command";

		return $this->upid;
	}

	public function getUPID()
	{
		return $this->upid;
	}

	public function pipe($p1, $p2, $stdin = STDIN, $stdout = null, $stderr = null)
	{
		$p1->run($stdin, ['pipe', 'w'], $stderr, ['wait' => false]);
		return $p2->run($p1->pipes[1], $stdout, $stderr, ['wait' => true]);
	}

	public function kill()
	{
		if ($this->running())
		{
			proc_terminate($this->process);
			$this->process = null;

			if ($this->upid) {
				try {
					self::killByUPID($this->upid);
				} catch (ChildProcessNotFound $e) {
					// maybe terminate killed it
				} catch (ProcessNotFound $e) {
					// maybe terminate killed it
				}
			}
		}
	}

	public function getStatus()
	{
		if ($this->process) {
			return proc_get_status($this->process);
		} else {
			return ['running' => false];
		}
	}

	public function running()
	{
		return $this->getStatus()['running'];
	}

	public function setEnv($param, $value)
	{
		$this->env[$param] = $value;
		return $this;
	}
}
