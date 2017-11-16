<?php namespace NymphTesting;

class QueriesPostgresTest extends QueriesTest {
  public function setUp() {
    include __DIR__.'/../bootstrapPostgreSQL.php';
    putenv('DB=pgsql');
  }
}
