<?php

error_reporting(E_ALL);

require file_exists(__DIR__.'/../vendor/autoload.php')
    ? __DIR__.'/../vendor/autoload.php'
    : __DIR__.'/../../autoload-dev.php';

$nymph_config = [
  'driver' => 'SQLite3'
];

$nymph_config['pubsub'] = false;

\Nymph\Nymph::configure($nymph_config);

require_once 'TestModel.php';
require_once 'TestBModel.php';
