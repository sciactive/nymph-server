<?php

error_reporting(E_ALL);

require file_exists(__DIR__.'/../vendor/autoload.php')
    ? __DIR__.'/../vendor/autoload.php'
    : __DIR__.'/../../autoload-dev.php';

$nymphConfig = [
  'driver' => 'PostgreSQL'
];
if (getenv('DATABASE_PGSQL')) {
  $dbopts = parse_url(getenv('DATABASE_PGSQL'));
  $nymphConfig['PostgreSQL'] = [
    'database' => ltrim($dbopts["path"], '/'),
    'host' => $dbopts["host"],
    'port' => $dbopts["port"],
    'user' => $dbopts["user"],
    'password' => key_exists("pass", $dbopts) ? $dbopts["pass"] : ''
  ];
} else {
  $nymphConfig['PostgreSQL'] = [
    'database' => 'nymph_testing',
    'user' => 'nymph_testing',
    'password' => 'password'
  ];
}

$nymphConfig['pubsub'] = false;

\Nymph\Nymph::configure($nymphConfig);

require_once 'TestModel.php';
require_once 'TestBModel.php';
