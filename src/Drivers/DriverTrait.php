<?php namespace Nymph\Drivers;

use Nymph\Exceptions;

/**
 * DriverTrait.
 *
 * Provides basic methods for a Nymph ORM driver.
 *
 * @package Nymph
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
trait DriverTrait {
  /**
   * Whether this instance is currently connected to a database.
   *
   * @var bool
   */
  public $connected = false;
  /**
   * Nymph configuration object.
   * @access protected
   * @var object
   */
  protected $config;
  /**
   * A cache to make entity retrieval faster.
   * @access protected
   * @var array
   */
  protected $entityCache = [];
  /**
   * A counter for the entity cache to determine the most accessed entities.
   * @access protected
   * @var array
   */
  protected $entityCount = [];
  /**
   * Sort case sensitively.
   * @access protected
   * @var bool
   */
  protected $sortCaseSensitive;
  /**
   * Parent property to sort by.
   * @access protected
   * @var string
   */
  protected $sortParent;
  /**
   * Property to sort by.
   * @access protected
   * @var string
   */
  protected $sortProperty;

  public function __construct($nymphConfig) {
    $this->config = $nymphConfig;
    $this->connect();
  }

  public function export($filename) {
    if (!$fhandle = fopen($filename, 'w')) {
      throw new Exceptions\InvalidParametersException(
          'Provided filename is not writeable.'
      );
    }
    $this->exportEntities(function ($output) use ($fhandle) {
      fwrite($fhandle, $output);
    });
    return fclose($fhandle);
  }

  public function exportPrint() {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=entities.nex;');
    // End all output buffering.
    while (ob_end_clean()) {
      // Keep going until empty.
      continue;
    }
    $this->exportEntities(function ($output) {
      echo $output;
    });
    return true;
  }

  private function importFromFile($filename, $saveEntityCallback, $saveUIDCallback, $startTransactionCallback = null, $commitTransactionCallback = null) {
    if (!$fhandle = fopen($filename, 'r')) {
      throw new Exceptions\InvalidParametersException(
          'Provided filename is unreadable.'
      );
    }
    $guid = null;
    $line = '';
    $data = [];
    if ($startTransactionCallback) {
      $startTransactionCallback();
    }
    while (!feof($fhandle)) {
      $line .= fgets($fhandle, 8192);
      if (substr($line, -1) != "\n") {
        continue;
      }
      if (preg_match('/^\s*#/S', $line)) {
        $line = '';
        continue;
      }
      $matches = [];
      if (preg_match('/^\s*{(\d+)}<([\w-_]+)>\[([\w,]*)\]\s*$/S', $line, $matches)) {
        // Save the current entity.
        if ($guid) {
          $saveEntityCallback($guid, explode(',', $tags), $data, $etype);
          $guid = null;
          $tags = [];
          $data = [];
        }
        // Record the new entity's info.
        $guid = (int) $matches[1];
        $etype = $matches[2];
        $tags = $matches[3];
      } elseif (preg_match('/^\s*([\w,]+)\s*=\s*(\S.*\S)\s*$/S', $line, $matches)) {
        // Add the variable to the new entity.
        if ($guid) {
          $data[$matches[1]] = json_decode($matches[2]);
        }
      } elseif (preg_match('/^\s*<([^>]+)>\[(\d+)\]\s*$/S', $line, $matches)) {
        // Add the UID.
        $saveUIDCallback($matches[1], $matches[2]);
      }
      $line = '';
      // Clear the entity cache.
      $this->entityCache = [];
    }
    // Save the last entity.
    if ($guid) {
      $saveEntityCallback($guid, explode(',', $tags), $data, $etype);
    }
    if ($commitTransactionCallback) {
      $commitTransactionCallback();
    }
    return true;
  }

  public function checkData(
      &$data,
      &$sdata,
      $selectors,
      $guid = null,
      $tags = null,
      $typesAlreadyChecked = [],
      $dataValsAreadyChecked = []
  ) {
    foreach ($selectors as $cur_selector) {
      $pass = false;
      foreach ($cur_selector as $key => $value) {
        if ($key === 0) {
          $type = $value;
          $type_is_not = ($type == '!&' || $type == '!|');
          $type_is_or = ($type == '|' || $type == '!|');
          $pass = !$type_is_or;
          continue;
        }
        if (is_numeric($key)) {
          $tmpArr = [$value];
          $pass = $this->checkData($data, $sdata, $tmpArr);
        } else {
          $clause_not = $key[0] === '!';
          if (in_array($key, $typesAlreadyChecked)) {
            // Skip because it has already been checked. (By the query.)
            $pass = true;
          } else {
            // Check if it doesn't pass any for &, check if it
            // passes any for |.
            foreach ($value as $cur_value) {
              if ((($key === 'guid' || $key === '!guid') && !isset($guid))
                  || (($key === 'tag' || $key === '!tag') && !isset($tags))
                  || (
                    ($key === 'data' || $key === '!data')
                    && in_array($cur_value[1], $dataValsAreadyChecked, true)
                  )) {
                // Skip because it has already been checked (by the query).
                $pass = true;
              } else {
                // Unserialize the data for this variable.
                if (isset($sdata[$cur_value[0]])) {
                  $data[$cur_value[0]] = unserialize($sdata[$cur_value[0]]);
                  unset($sdata[$cur_value[0]]);
                }
                if ($key !== 'guid'
                    // && $key !== '!guid'
                    && $key !== 'tag'
                    // && $key !== '!tag'
                    && substr($key, 0, 1) !== '!'
                    && !($key === 'data' && $cur_value[1] == false)
                    // && !($key === '!data' && $cur_value[1] == true)
                    && !key_exists($cur_value[0], $data)) {
                  $pass = false;
                } else {
                  switch ($key) {
                    case 'guid':
                    case '!guid':
                      $pass = ( // <-- The outside parens are necessary!
                          ($guid == $cur_value[0])
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'tag':
                    case '!tag':
                      $pass = (
                          in_array($cur_value[0], $tags)
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'isset':
                    case '!isset':
                      $pass = (
                          isset($data[$cur_value[0]])
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'ref':
                    case '!ref':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && $this->entityReferenceSearch(
                                $data[$cur_value[0]],
                                $cur_value[1]
                            )
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'strict':
                    case '!strict':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && $data[$cur_value[0]] === $cur_value[1]
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'data':
                    case '!data':
                      $pass = (
                          (
                            (
                              !isset($data[$cur_value[0]])
                              && $cur_value[1] == null
                            )
                            || (
                              isset($data[$cur_value[0]])
                              && $data[$cur_value[0]] == $cur_value[1]
                            )
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'like':
                    case '!like':
                      $pass = (
                          (
                              isset($data[$cur_value[0]])
                              && preg_match(
                                  '/^' . str_replace(
                                      ['%', '_'],
                                      ['.*?', '.'],
                                      preg_quote(
                                          $cur_value[1],
                                          '/'
                                      )
                                  ) . '$/',
                                  $data[$cur_value[0]]
                              )
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'pmatch':
                    case '!pmatch':
                      // Convert a POSIX regex to a PCRE regex.
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && preg_match(
                                '~' . str_replace(
                                    [
                                      '~',
                                      '[[:<:]]',
                                      '[[:>:]]',
                                      '[:alnum:]',
                                      '[:alpha:]',
                                      '[:ascii:]',
                                      '[:blank:]',
                                      '[:cntrl:]',
                                      '[:digit:]',
                                      '[:graph:]',
                                      '[:lower:]',
                                      '[:print:]',
                                      '[:punct:]',
                                      '[:space:]',
                                      '[:upper:]',
                                      '[:word:]',
                                      '[:xdigit:]',
                                    ],
                                    [
                                      '\~',
                                      '\b(?=\w)',
                                      '(?<=\w)\b',
                                      '[A-Za-z0-9]',
                                      '[A-Za-z]',
                                      '[\x00-\x7F]',
                                      '\s',
                                      '[\000\001\002\003\004\005\006\007\008\009\010\011\012\013\014\015\016\017\018\019\020\021\022\023\024\025\026\027\028\029\030\031\032\033\034\035\036\037\177]',
                                      '\d',
                                      '[A-Za-z0-9!"#$%&\'()*+,\-./:;<=>?@[\\\]^_`{|}\~]',
                                      '[a-z]',
                                      '[A-Za-z0-9!"#$%&\'()*+,\-./:;<=>?@[\\\]^_`{|}\~]',
                                      '[!"#$%&\'()*+,\-./:;<=>?@[\\\]^_`{|}\~]',
                                      '[\t\n\x0B\f\r ]',
                                      '[A-Z]',
                                      '[A-Za-z0-9_]',
                                      '[0-9A-Fa-f]',
                                    ],
                                    $cur_value[1]
                                ) . '~',
                                $data[$cur_value[0]]
                            )
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'match':
                    case '!match':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && preg_match($cur_value[1], $data[$cur_value[0]])
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'gt':
                    case '!gt':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && $data[$cur_value[0]] > $cur_value[1]
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'gte':
                    case '!gte':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && $data[$cur_value[0]] >= $cur_value[1]
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'lt':
                    case '!lt':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && $data[$cur_value[0]] < $cur_value[1]
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'lte':
                    case '!lte':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && $data[$cur_value[0]] <= $cur_value[1]
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                    case 'array':
                    case '!array':
                      $pass = (
                          (
                            isset($data[$cur_value[0]])
                            && (array) $data[$cur_value[0]] ===
                                $data[$cur_value[0]]
                            && in_array($cur_value[1], $data[$cur_value[0]])
                          )
                          xor ($type_is_not xor $clause_not));
                      break;
                  }
                }
              }
              if (!($type_is_or xor $pass)) {
                break;
              }
            }
          }
        }
        if (!($type_is_or xor $pass)) {
          break;
        }
      }
      if (!$pass) {
        return false;
      }
    }
    return true;
  }

  /**
   * Remove all copies of an entity from the cache.
   *
   * @param int $guid The GUID of the entity to remove.
   * @access protected
   */
  protected function cleanCache($guid) {
    unset($this->entityCache[$guid]);
  }

  public function deleteEntity(&$entity) {
    $class = get_class($entity);
    $return = $this->deleteEntityByID($entity->guid, $class::ETYPE);
    if ($return) {
      $entity->guid = null;
    }
    return $return;
  }

  /**
   * Search through a value for an entity reference.
   *
   * @param mixed $value Any value to search.
   * @param array|Entity|int $entity An entity, GUID, or array of either to
   *                                 search for.
   * @return bool True if the reference is found, false otherwise.
   * @access protected
   */
  protected function entityReferenceSearch($value, $entity) {
    if ((array) $value !== $value && !$value instanceof Traversable) {
      return false;
    }
    if (!isset($entity)) {
      throw new Exceptions\InvalidParametersException();
    }
    // Get the GUID, if the passed $entity is an object.
    if ((array) $entity === $entity) {
      foreach ($entity as &$cur_entity) {
        if ((object) $cur_entity === $cur_entity) {
          $cur_entity = $cur_entity->guid;
        }
      }
      unset($cur_entity);
    } elseif ((object) $entity === $entity) {
      $entity = [$entity->guid];
    } else {
      $entity = [(int) $entity];
    }
    if (isset($value[0]) && $value[0] == 'nymph_entity_reference') {
      return in_array($value[1], $entity);
    } else {
      // Search through multidimensional arrays looking for the reference.
      foreach ($value as $cur_value) {
        if ($this->entityReferenceSearch($cur_value, $entity)) {
          return true;
        }
      }
    }
    return false;
  }

  public function formatSelectors(&$selectors) {
    foreach ($selectors as &$cur_selector) {
      foreach ($cur_selector as $key => &$value) {
        if ($key === 0) {
          continue;
        }
        if (is_numeric($key)) {
          $tmpArr = [$value];
          $this->formatSelectors($tmpArr);
          $value = $tmpArr[0];
        } else {
          if ((array) $value !== $value) {
            $value = [[$value]];
          } elseif ((array) $value[0] !== $value[0]) {
            $value = [$value];
          }
          foreach ($value as &$cur_value) {
            if (
                (array) $cur_value === $cur_value
                && isset($cur_value[2])
                && $cur_value[1] === null
                && is_string($cur_value[2])
              ) {
              $timestamp = @strtotime($cur_value[2]);
              if ($timestamp !== false) {
                $cur_value[1] = $timestamp;
              }
            }
          }
          unset($cur_value);
        }
      }
      unset($value);
    }
    unset($cur_selector);
  }

  private function iterateSelectorsForQuery($selectors, $recurseCallback, $callback) {
    $query_parts = [];
    foreach ($selectors as $cur_selector) {
      $cur_selector_query = '';
      foreach ($cur_selector as $key => $value) {
        if ($key === 0) {
          $type = $value;
          $type_is_not = ($type == '!&' || $type == '!|');
          $type_is_or = ($type == '|' || $type == '!|');
          continue;
        }
        $cur_query = '';
        if (is_numeric($key)) {
          if ($cur_query) {
            $cur_query .= $type_is_or ? ' OR ' : ' AND ';
          }
          $cur_query .= $recurseCallback($value);
        } else {
          $callback($cur_query, $key, $value, $type_is_or, $type_is_not);
        }
        if ($cur_query) {
          if ($cur_selector_query) {
            $cur_selector_query .= $type_is_or ? ' OR ' : ' AND ';
          }
          $cur_selector_query .= $cur_query;
        }
      }
      if ($cur_selector_query) {
        $query_parts[] = $cur_selector_query;
      }
    }

    return $query_parts;
  }

  private function getEntitesRowLike(
    $options,
    $selectors,
    $typesAlreadyChecked,
    $dataValsAreadyChecked,
    $rowFetchCallback,
    $freeResultCallback,
    $getGUIDCallback,
    $getTagsAndDatesCallback,
    $getDataNameAndSValueCallback
  ) {
    if (!$this->connected) {
      throw new Exceptions\UnableToConnectException();
    }
    foreach ($selectors as $key => $selector) {
      if (!$selector
          || (
            count($selector) === 1
            && isset($selector[0])
            && in_array($selector[0], ['&', '!&', '|', '!|'])
          )) {
        unset($selectors[$key]);
        continue;
      }
      if (!isset($selector[0])
          || !in_array($selector[0], ['&', '!&', '|', '!|'])) {
        throw new Exceptions\InvalidParametersException(
            'Invalid query selector passed: '.print_r($selector, true)
        );
      }
    }

    $entities = [];
    $class = $options['class'] ?? '\\Nymph\\Entity';
    if (!class_exists($class)) {
      throw new Exceptions\EntityClassNotFoundException(
          "Query requested using a class that can't be found: $class."
      );
    }
    $etypeDirty = $options['etype'] ?? $class::ETYPE;
    $return = $options['return'] ?? 'entity';

    $count = $ocount = 0;

    // Check if the requested entity is cached.
    if ($this->config['cache'] && is_int($selectors[1]['guid'])) {
      // Only safe to use the cache option with no other selectors than a GUID
      // and tags.
      if (count($selectors) == 1 &&
          $selectors[1][0] == '&' &&
          (
            (count($selectors[1]) == 2) ||
            (count($selectors[1]) == 3 && isset($selectors[1]['tag']))
          )
        ) {
        $entity = $this->pullCache($selectors[1]['guid'], $class);
        if (isset($entity)
            && (
              !isset($selectors[1]['tag'])
              || $entity->hasTag($selectors[1]['tag'])
            )) {
          $entity->useSkipAc((bool) $options['skip_ac']);
          return [$entity];
        }
      }
    }

    $this->formatSelectors($selectors);
    $result =
        $this->query(
            $this->makeEntityQuery(
                $options,
                $selectors,
                $etypeDirty
            ),
            $etypeDirty
        );

    $row = $rowFetchCallback($result);
    while ($row) {
      $guid = $getGUIDCallback($row);
      $tagsAndDates = $getTagsAndDatesCallback($row);
      $tags = $tagsAndDates['tags'];
      $data = [
        'cdate' => $tagsAndDates['cdate'],
        'mdate' => $tagsAndDates['mdate']
      ];
      $dataNameAndSValue = $getDataNameAndSValueCallback($row);
      // Serialized data.
      $sdata = [];
      if (isset($dataNameAndSValue['name'])) {
        // This do will keep going and adding the data until the
        // next entity is reached. $row will end on the next entity.
        do {
          $dataNameAndSValue = $getDataNameAndSValueCallback($row);
          $sdata[$dataNameAndSValue['name']] = $dataNameAndSValue['svalue'];
          $row = $rowFetchCallback($result);
        } while ($getGUIDCallback($row) === $guid);
      } else {
        // Make sure that $row is incremented :)
        $row = $rowFetchCallback($result);
      }
      // Check all conditions.
      if ($this->checkData($data, $sdata, $selectors, null, null, $typesAlreadyChecked, $dataValsAreadyChecked)) {
        if (isset($options['offset']) && ($ocount < $options['offset'])) {
          // We must be sure this entity is actually a match before
          // incrementing the offset.
          $ocount++;
          continue;
        }
        switch ($return) {
          case 'entity':
          default:
            if ($this->config['cache']) {
              $entity = $this->pullCache($guid, $class);
            } else {
              $entity = null;
            }
            if (!isset($entity) || $data['mdate'] > $entity->mdate) {
              $entity = call_user_func([$class, 'factory']);
              $entity->guid = $guid;
              $entity->cdate = $data['cdate'];
              unset($data['cdate']);
              $entity->mdate = $data['mdate'];
              unset($data['mdate']);
              if ($tags) {
                $entity->tags = $tags;
              }
              $entity->putData($data, $sdata);
              if ($this->config['cache']) {
                $this->pushCache($entity, $class);
              }
            }
            if (isset($options['skip_ac'])) {
              $entity->useSkipAc((bool) $options['skip_ac']);
            }
            $entities[] = $entity;
            break;
          case 'guid':
            $entities[] = $guid;
            break;
        }
        $count++;
        if (isset($options['limit']) && $count >= $options['limit']) {
          break;
        }
      }
    }

    $freeResultCallback($result);

    return $entities;
  }

  public function getEntity($options = [], ...$selectors) {
    // Set up options and selectors.
    if ((int) $selectors[0] === $selectors[0] || is_numeric($selectors[0])) {
      $selectors[0] = ['&', 'guid' => (int) $selectors[0]];
    }
    $options['limit'] = 1;
    $entities = $this->getEntities($options, ...$selectors);
    if (!$entities) {
      return null;
    }
    return $entities[0];
  }

  private function saveEntityRowLike(
    &$entity,
    $formatEtypeCallback,
    $checkGUIDCallback,
    $saveNewEntityCallback,
    $saveExistingEntityCallback,
    $startTransactionCallback = null,
    $commitTransactionCallback = null
  ) {
    // Save the created date.
    if (!isset($entity->guid)) {
      $entity->cdate = microtime(true);
    }
    // Save the modified date.
    $entity->mdate = microtime(true);
    $data = $entity->getData();
    $sdata = $entity->getSData();
    $varlist = array_merge(array_keys($data), array_keys($sdata));
    $class = is_callable([$entity, '_hookObject']) ? get_class($entity->_hookObject()) : get_class($entity);
    $etypeDirty = $class::ETYPE;
    $etype = $formatEtypeCallback($etypeDirty);
    if ($startTransactionCallback) {
      $startTransactionCallback();
    }
    if (!isset($entity->guid)) {
      while (true) {
        // 2^53 is the maximum number in JavaScript
        // (http://ecma262-5.com/ELS5_HTML.htm#Section_8.5)
        $new_id = mt_rand(1, pow(2, 53));
        // That number might be too big on some machines. :(
        if ($new_id < 1) {
          $new_id = rand(1, 0x7FFFFFFF);
        }
        if ($checkGUIDCallback($new_id)) {
          break;
        }
      }
      $entity->guid = $new_id;
      $saveNewEntityCallback($entity, $data, $sdata, $varlist, $etype, $etypeDirty);
    } else {
      // Removed any cached versions of this entity.
      if ($this->config['cache']) {
        $this->cleanCache($entity->guid);
      }
      $saveExistingEntityCallback($entity, $data, $sdata, $varlist, $etype, $etypeDirty);
    }
    if ($commitTransactionCallback) {
      $commitTransactionCallback();
    }
    // Cache the entity.
    if ($this->config['cache']) {
      $this->pushCache($entity, $class);
    }
    return true;
  }

  /**
   * Pull an entity from the cache.
   *
   * @param int $guid The entity's GUID.
   * @param string $class The entity's class.
   * @return Entity|null The entity or null if it's not cached.
   * @access protected
   */
  protected function pullCache($guid, $class) {
    // Increment the entity access count.
    if (!isset($this->entityCount[$guid])) {
      $this->entityCount[$guid] = 0;
    }
    $this->entityCount[$guid]++;
    if (isset($this->entityCache[$guid][$class])) {
      return (clone $this->entityCache[$guid][$class]);
    }
    return null;
  }

  /**
   * Push an entity onto the cache.
   *
   * @param Entity &$entity The entity to push onto the cache.
   * @param string $class The class of the entity.
   * @access protected
   */
  protected function pushCache(&$entity, $class) {
    if (!isset($entity->guid)) {
      return;
    }
    // Increment the entity access count.
    if (!isset($this->entityCount[$entity->guid])) {
      $this->entityCount[$entity->guid] = 0;
    }
    $this->entityCount[$entity->guid]++;
    // Check the threshold.
    if ($this->entityCount[$entity->guid] < $this->config['cache_threshold']) {
      return;
    }
    // Cache the entity.
    if ((array) $this->entityCache[$entity->guid] ===
        $this->entityCache[$entity->guid]) {
      $this->entityCache[$entity->guid][$class] = clone $entity;
    } else {
      while ($this->config['cache_limit']
          && count($this->entityCache) >= $this->config['cache_limit']) {
        // Find which entity has been accessed the least.
        asort($this->entityCount);
        foreach ($this->entityCount as $key => $val) {
          if (isset($this->entityCache[$key])) {
            break;
          }
        }
        // Remove it.
        if (isset($this->entityCache[$key])) {
          unset($this->entityCache[$key]);
        }
      }
      $this->entityCache[$entity->guid] = [$class => (clone $entity)];
    }
    $this->entityCache[$entity->guid][$class]->clearCache();
  }

  public function hsort(
      &$array,
      $property = null,
      $parentProperty = null,
      $caseSensitive = false,
      $reverse = false
  ) {
    // First sort by the requested property.
    $this->sort($array, $property, $caseSensitive, $reverse);
    if (!isset($parentProperty)) {
      return;
    }
    // Now sort by children.
    $new_array = [];
    while ($array) {
      // Look for entities ready to go in order.
      $changed = false;
      foreach ($array as $key => &$cur_entity) {
        // Must break after adding one, so any following children don't go in
        // the wrong order.
        if (!isset($cur_entity->$parentProperty)
            || !$cur_entity->$parentProperty->inArray(
                array_merge($new_array, $array)
            )) {
          // If they have no parent (or their parent isn't in the array), they
          // go on the end.
          $new_array[] = $cur_entity;
          unset($array[$key]);
          $changed = true;
          break;
        } else {
          // Else find the parent.
          $pkey = $cur_entity->$parentProperty->arraySearch($new_array);
          if ($pkey !== false) {
            // And insert after the parent.
            // This makes entities go to the end of the child list.
            $ancestry = [$array[$key]->$parentProperty];
            $new_key = $pkey;
            while (isset($new_array[$new_key + 1])
                && isset($new_array[$new_key + 1]->$parentProperty)
                && $new_array[$new_key + 1]->$parentProperty->inArray(
                    $ancestry
                )) {
              $ancestry[] = $new_array[$new_key + 1];
              $new_key += 1;
            }
            // Where to place the entity.
            $new_key += 1;
            if (isset($new_array[$new_key])) {
              // If it already exists, we have to splice it in.
              array_splice($new_array, $new_key, 0, [$cur_entity]);
              $new_array = array_values($new_array);
            } else {
              // Else just add it.
              $new_array[$new_key] = $cur_entity;
            }
            unset($array[$key]);
            $changed = true;
            break;
          }
        }
      }
      unset($cur_entity);
      if (!$changed) {
        // If there are any unexpected errors and the array isn't changed, just
        // stick the rest on the end.
        $entities_left = array_splice($array, 0);
        $new_array = array_merge($new_array, $entities_left);
      }
    }
    // Now push the new array out.
    $array = array_values($new_array);
  }

  public function psort(
      &$array,
      $property = null,
      $parentProperty = null,
      $caseSensitive = false,
      $reverse = false
  ) {
    // Sort by the requested property.
    if (isset($property)) {
      $this->sortProperty = $property;
      $this->sortParent = $parentProperty;
      $this->sortCaseSensitive = $caseSensitive;
      \usort($array, [$this, 'sortProperty']);
    }
    if ($reverse) {
      $array = array_reverse($array);
    }
  }

  public function sort(
      &$array,
      $property = null,
      $caseSensitive = false,
      $reverse = false
  ) {
    // Sort by the requested property.
    if (isset($property)) {
      $this->sortProperty = $property;
      $this->sortParent = null;
      $this->sortCaseSensitive = $caseSensitive;
      \usort($array, [$this, 'sortProperty']);
    }
    if ($reverse) {
      $array = array_reverse($array);
    }
  }

  /**
   * Determine the sort order between two entities.
   *
   * @param Entity $a Entity A.
   * @param Entity $b Entity B.
   * @return int Sort order.
   * @access protected
   */
  protected function sortProperty($a, $b) {
    $property = $this->sortProperty;
    $parent = $this->sortParent;
    if (isset($parent)
        && (isset($a->$parent->$property) || isset($b->$parent->$property))) {
      if (!$this->sortCaseSensitive
          && is_string($a->$parent->$property)
          && is_string($b->$parent->$property)) {
        $aprop = strtoupper($a->$parent->$property);
        $bprop = strtoupper($b->$parent->$property);
        if ($aprop > $bprop) {
          return 1;
        }
        if ($aprop < $bprop) {
          return -1;
        }
      } else {
        if ($a->$parent->$property > $b->$parent->$property) {
          return 1;
        }
        if ($a->$parent->$property < $b->$parent->$property) {
          return -1;
        }
      }
    }
    // If they have the same parent, order them by their own property.
    if (!$this->sortCaseSensitive
        && is_string($a->$property)
        && is_string($b->$property)) {
      $aprop = strtoupper($a->$property);
      $bprop = strtoupper($b->$property);
      if ($aprop > $bprop) {
        return 1;
      }
      if ($aprop < $bprop) {
        return -1;
      }
    } else {
      if ($a->$property > $b->$property) {
        return 1;
      }
      if ($a->$property < $b->$property) {
        return -1;
      }
    }
    return 0;
  }
}
