<?php namespace Nymph;

/**
 * Entity interface.
 *
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @see http://nymph.io/
 */
interface EntityInterface extends DataObjectInterface, \JsonSerializable {
  /**
   * Load an entity.
   *
   * @param int $id The ID of the entity to load, 0 for a new entity.
   * @return Entity|null
   */
  public function __construct($id = 0);
  /**
   * Create a new instance.
   *
   * @return Entity An entity instance.
   */
  public static function factory();
  /**
   * Set whether to use "skip_ac" when accessing entity references.
   *
   * @param bool $useSkipAc True or false, whether to use it.
   */
  public function useSkipAc($useSkipAc);
  /**
   * Add one or more tags.
   *
   * @param mixed $tag,... List or array of tags.
   */
  public function addTag();
  /**
   * Replace any referenced entities in the data with sleeping references.
   *
   * Calling this function ensures that the next time a referenced entity is
   * accessed, it will be retrieved from the DB (unless it is in Nymph's cache).
   */
  public function clearCache();
  /**
   * Used to retrieve the data array.
   *
   * This should only be used by Nymph to save the data into storage.
   *
   * @param bool $includeSData Whether to include the serialized data as well.
   * @return array The entity's data array.
   */
  public function getData($includeSData = false);
  /**
   * Used to retrieve the serialized data array.
   *
   * This should only be used by Nymph to save the data array into storage.
   *
   * This method is used by Nymph to avoid unserializing data that hasn't been
   * requested yet.
   *
   * It should always be called after getData().
   *
   * @return array The entity's serialized data array.
   */
  public function getSData();
  /**
   * Get the original values of the AC properties.
   *
   * @return array An associative array of AC properties.
   */
  public function getOriginalAcValues();
  /**
   * Get a stdClass object that holds the same data as the entity.
   *
   * A validator that uses the reflection API would not be able to validate an
   * entity. This provides an object that can be validated.
   *
   * @return \stdClass A pure object representation of the entity.
   */
  public function getValidatable();
  /**
   * Check that the entity has all of the given tags.
   *
   * @param mixed $tag,... List or array of tags.
   * @return bool
   */
  public function hasTag();
  /**
   * Used to set the data.
   *
   * This should only be used by Nymph to push the data from storage.
   *
   * $sdata is used by Nymph to avoid unserializing data that hasn't been
   * requested yet.
   *
   * @param array $data The data array.
   * @param array $sdata The serialized data array.
   */
  public function putData($data, $sdata = []);
  /**
   * Remove one or more tags.
   *
   * @param mixed $tag,... List or array of tags.
   */
  public function removeTag();
  /**
   * Return a Nymph Entity Reference for this entity.
   *
   * @return array|\Nymph\Entity A Nymph Entity Reference array, or the entity
   *                             if it is not saved yet.
   */
  public function toReference();
}
