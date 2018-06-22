<?php namespace NymphTesting;

// phpcs:disable Generic.Files.LineLength.TooLong

class EntityTest extends \PHPUnit\Framework\TestCase {
  public function testHookPHP() {
    $this->assertTrue(
        class_exists('\SciActive\Hook'),
        'HookPHP must be installed to run these tests.'
    );
  }

  public function testInstantiate() {
    $testEntity = TestModel::factory();

    $this->assertInstanceOf('SciActive\HookOverride_NymphTesting_TestModel', $testEntity);

    $this->assertInstanceOf('NymphTesting\TestModel', $testEntity->_hookObject());

    $this->assertTrue($testEntity->hasTag('test'));

    $this->assertTrue($testEntity->boolean);

    return $testEntity;
  }

  /**
   * @depends testInstantiate
   */
  public function testAssignment($testEntity) {
    // Assign some variables.
    $testEntity->name = 'Entity Test';
    $testEntity->null = null;
    $testEntity->string = 'test';
    $testEntity->array = ['full', 'of', 'values', 500];
    $testEntity->number = 30;
    $testEntity->mdate = 30.00001;

    $this->assertSame('Entity Test', $testEntity->name);
    $this->assertNull($testEntity->null);
    $this->assertSame('test', $testEntity->string);
    $this->assertSame(['full', 'of', 'values', 500], $testEntity->array);
    $this->assertSame(30, $testEntity->number);
    $this->assertSame(30.0, $testEntity->mdate);

    $this->assertTrue($testEntity->save());

    $entityReferenceTest = TestModel::factory();
    $entityReferenceTest->string = 'wrong';
    $this->assertTrue($entityReferenceTest->save());
    $entityReferenceGuid = $entityReferenceTest->guid;
    $testEntity->reference = $entityReferenceTest;
    $testEntity->refArray = [0 => ['entity' => $entityReferenceTest]];
    $testEntity->refObject =
        (object) ['thing' => (object) ['entity' => $entityReferenceTest]];
    $this->assertTrue($testEntity->save());

    $entityReferenceTest->test = 'good';
    $this->assertTrue($entityReferenceTest->save());

    return ['entity' => $testEntity, 'refGuid' => $entityReferenceGuid];
  }

  /**
   * @depends testAssignment
   */
  public function testComparison($arr) {
    $testEntity = $arr['entity'];
    $compare = TestModel::factory($testEntity->guid);

    $this->assertTrue($testEntity->is($compare));
    $testEntity->refresh();
    $compare->refresh();
    $this->assertTrue($testEntity->equals($compare));

    $compare->string = 'different';

    $this->assertTrue($testEntity->is($compare));
    $this->assertFalse($testEntity->equals($compare));
  }

  /**
   * @depends testAssignment
   */
  public function testArraySearching($arr) {
    $testEntity = $arr['entity'];
    $array = ['thing', TestModel::factory($testEntity->guid)];

    $this->assertTrue($testEntity->inArray($array));
    $testEntity->refresh();
    $array[1]->refresh();
    $this->assertTrue($testEntity->inArray($array, true));
    $this->assertFalse($testEntity->inArray([0, 1, 2, 3, 4, 5]));
    $this->assertFalse($testEntity->inArray([0, 1, 2, 3, 4, 5], true));

    $array[1]->string = 'different';

    $this->assertTrue($testEntity->inArray($array));
    $this->assertFalse($testEntity->inArray($array, true));

    $this->assertSame(1, $testEntity->arraySearch($array));
    $testEntity->refresh();
    $array[1]->refresh();
    $this->assertSame(1, $testEntity->arraySearch($array, true));
    $this->assertSame(false, $testEntity->arraySearch([0, 1, 2, 3, 4, 5]));
    $this->assertSame(
        false,
        $testEntity->arraySearch([0, 1, 2, 3, 4, 5], true)
    );

    $array[1]->string = 'different';

    $this->assertSame(1, $testEntity->arraySearch($array));
    $this->assertSame(false, $testEntity->arraySearch($array, true));
  }

