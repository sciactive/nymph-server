<?php
namespace NymphTesting;

use Nymph\Nymph;

class ExportImportTest extends \PHPUnit\Framework\TestCase {

  public function testDeleteOldTestData() {
    $all = Nymph::getEntities(['class' => 'NymphTesting\TestModel']);
    $this->assertTrue((array) $all === $all);
    foreach ($all as $cur) {
      $this->assertTrue($cur->delete());
    }

    $all = Nymph::getEntities(['class' => 'NymphTesting\TestModel']);
    $this->assertEmpty($all);

    $all = Nymph::getEntities(['class' => 'NymphTesting\TestBModel']);
    $this->assertTrue((array) $all === $all);
    foreach ($all as $cur) {
      $this->assertTrue($cur->delete());
    }

    $all = Nymph::getEntities(['class' => 'NymphTesting\TestBModel']);
    $this->assertEmpty($all);

    $this->assertTrue(Nymph::deleteUID('TestUID'));
    $this->assertTrue(Nymph::deleteUID('TestUID2'));
  }

  public function testSetupData() {
    $this->assertEquals(1, Nymph::newUID('TestUID'));
    $this->assertEquals(2, Nymph::newUID('TestUID'));
    $this->assertEquals(1, Nymph::newUID('TestUID2'));

    for ($i = 0; $i < 20; $i++) {
      $class = $i < 15 ? 'NymphTesting\TestModel' : 'NymphTesting\TestBModel';

      // Creating entity...
      $testEntity = $class::factory();

      // Saving entity...
      $testEntity->name = 'Entity Test '.time();
      $testEntity->null = null;
      $testEntity->string = 'test';
      $testEntity->array = ['full', 'of', 'values', 500];
      $testEntity->number = 30;
      $testEntity->number_float = 30.5;
      $testEntity->timestamp = time();
      $testEntity->index = $i.'a';

      $entity_reference_test = new $class();
      $entity_reference_test->string = 'another';
      $entity_reference_test->index = $i.'b';

      $this->assertTrue($entity_reference_test->save());
      $testEntity->reference = $entity_reference_test;
      $testEntity->ref_array = [0 => ['entity' => $entity_reference_test]];

      $this->assertTrue($testEntity->save());
    }
  }

  /**
   * @depends testSetupData
   */
  public function testEntityAndDataCount() {
    $this->assertEquals(2, Nymph::getUID('TestUID'));
    $this->assertEquals(1, Nymph::getUID('TestUID2'));

    $models = Nymph::getEntities(['class' => 'NymphTesting\TestModel']);
    $bmodels = Nymph::getEntities(['class' => 'NymphTesting\TestBModel']);

    $this->assertCount(30, $models);
    $this->assertCount(10, $bmodels);

    $all = $models + $bmodels;
    foreach ($all as $model) {
      if (preg_match('/^\d+a$/', $model->index)) {
        $this->assertNull($model->null);
        $this->assertEquals('test', $model->string);
        $this->assertEquals(['full', 'of', 'values', 500], $model->array);
        $this->assertEquals(30, $model->number);
        $this->assertEquals(30.5, $model->number_float);
        $this->assertGreaterThanOrEqual(strtotime('-2 minutes'), $model->timestamp);
        $this->assertRegExp('/^\d+a$/', $model->index);

        $this->assertNotNull($model->reference->guid);
        $this->assertEquals('another', $model->reference->string);
        $this->assertRegExp('/^\d+b$/', $model->reference->index);
        $this->assertNotNull($model->ref_array[0]['entity']->guid);
        $this->assertEquals($model->reference->guid, $model->ref_array[0]['entity']->guid);
      }
    }
  }

  /**
   * @depends testSetupData
   */
  public function testExportEntities() {
    $this->assertTrue(Nymph::export(__DIR__.'/testentityexport.nex'));
  }

  /**
   * @depends testExportEntities
   */
  public function testImportEntities() {
    $this->testDeleteOldTestData();

    $this->assertEquals(0, Nymph::getUID('TestUID'));
    $this->assertEquals(0, Nymph::getUID('TestUID2'));
    $models = Nymph::getEntities(['class' => 'NymphTesting\TestModel']);
    $bmodels = Nymph::getEntities(['class' => 'NymphTesting\TestBModel']);
    $this->assertEmpty($models);
    $this->assertEmpty($bmodels);

    $this->assertTrue(Nymph::import(__DIR__.'/testentityexport.nex'));

    $this->testEntityAndDataCount();

    unlink(__DIR__.'/testentityexport.nex');
  }

  // This will fail if entities are exported in a different order. Also, the
  // timestamp.
  // /**
  //  * @depends testImportEntities
  //  */
  // public function testExportEntitiesEquality() {
  //   $this->assertTrue(Nymph::export(__DIR__.'/testentityexport2.nex'));
  //
  //   $this->assertFileEquals(
  //       __DIR__.'/testentityexport.nex',
  //       __DIR__.'/testentityexport2.nex'
  //   );
  //
  //   unlink(__DIR__.'/testentityexport.nex');
  //   unlink(__DIR__.'/testentityexport2.nex');
  // }
}
