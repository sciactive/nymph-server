import DataObjectInterface from './DataObjectInterface';

/**
* Entity interface.
 *
 * @property int $guid The GUID of the entity.
 * @property array $tags Array of the entity's tags.
 */
export default class EntityInterface extends DataObjectInterface {
  /**
   * Load an entity.
   *
   * @param int $id The ID of the entity to load, 0 for a new entity.
   * @return Entity|null
   */
  constructor ($id = 0) {
    super();
    for (let name of Object.getOwnPropertyNames(EntityInterface.prototype)) {
      if (name === 'constructor') continue;
      if (this[name].toString().split('\n')[0] !== EntityInterface.prototype[name].toString().split('\n')[0]) {
        throw new Error(`Function signature doesn't match: ${name}`);
      }
    }
  }
  /**
   * Create a new instance.
   *
   * @return Entity An entity instance.
   */
  static factory () {
    throw new Error('Method not implemented.');
  }
  /**
   * Set whether to use "skip_ac" when accessing entity references.
   *
   * @param bool $useSkipAc True or false, whether to use it.
   */
  useSkipAc (useSkipAc) {
    throw new Error('Method not implemented.');
  }
  /**
   * Add one or more tags.
   *
   * @param mixed $tag,... List or array of tags.
   */
  addTag (...tag) {
    throw new Error('Method not implemented.');
  }
  /**
   * Clear the cache of referenced entities.
   *
   * Calling this function ensures that the next time a referenced entity is
   * accessed, it will be retrieved from the entity manager.
   */
  clearCache () {
    throw new Error('Method not implemented.');
  }
  /**
   * Used to retrieve the data array.
   *
   * This should only be used by the entity manager to save the data array
   * into storage.
   *
   * @param bool $includeSData Whether to include the serialized data as well.
   * @return array The entity's data array.
   * @access protected
   */
  getData (includeSData = false) {
    throw new Error('Method not implemented.');
  }
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
  getSData () {
    throw new Error('Method not implemented.');
  }
  /**
   * Get a stdClass object that holds the same data as the entity.
   *
   * A validator that uses the reflection API would not be able to validate an
   * entity. This provides an object that can be validated.
   *
   * @return \stdClass A pure object representation of the entity.
   */
  getValidatable () {
    throw new Error('Method not implemented.');
  }
  /**
   * Check that the entity has all of the given tags.
   *
   * @param mixed $tag,... List or array of tags.
   * @return bool
   */
  hasTag (...tag) {
    throw new Error('Method not implemented.');
  }
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
  putData (data, sdata = {}) {
    throw new Error('Method not implemented.');
  }
  /**
   * Remove one or more tags.
   *
   * @param mixed $tag,... List or array of tags.
   */
  removeTag (...tag) {
    throw new Error('Method not implemented.');
  }
  /**
   * Return a Nymph Entity Reference for this entity.
   *
   * @return array|\Nymph\Entity A Nymph Entity Reference array, or the entity
   *                             if it is not saved yet.
   */
  toReference () {
    throw new Error('Method not implemented.');
  }
}
