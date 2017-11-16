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
class TestModel extends \Nymph\Entity {
  const ETYPE = 'test_model';
  protected $privateData = ['boolean'];
  protected $whitelistData = ['string', 'array', 'mdate'];
  protected $protectedTags = ['test', 'notag'];
  protected $whitelistTags = ['newtag'];

  public function __construct($id = 0) {
    $this->addTag('test');
    $this->boolean = true;
    parent::__construct($id);
  }

  public function useProtectedData() {
    $this->whitelistData = false;
    $this->protectedData = ['number'];
  }
}
