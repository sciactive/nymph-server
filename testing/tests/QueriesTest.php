<?php namespace NymphTesting;

use Nymph\Nymph;

// phpcs:disable Generic.Files.LineLength.TooLong

class QueriesTest extends \PHPUnit\Framework\TestCase {
  public function testInstantiate() {
    $driver = Nymph::$driver;
    if (class_exists('\SciActive\Hook')) {
      if (getenv('DB') === 'pgsql') {
        $this->assertInstanceOf('\SciActive\HookOverride_Nymph_Drivers_PostgreSQLDriver', $driver);
      } elseif (getenv('DB') === 'sqlite') {
        $this->assertInstanceOf('\SciActive\HookOverride_Nymph_Drivers_SQLite3Driver', $driver);
      } else {
        $this->assertInstanceOf('\SciActive\HookOverride_Nymph_Drivers_MySQLDriver', $driver);
      }
    } else {
      $this->assertInstanceOf('\Nymph\Drivers\DriverInterface', $driver);
    }
  }

  public function testTranslateSelectorsAndRestrictSearch() {
    $actual = \Nymph\REST::translateSelector('NymphTesting\TestModel', [
      'type' => '&',
      'strict' => ['fish', 'crab'],
      'isset' => 'fish',
      'gte' => [
        ['boats', 49],
        ['fish', 50]
      ],
      1 => [
        'type' => '&',
        'isset' => ['fish'],
        'data' => ['fish', 'junk'],
        'equal' => ['fish', 'junk'],
        '!equal' => ['fish', 'junk'],
        'array' => [
          ['spoot', 'smoot'],
          ['fish', 'barbecue']
        ]
      ],
      2 => [
        '&',
        [
          'isset' => ['fish', 'spoot'],
          'data' => ['fish', 'junk'],
          'array' => [
            ['spoot', 'smoot'],
            ['fish', 'barbecue'],
            ['kirk', 'land']
          ]
        ]
      ]
    ]);

    $expected = ['&',
      ['&',
        'array' => [
          ['spoot', 'smoot']
        ]
      ],
      ['&',
        'isset' => ['spoot'],
        'array' => [
          ['spoot','smoot'],
          ['kirk', 'land']
        ]
      ],
      'gte' => [
        ['boats', 49]
      ]
    ];

    $this->assertEquals($expected, $actual);
  }

