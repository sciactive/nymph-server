<?php namespace Nymph;

/**
 * Data Object interface.
 *
 * Objects which hold data from some type of storage.
 *
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
interface DataObjectInterface {
  /**
   * Search the array for this object and return the corresponding key.
   *
   * If $strict is false, is() is used to compare. If $strict is true,
   * equals() is used.
   *
   * @param array $array The array to search.
   * @param bool $strict Whether to use stronger comparison.
   * @return mixed The key if the object is in the array, false if it isn't or
   *               if $array is not an array.
   */
  public function arraySearch($array, $strict = false);
  /**
   * Delete the object from storage.
   *
   * @return bool True on success, false on failure.
   */
  public function delete();
  /**
   * Perform a more strict comparison of this object to another.
   *
   * @param mixed &$object The object to compare.
   * @return bool True or false.
   */
  public function equals(&$object);
  /**
   * Check whether this object is in an array.
   *
   * If $strict is false, is() is used to compare. If $strict is true,
   * equals() is used.
   *
   * @param array $array The array to search.
   * @param bool $strict Whether to use stronger comparison.
   * @return bool True if the object is in the array, false if it isn't or if
   *              $array is not an array.
   */
  public function inArray($array, $strict = false);
  /**
   * Perform a less strict comparison of this object to another.
   *
   * @param mixed &$object The object to compare.
   * @return bool True or false.
   */
  public function is(&$object);
  /**
   * Refresh the object from storage. (Bypasses Nymph's cache.)
   *
   * If the object has been deleted from storage, the database cannot be
   * reached, or a database error occurs, refresh() will return 0.
   *
   * @return bool|int False if the data has not been saved, 0 if it can't be
   *                  refreshed, true on success.
   */
  public function refresh();
  /**
   * Save the object to storage.
   *
   * @return bool True on success, false on failure.
   */
  public function save();
}
