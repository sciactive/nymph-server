<?php namespace Nymph;

/**
 * Database abstraction object.
 *
 * Provides a way to access, manipulate, and store data in Nymph.
 *
 * The GUID is not set until the entity is saved. GUIDs must be unique forever,
 * even after deletion. It's the job of the Nymph DB driver to make sure no two
 * entities ever have the same GUID.
 *
 * Each entity class has an etype that determines which table(s) in the database
 * it belongs to. If two entity classes have the same etype, their data will be
 * stored in the same table(s). This isn't a good idea, however, because
 * references to an entity store a class name, not an etype.
 *
 * Tags are used to classify entities. Where an etype is used to separate data
 * by tables, tags can be used to separate entities within a table. You can
 * define specific tags to be protected, meaning they cannot be added/removed
 * from the REST endpoint. It can be useful to allow user defined tags, such as
 * for blog posts.
 *
 * Simply calling delete() will not unset the entity. It will still take up
 * memory. Likewise, simply calling unset will not delete the entity from the
 * DB.
 *
 * Some notes about equals() and is(), the replacements for "==":
 *
 * The == operator will likely not give you the result you want, since any yet
 * to be unserialized data causes == to return false when you probably want it
 * to return true.
 *
 * equals() performs a more strict comparison of the entity to another. Use
 * equals() instead of the == operator when you want to check both the entities
 * they represent, and the data inside them. In order to return true for
 * equals(), the entity and $object must meet the following criteria:
 *
 * - They must be entities.
 * - They must have equal GUIDs, or both must have no GUID.
 * - They must be instances of the same class.
 * - Their data must be equal.
 *
 * is() performs a less strict comparison of the entity to another. Use is()
 * instead of the == operator when the entity's data may have been changed, but
 * you only care if they represent the same entity. In order to return true, the
 * entity and $object must meet the following criteria:
 *
 * - They must be entities.
 * - They must have equal GUIDs, or both must have no GUID.
 * - If they have no GUIDs, their data must be equal.
 *
 * Some notes about saving entities in other entity's properties:
 *
 * Entities use references in the DB to store an entity in their properties. The
 * reference is stored as an array with the values:
 *
 * - 0 => The string 'nymph_entity_reference'
 * - 1 => The referenced entity's GUID.
 * - 2 => The referenced entity's class name.
 *
 * Since the referenced entity's class name is stored in the reference on the
 * parent entity, if you change the class name in an update, you need to
 * reassign all referenced entities of that class and resave.
 *
 * When an entity is loaded, it does not request its referenced entities from
 * Nymph. Instead, it creates instances without data called sleeping references.
 * When you first access an entity's data, if it is a sleeping reference, it
 * will fill its data from the DB. You can call clearCache() to turn all the
 * entities back into sleeping references.
 *
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 *
 * @property int $guid The entity's Globally Unique ID.
 * @property int $cdate The entity's creation date, as a high precision Unix
 *                      timestamp. The value is rounded to the ten thousandths
 *                      digit.
 * @property int $mdate The entity's modification date, as a high precision Unix
 *                      timestamp. The value is rounded to the ten thousandths
 *                      digit.
 * @property array $tags The entity's tags. An array of strings.
 */
class Entity implements EntityInterface {
  const ETYPE = 'entity';

