<?php namespace Nymph;

use SciActive\RequirePHP as RequirePHP;

/**
 * Simple Nymph REST server implementation.
 *
 * Provides Nymph functionality compatible with a REST API. Allows the developer
 * to design their own API, or just use the reference implementation.
 *
 * @package Nymph
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
class REST {
  /**
   * Run the Nymph REST server process.
   *
   * Note that on failure, an HTTP error status code will be sent, usually
   * along with a message body.
   *
   * @param string $method The HTTP method.
   * @param string $action The Nymph action.
   * @param string $data The JSON encoded data.
   * @return bool True on success, false on failure.
   */
  public function run($method, $action, $data) {
    $method = strtoupper($method);
    if (is_callable([$this, $method])) {
      return $this->$method($action, $data);
    }
    return $this->httpError(405, 'Method Not Allowed');
  }

  protected function DELETE($action = '', $data = '') {
    if (!in_array($action, ['entity', 'entities', 'uid'])) {
      return $this->httpError(400, 'Bad Request');
    }
    ob_start();
    if (in_array($action, ['entity', 'entities'])) {
      $ents = json_decode($data, true);
      if ($action === 'entity') {
        $ents = [$ents];
      }
      $deleted = [];
      $failures = false;
      foreach ($ents as $delEnt) {
        $guid = (int) $delEnt['guid'];
        $etype = $delEnt['etype'];
        try {
          if (Nymph::deleteEntityByID($guid, $etype)) {
            $deleted[] = $guid;
          } else {
            $failures = true;
          }
        } catch (\Exception $e) {
          $failures = true;
        }
      }
      if (empty($deleted)) {
        if ($failures) {
          return $this->httpError(400, 'Bad Request');
        } else {
          return $this->httpError(500, 'Internal Server Error');
        }
      }
      header('HTTP/1.1 200 OK', true, 200);
      header('Content-Type: application/json');
      if ($action === 'entity') {
        echo json_encode($deleted[0]);
      } else {
        echo json_encode($deleted);
      }
    } else {
      if (!Nymph::deleteUID("$data")) {
        return $this->httpError(500, 'Internal Server Error');
      }
      header('HTTP/1.1 204 No Content', true, 204);
    }
    ob_end_flush();
    return true;
  }

  protected function POST($action = '', $data = '') {
    if (!in_array($action, ['entity', 'entities', 'uid', 'method'])) {
      return $this->httpError(400, 'Bad Request');
    }
    ob_start();
    if (in_array($action, ['entity', 'entities'])) {
      $ents = json_decode($data, true);
      if ($action === 'entity') {
        $ents = [$ents];
      }
      $created = [];
      $invalidData = false;
      foreach ($ents as $newEnt) {
        if ((int) $newEnt['guid'] > 0) {
          $invalidData = true;
          continue;
        }
        $entity = $this->loadEntity($newEnt);
        if (!$entity) {
          $invalidData = true;
          continue;
        }
        try {
          if ($entity->save()) {
            $created[] = $entity;
          }
        } catch (Exceptions\EntityInvalidDataException $e) {
          $invalidData = true;
        } catch (\Exception $e) {
          return $this->httpError(500, 'Internal Server Error', $e);
        }
      }
      if (empty($created)) {
        if ($invalidData) {
          return $this->httpError(400, 'Bad Request');
        } else {
          return $this->httpError(500, 'Internal Server Error');
        }
      }
      header('HTTP/1.1 201 Created', true, 201);
      header('Content-Type: application/json');
      if ($action === 'entity') {
        echo json_encode($created[0]);
      } else {
        echo json_encode($created);
      }
    } elseif ($action === 'method') {
      $args = json_decode($data, true);
      array_walk($args['params'], [$this, 'referenceToEntity']);
      if (isset($args['static']) && $args['static']) {
        $class = $args['class'];
        if (!class_exists($class)
            || !isset($class::$clientEnabledStaticMethods)) {
          return $this->httpError(400, 'Bad Request');
        }
        if (!in_array($args['method'], $class::$clientEnabledStaticMethods)) {
          return $this->httpError(403, 'Forbidden');
        }
        try {
          $return = call_user_func_array(
              [$class, $args['method']],
              $args['params']
          );
          header('Content-Type: application/json');
          echo json_encode(['return' => $return]);
        } catch (\Exception $e) {
          return $this->httpError(500, 'Internal Server Error', $e);
        }
      } else {
        $entity = $this->loadEntity($args['entity']);
        if (!$entity
            || ((int) $args['entity']['guid'] > 0 && !$entity->guid)
            || !is_callable([$entity, $args['method']])) {
          return $this->httpError(400, 'Bad Request');
        }
        if (!in_array($args['method'], $entity->clientEnabledMethods())) {
          return $this->httpError(403, 'Forbidden');
        }
        try {
          $return = call_user_func_array(
              [$entity, $args['method']],
              $args['params']
          );
          header('Content-Type: application/json');
          echo json_encode(['entity' => $entity, 'return' => $return]);
        } catch (\Exception $e) {
          return $this->httpError(500, 'Internal Server Error', $e);
        }
      }
      header('HTTP/1.1 200 OK', true, 200);
    } else {
      try {
        $result = Nymph::newUID("$data");
      } catch (\Exception $e) {
        return $this->httpError(500, 'Internal Server Error', $e);
      }
      if (!is_int($result)) {
        return $this->httpError(500, 'Internal Server Error');
      }
      header('HTTP/1.1 201 Created', true, 201);
      header('Content-Type: text/plain');
      echo $result;
    }
    ob_end_flush();
    return true;
  }

  protected function PUT($action = '', $data = '') {
    if (!in_array($action, ['entity', 'entities', 'uid'])) {
      return $this->httpError(400, 'Bad Request');
    }
    ob_start();
    if ($action === 'uid') {
      $args = json_decode($data, true);
      if (!isset($args['name'])
          || !isset($args['value'])
          || !is_string($args['name'])
          || !is_numeric($args['value'])) {
        return $this->httpError(400, 'Bad Request');
      }
      try {
        $result = Nymph::setUID($args['name'], (int) $args['value']);
      } catch (\Exception $e) {
        return $this->httpError(500, 'Internal Server Error', $e);
      }
      if (!$result) {
        return $this->httpError(500, 'Internal Server Error');
      }
      header('Content-Type: text/plain');
      echo json_encode($result);
    } else {
      $ents = json_decode($data, true);
      if ($action === 'entity') {
        $ents = [$ents];
      }
      $saved = [];
      $invalidData = false;
      $notfound = false;
      $lastException = null;
      foreach ($ents as $newEnt) {
        if (!is_numeric($newEnt['guid']) || (int) $newEnt['guid'] <= 0) {
          $invalidData = true;
          continue;
        }
        $entity = $this->loadEntity($newEnt);
        if (!$entity) {
          $invalidData = true;
          continue;
        }
        try {
          if ($entity->save()) {
            $saved[] = $entity;
          }
        } catch (Exceptions\EntityInvalidDataException $e) {
          $invalidData = true;
        } catch (\Exception $e) {
          $lastException = $e;
        }
      }
      if (empty($saved)) {
        if ($invalidData) {
          return $this->httpError(400, 'Bad Request');
        } elseif ($notfound) {
          return $this->httpError(404, 'Not Found');
        } else {
          return $this->httpError(500, 'Internal Server Error', $lastException);
        }
      }
      header('Content-Type: application/json');
      if ($action === 'entity') {
        echo json_encode($saved[0]);
      } else {
        echo json_encode($saved);
      }
    }
    header('HTTP/1.1 200 OK', true, 200);
    ob_end_flush();
    return true;
  }

  protected function GET($action = '', $data = '') {
    if (!in_array($action, ['entity', 'entities', 'uid'])) {
      return $this->httpError(400, 'Bad Request');
    }
    $actionMap = [
      'entity' => 'getEntity',
      'entities' => 'getEntities',
      'uid' => 'getUID'
    ];
    $method = $actionMap[$action];
    if (in_array($action, ['entity', 'entities'])) {
      $args = json_decode($data, true);
      if (is_int($args)) {
        try {
          $result = Nymph::$method($args);
        } catch (\Exception $e) {
          return $this->httpError(500, 'Internal Server Error', $e);
        }
      } else {
        $count = count($args);
        if ($count > 1) {
          for ($i = 1; $i < $count; $i++) {
            $newArg = self::translateSelector($args[$i]);
            if ($newArg === false) {
              return $this->httpError(400, 'Bad Request');
            }
            $args[$i] = $newArg;
          }
        }
        try {
          $result = call_user_func_array("\Nymph\Nymph::$method", $args);
        } catch (\Exception $e) {
          return $this->httpError(500, 'Internal Server Error', $e);
        }
      }
      if (empty($result)) {
        if ($action === 'entity'
            || RequirePHP::_('NymphConfig')['empty_list_error']) {
          return $this->httpError(404, 'Not Found');
        }
      }
      header('Content-Type: application/json');
      echo json_encode($result);
      return true;
    } else {
      try {
        $result = Nymph::$method("$data");
      } catch (\Exception $e) {
        return $this->httpError(500, 'Internal Server Error', $e);
      }
      if ($result === null) {
        return $this->httpError(404, 'Not Found');
      } elseif (!is_int($result)) {
        return $this->httpError(500, 'Internal Server Error');
      }
      header('Content-Type: text/plain');
      echo $result;
      return true;
    }
  }

  /**
   * Translate
   * - JS {"type": "&", "crit": "val", "1": {"type": "&", ...}, ...}
   * - JS ["&", {"crit": "val"}, ["&", ...], ...]
   * to PHP ["&", "crit" => "val", ["&", ...], ...]
   */
  public static function translateSelector($selector) {
    $newSel = [];
    foreach ($selector as $key => $val) {
      if ($key === 'type' || $key === 0) {
        $tmpArg = [$val];
        $newSel = array_merge($tmpArg, $newSel);
      } elseif (is_numeric($key)) {
        if (isset($val['type'])
            || (
              isset($val[0])
              && in_array($val[0], ['&', '!&', '|', '!|'])
            )) {
          $tmpSel = self::translateSelector($val);
          if ($tmpSel === false) {
            return false;
          }
          $newSel[] = $tmpSel;
        } else {
          foreach ($val as $k2 => $v2) {
            if (key_exists($k2, $newSel)) {
              return false;
            }
            $newSel[$k2] = $v2;
          }
        }
      } else {
        $newSel[$key] = $val;
      }
    }
    if (!isset($newSel[0]) || !in_array($newSel[0], ['&', '!&', '|', '!|'])) {
      return false;
    }
    return $newSel;
  }

  protected function loadEntity($entityData) {
    if (!class_exists($entityData['class']) || $entityData['class'] === 'Entity') {
      // Don't let clients use the `Entity` class, since it has no validity/AC checks.
      return false;
    }
    if ((int) $entityData['guid'] > 0) {
      $entity = Nymph::getEntity(
          ['class' => $entityData['class']],
          ['&',
            'guid' => (int) $entityData['guid']
          ]
      );
      if ($entity === null) {
        return false;
      }
    } elseif (is_callable([$entityData['class'], 'factory'])) {
      $entity = call_user_func([$entityData['class'], 'factory']);
    } else {
      $entity = new $entityData['class'];
    }
    $entity->jsonAcceptTags($entityData['tags']);
    if (isset($entityData['cdate'])) {
      $entity->cdate = (float) $entityData['cdate'];
    }
    if (isset($entityData['mdate'])) {
      $entity->mdate = (float) $entityData['mdate'];
    }
    $entity->jsonAcceptData($entityData['data']);
    return $entity;
  }

  /**
   * Return the request with an HTTP error response.
   *
   * @param int $errorCode The HTTP status code.
   * @param string $message The message to place on the HTTP status header line.
   * @param Exception $exception An optional exception object to report.
   * @return boolean Always returns false.
   * @access protected
   */
  protected function httpError($errorCode, $message, $exception = null) {
    header("HTTP/1.1 $errorCode $message", true, $errorCode);
    if ($exception) {
      echo json_encode([
        'textStatus' => "$errorCode $message",
        'exception' => get_class($exception),
        'code' => $exception->getCode(),
        'message' => $exception->getMessage()
      ]);
    } else {
      echo json_encode(['textStatus' => "$errorCode $message"]);
    }
    return false;
  }

  /**
   * Check if an item is a reference, and if it is, convert it to an entity.
   *
   * This function will recurse into deeper arrays.
   *
   * @param mixed &$item The item to check.
   * @param mixed $key Unused.
   * @access private
   */
  private function referenceToEntity(&$item, $key) {
    if ((array) $item === $item) {
      if (isset($item[0]) && $item[0] === 'nymph_entity_reference') {
        $item = call_user_func([$item[2], 'factoryReference'], $item);
      } else {
        array_walk($item, [$this, 'referenceToEntity']);
      }
    } elseif ((object) $item === $item
              && !(
                (
                  is_a($item, '\Nymph\Entity')
                  || is_a($item, '\SciActive\HookOverride')
                )
                && is_callable([$item, 'toReference'])
              )) {
      // Only do this for non-entity objects.
      foreach ($item as &$cur_property) {
        $this->referenceToEntity($cur_property, null);
      }
      unset($cur_property);
    }
  }
}
