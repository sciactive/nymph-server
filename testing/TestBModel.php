<?php namespace NymphTesting;

/**
 * This class is a test class that extends the Entity class.
 *
 * @property string $name A string.
 * @property null $null A null.
 * @property string $string A string.
 * @property string $test A string.
 * @property array $array An string.
 * @property string $match A string.
 * @property integer $number A number.
 * @property bool $boolean A boolean.
 * @property TestModel $reference A TestModel.
 * @property array $ref_array An array.
 * @property stdClass $ref_object An object.
 * @property TestModel $parent A parent entity.
 */
class TestBModel extends TestModel {
  const ETYPE = 'test_b_model';
}
