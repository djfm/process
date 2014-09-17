#!/usr/bin/php
<?php

@ini_set('display_errors', 'on');

require __DIR__.'/vendor/autoload.php';

$p = new \djfm\Process\Process('sleep 1000');

$p->run();