  /**
   * @depends testAssignment
   */
  public function testRefresh($arr) {
    $testEntity = $arr['entity'];

    $testEntity->null = true;
    $this->assertTrue($testEntity->null);
    $this->assertTrue($testEntity->refresh());
    $this->assertNull($testEntity->null);
  }

  /**
   * @depends testAssignment
   */
  public function testUpdateRefresh($arr) {
    $testEntity = $arr['entity'];

    $this->assertSame('test', $testEntity->string);
    $testEntity->string = 'updated';
    $this->assertTrue($testEntity->save());
    $testEntity->refresh();
    $this->assertTrue($testEntity->save());

    $retrieve = TestModel::factory($testEntity->guid);
    $this->assertSame('updated', $retrieve->string);
    $testEntity->string = 'test';
    $this->assertTrue($testEntity->save());

    $testEntity->refresh();
    $this->assertSame('test', $testEntity->string);
  }

  /**
   * @depends testAssignment
   */
  public function testToReference($arr) {
    $testEntity = $arr['entity'];

    $reference = $testEntity->toReference();

    $this->assertEquals(
        ['nymph_entity_reference', $testEntity->guid, 'NymphTesting\TestModel'],
        $reference
    );
  }

  /**
   * @depends testAssignment
   */
  public function testTags($arr) {
    $testEntity = $arr['entity'];

    $this->assertTrue($testEntity->hasTag('test'));
    $testEntity->addTag('test', 'test2');
    $this->assertTrue($testEntity->hasTag('test', 'test2'));
    $testEntity->addTag(['test', 'test3', 'test4', 'test5', 'test6']);
    $this->assertTrue(
        $testEntity->hasTag(['test', 'test3', 'test4', 'test5', 'test6'])
    );
    $testEntity->removeTag('test2');
    $this->assertFalse($testEntity->hasTag('test2'));
    $testEntity->removeTag('test3', 'test4');
    $this->assertFalse($testEntity->hasTag('test3', 'test4'));
    $testEntity->removeTag(['test5', 'test6']);
    $this->assertFalse($testEntity->hasTag(['test5', 'test6']));
    $this->assertEquals(['test'], $testEntity->getTags());
  }

  /**
   * @depends testAssignment
   */
  public function testReferences($arr) {
    $testEntity = $arr['entity'];

    $testEntity->refresh();

    $this->assertSame($arr['refGuid'], $testEntity->reference->guid);
    $this->assertSame(
        $arr['refGuid'],
        $testEntity->refArray[0]['entity']->guid
    );
    $this->assertSame(
        $arr['refGuid'],
        $testEntity->refObject->thing->entity->guid
    );

    $entity = TestModel::factory($testEntity->guid);

    $this->assertSame($arr['refGuid'], $entity->reference->guid);
    $this->assertSame($arr['refGuid'], $entity->refArray[0]['entity']->guid);
    $this->assertSame(
        $arr['refGuid'],
        $entity->refObject->thing->entity->guid
    );
  }

  /**
   * @depends testAssignment
   */
  public function testSleepingReferences($arr) {
    $testEntity = $arr['entity'];

    $entity = TestModel::factoryReference(
        ['nymph_entity_reference', $testEntity->guid, 'NymphTesting\TestModel']
    );

    $this->assertSame($testEntity->guid, $entity->guid);
    $this->assertSame($testEntity->cdate, $entity->cdate);
    $this->assertSame($testEntity->mdate, $entity->mdate);
    $this->assertSame($testEntity->tags, $entity->tags);
    $this->assertSame('Entity Test', $entity->name);
    $this->assertNull($entity->null);
    $this->assertSame('test', $entity->string);
    $this->assertSame(['full', 'of', 'values', 500], $entity->array);
    $this->assertSame(30, $entity->number);
    $this->assertSame($arr['refGuid'], $entity->reference->guid);
    $this->assertSame($arr['refGuid'], $entity->refArray[0]['entity']->guid);
    $this->assertSame(
        $arr['refGuid'],
        $entity->refObject->thing->entity->guid
    );
  }