  /**
   * The GUID of the entity.
   *
   * @var int|null
   * @access private
   */
  private $guid = null;
  /**
   * The creation date of the entity as a high precision Unix timestamp.
   *
   * The value is rounded to the ten thousandths digit.
   *
   * @var float|null
   * @access private
   */
  private $cdate = null;
  /**
   * The modified date of the entity as a high precision Unix timestamp.
   *
   * The value is rounded to the ten thousandths digit.
   *
   * @var float|null
   * @access private
   */
  private $mdate = null;
  /**
   * Array of the entity's tags.
   *
   * @var array
   * @access protected
   */
  protected $tags = [];
  /**
   * The data store.
   *
   * @var array
   * @access protected
   */
  protected $data;
  /**
   * Whether this instance is a sleeping reference.
   *
   * @var bool
   * @access private
   */
  private $isASleepingReference = false;
  /**
   * The reference to use to wake.
   *
   * @var array|null
   * @access private
   */
  private $sleepingReference = null;
  /**
   * Properties that should be converted to standard objects instead of arrays
   * when unserializing from JSON.
   *
   * @var array
   * @access public
   */
  public $objectData = [];
  /**
   * Properties that will not be serialized into JSON with json_encode(). This
   * can be considered a blacklist, because these properties will not be set
   * with incoming JSON.
   *
   * Clients CAN still determine what is in these properties, unless they are
   * also listed in searchRestrictedData.
   *
   * @var array
   * @access protected
   */
  protected $privateData = [];
  /**
   * Properties that will not be searchable from the frontend. If the frontend
   * includes any of these properties in any of their clauses, they will be
   * filtered out before the search is executed.
   *
   * @var array
   * @access protected
   */
  public static $searchRestrictedData = [];
  /**
   * Properties that can only be modified by server side code. They will still
   * be visible on the frontend, unlike privateData, but any changes to them
   * that come from the frontend will be ignored.
   *
   * In addition to what's listed here, the 'user' and 'group' properties will
   * be filtered for non-admins when Tilmeld is detected.
   *
   * @var array
   * @access protected
   */
  protected $protectedData = [];
  /**
   * If this is an array, then it lists the only properties that will be
   * accepted from incoming JSON. Any other properties will be ignored.
   *
   * If you use a whitelist, you don't need to use protectedData, since you
   * can simply leave those entries out of whitelistData.
   *
   * @var array|bool
   * @access protected
   */
  protected $whitelistData = false;
  /**
   * Tags that can only be added/removed by server side code. They will still be
   * visible on the frontend, but any changes to them that come from the
   * frontend will be ignored.
   *
   * @var array
   * @access protected
   */
  protected $protectedTags = [];
  /**
   * If this is an array, then it lists the only tags that will be accepted from
   * incoming JSON. Any other tags will be ignored.
   *
   * @var array|bool
   * @access protected
   */
  protected $whitelistTags = false;
  /**
   * The names of methods allowed to be called by the frontend with serverCall.
   *
   * @var array
   * @access protected
   */
  protected $clientEnabledMethods = [];
  /**
   * The names of static methods allowed to be called by the frontedn with
   * serverCallStatic.
   *
   * @var array
   * @access public
   */
  public static $clientEnabledStaticMethods = [];
  /**
   * Whether to use "skip_ac" when accessing entity references.
   *
   * @var bool
   * @access private
   */
  private $useSkipAc = false;
  /**
   * The AC properties' values when the entity was loaded.
   *
   * @var array
   * @access private
   */
  private $originalAcValues = [];

  /**
   * Load an entity.
   * @param int $id The ID of the entity to load, 0 for a new entity.
   */
  public function __construct($id = 0) {
    if (!isset($this->data)) {
      $this->data = new EntityData();
    }
    if ($id > 0) {
      $entity = Nymph::getEntity(
          ['class' => get_class($this)],
          ['&', 'guid' => $id]
      );
      if (isset($entity)) {
        $this->guid = $entity->guid;
        $this->tags = $entity->tags;
        $this->putData($entity->getData(), $entity->getSData());
        return $this;
      }
    }
    return null;
  }

  /**
   * Create a new instance.
   * @return \Nymph\Entity The new instance.
   */
  public static function factory() {
    $className = get_called_class();
    $args = func_get_args();
    $reflector = new \ReflectionClass($className);
    $entity = $reflector->newInstanceArgs($args);
    // Use hook functionality when available.
    if (class_exists('\SciActive\Hook')) {
      \SciActive\Hook::hookObject($entity, $className.'->', false);
    }
    return $entity;
  }

  /**
   * Create a new sleeping reference instance.
   *
   * Sleeping references won't retrieve their data from the database until it
   * is actually used.
   *
   * @param array $reference The Nymph Entity Reference to use to wake.
   * @return \Nymph\Entity The new instance.
   */
  public static function factoryReference($reference) {
    $className = $reference[2];
    if (!class_exists($className)) {
      throw new Exceptions\EntityClassNotFoundException(
          "factoryReference called for a class that can't be found, $className."
      );
    }
    $entity = call_user_func([$className, 'factory']);
    $entity->referenceSleep($reference);
    return $entity;
  }

