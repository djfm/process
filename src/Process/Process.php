<?php

namespace djfm\Process;

class Process
{
	private $cwd;
	private $env;
	private $executable;

	public function __construct($executable, array $arguments = array(), array $options = array())
	{
		$this->executable = $executable;
	}

	private static function windows()
	{
		return preg_match('/^WIN/', PHP_OS);
	}

	private static function getChildPID($pid)
	{
		if (self::windows())
		{
			$command = "wmic process where (ParentProcessId=$pid) get ProcessId";
			$output = [];
			exec($command, $output);
			return (int)$output[1];
		}
		else
		{
			$command = "pgrep -P $pid";
			$output = [];
			exec($command, $output);
			return (int)$output[0];
		}
	}

	private static function getProcessCreationDate($pid)
	{
		if (self::windows())
		{
			$command = "wmic process where (ProcessId=$pid) get CreationDate";
			$output = [];
			exec($command, $output);
			return $output[1];
		}
		else
		{
			$command = "ps -p $pid -wo lstart";
			$output = [];
			exec($command, $output);
			return $output[1];
		}
	}

	private static function getProcessCommand($pid)
	{
		if (self::windows())
		{
			$command = "wmic process where (ProcessId=$pid) get Name";
			$output = [];
			exec($command, $output);
			return $output[1];
		}
		else
		{
			$command = "ps -p $pid -wo cmd";
			$output = [];
			exec($command, $output);
			return $output[1];
		}
	}

	public static function kill($upid)
	{
		list($pid, $creation_date, $cmd) = explode('@', $upid, 3);
		if (static::getProcessCreationDate($pid) === $creation_date &&
			static::getProcessCommand($pid) === $cmd)
		{
			if (self::windows())
				exec("tskill $pid");
			else
				exec("kill $pid");
		}
	}

	public function run()
	{
		$cmd = $this->executable;

		$dspec = [
			STDIN,
			STDOUT,
			STDERR
		];

		$pipes = [];

		$process = proc_open($cmd, $dspec, $pipes, $this->cwd, $this->env);

		$pid = proc_get_status($process)['pid'];
		$child_pid = self::getChildPID($pid);
		$creation_date = self::getProcessCreationDate($child_pid);
		$command = self::getProcessCommand($child_pid);

		$upid = "$child_pid@$creation_date@$command";

		return $upid;
	}
}