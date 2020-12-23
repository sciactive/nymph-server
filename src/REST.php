<?php namespace Nymph;

/**
 * Simple Nymph REST server implementation.
 *
 * Provides Nymph functionality compatible with a REST API. Allows the developer
 * to design their own API, or just use the reference implementation.
 *
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @see http://nymph.io/
 */
class REST {
  /**
   * Respond to the incoming REST request.
   *
   * This function will decode the incoming values and call `run`.
   *
   * @return bool True on success, false on failure.
   */
  public function respond() {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      return $this->run(
        $_SERVER['REQUEST_METHOD'],
        json_decode($_REQUEST['action'], true),
        json_decode($_REQUEST['data'], true)
      );
    } else {
      $args = json_decode(file_get_contents("php://input"), true);
      return $this->run(
        $_SERVER['REQUEST_METHOD'],
        $args['action'],
        $args['data']
      );
    }
  }

  /**
   * Run the Nymph REST server process.
   *
   * Note that on failure, an HTTP error status code will be sent, usually
   * along with a message body.
   *
   * @param string $method The HTTP method.
   * @param string $action The Nymph action.
   * @param string $data The decoded data.
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
      if ($action === 'entity') {
        $data = [$data];
      }
      $deleted = [];
      $failures = false;
      foreach ($data as $delEnt) {
        try {
          $guid = (int) $delEnt['guid'];
          if (Nymph::deleteEntityByID($guid, $delEnt['class'])) {
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
      http_response_code(200);
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
      http_response_code(204);
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
      if ($action === 'entity') {
        $data = [$data];
      }
      $created = [];
      $hadSuccess = false;
      $invalidData = false;
      $conflict = false;
      $lastException = null;
      foreach ($data as $entData) {
        if ((int) $entData['guid'] > 0) {
          $invalidData = true;
          $created[] = null;
          continue;
        }
        try {
          $entity = $this->loadEntity($entData);
        } catch (Exceptions\EntityConflictException $e) {
          $conflict = true;
          $created[] = null;
          continue;
        }
        if (!$entity) {
          $invalidData = true;
          $created[] = null;
          continue;
        }
        try {
          if ($entity->save()) {
            $created[] = $entity;
            $hadSuccess = true;
          } else {
            $created[] = false;
          }
        } catch (Exceptions\EntityInvalidDataException $e) {
          $invalidData = true;
          $created[] = null;
        } catch (\Exception $e) {
          $lastException = $e;
          $created[] = null;
        }
      }
      if (!$hadSuccess) {
        if ($invalidData) {
          return $this->httpError(400, 'Bad Request');
        } elseif ($conflict) {
          return $this->httpError(409, 'Conflict');
        } else {
          return $this->httpError(500, 'Internal Server Error', $lastException);
        }
      }
      http_response_code(201);
      header('Content-Type: application/json');
      if ($action === 'entity') {
        echo json_encode($created[0]);
      } else {
        echo json_encode($created);
      }
    } elseif ($action === 'method') {
      array_walk($data['params'], [$this, 'referenceToEntity']);
      if (isset($data['static']) && $data['static']) {
        $className = $data['class'];
        if (!class_exists($className)
          || !isset($className::$clientEnabledStaticMethods)
        ) {
          return $this->httpError(400, 'Bad Request');
        }
        if (!in_array(
          $data['method'],
          $className::$clientEnabledStaticMethods
        )
        ) {
          return $this->httpError(403, 'Forbidden');
        }
        try {
          $ret = call_user_func_array(
            [$className, $data['method']],
            $data['params']
          );
          header('Content-Type: application/json');
          echo json_encode(['return' => $ret]);
        } catch (\Exception $e) {
          return $this->httpError(500, 'Internal Server Error', $e);
        }
      } else {
        try {
          $entity = $this->loadEntity($data['entity']);
        } catch (Exceptions\EntityConflictException $e) {
          return $this->httpError(409, 'Conflict');
        }
        if (!$entity
          || ((int) $data['entity']['guid'] > 0 && !$entity->guid)
          || !is_callable([$entity, $data['method']])
        ) {
          return $this->httpError(400, 'Bad Request');
        }
        if (!in_array($data['method'], $entity->clientEnabledMethods())) {
          return $this->httpError(403, 'Forbidden');
        }
        try {
          $ret = call_user_func_array(
            [$entity, $data['method']],
            $data['params']
          );
          header('Content-Type: application/json');
          if ($data['stateless']) {
            echo json_encode(['return' => $ret]);
          } else {
            echo json_encode(['entity' => $entity, 'return' => $ret]);
          }
        } catch (\Exception $e) {
          return $this->httpError(500, 'Internal Server Error', $e);
        }
      }
      http_response_code(200);
    } else {
      try {
        $result = Nymph::newUID("$data");
      } catch (\Exception $e) {
        return $this->httpError(500, 'Internal Server Error', $e);
      }
      if (!is_int($result)) {
        return $this->httpError(500, 'Internal Server Error');
      }
      http_response_code(201);
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
    return $this->doPutOrPatch($action, $data, false);
  }

  protected function PATCH($action = '', $data = '') {
    if (!in_array($action, ['entity', 'entities'])) {
      return $this->httpError(400, 'Bad Request');
    }
    return $this->doPutOrPatch($action, $data, true);
  }

  protected function doPutOrPatch($action, $data, $patch) {
    ob_start();
    if ($action === 'uid') {
      if (!isset($data['name'])
        || !isset($data['value'])
        || !is_string($data['name'])
        || !is_numeric($data['value'])
      ) {
        return $this->httpError(400, 'Bad Request');
      }
      try {
        $result = Nymph::setUID($data['name'], (int) $data['value']);
      } catch (\Exception $e) {
        return $this->httpError(500, 'Internal Server Error', $e);
      }
      if (!$result) {
        return $this->httpError(500, 'Internal Server Error');
      }
      header('Content-Type: text/plain');
      echo json_encode($result);
    } else {
      if ($action === 'entity') {
        $data = [$data];
      }
      $saved = [];
      $hadSuccess = false;
      $invalidData = false;
      $conflict = false;
      $notfound = false;
      $lastException = null;
      foreach ($data as $entData) {
        if (!is_numeric($entData['guid']) || (int) $entData['guid'] <= 0) {
          $invalidData = true;
          $saved[] = null;
          continue;
        }
        try {
          $entity = $this->loadEntity($entData, $patch);
        } catch (Exceptions\EntityConflictException $e) {
          $conflict = true;
          $saved[] = null;
          continue;
        }
        if (!$entity) {
          $invalidData = true;
          $saved[] = null;
          continue;
        }
        try {
          if ($entity->save()) {
            $saved[] = $entity;
            $hadSuccess = true;
          } else {
            $saved[] = false;
          }
        } catch (Exceptions\EntityInvalidDataException $e) {
          $invalidData = true;
          $saved[] = null;
        } catch (\Exception $e) {
          $lastException = $e;
          $saved[] = null;
        }
      }
      if (!$hadSuccess) {
        if ($invalidData) {
          return $this->httpError(400, 'Bad Request');
        } elseif ($conflict) {
          return $this->httpError(409, 'Conflict');
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
    http_response_code(200);
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
    if (!key_exists($action, $actionMap)) {
      return $this->httpError(400, 'Bad Request');
    }
    $method = $actionMap[$action];
    if (in_array($action, ['entity', 'entities'])) {
      if (!is_array($data)) {
        return $this->httpError(400, 'Bad Request');
      }
      $count = count($data);
      if ($count < 1 || !is_array($data[0])) {
        return $this->httpError(400, 'Bad Request');
      }
      if (!isset($data[0]['class']) || !class_exists($data[0]['class'])) {
        return $this->httpError(400, 'Bad Request');
      }
      $data[0]['source'] = 'client';
      $data[0]['skip_ac'] = false;
      if ($count > 1) {
        for ($i = 1; $i < $count; $i++) {
          $newArg = self::translateSelector($data[0]['class'], $data[$i]);
          if ($newArg === false) {
            return $this->httpError(400, 'Bad Request');
          }
          $data[$i] = $newArg;
        }
      }
      try {
        $result = call_user_func_array("\Nymph\Nymph::$method", $data);
      } catch (\Exception $e) {
        return $this->httpError(500, 'Internal Server Error', $e);
      }
      if (empty($result)) {
        if ($action === 'entity'
          || Nymph::$config['empty_list_error']
        ) {
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
   *
   * Also filter out clauses that use restricted properties.
   *
   * @param string $className The name of the class.
   * @param array $selector The selector to translate.
   */
  public static function translateSelector($className, $selector) {
    $restricted = [];
    if (isset($className::$searchRestrictedData)) {
      $restricted = $className::$searchRestrictedData;
    }
    // Filter clauses that are restricted for frontend searches.
    $filterClauses = function ($clause, $value) use ($restricted) {
      $unrestrictedClauses = ['guid', 'tag'];
      $scalarClauses = ['isset'];
      if (empty($restricted) || in_array($clause, $unrestrictedClauses)) {
        return $value;
      }
      if (in_array($clause, $scalarClauses)) {
        // Each entry is a property name.
        if (is_array($value)) {
          return array_values(array_diff($value, $restricted));
        } else {
          return in_array($value, $restricted) ? null : $value;
        }
      } else {
        // Each entry is an array of property name, value.
        if (is_array($value[0])) {
          return array_values(
            array_filter(
              $value,
              function ($arr) use ($restricted) {
                return !in_array($arr[0], $restricted);
              }
            )
          );
        } else {
          return in_array($value[0], $restricted) ? null : $value;
        }
      }
    };
    $newSel = [];
    foreach ($selector as $key => $val) {
      if ($key === 'type' || $key === 0) {
        $tmpArg = [$val];
        $newSel = array_merge($tmpArg, $newSel);
      } elseif (is_numeric($key)) {
        if (isset($val['type'])
          || (isset($val[0])
          && in_array($val[0], ['&', '!&', '|', '!|']))
        ) {
          $tmpSel = self::translateSelector($className, $val);
          if ($tmpSel === false) {
            return false;
          }
          $newSel[] = $tmpSel;
        } else {
          foreach ($val as $k2 => $v2) {
            if (key_exists($k2, $newSel)) {
              return false;
            }
            $value = $filterClauses($k2, $v2);
            if (!empty($value)) {
              $newSel[$k2] = $value;
            }
          }
        }
      } else {
        $value = $filterClauses($key, $val);
        if (!empty($value)) {
          $newSel[$key] = $value;
        }
      }
    }
    if (!isset($newSel[0]) || !in_array($newSel[0], ['&', '!&', '|', '!|'])) {
      return false;
    }
    return $newSel;
  }

  protected function loadEntity(
    $entityData,
    $patch = false,
    $allowConflict = false
  ) {
    if (!class_exists($entityData['class'])
      || $entityData['class'] === 'Entity'
      || $entityData['class'] === 'Nymph\Entity'
      || $entityData['class'] === '\Nymph\Entity'
    ) {
      // Don't let clients use the `Entity` class, since it has no validity/AC
      // checks.
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
    if ($patch) {
      $entity->jsonAcceptPatch($entityData, $allowConflict);
    } else {
      $entity->jsonAcceptData($entityData, $allowConflict);
    }
    return $entity;
  }

  /**
   * Return the request with an HTTP error response.
   *
   * @param int $errorCode The HTTP status code.
   * @param string $message The message to place on the HTTP status header line.
   * @param Exception $exception An optional exception object to report.
   * @return boolean Always returns false.
   */
  protected function httpError($errorCode, $message, $exception = null) {
    http_response_code($errorCode);
    if ($exception) {
      echo json_encode(
        [
          'textStatus' => "$errorCode $message",
          'exception' => get_class($exception),
          'code' => $exception->getCode(),
          'message' => $exception->getMessage()
        ]
      );
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
   * @param mixed $item The item to check.
   * @param mixed $key Unused.
   */
  private function referenceToEntity(&$item, $key) {
    if (is_array($item)) {
      if (isset($item[0]) && $item[0] === 'nymph_entity_reference') {
        $item = call_user_func([$item[2], 'factoryReference'], $item);
      } else {
        array_walk($item, [$this, 'referenceToEntity']);
      }
    } elseif (is_object($item)
      && !((is_a($item, '\Nymph\Entity')
      || is_a($item, '\SciActive\HookOverride'))
      && is_callable([$item, 'toReference']))
    ) {
      // Only do this for non-entity objects.
      foreach ($item as &$curProperty) {
        $this->referenceToEntity($curProperty, null);
      }
      unset($curProperty);
    }
  }
}
