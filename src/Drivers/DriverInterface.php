<?php namespace Nymph\Drivers;

/**
 * Nymph driver interface.
 *
 * @package Nymph
 * @license http://www.gnu.org/licenses/lgpl.html
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
interface DriverInterface {
  public function connect();
  public function deleteEntity(&$entity);
  public function deleteEntityByID($guid);
  public function deleteUID($name);
  public function disconnect();
  public function export($filename);
  public function exportPrint();
  public function getEntities();
  public function getEntity();
  public function getUID($name);
  public function hsort(&$array, $property = null, $parentProperty = null, $caseSensitive = false, $reverse = false);
  public function import($filename);
  public function newUID($name);
  public function psort(&$array, $property = null, $parentProperty = null, $caseSensitive = false, $reverse = false);
  public function renameUID($oldName, $newName);
  public function saveEntity(&$entity);
  public function setUID($name, $value);
  public function sort(&$array, $property = null, $caseSensitive = false, $reverse = false);
}
