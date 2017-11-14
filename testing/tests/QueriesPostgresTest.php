<?php
namespace NymphTesting;

class QueriesPostgresTest extends QueriesTest {
  public function setUp() {
    include __DIR__.'/../bootstrapPostgreSQL.php';
    \SciActive\RequirePHP::_('Nymph', ['NymphConfig'], function ($NymphConfig) {
      $class = '\Nymph\Drivers\\'.$NymphConfig['driver'].'Driver';

      $Nymph = new $class($NymphConfig);
      if (class_exists('\\SciActive\\Hook')) {
        \SciActive\Hook::hookObject($Nymph, 'Nymph->');
      }
      return $Nymph;
    });
  }
}
