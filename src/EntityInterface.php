<?php namespace Nymph;
/**
 * Entity interface.
 *
 * @package Nymph
 * @license http://www.gnu.org/licenses/lgpl.html
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */

/**
 * Entity interface.
 *
 * @package Nymph
 * @property int $guid The GUID of the entity.
 * @property array $tags Array of the entity's tags.
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
   * Clear the cache of referenced entities.
   *
   * Calling this function ensures that the next time a referenced entity is
   * accessed, it will be retrieved from the entity manager.
   */
  public function clearCache();
  /**
   * Used to retrieve the data array.
   *
   * This should only be used by the entity manager to save the data array
   * into storage.
   *
   * @return array The entity's data array.
   * @access protected
   */
  public function getData();
  /**
   * Used to retrieve the serialized data array.
   *
   * This should only be used by the entity manager to save the data array
   * into storage.
   *
   * This method can be used by entity managers to avoid unserializing data
   * that hasn't been requested yet.
   *
   * It should always be called after getData().
   *
   * @return array The entity's serialized data array.
   * @access protected
   */
  public function getSData();
  /**
   * Check that the entity has all of the given tags.
   *
   * @param mixed $tag,... List or array of tags.
   * @return bool
   */
  public function hasTag();
  /**
   * Used to set the data array.
   *
   * This should only be used by the entity manager to push the data array
   * from storage.
   *
   * $sdata be used by entity managers to avoid unserializing data that hasn't
   * been requested yet.
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
   * @return array|\Nymph\Entity A Nymph Entity Reference array, or the entity if it is not saved yet.
   */
  public function toReference();
}