  /**
   * Retrieve a property.
   *
   * You do not need to explicitly call this method. It is called by PHP when
   * you access the property normally.
   *
   * @param string $name The name of the property.
   * @return mixed The value of the property or null if it doesn't exist.
   */
  public function &__get($name) {
    if ($name === 'guid') {
      return $this->guid;
    }
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if ($name === 'cdate'
        || $name === 'mdate'
        || $name === 'tags'
      ) {
      return $this->$name;
    }
    return $this->data->$name;
  }

  /**
   * Checks whether a property is set.
   *
   * You do not need to explicitly call this method. It is called by PHP when
   * you access the property normally.
   *
   * @param string $name The name of the property.
   * @return bool
   */
  public function __isset($name) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if ($name === 'guid'
        || $name === 'cdate'
        || $name === 'mdate'
        || $name === 'tags'
      ) {
      return isset($this->$name);
    }
    return isset($this->data->$name);
  }

  /**
   * Sets a property.
   *
   * You do not need to explicitly call this method. It is called by PHP when
   * you access the property normally.
   *
   * @param string $name The name of the property.
   * @param mixed $value The value of the property.
   * @return mixed The value of the property.
   */
  public function __set($name, $value) {
    // When providing defaults, a subclass may try to set values before the
    // constructor has run.
    if (!isset($this->data)) {
      $this->data = new EntityData();
    }
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if ($name === 'guid') {
      return ($this->$name = isset($value) ? (int) $value : null);
    }
    if ($name === 'cdate' || $name === 'mdate') {
      return ($this->$name = floor(((float) $value) * 10000) / 10000);
    }
    if ($name === 'tags') {
      return ($this->$name = (array) $value);
    }
    return ($this->data->$name = $value);
  }

  /**
   * Unsets a property.
   *
   * You do not need to explicitly call this method. It is called by PHP when
   * you access the property normally.
   *
   * @param string $name The name of the property.
   */
  public function __unset($name) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if ($name === 'guid') {
      unset($this->$name);
      return;
    }
    if ($name === 'cdate') {
      $this->$name = null;
      return;
    }
    if ($name === 'mdate') {
      $this->$name = null;
      return;
    }
    if ($name === 'tags') {
      $this->$name = [];
      return;
    }
    unset($this->data->$name);
  }

  public function addTag() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    $tagArray = func_get_args();
    if (is_array($tagArray[0])) {
      $tagArray = $tagArray[0];
    }
    if (empty($tagArray)) {
      return;
    }
    foreach ($tagArray as $tag) {
      $this->tags[] = $tag;
    }
    $this->tags = array_keys(array_flip($this->tags));
  }

  public function arraySearch($array, $strict = false) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if (!is_array($array)) {
      return false;
    }
    foreach ($array as $key => $curEntity) {
      if ($strict ? $this->equals($curEntity) : $this->is($curEntity)) {
        return $key;
      }
    }
    return false;
  }

  public function clearCache() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }

    $this->data->putData(
        $this->data->getData(),
        $this->data->getSData(),
        $this->useSkipAc
    );
  }

  public function clientEnabledMethods() {
    return $this->clientEnabledMethods;
  }

  public function delete() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    return Nymph::deleteEntity($this);
  }

  public function equals(&$object) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    // If this object is hooked, get the real object.
    if (is_a($object, '\SciActive\HookOverride')) {
      $testObject = $object->_hookObject();
    } else {
      $testObject = $object;
    }
    if (!is_a($testObject, '\Nymph\Entity')) {
      return false;
    }
    if (isset($this->guid) || isset($testObject->guid)) {
      if ($this->guid !== $testObject->guid) {
        return false;
      }
    }
    if (get_class($testObject) !== get_class($this)) {
      return false;
    }
    if ($testObject->cdate !== $this->cdate) {
      return false;
    }
    if ($testObject->mdate !== $this->mdate) {
      return false;
    }
    $obData = $testObject->getData(true);
    $myData = $this->getData(true);
    return ($obData == $myData);
  }

  public function getData($includeSData = false) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if ($includeSData) {
      // Access all the serialized properties to initialize them.
      $sdata = $this->data->getSData();
      foreach ($sdata as $key => $value) {
        $unused = $this->data->$key;
      }
    }
    return $this->data->getData();
  }

  public function getSData() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    return $this->data->getSData();
  }

  public function getOriginalAcValues() {
    return $this->originalAcValues;
  }

  public function getValidatable() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    $sdata = $this->data->getSData();
    foreach ($sdata as $key => $value) {
      $unused = $this->data->$key;
    }
    // Access all the serialized properties to initialize them.
    $data = $this->data->getData(false);
    $data['guid'] = $this->guid;
    $data['cdate'] = $this->cdate;
    $data['mdate'] = $this->mdate;
    $data['tags'] = $this->tags;
    array_walk_recursive($data, function (&$item) {
      if (is_a($item, '\SciActive\HookOverride')
          && is_callable([$item, '_hookObject'])) {
        $item = $item->_hookObject();
      }
    });
    return (object) $data;
  }

  public function getTags() {
    return $this->tags;
  }

  public function hasTag() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if (!is_array($this->tags)) {
      return false;
    }
    $tagArray = func_get_args();
    if (!$tagArray) {
      return false;
    }
    if (is_array($tagArray[0])) {
      $tagArray = $tagArray[0];
    }
    foreach ($tagArray as $tag) {
      if (!in_array($tag, $this->tags)) {
        return false;
      }
    }
    return true;
  }

  public function inArray($array, $strict = false) {
    return $this->arraySearch($array, $strict) !== false;
  }

  public function is(&$object) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    // If this object is hooked, get the real object.
    if (is_a($object, '\SciActive\HookOverride')) {
      $testObject = $object->_hookObject();
    } else {
      $testObject = $object;
    }
    if (!is_a($testObject, '\Nymph\Entity')) {
      return false;
    }
    if (isset($this->guid) || isset($testObject->guid)) {
      return ($this->guid === $testObject->guid);
    } elseif (!is_callable([$testObject, 'getData'])) {
      return false;
    } else {
      $obData = $testObject->getData(true);
      $myData = $this->getData(true);
      return ($obData == $myData);
    }
  }

  public function jsonSerialize() {
    if ($this->isASleepingReference) {
      return $this->sleepingReference;
    }
    $object = (object) [];
    $object->guid = $this->guid;
    $object->cdate = $this->cdate;
    $object->mdate = $this->mdate;
    $object->tags = $this->tags;
    $object->data = [];
    foreach ($this->getData(true) as $key => $val) {
      if (!in_array($key, $this->privateData)) {
        $object->data[$key] = $val;
      }
    }
    $object->class = get_class($this);
    return $object;
  }

  public function jsonAcceptTags($tags) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }

    $currentTags = $this->getTags();
    $protectedTags = array_intersect($this->protectedTags, $currentTags);
    $tags = array_diff($tags, $this->protectedTags);

    if ($this->whitelistTags !== false) {
      $tags = array_intersect($tags, $this->whitelistTags);
    }

    $this->removeTag($currentTags);
    $this->addTag(array_keys(array_flip(array_merge($tags, $protectedTags))));
  }

  public function jsonAcceptData($data) {
    // TODO: Do this without causing everything to become unserialized.
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }

    foreach ($this->objectData as $name) {
      if (isset($data[$name]) && (array) $data[$name] === $data) {
        $data[$name] = (object) $data[$name];
      }
    }

    $privateData = [];
    foreach ($this->privateData as $name) {
      if (isset($this->data->$name)) {
        $privateData[$name] = $this->data->$name;
      }
      if (key_exists($name, $data)) {
        unset($data[$name]);
      }
    }

    $protectedData = [];
    $protectedProps = $this->protectedData;
    if (class_exists('\Tilmeld\Tilmeld')
        && !\Tilmeld\Tilmeld::gatekeeper('tilmeld/admin')
    ) {
      $protectedProps[] = 'user';
      $protectedProps[] = 'group';
    }
    foreach ($protectedProps as $name) {
      if (isset($this->data->$name)) {
        $protectedData[$name] = $this->data->$name;
      }
      if (key_exists($name, $data)) {
        unset($data[$name]);
      }
    }

    $nonWhitelistData = [];
    if ($this->whitelistData !== false) {
      $nonWhitelistData = $this->getData(true);
      foreach ($this->whitelistData as $name) {
        unset($nonWhitelistData[$name]);
      }
      foreach ($data as $name => $val) {
        if (!in_array($name, $this->whitelistData)) {
          unset($data[$name]);
        }
      }
    }

    $newData = array_merge($nonWhitelistData, $data, $protectedData, $privateData);

    $this->putData($newData);
  }

  public function putData($data, $sdata = []) {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    $this->data->putData($data, $sdata, $this->useSkipAc);

    if (empty($this->originalAcValues)) {
      $this->originalAcValues['user'] = $this->data->user ?? null;
      $this->originalAcValues['group'] = $this->data->group ?? null;
      $this->originalAcValues['acUser'] = $this->data->acUser ?? null;
      $this->originalAcValues['acGroup'] = $this->data->acGroup ?? null;
      $this->originalAcValues['acOther'] = $this->data->acOther ?? null;
      $this->originalAcValues['acRead'] = $this->data->acRead ?? null;
      $this->originalAcValues['acWrite'] = $this->data->acWrite ?? null;
      $this->originalAcValues['acFull'] = $this->data->acFull ?? null;
    }
  }

  /**
   * Set up a sleeping reference.
   * @param array $reference The reference to use to wake.
   */
  public function referenceSleep($reference) {
    if (count($reference) !== 3
        || $reference[0] !== 'nymph_entity_reference'
        || !is_int($reference[1])
        || !is_string($reference[2])) {
      throw new Exceptions\InvalidParametersException(
          'referenceSleep expects parameter 1 to be a valid Nymph entity '.
          'reference.'
      );
    }
    $thisClass = get_class($this);
    if ($reference[2] !== $thisClass) {
      throw new Exceptions\InvalidParametersException(
          "referenceSleep can only be called with an entity reference of the ".
          "same class. Given class: {$reference[2]}; this class: $thisClass."
      );
    }
    $this->isASleepingReference = true;
    $this->guid = $reference[1];
    $this->sleepingReference = $reference;
  }

  /**
   * Wake from a sleeping reference.
   *
   * @return bool True on success, false on failure.
   */
  private function referenceWake() {
    if (!$this->isASleepingReference) {
      return true;
    }
    if (!class_exists($this->sleepingReference[2])) {
      throw new Exceptions\EntityClassNotFoundException(
          "Tried to wake sleeping reference entity that refers to a class ".
          "that can't be found, {$this->sleepingReference[2]}."
      );
    }
    $entity = Nymph::getEntity(
        ['class' => $this->sleepingReference[2], 'skip_ac' => $this->useSkipAc],
        ['&', 'guid' => $this->sleepingReference[1]]
    );
    if (!isset($entity)) {
      return false;
    }
    $this->isASleepingReference = false;
    $this->sleepingReference = null;
    $this->guid = $entity->guid;
    $this->tags = $entity->tags;
    $this->cdate = $entity->cdate;
    $this->mdate = $entity->mdate;
    $this->putData($entity->getData(), $entity->getSData());
    return true;
  }

  public function refresh() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    if (!isset($this->guid)) {
      return false;
    }
    $refresh = Nymph::getEntity(
        [
          'class' => get_class($this),
          'skip_cache' => true,
          'skip_ac' => $this->useSkipAc
        ],
        ['&', 'guid' => $this->guid]
    );
    if (!isset($refresh)) {
      return 0;
    }
    $this->tags = $refresh->tags;
    $this->cdate = $refresh->cdate;
    $this->mdate = $refresh->mdate;
    $this->putData($refresh->getData(), $refresh->getSData());
    return true;
  }

  public function removeTag() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    $tagArray = func_get_args();
    if (is_array($tagArray[0])) {
      $tagArray = $tagArray[0];
    }
    foreach ($tagArray as $tag) {
      // Can't use array_search, because $tag may exist more than once.
      foreach ($this->tags as $curKey => $curTag) {
        if ($curTag === $tag) {
          unset($this->tags[$curKey]);
        }
      }
    }
    $this->tags = array_values($this->tags);
  }

  public function save() {
    if ($this->isASleepingReference) {
      $this->referenceWake();
    }
    return Nymph::saveEntity($this);
  }

  public function toReference() {
    if ($this->isASleepingReference) {
      return $this->sleepingReference;
    }
    if (!isset($this->guid)) {
      return $this;
    }
    return ['nymph_entity_reference', $this->guid, get_class($this)];
  }

  public function useSkipAc($useSkipAc) {
    $this->useSkipAc = (bool) $useSkipAc;
  }
}
