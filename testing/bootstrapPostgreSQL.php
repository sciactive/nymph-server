<?php

error_reporting(E_ALL);

require file_exists(__DIR__.'/../vendor/autoload.php')
    ? __DIR__.'/../vendor/autoload.php'
    : __DIR__.'/../../autoload-dev.php';
use SciActive\RequirePHP as RequirePHP;

RequirePHP::undef('NymphConfig');
RequirePHP::undef('Nymph');

$nymph_config = [
  'driver' => 'PostgreSQL'
];
if (getenv('DATABASE_PGSQL')) {
  $dbopts = parse_url(getenv('DATABASE_PGSQL'));
  $nymph_config['PostgreSQL'] = [
    'database' => ltrim($dbopts["path"], '/'),
    'host' => $dbopts["host"],
    'port' => $dbopts["port"],
    'user' => $dbopts["user"],
    'password' => key_exists("pass", $dbopts) ? $dbopts["pass"] : ''
  ];
} else {
  $nymph_config['PostgreSQL'] = [
    'database' => 'nymph_testing',
    'user' => 'nymph_testing',
    'password' => 'password'
  ];
}

$nymph_config['pubsub'] = false;

\Nymph\Nymph::configure($nymph_config);

require_once 'TestModel.php';
