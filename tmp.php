#!/usr/bin/php
<?php

@ini_set('display_errors', 'on');

require __DIR__.'/vendor/autoload.php';

$p = new \djfm\Process\Process('timeout 60 > NUL');

$upid = $p->run();

\djfm\Process\Process::kill($upid);