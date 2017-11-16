<?php

error_reporting(E_ALL);

require file_exists(__DIR__.'/../vendor/autoload.php')
    ? __DIR__.'/../vendor/autoload.php'
    : __DIR__.'/../../autoload-dev.php';

$nymph_config = [];
if (getenv('DATABASE_MYSQL')) {
  $dbopts = parse_url(getenv('DATABASE_MYSQL'));
  $nymph_config['MySQL'] = [
    'database' => ltrim($dbopts["path"], '/'),
    'host' => $dbopts["host"],
    'port' => $dbopts["port"],
    'user' => $dbopts["user"],
    'password' => key_exists("pass", $dbopts) ? $dbopts["pass"] : ''
  ];
} else {
  $nymph_config['MySQL'] = [
    'host' => '127.0.0.1',
    'database' => 'nymph_testing',
    'user' => 'nymph_testing',
    'password' => 'password'
  ];
}

$nymph_config['pubsub'] = false;

\Nymph\Nymph::configure($nymph_config);

require_once 'TestModel.php';
require_once 'TestBModel.php';
