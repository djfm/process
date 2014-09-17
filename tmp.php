#!/usr/bin/php
<?php

@ini_set('display_errors', 'on');

require __DIR__.'/vendor/autoload.php';

$p = new \djfm\Process\Process('sleep 60');

$upid = $p->run();

echo "$upid\n";

\djfm\Process\Process::kill($upid);