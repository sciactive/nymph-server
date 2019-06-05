<?php

error_reporting(E_ALL);

require(
  file_exists(__DIR__.'/../vendor/autoload.php')
    ? __DIR__.'/../vendor/autoload.php'
    : __DIR__.'/../../autoload-dev.php'
);

$nymphConfig = [];
if (getenv('DATABASE_MYSQL')) {
  $dbopts = parse_url(getenv('DATABASE_MYSQL'));
  $nymphConfig['MySQL'] = [
    'database' => ltrim($dbopts["path"], '/'),
    'host' => $dbopts["host"],
    'port' => $dbopts["port"],
    'user' => $dbopts["user"],
    'password' => key_exists("pass", $dbopts) ? $dbopts["pass"] : ''
  ];
} else {
  $nymphConfig['MySQL'] = [
    'host' => '127.0.0.1',
    'database' => 'nymph_testing',
    'user' => 'nymph_testing',
    'password' => 'password'
  ];
  // $nymphConfig['MySQL'] = [
  //   'link' => mysqli_connect('127.0.0.1', 'nymph_testing', 'password', 'nymph_testing')
  // ];
}

$nymphConfig['pubsub'] = false;

\Nymph\Nymph::configure($nymphConfig);

require_once 'TestModel.php';
require_once 'TestBModel.php';
