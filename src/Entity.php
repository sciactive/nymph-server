<?php namespace Nymph;

/**
 * Database abstraction object.
 *
 * Used to provide a standard, abstract way to access, manipulate, and store
 * data.
 *
 * The GUID is not set until the entity is saved. GUIDs must be unique forever,
 * even after deletion. It's the job of the entity manager to make sure no two
 * entities ever have the same GUID.
 *
 * Tags are used to classify entities. Where an etype is used to separate data
 * by table, tags are used to separate entities within a table. You can define
 * specific tags to be protected, meaning they cannot be added/removed on the
 * frontend. It can be useful to allow user defined tags, such as for a blog
 * post.
 *
 * Simply calling delete() will not unset the entity. It will still take up
 * memory. Likewise, simply calling unset will not delete the entity from
 * storage.
 *
 * Some notes about equals() and is():
 *
 * equals() performs a more strict comparison of the entity to another. Use
 * equals() instead of the == operator, because the cached entity data causes ==
 * to return false when it should return true. In order to return true, the
 * entity and $object must meet the following criteria:
 *
 * - They must be entities.
 * - They must have equal GUIDs. (Or both can have no GUID.)
 * - They must be instances of the same class.
 * - Their data must be equal.
 *
 * is() performs a less strict comparison of the entity to another. Use is()
 * instead of the == operator when the entity's data may have been changed, but
 * you only care if it is the same entity. In order to return true, the entity
 * and $object must meet the following criteria:
 *
 * - They must be entities.
 * - They must have equal GUIDs. (Or both can have no GUID.)
 * - If they have no GUIDs, their data must be equal.
 *
 * Some notes about saving entities in other entity's variables:
 *
 * The entity class often uses references to store an entity in another entity's
 * variable or array. The reference is stored as an array with the values:
 *
 * - 0 => The string 'nymph_entity_reference'
 * - 1 => The reference entity's GUID.
 * - 2 => The reference entity's class name.
 *
 * Since the reference entity's class name is stored in the reference on the
 * entity's first save and used to retrieve the reference entity using the same
 * class, if you change the class name in an update, you need to reassign the
 * reference entity and save to storage.
 *
 * When an entity is loaded, it does not request its referenced entities from
 * the entity manager. This is done the first time the variable/array is
 * accessed. The referenced entity is then stored in a cache, so if it is
 * altered elsewhere, then accessed again through the variable, the changes will
 * *not* be there. Therefore, you should take great care when accessing entities
 * from multiple variables. If you might be using a referenced entity again
 * later in the code execution (after some other processing occurs), it's
 * recommended to call clearCache().
 *
 * @package Nymph
 * @license http://www.gnu.org/licenses/lgpl.html
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 * @property int $guid The entity's Globally Unique ID.
 * @property int $cdate The entity's creation date, as a Unix timestamp.
 * @property int $mdate The entity's modification date, as a Unix timestamp.
 * @property string $name An optional name of the entity. This will be provided in the "info" property of the JS object.
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
	 * Array of the entity's tags.
	 *
	 * @var array
	 * @access protected
	 */
	protected $tags = [];
	/**
	 * The array used to store each variable assigned to an entity.
	 *
	 * @var array
	 * @access protected
	 */
	protected $data = [];
	/**
	 * Same as $data, but hasn't been unserialized.
	 *
	 * @var array
	 * @access protected
	 */
	protected $sdata = [];
	/**
	 * The array used to store referenced entities.
	 *
	 * This technique allows your code to see another entity as a variable,
	 * while storing only a reference.
	 *
	 * @var array
	 * @access protected
	 */
	protected $entityCache = [];
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
	 * The entries listed here correspond to variables that should be converted
	 * to standard objects instead of arrays when unserializing from JSON.
	 *
	 * @var array
	 * @access public
	 */
	public $objectData = ['ac'];
	/**
	 * The entries listed here correspond to properties that will not be
	 * serialized into JSON with json_encode(). This can also be considered a
	 * blacklist, because these properties will not be set with incoming JSON.
	 *
	 * @var array
	 * @access protected
	 * @todo Filter these vars from requests from the frontend with Tilmeld.
	 */
	protected $privateData = [];
	/**
	 * The entries listed here correspond to properties that can only be
	 * modified by server side code. They will still be visible on the frontend,
	 * unlike privateData, but any changes to them that come from the frontend
	 * will be ignored. This can also be considered a blacklist.
	 *
	 * @var array
	 * @access protected
	 */
	protected $protectedData = [];
	/**
	 * If this is an array, then entries listed here correspond to the only
	 * properties that will be accepted from incoming JSON. Any other properties
	 * will be ignored.
	 *
	 * If you use a whitelist, you don't need to use protectedData, since you
	 * can simply leave those entries out of whitelistData.
	 *
	 * @var array|bool
	 * @access protected
	 */
	protected $whitelistData = false;
	/**
	 * The entries listed here correspond to tags that can only be added/removed
	 * by server side code. They will still be visible on the frontend, but any
	 * changes to them that come from the frontend will be ignored. This can
	 * also be considered a blacklist.
	 *
	 * @var array
	 * @access protected
	 */
	protected $protectedTags = [];
	/**
	 * If this is an array, then tags listed here are the only tags that will be
	 * accepted from incoming JSON. Any other tags will be ignored.
	 *
	 * @var array|bool
	 * @access protected
	 */
	protected $whitelistTags = false;
	/**
	 * The names of the methods allowed to be called by client side JavaScript
	 * with serverCall.
	 *
	 * @var array
	 * @access protected
	 */
	protected $clientEnabledMethods = [];
	/**
	 * The names of the static methods allowed to be called by client side
	 * JavaScript with serverCallStatic.
	 *
	 * Static methods should be called from their class' object, rather than an
	 * instance, in JavaScript.
	 *
	 * @var array
	 * @access public
	 */
	public static $clientEnabledStaticMethods = [];
	/**
	 * The name of the corresponding class on the client side. Leave null to use
	 * the same name.
	 *
	 * @var string|null
	 * @access protected
	 */
	protected $clientClassName = null;
	/**
	 * Whether to use "skip_ac" when accessing entity references.
	 *
	 * @var bool
	 * @access private
	 */
	private $useSkipAc = false;

	/**
	 * Load an entity.
	 * @param int $id The ID of the entity to load, 0 for a new entity.
	 */
	public function __construct($id = 0) {
		if ($id > 0) {
			$entity = Nymph::getEntity(['class' => get_class($this)], ['&', 'guid' => $id]);
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
		$class = get_called_class();
		$args = func_get_args();
		$reflector = new \ReflectionClass($class);
		$entity = $reflector->newInstanceArgs($args);
		// Use hook functionality when in 2be.
		if (class_exists('\SciActive\Hook')) {
			\SciActive\Hook::hookObject($entity, $class.'->', false);
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
		$class = $reference[2];
		if (!class_exists($class)) {
			throw new Exceptions\EntityClassNotFoundException("factoryReference called for a class that can't be found, $class.");
		}
		$entity = call_user_func([$class, 'factory']);
		$entity->referenceSleep($reference);
		return $entity;
	}

	/**
	 * Retrieve a variable.
	 *
	 * You do not need to explicitly call this method. It is called by PHP when
	 * you access the variable normally.
	 *
	 * @param string $name The name of the variable.
	 * @return mixed The value of the variable or nothing if it doesn't exist.
	 */
	public function &__get($name) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ($name === 'guid' || $name === 'tags') {
			return $this->$name;
		}
		// Unserialize.
		if (isset($this->sdata[$name])) {
			$this->data[$name] = unserialize($this->sdata[$name]);
			unset($this->sdata[$name]);
		}
		// Check for peditor sources.
		if (substr($name, -9) === '_pesource' && !isset($this->sdata[$name]) && isset($this->sdata[substr($name, 0, -9)])) {
			$this->data[substr($name, 0, -9)] = unserialize($this->sdata[substr($name, 0, -9)]);
			unset($this->sdata[substr($name, 0, -9)]);
		}
		// Check for an entity first.
		if (isset($this->entityCache[$name])) {
			if ($this->data[$name][0] == 'nymph_entity_reference') {
				if ($this->entityCache[$name] === 0) {
					// The entity hasn't been loaded yet, so load it now.
					$class = $this->data[$name][2];
					if (!class_exists($class)) {
						throw new Exceptions\EntityCorruptedException("Entity reference refers to a class that can't be found, $class.");
					}
					$this->entityCache[$name] = $class::factoryReference($this->data[$name]);
					$this->entityCache[$name]->useSkipAc($this->useSkipAc);
				}
				return $this->entityCache[$name];
			} else {
				throw new Exceptions\EntityCorruptedException("Entity data has become corrupt and cannot be determined.");
			}
		}
		// Check if it's set.
		if (!isset($this->data[$name])) {
			return $this->data[$name];
		}
		// If it's not an entity, return the regular value.
		try {
			if ((array) $this->data[$name] === $this->data[$name]) {
				// But, if it's an array, check all the values for entity references, and change them.
				array_walk($this->data[$name], [$this, 'referenceToEntity']);
			} elseif ((object) $this->data[$name] === $this->data[$name] && !(((is_a($this->data[$name], '\Nymph\Entity') || is_a($this->data[$name], '\SciActive\HookOverride'))) && is_callable([$this->data[$name], 'toReference']))) {
				// Only do this for non-entity objects.
				foreach ($this->data[$name] as &$curProperty) {
					$this->referenceToEntity($curProperty, null);
				}
				unset($curProperty);
			}
		} catch (Exceptions\EntityClassNotFoundException $e) {
			throw new Exceptions\EntityCorruptedException($e->getMessage());
		}
		// Check for peditor sources.
		if (substr($name, -9) === '_pesource' && !isset($this->data[$name])) {
			return $this->data[substr($name, 0, -9)];
		}
		return $this->data[$name];
	}

	/**
	 * Checks whether a variable is set.
	 *
	 * You do not need to explicitly call this method. It is called by PHP when
	 * you access the variable normally.
	 *
	 * @param string $name The name of the variable.
	 * @return bool
	 * @todo Check that a referenced entity has not been deleted.
	 */
	public function __isset($name) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ($name === 'guid' || $name === 'tags') {
			return isset($this->$name);
		}
		// Unserialize.
		if (isset($this->sdata[$name])) {
			$this->data[$name] = unserialize($this->sdata[$name]);
			unset($this->sdata[$name]);
		}
		return isset($this->data[$name]);
	}

	/**
	 * Sets a variable.
	 *
	 * You do not need to explicitly call this method. It is called by PHP when
	 * you access the variable normally.
	 *
	 * @param string $name The name of the variable.
	 * @param mixed $value The value of the variable.
	 * @return mixed The value of the variable.
	 */
	public function __set($name, $value) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ($name === 'guid') {
			return ($this->$name = isset($value) ? (int) $value : null);
		}
		if ($name === 'tags') {
			return ($this->$name = (array) $value);
		}
		// Delete any serialized value.
		if (isset($this->sdata[$name])) {
			unset($this->sdata[$name]);
		}
		if ((is_a($value, '\Nymph\Entity') || is_a($value, '\SciActive\HookOverride')) && is_callable([$value, 'toReference'])) {
			// Store a reference to the entity (its GUID and the class it was loaded as).
			// We don't want to manipulate $value itself, because it could be a variable that the program is still using.
			$saveValue = $value->toReference();
			// If toReference returns an array, the GUID of the entity is set
			// or it's a sleeping reference, so this is an entity and we don't
			// store it in the data array.
			if ((array) $saveValue === $saveValue) {
				$this->entityCache[$name] = $value;
			} elseif (isset($this->entityCache[$name])) {
				unset($this->entityCache[$name]);
			}
			$this->data[$name] = $saveValue;
			return $value;
		} else {
			// This is not an entity, so if it was one, delete the cached entity.
			if (isset($this->entityCache[$name])) {
				unset($this->entityCache[$name]);
			}
			// Store the actual value passed.
			$saveValue = $value;
			// If the variable is an array, look through it and change entities to references.
			if ((array) $saveValue === $saveValue) {
				array_walk_recursive($saveValue, [$this, 'entityToReference']);
			}
			return ($this->data[$name] = $saveValue);
		}
	}

	/**
	 * Unsets a variable.
	 *
	 * You do not need to explicitly call this method. It is called by PHP when
	 * you access the variable normally.
	 *
	 * @param string $name The name of the variable.
	 */
	public function __unset($name) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ($name === 'guid') {
			unset($this->$name);
			return;
		}
		if ($name === 'tags') {
			$this->$name = [];
			return;
		}
		if (isset($this->entityCache[$name])) {
			unset($this->entityCache[$name]);
		}
		unset($this->data[$name]);
		unset($this->sdata[$name]);
	}

	public function addTag() {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		$tagArray = func_get_args();
		if ((array) $tagArray[0] === $tagArray[0]) {
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
		if ((array) $array !== $array) {
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
		// Convert entities in arrays.
		foreach ($this->data as &$value) {
			if ((array) $value === $value) {
				array_walk_recursive($value, [$this, 'entityToReference']);
			}
		}
		unset($value);

		// Handle individual entities.
		foreach ($this->entityCache as $key => &$value) {
			if (strpos($key, 'reference_guid: ') === 0) {
				// If it's from an array, remove it.
				unset($this->entityCache[$key]);
			} else {
				// If it's from a property, set it back to 0.
				$value = 0;
			}
		}
		unset($value);
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

	/**
	 * Check if an item is an entity, and if it is, convert it to a reference.
	 *
	 * @param mixed &$item The item to check.
	 * @param mixed $key Unused, but can't be removed because array_walk_recursive will fail.
	 * @access private
	 */
	private function entityToReference(&$item, $key) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ((is_a($item, '\Nymph\Entity') || is_a($item, '\SciActive\HookOverride')) && isset($item->guid) && is_callable([$item, 'toReference'])) {
			// This is an entity, so we should put it in the entity cache.
			if (!isset($this->entityCache["reference_guid: {$item->guid}"])) {
				$this->entityCache["reference_guid: {$item->guid}"] = clone $item;
			}
			// Make a reference to the entity (its GUID) and the class the entity was loaded as.
			$item = $item->toReference();
		}
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
			if ($this->guid != $testObject->guid) {
				return false;
			}
		}
		if (get_class($testObject) != get_class($this)) {
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
			foreach ($this->sdata as $key => $value) {
				$this->data[$key] = unserialize($value);
				unset($this->sdata[$key]);
			}
		}
		// Convert any entities to references.
		return array_map([$this, 'getDataReference'], $this->data);
	}

	/**
	 * Convert entities to references and return the result.
	 *
	 * @param mixed $item The item to convert.
	 * @return mixed The resulting item.
	 */
	private function getDataReference($item) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ((is_a($item, '\Nymph\Entity') || is_a($item, '\SciActive\HookOverride')) && is_callable([$item, 'toReference'])) {
			// Convert entities to references.
			return $item->toReference();
		} elseif ((array) $item === $item) {
			// Recurse into lower arrays.
			return array_map([$this, 'getDataReference'], $item);
		} elseif ((object) $item === $item) {
			foreach ($item as &$curProperty) {
				$curProperty = $this->getDataReference($curProperty);
			}
			unset($curProperty);
		}
		// Not an entity or array, just return it.
		return $item;
	}

	public function getSData() {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		return $this->sdata;
	}

	public function getTags() {
		return $this->tags;
	}

	public function hasTag() {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ((array) $this->tags !== $this->tags) {
			return false;
		}
		$tagArray = func_get_args();
		if (!$tagArray) {
			return false;
		}
		if ((array) $tagArray[0] === $tagArray[0]) {
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

	public function info($type) {
		if ($type == 'name' && isset($this->name)) {
			return $this->name;
		} elseif ($type == 'type') {
			return 'entity';
		} elseif ($type == 'types') {
			return 'entities';
		}
		return null;
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
			return ($this->guid == $testObject->guid);
		} elseif (!is_callable([$testObject, 'getData'])) {
			return false;
		} else {
			$obData = $testObject->getData(true);
			$myData = $this->getData(true);
			return ($obData == $myData);
		}
	}

	public function jsonSerialize($clientClassName = true) {
		$object = (object) [];
		if ($this->isASleepingReference) {
			return $this->sleepingReference;
		}
		$object->guid = $this->guid;
		$object->cdate = $this->cdate;
		$object->mdate = $this->mdate;
		$object->tags = $this->tags;
		$object->info = [
			'name' => $this->info('name'),
			'type' => $this->info('type'),
			'types' => $this->info('types')
		];
		if ($this->info('url_view')) {
			$object->info['url_view'] = $this->info('url_view');
		}
		if ($this->info('url_edit')) {
			$object->info['url_edit'] = $this->info('url_edit');
		}
		if ($this->info('url_list')) {
			$object->info['url_list'] = $this->info('url_list');
		}
		if ($this->info('icon')) {
			$object->info['icon'] = $this->info('icon');
		}
		if ($this->info('image')) {
			$object->info['image'] = $this->info('image');
		}
		$object->data = [];
		foreach ($this->getData(true) as $key => $val) {
			if ($key !== 'cdate' && $key !== 'mdate' && !in_array($key, $this->privateData)) {
				$object->data[$key] = $val;
			}
		}
		$object->class = ($clientClassName && isset($this->clientClassName)) ? $this->clientClassName : get_class($this);
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
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}

		foreach ($this->objectData as $var) {
			if (isset($data[$var]) && (array) $data[$var] === $data) {
				$data[$var] = (object) $data[$var];
			}
		}

		$privateData = [];
		foreach ($this->privateData as $var) {
			if (key_exists($var, $this->data) || key_exists($var, $this->sdata)) {
				$privateData[$var] = $this->$var;
			}
			if (key_exists($var, $data)) {
				unset($data[$var]);
			}
		}

		$protectedData = [];
		foreach ($this->protectedData as $var) {
			if (key_exists($var, $this->data) || key_exists($var, $this->sdata)) {
				$protectedData[$var] = $this->$var;
			}
			if (key_exists($var, $data)) {
				unset($data[$var]);
			}
		}

		$nonWhitelistData = [];
		if ($this->whitelistData !== false) {
			$nonWhitelistData = $this->getData(true);
			foreach ($data as $var => $val) {
				if (!in_array($var, $this->whitelistData)) {
					unset($data[$var]);
				}
			}
		}

		$data = array_merge($nonWhitelistData, $data, $protectedData, $privateData);

		if (!isset($data['cdate'])) {
			$data['cdate'] = $this->cdate;
		}
		if (!isset($data['mdate'])) {
			$data['mdate'] = $this->mdate;
		}

		$this->putData($data);
	}

	public function putData($data, $sdata = []) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ((array) $data !== $data) {
			$data = [];
		}
		// Erase the entity cache.
		$this->entityCache = [];
		foreach ($data as $name => $value) {
			if ((array) $value === $value && isset($value[0]) && $value[0] === 'nymph_entity_reference') {
				// Don't load the entity yet, but make the entry in the array,
				// so we know it is an entity reference. This will speed up
				// retrieving entities with lots of references, especially
				// recursive references.
				$this->entityCache[$name] = 0;
			}
		}
		foreach ($sdata as $name => $value) {
			if (strpos($value, 'a:3:{i:0;s:22:"nymph_entity_reference";') === 0) {
				// Don't load the entity yet, but make the entry in the array,
				// so we know it is an entity reference. This will speed up
				// retrieving entities with lots of references, especially
				// recursive references.
				$this->entityCache[$name] = 0;
			}
		}
		$this->data = $data;
		$this->sdata = $sdata;
	}

	/**
	 * Set up a sleeping reference.
	 * @param array $reference The reference to use to wake.
	 */
	public function referenceSleep($reference) {
		if (count($reference) !== 3 || $reference[0] !== 'nymph_entity_reference' || (int) $reference[1] !== $reference[1] || (string) $reference[2] !== $reference[2]) {
			throw new Exceptions\InvalidParametersException('referenceSleep expects parameter 1 to be a valid Nymph entity reference.');
		}
		$thisClass = get_class($this);
		if ($reference[2] !== $thisClass) {
			throw new Exceptions\InvalidParametersException("referenceSleep can only be called with an entity reference of the same class. Given class: {$reference[2]}; this class: $thisClass.");
		}
		$this->isASleepingReference = true;
		$this->sleepingReference = $reference;
	}

	/**
	 * Check if an item is a reference, and if it is, convert it to an entity.
	 *
	 * This function will recurse into deeper arrays.
	 *
	 * @param mixed &$item The item to check.
	 * @param mixed $key Unused, but can't be removed because array_walk will fail.
	 * @access private
	 */
	private function referenceToEntity(&$item, $key) {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		if ((array) $item === $item) {
			if (isset($item[0]) && $item[0] === 'nymph_entity_reference') {
				if (!isset($this->entityCache["reference_guid: {$item[1]}"])) {
					if (!class_exists($item[2])) {
						throw new Exceptions\EntityClassNotFoundException("Tried to load entity reference that refers to a class that can't be found, {$item[2]}.");
					}
					$this->entityCache["reference_guid: {$item[1]}"] = call_user_func([$item[2], 'factoryReference'], $item);
				}
				$item = $this->entityCache["reference_guid: {$item[1]}"];
			} else {
				array_walk($item, [$this, 'referenceToEntity']);
			}
		} elseif ((object) $item === $item && !(((is_a($item, '\Nymph\Entity') || is_a($item, '\SciActive\HookOverride'))) && is_callable([$item, 'toReference']))) {
			// Only do this for non-entity objects.
			foreach ($item as &$curProperty) {
				$this->referenceToEntity($curProperty, null);
			}
			unset($curProperty);
		}
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
			throw new Exceptions\EntityClassNotFoundException("Tried to wake sleeping reference entity that refers to a class that can't be found, {$this->sleepingReference[2]}.");
		}
		$entity = Nymph::getEntity(['class' => $this->sleepingReference[2], 'skip_ac' => $this->useSkipAc], ['&', 'guid' => $this->sleepingReference[1]]);
		if (!isset($entity)) {
			return false;
		}
		$this->isASleepingReference = false;
		$this->sleepingReference = null;
		$this->guid = $entity->guid;
		$this->tags = $entity->tags;
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
		$refresh = Nymph::getEntity(['class' => get_class($this)], ['&', 'guid' => $this->guid]);
		if (!isset($refresh)) {
			return 0;
		}
		$this->clearCache();
		$this->tags = $refresh->tags;
		$this->putData($refresh->getData(), $refresh->getSData());
		return true;
	}

	public function removeTag() {
		if ($this->isASleepingReference) {
			$this->referenceWake();
		}
		$tagArray = func_get_args();
		if ((array) $tagArray[0] === $tagArray[0]) {
			$tagArray = $tagArray[0];
		}
		foreach ($tagArray as $tag) {
			// Can't use array_search, because $tag may exist more than once.
			foreach ($this->tags as $curKey => $cur_tag) {
				if ($cur_tag === $tag) {
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
