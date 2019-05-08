<?php namespace Nymph;

/**
 * Entity data storage object.
 *
 * This object is designed to work like a standard object. But, it also has the
 * ability to store serialized data and unserialize it when it is first
 * accessed.
 *
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @see http://nymph.io/
 */
class EntityData {

  /**
   * Unserialized data.
   *
   * @var array
   */
  private $sdata = [];
  /**
   * Whether to use "skip_ac" when accessing entity references.
   *
   * @var bool
   */
  private $useSkipAc = false;

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
    // Unserialize.
    if ($name === 'sdata' || $name === 'useSkipAc') {
      throw new Exceptions\InvalidParametersException(
          "You can't read the property \"{$name}\" in entity data. It is a ".
          "reserved property."
      );
    }
    if (key_exists($name, $this->sdata)) {
      $this->$name = $this->referencesToEntities(
          unserialize($this->sdata[$name])
      );
      // Setting the property removed the item from sdata.
    } else {
      // Since we must return by reference, we have to try to access the
      // property here by assigning by value to raise a PHP notice about the
      // undefined property. If we return $this->$name, a property will be
      // created.
      $val = $this->$name;
      return $val;
    }
    return $this->$name;
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
    // Unserialize.
    if (key_exists($name, $this->sdata)) {
      $this->$name = $this->referencesToEntities(
          unserialize($this->sdata[$name])
      );
      unset($this->sdata[$name]);
    }
    return isset($this->$name);
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
    if ($name === 'sdata' || $name === 'useSkipAc') {
      throw new Exceptions\InvalidParametersException(
          "You can't set the property \"{$name}\" in entity data. It is a ".
          "reserved property."
      );
    }
    // Delete any serialized value.
    if (isset($this->sdata[$name])) {
      unset($this->sdata[$name]);
    }
    return ($this->$name = $value);
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
    unset($this->$name);
    unset($this->sdata[$name]);
  }

  public function getData($references = true) {
    $data = [];
    foreach ($this as $key => $value) {
      $getValue = $value;
      if (is_object($value)) {
        $getValue = clone $value;
      }
      if ($key !== 'sdata' && $key !== 'useSkipAc') {
        $data[$key] = $references
          ? $this->entitiesToReferences($value)
          : $value;
      }
    }
    return $data;
  }

  public function getSData() {
    return (array) $this->sdata;
  }

  public function putData($data, $sdata = [], $useSkipAc = false) {
    $this->useSkipAc = $useSkipAc;

    if (!is_array($data)) {
      $data = [];
    }

    foreach ($this as $key => $value) {
      if ($key !== 'sdata' && $key !== 'useSkipAc') {
        unset($this->$key);
      }
    }

    $this->sdata = (array) $sdata;

    foreach ($data as $name => $value) {
      $this->$name = $this->referencesToEntities($value);
    }
  }

  /**
   * Convert entities to references and return the result.
   *
   * This function will recurse into arrays and objects.
   *
   * @param mixed $item The item to convert.
   * @return mixed The resulting item.
   */
  private function entitiesToReferences($item) {
    if ((is_a($item, '\Nymph\Entity') || is_a($item, '\SciActive\HookOverride'))
        && is_callable([$item, 'toReference'])) {
      // Convert entities to references.
      return $item->toReference();
    } elseif (is_array($item)) {
      // Recurse into lower arrays.
      return array_map([$this, 'entitiesToReferences'], $item);
    } elseif (is_object($item)) {
      $clone = clone $item;
      foreach ($clone as &$curProperty) {
        $curProperty = $this->entitiesToReferences($curProperty);
      }
      unset($curProperty);
      return $clone;
    }
    // Not an entity or array, just return it.
    return $item;
  }

  /**
   * Convert references to entities and return the result.
   *
   * This function will recurse into arrays and objects.
   *
   * @param mixed $item The item to convert.
   * @return mixed The resulting item.
   */
  private function referencesToEntities($item) {
    if (is_array($item)) {
      if (isset($item[0]) && $item[0] === 'nymph_entity_reference') {
        if (!class_exists($item[2])) {
          throw new Exceptions\EntityClassNotFoundException(
              "Tried to load entity reference that refers to a class that ".
              "can't be found, {$item[2]}."
          );
        }
        // Convert references to entities.
        $entity = call_user_func([$item[2], 'factoryReference'], $item);
        $entity->useSkipAc($this->useSkipAc);
        return $entity;
      } else {
        // Recurse into lower arrays.
        return array_map([$this, 'referencesToEntities'], $item);
      }
    } elseif (is_object($item)
              && !(
                (
                  is_a($item, '\Nymph\Entity')
                  || is_a($item, '\SciActive\HookOverride')
                )
                && is_callable([$item, 'toReference'])
              )) {
      // Only do this for non-entity objects.
      $clone = clone $item;
      foreach ($clone as &$curProperty) {
        $curProperty = $this->referencesToEntities($curProperty);
      }
      unset($curProperty);
      return $clone;
    }
    // Not an object or array, just return it.
    return $item;
  }
}