  /**
   * @depends testAssignment
   */
  public function testJSON($arr) {
    $testEntity = $arr['entity'];

    $json = json_encode($testEntity);

    $this->assertJsonStringEqualsJsonString(
        '{"guid":' . $testEntity->guid.',"cdate":' .
            json_encode($testEntity->cdate) . ',"mdate":' .
            json_encode($testEntity->mdate) . ',"tags":["test"],"data":' .
            '{"reference":["nymph_entity_reference",' .
            $arr['refGuid'] . ',"NymphTesting\\\\TestModel"],"refArray":[{"entity":' .
            '["nymph_entity_reference",' .
            $arr['refGuid'] . ',"NymphTesting\\\\TestModel"]}],"refObject":{"thing":' .
            '{"entity":["nymph_entity_reference",' . $arr['refGuid'] .
            ',"NymphTesting\\\\TestModel"]}},"name":"Entity Test","number":30,"array":' .
            '["full","of","values",500],"string":"test","null":null},' .
            '"class":"NymphTesting\\\\TestModel"}',
        $json
    );
  }

  /**
   * @depends testAssignment
   */
  public function testAcceptJSON($arr) {
    $testEntity = $arr['entity'];

    $json = json_encode($testEntity);

    $entityData = json_decode($json, true);

    $testEntity->cdate = "13";
    $testEntity->mdate = "14.00009";
    $entityData['tags'] = ['test', 'notag', 'newtag'];
    $testEntity->jsonAcceptTags($entityData['tags']);
    $entityData['data']['name'] = 'bad';
    $entityData['data']['string'] = 'good';
    $entityData['data']['null'] = true;
    $entityData['data']['array'] = ['imanarray'];
    $entityData['data']['number'] = 4;
    $entityData['data']['reference'] = false;
    $entityData['data']['refArray'] = [false];
    $entityData['data']['refObject'] = (object) ["thing"=>false];
    $testEntity->jsonAcceptData($entityData['data']);

    $this->assertFalse($testEntity->hasTag('notag'));
    $this->assertTrue($testEntity->hasTag('newtag'));
    $this->assertSame(13.0, $testEntity->cdate);
    $this->assertSame(14.0, $testEntity->mdate);
    $this->assertSame('Entity Test', $testEntity->name);
    $this->assertNull($testEntity->null);
    $this->assertSame('good', $testEntity->string);
    $this->assertSame(['imanarray'], $testEntity->array);
    $this->assertSame(30, $testEntity->number);
    $this->assertSame($arr['refGuid'], $testEntity->reference->guid);
    $this->assertSame(
        $arr['refGuid'],
        $testEntity->refArray[0]['entity']->guid
    );
    $this->assertSame(
        $arr['refGuid'],
        $testEntity->refObject->thing->entity->guid
    );

    $this->assertTrue($testEntity->refresh());
    $testEntity->useProtectedData();

    $testEntity->cdate = "13";
    $testEntity->mdate = "14";
    $testEntity->jsonAcceptTags($entityData['tags']);
    $testEntity->jsonAcceptData($entityData['data']);

    $this->assertFalse($testEntity->hasTag('notag'));
    $this->assertTrue($testEntity->hasTag('newtag'));
    $this->assertSame(13.0, $testEntity->cdate);
    $this->assertSame(14.0, $testEntity->mdate);
    $this->assertSame('bad', $testEntity->name);
    $this->assertTrue($testEntity->null);
    $this->assertSame('good', $testEntity->string);
    $this->assertSame(['imanarray'], $testEntity->array);
    $this->assertSame(30, $testEntity->number);
    $this->assertFalse($testEntity->reference);
    $this->assertSame([false], $testEntity->refArray);
    $this->assertEquals((object) ["thing"=>false], $testEntity->refObject);

    $this->assertTrue($testEntity->refresh());
  }
}
