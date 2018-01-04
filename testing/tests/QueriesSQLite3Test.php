<?php namespace NymphTesting;

class QueriesSQLite3Test extends QueriesTest {
  public function setUp() {
    include __DIR__.'/../bootstrapSQLite3.php';
    putenv('DB=sqlite');
  }
}
