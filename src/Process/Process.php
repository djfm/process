<?php

namespace djfm\Process;

class Process
{
	private $cwd;
	private $env = [];
	private $executable;

	public function __construct($executable, array $arguments = array(), array $options = array())
	{
		$this->executable = $executable;
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
		echo $pid."\n";
	}
}