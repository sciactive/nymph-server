<?php
require file_exists(dirname(dirname(__DIR__)).'/autoload-dev.php') ? dirname(dirname(__DIR__)).'/autoload-dev.php' : dirname(__DIR__).'/vendor/autoload.php';
use SciActive\RequirePHP as RequirePHP;

RequirePHP::undef('NymphConfig');
RequirePHP::undef('Nymph');

RequirePHP::_('NymphConfig', [], function(){
	// Nymph's configuration.

	$nymph_config = include(__DIR__.DIRECTORY_SEPARATOR.'../conf/defaults.php');
	if (getenv('DATABASE_MYSQL')) {
		$dbopts = parse_url(getenv('DATABASE_MYSQL'));
		$nymph_config->MySQL->database['value'] = ltrim($dbopts["path"],'/');
		$nymph_config->MySQL->host['value'] = $dbopts["host"];
		$nymph_config->MySQL->port['value'] = $dbopts["port"];
		$nymph_config->MySQL->user['value'] = $dbopts["user"];
		$nymph_config->MySQL->password['value'] = key_exists("pass", $dbopts) ? $dbopts["pass"] : '';
	} else {
		$nymph_config->MySQL->host['value'] = '127.0.0.1';
		$nymph_config->MySQL->database['value'] = 'nymph_testing';
		$nymph_config->MySQL->user['value'] = 'nymph_testing';
		$nymph_config->MySQL->password['value'] = 'password';
	}

	$nymph_config->pubsub['value'] = false;

	return $nymph_config;
});

require_once 'TestModel.php';
