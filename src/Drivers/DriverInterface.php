<?php namespace Nymph\Drivers;

/**
 * Nymph driver interface.
 *
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
interface DriverInterface {
  public function connect();
  public function deleteEntity(&$entity);
  public function deleteEntityByID($guid, $className = null);
  public function deleteUID($name);
  public function disconnect();
  public function export($filename);
  public function exportPrint();
  public function getEntities($options = [], ...$selectors);
  public function getEntity($options = [], ...$selectors);
  public function getUID($name);
  public function hsort(
      &$array,
      $property = null,
      $parentProperty = null,
      $caseSensitive = false,
      $reverse = false
  );
  public function import($filename);
  public function newUID($name);
  public function psort(
      &$array,
      $property = null,
      $parentProperty = null,
      $caseSensitive = false,
      $reverse = false
  );
  public function renameUID($oldName, $newName);
  public function saveEntity(&$entity);
  public function setUID($name, $value);
  public function sort(
      &$array,
      $property = null,
      $caseSensitive = false,
      $reverse = false
  );
}
