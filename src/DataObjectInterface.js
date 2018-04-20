/**
 * Data Object interface.
 *
 * Objects which hold data from some type of storage.
 */
export default class DataObjectInterface {
  constructor () {
    for (let name of Object.getOwnPropertyNames(DataObjectInterface.prototype)) {
      if (name === 'constructor') continue;
      if (this[name].toString().split('\n')[0] !== DataObjectInterface.prototype[name].toString().split('\n')[0]) {
        throw new Error(`Function signature doesn't match: ${name}`);
      }
    }
  }
  /**
   * Search the array for this object and return the corresponding key.
   *
   * If strict is false, is() is used to compare. If strict is true,
   * equals() is used.
   *
   * @param array array The array to search.
   * @param bool strict Whether to use stronger comparison.
   * @return mixed The key if the object is in the array, false if it isn't or
   *               if array is not an array.
   */
  arraySearch (array, strict = false) {
    throw new Error('Method not implemented.');
  }
  /**
   * Delete the object from storage.
   *
   * @return bool True on success, false on failure.
   */
  delete () {
    throw new Error('Method not implemented.');
  }
  /**
   * Perform a more strict comparison of this object to another.
   *
   * @param mixed &object The object to compare.
   * @return bool True or false.
   */
  equals (object) {
    throw new Error('Method not implemented.');
  }
  /**
   * Check whether this object is in an array.
   *
   * If strict is false, is() is used to compare. If strict is true,
   * equals() is used.
   *
   * @param array array The array to search.
   * @param bool strict Whether to use stronger comparison.
   * @return bool True if the object is in the array, false if it isn't or if
   *              array is not an array.
   */
  inArray (array, strict = false) {
    throw new Error('Method not implemented.');
  }
  /**
   * Perform a less strict comparison of this object to another.
   *
   * @param mixed &object The object to compare.
   * @return bool True or false.
   */
  is (object) {
    throw new Error('Method not implemented.');
  }
  /**
   * Refresh the object from storage.
   *
   * If the object has been deleted from storage, the database cannot be
   * reached, or a database error occurs, refresh() will return 0.
   *
   * @return bool|int False if the data has not been saved, 0 if it can't be
   *                  refreshed, true on success.
   */
  refresh () {
    throw new Error('Method not implemented.');
  }

  /**
   * Save the object to storage.
   *
   * @return bool True on success, false on failure.
   */
  save () {
    throw new Error('Method not implemented.');
  }
}
