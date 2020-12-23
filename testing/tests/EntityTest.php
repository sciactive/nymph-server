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
   * @param \NymphTesting\TestModel $testEntity
   */
  public function testAssignment($testEntity) {
    // Assign some variables.
    $testEntity->name = 'Entity Test';
    $testEntity->null = null;
    $testEntity->string = 'test';
    $testEntity->array = ['full', 'of', 'values', 500];
    $testEntity->number = 30;

    $this->assertSame('Entity Test', $testEntity->name);
    $this->assertNull($testEntity->null);
    $this->assertSame('test', $testEntity->string);
    $this->assertSame(['full', 'of', 'values', 500], $testEntity->array);
    $this->assertSame(30, $testEntity->number);

    $this->assertTrue($testEntity->save());

    $entityReferenceTest = TestModel::factory();
    $entityReferenceTest->string = 'wrong';
    $this->assertTrue($entityReferenceTest->save());
    $entityReferenceGuid = $entityReferenceTest->guid;
    $testEntity->reference = $entityReferenceTest;
    $testEntity->refArray = [0 => ['entity' => $entityReferenceTest]];
    $testEntity->refObject = (object) [
      'thing' => (object) ['entity' => $entityReferenceTest]
    ];
    $this->assertTrue($testEntity->save());

    $entityReferenceTest->test = 'good';
    $this->assertTrue($entityReferenceTest->save());

    return ['entity' => $testEntity, 'refGuid' => $entityReferenceGuid];
  }

  /**
   * @depends testAssignment
   * @param array $arr
   */
  public function testUndefinedProperty($arr) {
    $testEntity = $arr['entity'];

    try {
      $string = $testEntity->undefinedProperty;
    } catch (\PHPUnit\Framework\Error\Notice $e) {
      $this->assertEquals(8, $e->getCode());
      $this->assertEquals(
        'Undefined property: Nymph\EntityData::$undefinedProperty',
        $e->getMessage()
      );
    }
  }

  /**
   * @depends testAssignment
   * @param array $arr
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
   * @param array $arr
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
   * @param array $arr
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
   * @param array $arr
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
   * @param array $arr
   */
  public function testConflictFailsToSave($arr) {
    $testEntity = $arr['entity'];

    $testEntityCopy = TestModel::factory($testEntity->guid);
    $this->assertTrue($testEntityCopy->save());

    $this->assertFalse($testEntity->save());

    $testEntity->refresh();

    $this->assertLessThan(
      .001,
      abs($testEntityCopy->mdate - $testEntity->mdate)
    );
  }

  /**
   * @depends testAssignment
   * @param array $arr
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
   * @param array $arr
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

    // Remove all tags.
    $testEntity->removeTag('test');
    $this->assertTrue($testEntity->save());
    $this->assertTrue($testEntity->refresh());
    $this->assertFalse($testEntity->hasTag('test'));
    $this->assertEquals([], $testEntity->getTags());
    $testEntity->addTag('test');
    $this->assertTrue($testEntity->save());
    $this->assertTrue($testEntity->hasTag('test'));
  }

  /**
   * @depends testAssignment
   * @param array $arr
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
   * @param array $arr
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
   * @param array $arr
   */
  public function testJSON($arr) {
    $testEntity = $arr['entity'];

    $json = json_encode($testEntity);

    $this->assertJsonStringEqualsJsonString(
      '{"guid":'.$testEntity->guid.',"cdate":'.
        json_encode($testEntity->cdate).',"mdate":'.
        json_encode($testEntity->mdate).',"tags":["test"],"data":'.
        '{"reference":["nymph_entity_reference",'.
        $arr['refGuid'].',"NymphTesting\\\\TestModel"],"refArray":[{"entity":'.
        '["nymph_entity_reference",'.
        $arr['refGuid'].',"NymphTesting\\\\TestModel"]}],"refObject":{"thing":'.
        '{"entity":["nymph_entity_reference",'.$arr['refGuid'].
        ',"NymphTesting\\\\TestModel"]}},"name":"Entity Test","number":30,"array":'.
        '["full","of","values",500],"string":"test","null":null},'.
        '"class":"NymphTesting\\\\TestModel"}',
      $json
    );
  }

  /**
   * @depends testAssignment
   * @param array $arr
   */
  public function testAcceptJSON($arr) {
    $testEntity = $arr['entity'];

    // Test that a property can be deleted.
    $json = json_encode($testEntity);

    $entityDataDelete = json_decode($json, true);

    unset($entityDataDelete['data']['string']);
    $testEntity->jsonAcceptData($entityDataDelete);

    $this->assertFalse(isset($testEntity->string));

    $this->assertTrue($testEntity->refresh());

    // Test whitelisted data.
    $json = json_encode($testEntity);

    $entityData = json_decode($json, true);

    $testEntity->cdate = 13;
    $testEntity->mdate = 14;
    $entityData['cdate'] = 13;
    $entityData['mdate'] = 14.00009;
    $entityData['tags'] = ['test', 'notag', 'newtag'];
    $entityData['data']['name'] = 'bad';
    $entityData['data']['string'] = 'good';
    $entityData['data']['null'] = true;
    $entityData['data']['array'] = ['imanarray'];
    $entityData['data']['number'] = 4;
    $entityData['data']['reference'] = false;
    $entityData['data']['refArray'] = [false];
    $entityData['data']['refObject'] = (object) ["thing" => false];
    $testEntity->jsonAcceptData($entityData);

    $this->assertFalse($testEntity->hasTag('notag'));
    $this->assertTrue($testEntity->hasTag('newtag'));
    $this->assertSame(13.0, $testEntity->cdate);
    $this->assertSame(14.00009, $testEntity->mdate);
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

    // Test no whitelist, but protected data instead.
    $testEntity->useProtectedData();

    $testEntity->cdate = 13;
    $testEntity->mdate = 14;
    $testEntity->jsonAcceptData($entityData);

    $this->assertFalse($testEntity->hasTag('notag'));
    $this->assertTrue($testEntity->hasTag('newtag'));
    $this->assertSame(13.0, $testEntity->cdate);
    $this->assertSame(14.00009, $testEntity->mdate);
    $this->assertSame('bad', $testEntity->name);
    $this->assertTrue($testEntity->null);
    $this->assertSame('good', $testEntity->string);
    $this->assertSame(['imanarray'], $testEntity->array);
    $this->assertSame(30, $testEntity->number);
    $this->assertFalse($testEntity->reference);
    $this->assertSame([false], $testEntity->refArray);
    $this->assertEquals((object) ["thing" => false], $testEntity->refObject);

    $this->assertTrue($testEntity->refresh());
  }

  /**
   * @depends testAssignment
   * @param array $arr
   */
  public function testConflictJSON($arr) {
    $testEntity = $arr['entity'];

    // Test that an old JSON payload causes a conflict.
    $json = json_encode($testEntity);

    $this->assertTrue($testEntity->save());

    $thrown = false;
    $data = json_decode($json, true);

    try {
      $testEntity->jsonAcceptData($data);
    } catch (\Nymph\Exceptions\EntityConflictException $e) {
      $thrown = true;
    }

    $this->assertTrue($thrown);

    $thrown = false;

    try {
      $testEntity->jsonAcceptPatch(
        [
          'guid' => $data['guid'],
          'mdate' => $data['mdate'],
          'set' => [
            'test' => true
          ],
          'unset' => [],
          'addTags' => [],
          'removeTags' => []
        ]
      );
    } catch (\Nymph\Exceptions\EntityConflictException $e) {
      $thrown = true;
    }

    $this->assertTrue($thrown);
  }
}
