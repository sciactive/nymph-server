<?php

error_reporting(E_ALL);

require file_exists(__DIR__.'/../vendor/autoload.php')
    ? __DIR__.'/../vendor/autoload.php'
    : __DIR__.'/../../autoload-dev.php';

$nymphConfig = [
  'driver' => 'SQLite3',
  'SQLite3' => [
    'filename' => __DIR__.'/test.db'
  ]
];

$nymphConfig['pubsub'] = false;

\Nymph\Nymph::configure($nymphConfig);

require_once 'TestModel.php';
require_once 'TestBModel.php';