  /**
   * @expectedException \Nymph\Exceptions\InvalidParametersException
   */
  public function testInvalidQueryDeprecated() {
    Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'thing'
        ],
        [
          'data' => ['this_query', 'should_fail']
        ]
    );
  }

  /**
   * @expectedException \Nymph\Exceptions\InvalidParametersException
   */
  public function testInvalidQuery() {
    Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'thing'
        ],
        [
          'equal' => ['this_query', 'should_fail']
        ]
    );
  }

  public function testDeleteOldTestData() {
    $all = Nymph::getEntities(['class' => 'NymphTesting\TestModel']);
    $this->assertTrue(is_array($all));
    foreach ($all as $cur) {
      $this->assertTrue($cur->delete());
    }

    $all = Nymph::getEntities(['class' => 'NymphTesting\TestModel']);
    $this->assertEmpty($all);
  }

  /**
   * @depends testDeleteOldTestData
   */
  public function testCreateEntity() {
    // Creating entity...
    $testEntity = TestModel::factory();
    $this->assertThat(
        $testEntity,
        $this->logicalOr(
            $this->isInstanceOf('NymphTesting\TestModel'),
            $this->isInstanceOf('\Sciactive\HookOverride_NymphTesting_TestModel')
        )
    );

    // Saving entity...
    $testEntity->name = 'Entity Test '.time();
    $testEntity->null = null;
    $testEntity->string = 'test';
    $testEntity->array = ['full', 'of', 'values', 500];
    $testEntity->match = "Hello, my name is Edward McCheese. It is a pleasure to meet you. As you can see, I have several hats of the most pleasant nature.

  This one's email address is nice_hat-wednesday+newyork@im-a-hat.hat.
  This one's phone number is (555) 555-1818.
  This one's zip code is 92064.";
    $testEntity->number = 30;
    $testEntity->numberString = "30";
    $testEntity->numberFloat = 30.5;
    $testEntity->numberFloatString = "30.5";
    $testEntity->timestamp = time();
    $this->assertTrue($testEntity->save());
    $entityGuid = $testEntity->guid;

    $entityReferenceTest = new TestModel();
    $entityReferenceTest->string = 'wrong';
    $entityReferenceTest->timestamp = strtotime('-2 days');
    $this->assertTrue($entityReferenceTest->save());
    $entityReferenceGuid = $entityReferenceTest->guid;
    $testEntity->reference = $entityReferenceTest;
    $testEntity->refArray = [0 => ['entity' => $entityReferenceTest]];
    $this->assertTrue($testEntity->save());

    $entityReferenceTest->test = 'good';
    $this->assertTrue($entityReferenceTest->save());

    $testEntity = Nymph::getEntity(['class' => 'NymphTesting\TestModel'], $entityGuid);
    $this->assertThat(
        $testEntity,
        $this->logicalOr(
            $this->isInstanceOf('NymphTesting\TestModel'),
            $this->isInstanceOf('\Sciactive\HookOverride_NymphTesting_TestModel')
        )
    );

    return ['entity' => $testEntity, 'refGuid' => $entityReferenceGuid];
  }

  /**
   * @depends testCreateEntity
   */
  public function testCreateMultiEntities() {
    // Creating 100 entities...
    for ($i = 0; $i < 100; $i++) {
      $testEntity = TestModel::factory();
      $testEntity->name = "Multi Test {$i}";
      $testEntity->removeTag('test');
      $testEntity->addTag('multiTest');
      $this->assertTrue($testEntity->save());
      usleep(20);
    }
  }

  /**
   * @depends testCreateEntity
   */
  public function testByGuid($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by GUID...
    $resultEntity = Nymph::getEntity(
        ['class' => 'NymphTesting\TestModel'],
        $testEntity->guid
    );
    $this->assertTrue($testEntity->is($resultEntity));

    // Using class constructor...
    $resultEntity = TestModel::factory($testEntity->guid);
    $this->assertTrue($testEntity->is($resultEntity));

    // Testing wrong GUID...
    $resultEntity = Nymph::getEntity(
        ['class' => 'NymphTesting\TestModel'],
        $testEntity->guid + 1
    );
    if (!empty($resultEntity)) {
      $this->assertTrue(!$testEntity->is($resultEntity));
    } else {
      $this->assertNull($resultEntity);
    }
  }

  /**
   * @depends testCreateEntity
   */
  public function testOptions($arr) {
    $testEntity = $arr['entity'];

    // Testing entity order, offset, limit...
    $resultEntities = Nymph::getEntities(
        [
          'class' => 'NymphTesting\TestModel',
          'reverse' => true,
          'offset' => 1,
          'limit' => 1,
          'sort' => 'cdate'
        ],
        ['&', 'tag' => 'test']
    );
    $this->assertEquals(1, count($resultEntities));
    $this->assertTrue($testEntity->is($resultEntities[0]));
  }

  /**
   * @depends testCreateEntity
   */
  public function testGUIDAndTags($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by GUID and tags...
    $resultEntity = Nymph::getEntity(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'guid' => $testEntity->guid, 'tag' => 'test']
    );
    $this->assertTrue($testEntity->is($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testOrSelector($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by GUID and tags...
    $resultEntity = Nymph::getEntity(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'guid' => [$testEntity->guid, $testEntity->guid % 1000 + 1]]
    );
    $this->assertTrue($testEntity->is($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongOrSelector($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by GUID and tags...
    $resultEntity = Nymph::getEntity(
        ['class' => 'NymphTesting\TestModel'],
        ['|',
          'guid' => [
            $testEntity->guid % 1000 + 1,
            $testEntity->guid % 1000 + 2
          ]
        ]
    );
    $this->assertFalse($testEntity->is($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotGUID($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !GUID...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', '!guid' => ($testEntity->guid + 1), 'tag' => 'test']
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotTags($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !tags...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'guid' => $testEntity->guid, '!tag' => ['barbecue', 'pickles']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testGUIDAndWrongTags($arr) {
    $testEntity = $arr['entity'];

    // Testing GUID and wrong tags...
    $resultEntity = Nymph::getEntity(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'guid' => $testEntity->guid, 'tag' => ['pickles']]
    );
    $this->assertEmpty($resultEntity);
  }

  /**
   * @depends testCreateEntity
   */
  public function testTags($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by tags...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test']
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongTags($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong tags...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickles']
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testInclusiveTags($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by tags inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'tag' => ['pickles', 'test', 'barbecue']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongInclusiveTags($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong inclusive tags...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'tag' => ['pickles', 'barbecue']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testMixedTags($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by mixed tags...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|', 'tag' => ['pickles', 'test', 'barbecue']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongInclusiveMixedTags($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong inclusive mixed tags...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|', 'tag' => ['pickles', 'barbecue']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongExclusiveMixedTags($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong exclusive mixed tags...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickles'],
        ['|', 'tag' => ['test', 'barbecue']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testIsset($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by isset...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'isset' => ['string']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotIsset($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !isset...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', '!isset' => ['null']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotIssetOnUnset($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !isset on unset var...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['!&', 'isset' => ['pickles']],
        ['&', 'tag' => 'test']
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testStrict($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by strict...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'strict' => ['string', 'test']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotStrict($arr) {
    $testEntity = $arr['entity'];
    $referenceEntity = TestModel::factory($arr['refGuid']);
    $this->assertSame($arr['refGuid'], $referenceEntity->guid);

    // Retrieving entity by !strict...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', '!strict' => ['string', 'wrong']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testDataDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'data' => ['string', 'test']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testEqual($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by equal...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'equal' => ['string', 'test']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotDataDeprecated($arr) {
    $testEntity = $arr['entity'];
    $referenceEntity = TestModel::factory($arr['refGuid']);
    $this->assertSame($arr['refGuid'], $referenceEntity->guid);

    // Retrieving entity by !data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', '!data' => ['string', 'wrong']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotEqual($arr) {
    $testEntity = $arr['entity'];
    $referenceEntity = TestModel::factory($arr['refGuid']);
    $this->assertSame($arr['refGuid'], $referenceEntity->guid);

    // Retrieving entity by !equal...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', '!equal' => ['string', 'wrong']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testDataInclusiveDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by data inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'data' => [['string', 'test'], ['string', 'pickles']]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testEqualInclusive($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by equal inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'equal' => [['string', 'test'], ['string', 'pickles']]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotDataInclusiveDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !data inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['!|', 'data' => [['name', $testEntity->name], ['string', 'pickles']]],
        ['|', '!data' => [['name', $testEntity->name], ['string', 'pickles']]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotEqualInclusive($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !equal inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['!|', 'equal' => [['name', $testEntity->name], ['string', 'pickles']]],
        ['|', '!equal' => [['name', $testEntity->name], ['string', 'pickles']]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongDataDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'data' => ['string', 'pickles']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongEqual($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong equal...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'equal' => ['string', 'pickles']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testLike($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by like...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'like' => ['string', 't_s%']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotLike($arr) {
    $testEntity = $arr['entity'];
    $referenceEntity = TestModel::factory($arr['refGuid']);
    $this->assertSame($arr['refGuid'], $referenceEntity->guid);

    // Retrieving entity by !data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', '!like' => ['string', 'wr_n%']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testIlike($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'ilike' => ['string', 'T_s%']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotIlike($arr) {
    $testEntity = $arr['entity'];
    $referenceEntity = TestModel::factory($arr['refGuid']);
    $this->assertSame($arr['refGuid'], $referenceEntity->guid);

    // Retrieving entity by !data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', '!ilike' => ['string', 'wr_n%']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testLikeWrongCase($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'like' => ['string', 'T_s%']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testTagsAndDataDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by tags and data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'data' => ['string', 'test']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testTagsAndEqual($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by tags and equal...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'equal' => ['string', 'test']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongTagsRightDataDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong tags and right data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickles', 'data' => ['string', 'test']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongTagsRightEqual($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong tags and right equal...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickles', 'equal' => ['string', 'test']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testRightTagsWrongDataDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Testing right tags and wrong data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'data' => ['string', 'pickles']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testRightTagsWrongEqual($arr) {
    $testEntity = $arr['entity'];

    // Testing right tags and wrong equal...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'equal' => ['string', 'pickles']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongTagsWrongDataDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong tags and wrong data...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickles', 'data' => ['string', 'pickles']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongTagsWrongEqual($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong tags and wrong equal...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickles', 'equal' => ['string', 'pickles']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testArrayValue($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by array value...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'array' => ['array', 'values']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotArrayValue($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !array value...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['!&', 'array' => ['array', 'pickles']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongArrayValue($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong array value...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'array' => ['array', 'pickles']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPCRE($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex match...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'match' => ['match', '/.*/']] // anything
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'match' => ['match', '/Edward McCheese/']
        ] // a substring
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|',
          'match' => [
            ['string', '/\d/'],
            ['match', '/Edward McCheese/']
          ]
        ] // inclusive test
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'match' => ['match', '/\b[\w\-+]+@[\w-]+\.\w{2,4}\b/']
        ] // a simple email
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'match' => ['match', '/\(\d{3}\)\s\d{3}-\d{4}/']
        ] // a phone number
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongPCRE($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong regex match...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'match' => ['match', '/Q/']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickle', 'match' => ['match', '/.*/']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'match' => [['string', '/\d/'], ['match', '/,,/']]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPCREAndDataInclusiveDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex + data inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|', 'data' => ['string', 'pickles'], 'match' => ['string', '/test/']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPCREAndEqualInclusive($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex + equal inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|', 'equal' => ['string', 'pickles'], 'match' => ['string', '/test/']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPosixRegex($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex match...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'pmatch' => ['match', '.*']] // anything
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'pmatch' => ['match', 'Edward McCheese']
        ] // a substring
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|',
          'pmatch' => [['string', '[0-9]'], ['match', 'Edward McCheese']]
        ] // inclusive test
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'pmatch' => [
            'match',
            '[[:<:]][a-zA-Z0-9+_-]+@[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]{2,4}[[:>:]]'
          ]
        ] // a simple email
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'pmatch' => ['match', '\([0-9]{3}\) [0-9]{3}-[0-9]{4}']
        ] // a phone number
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPosixRegexCase($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex match...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'ipmatch' => ['match', 'edward mccheese']
        ] // a substring
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongPosixRegex($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong regex match...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'pmatch' => ['match', 'Q']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'pickle', 'pmatch' => ['match', '.*']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'pmatch' => [['string', '[0-9]'], ['match', ',,']]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPosixRegexWrongCase($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex match...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'tag' => 'test',
          'pmatch' => ['match', 'edward mccheese']
        ] // a substring
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPosixRegexAndDataInclusiveDeprecated($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex + data inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|', 'data' => ['string', 'pickles'], 'pmatch' => ['string', 'test']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testPosixRegexAndEqualInclusive($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by regex + equal inclusively...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['|', 'equal' => ['string', 'pickles'], 'pmatch' => ['string', 'test']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testGreaterThanInequality($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['number', 30], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['numberFloat', 30.5], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['number', 31], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['numberFloat', 31], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['number', 29], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['numberString', 30], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['numberString', 31], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['numberString', 29], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gt' => [['numberFloatString', 29], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testGreaterThanOrEqualToInequality($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['number', 30], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['numberFloat', 30.5], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['number', 31], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['numberFloat', 31], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['number', 29], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['numberString', 30], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['numberString', 31], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['numberString', 29], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'gte' => [['numberFloatString', 29], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testLessThanInequality($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['number', 30], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['numberFloat', 30.5], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['number', 31], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['numberFloat', 31], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['number', 29], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['numberString', 30], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['numberString', 31], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['numberString', 29], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lt' => [['numberFloatString', 29], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testLessThanOrEqualToInequality($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['number', 30], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['numberFloat', 30.5], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['number', 31], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['numberFloat', 31], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['number', 29], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['numberString', 30], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['numberString', 31], ['pickles', 100]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['numberString', 29], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Retrieving entity by inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'lte' => [['numberFloatString', 29], ['pickles', 100]]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotInequality($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['!&', 'gte' => ['number', 60]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongInequality($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong inequality...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'lte' => ['number', 29.99]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testCDate($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'gt' => ['cdate', $testEntity->cdate - 120]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongCDate($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'gte' => ['cdate', $testEntity->cdate + 1]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testTimeSelector($arr) {
    $testEntity = $arr['entity'];
    $referenceEntity = TestModel::factory($arr['refGuid']);

    // Retrieving entity by relative time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'gt' => ['timestamp', null, '-1 day']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));

    // Retrieving entity by relative time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'gt' => ['timestamp', null, '-3 days']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertTrue($referenceEntity->inArray($resultEntity));

    // Retrieving entity by relative time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'gt' => ['cdate', null, '-1 day']]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
    $this->assertTrue($referenceEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongTimeSelector($arr) {
    $testEntity = $arr['entity'];
    $referenceEntity = TestModel::factory($arr['refGuid']);

    // Retrieving entity by relative time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'gt' => ['timestamp', null, '+1 day']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));

    // Retrieving entity by relative time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'lt' => ['timestamp', null, '-3 days']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));

    // Retrieving entity by relative time...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test', 'gt' => ['cdate', null, '+1 day']]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
    $this->assertFalse($referenceEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testReferences($arr) {
    $testEntity = $arr['entity'];

    // Testing referenced entities...
    $this->assertSame('good', $testEntity->reference->test);

    // Testing referenced entity arrays...
    $this->assertSame('good', $testEntity->refArray[0]['entity']->test);
  }

  /**
   * @depends testCreateEntity
   */
  public function testReference($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'ref' => ['reference', $arr['refGuid']]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNotReference($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by !reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'tag' => 'test'],
        ['!&', 'ref' => ['reference', $arr['refGuid'] + 1]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongReference($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'ref' => ['reference', $arr['refGuid'] + 1]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testNonexistentReference($arr) {
    $testEntity = $arr['entity'];

    // Testing non-existent reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'ref' => ['pickle', $arr['refGuid']]]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testInclusiveReference($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by inclusive reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|', 'ref' => ['reference', [$arr['refGuid'], $arr['refGuid'] + 1]]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    // Retrieving entity by inclusive reference... (slower query when written
    // like this.)
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|',
          'ref' => [
            ['reference', $arr['refGuid']],
            ['reference', $arr['refGuid'] + 1]
          ]
        ]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongInclusiveReference($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong inclusive reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|',
          'ref' => ['reference', [$arr['refGuid'] + 2, $arr['refGuid'] + 1]]
        ]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));

    // Testing wrong inclusive reference... (slower query when written like
    // this.)
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|',
          'ref' => [
            ['reference', $arr['refGuid'] + 2],
            ['reference', $arr['refGuid'] + 1]
          ]
        ]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testArrayReference($arr) {
    $testEntity = $arr['entity'];

    // Retrieving entity by array reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'ref' => ['refArray', $arr['refGuid']]]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongArrayReference($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong array reference...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          'ref' => [
            ['refArray', $arr['refGuid']],
            ['refArray', $arr['refGuid'] + 1]
          ]
        ]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testLogicOperations($arr) {
    $testEntity = $arr['entity'];

    // Testing logic operations...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          '!ref' => [
            ['refArray', $arr['refGuid'] + 1],
            ['refArray', $arr['refGuid'] + 2]
          ],
          '!lte' => ['number', 29.99]
        ],
        ['|',
          '!lte' => [
            ['number', 29.99],
            ['number', 30]
          ]
        ],
        ['!&',
          '!strict' => ['string', 'test'],
          '!array' => [
            ['array', 'full'],
            ['array', 'of'],
            ['array', 'values'],
            ['array', 500]
          ]
        ],
        ['!|',
          '!strict' => ['string', 'test'],
          'array' => [
            ['array', 'full'],
            ['array', 'of'],
            ['array', 'values'],
            ['array', 500]
          ]
        ]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateEntity
   */
  public function testDeepSelector($arr) {
    $testEntity = $arr['entity'];

    // Testing deep selectors...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          '!ref' => [
            ['refArray', $arr['refGuid'] + 1],
            ['refArray', $arr['refGuid'] + 2]
          ],
          '!lte' => ['number', 29.99]
        ],
        ['&',
          ['|',
            '!lte' => [
              ['number', 29.99],
              ['number', 30]
            ]
          ],
          ['!&',
            '!strict' => ['string', 'test'],
            '!array' => [
              ['array', 'full'],
              ['array', 'of'],
              ['array', 'values'],
              ['array', 500]
            ]
          ],
          ['!|',
            '!strict' => ['string', 'test'],
            'array' => [
              ['array', 'full'],
              ['array', 'of'],
              ['array', 'values'],
              ['array', 500]
            ]
          ]
        ]
    );
    $this->assertTrue($testEntity->inArray($resultEntity));

    $resultEntity2 = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          '!ref' => [
            ['refArray', $arr['refGuid'] + 1],
            ['refArray', $arr['refGuid'] + 2]
          ],
          '!lte' => ['number', 29.99]
        ],
        ['|',
          ['&',
            '!lte' => [
              ['number', 29.99],
              ['number', 30]
            ]
          ],
          ['!&',
            '!strict' => ['string', 'test'],
            '!array' => [
              ['array', 'full'],
              ['array', 'of'],
              ['array', 'values'],
              ['array', 500]
            ]
          ],
          ['&',
            '!strict' => ['string', 'test'],
            'array' => [
              ['array', 'full'],
              ['array', 'of'],
              ['array', 'values'],
              ['array', 500]
            ]
          ]
        ]
    );
    $this->assertTrue($testEntity->inArray($resultEntity2));

    $resultEntity3 = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|',
          ['&',
            '!ref' => ['refArray', $arr['refGuid'] + 2],
            '!lte' => ['number', 29.99]
          ],
          ['&',
            'gte' => ['number', 16000]
          ]
        ]
    );
    $this->assertTrue($testEntity->inArray($resultEntity3));

    $resultEntity4 = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['|',
          ['&',
            '!ref' => ['refArray', $arr['refGuid'] + 2],
            '!lte' => ['number', 29.99]
          ],
          ['&',
            ['&',
              ['&',
                'gte' => ['number', 16000]
              ]
            ]
          ]
        ]
    );
    $this->assertTrue($testEntity->inArray($resultEntity4));
  }

  /**
   * @depends testCreateEntity
   */
  public function testWrongDeepSelector($arr) {
    $testEntity = $arr['entity'];

    // Testing wrong deep selectors...
    $resultEntity = Nymph::getEntities(
        ['class' => 'NymphTesting\TestModel'],
        ['&',
          ['&',
            '!ref' => ['refArray', $arr['refGuid'] + 2],
            '!lte' => ['number', 29.99]
          ],
          ['&',
            'gte' => ['number', 16000]
          ]
        ]
    );
    $this->assertFalse($testEntity->inArray($resultEntity));
  }

  /**
   * @depends testCreateMultiEntities
   */
  public function testSort($arr) {
    foreach (['guid', 'cdate', 'mdate'] as $sort) {
      // Retrieving entities sorted...
      $resultEntities = Nymph::getEntities(
          ['class' => 'NymphTesting\TestModel', 'sort' => $sort]
      );
      $this->assertNotEmpty($resultEntities);
      $this->assertGreaterThan(100, count($resultEntities));
      for ($i = 0; isset($resultEntities[$i+1]); $i++) {
        $this->assertLessThan($resultEntities[$i+1]->$sort, $resultEntities[$i]->$sort);
      }

      // Retrieving entities reverse sorted...
      $resultEntities = Nymph::getEntities(
          ['class' => 'NymphTesting\TestModel', 'sort' => $sort, 'reverse' => true]
      );
      $this->assertNotEmpty($resultEntities);
      $this->assertGreaterThan(100, count($resultEntities));
      for ($i = 0; isset($resultEntities[$i+1]); $i++) {
        $this->assertGreaterThan($resultEntities[$i+1]->$sort, $resultEntities[$i]->$sort);
      }

      // And again with other selectors.
      // Retrieving entities sorted...
      $resultEntities = Nymph::getEntities(
          ['class' => 'NymphTesting\TestModel', 'sort' => $sort],
          ['&', 'pmatch' => ['name', '^Multi Test ']]
      );
      $this->assertNotEmpty($resultEntities);
      $this->assertEquals(100, count($resultEntities));
      for ($i = 0; isset($resultEntities[$i+1]); $i++) {
        $this->assertLessThan($resultEntities[$i+1]->$sort, $resultEntities[$i]->$sort);
      }

      // Retrieving entities reverse sorted...
      $resultEntities = Nymph::getEntities(
          ['class' => 'NymphTesting\TestModel', 'sort' => $sort, 'reverse' => true],
          ['&', 'pmatch' => ['name', '^Multi Test ']]
      );
      $this->assertNotEmpty($resultEntities);
      $this->assertEquals(100, count($resultEntities));
      for ($i = 0; isset($resultEntities[$i+1]); $i++) {
        $this->assertGreaterThan($resultEntities[$i+1]->$sort, $resultEntities[$i]->$sort);
      }
    }
  }

  /**
   * @depends testCreateEntity
   */
  public function testDeleteReference($arr) {
    $testEntity = $arr['entity'];

    // Deleting referenced entities...
    $this->assertTrue($testEntity->reference->delete());
    $this->assertNull($testEntity->reference->guid);
  }

  /**
   * @depends testCreateEntity
   */
  public function testDelete($arr) {
    $testEntity = $arr['entity'];

    $guid = $testEntity->guid;

    // Deleting entity...
    $this->assertTrue($testEntity->delete());
    $this->assertNull($testEntity->guid);

    $entity = Nymph::getEntity(
        ['class' => 'NymphTesting\TestModel'],
        ['&', 'guid' => $guid]
    );

    $this->assertNull($entity);
  }
}
