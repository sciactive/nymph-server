<?php namespace Nymph\Drivers;

use Nymph\Exceptions;

/**
 * DriverTrait.
 *
 * Provides basic methods for a Nymph ORM driver.
 *
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

  private function posixRegexMatch($pattern, $subject) {
    return preg_match(
        '~'.str_replace(
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
              '[\000\001\002\003\004\005\006\007\008\009\010\011\012\013\014'.
                '\015\016\017\018\019\020\021\022\023\024\025\026\027\028\029'.
                '\030\031\032\033\034\035\036\037\177]',
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
            $pattern
        ).'~',
        $subject
    );
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

  private function importFromFile(
      $filename,
      $saveEntityCallback,
      $saveUIDCallback,
      $startTransactionCallback = null,
      $commitTransactionCallback = null
  ) {
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
      if (preg_match(
          '/^\s*{(\d+)}<([\w-_]+)>\[([\w,]*)\]\s*$/S',
          $line,
          $matches
      )) {
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
      } elseif (preg_match(
          '/^\s*([\w,]+)\s*=\s*(\S.*\S)\s*$/S',
          $line,
          $matches
      )) {
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
    foreach ($selectors as $curSelector) {
      $pass = false;
      foreach ($curSelector as $key => $value) {
        if ($key === 0) {
          $type = $value;
          $typeIsNot = ($type === '!&' || $type === '!|');
          $typeIsOr = ($type === '|' || $type === '!|');
          $pass = !$typeIsOr;
          continue;
        }
        if (is_numeric($key)) {
          $tmpArr = [$value];
          $pass = $this->checkData($data, $sdata, $tmpArr);
        } else {
          $clauseNot = $key[0] === '!';
          if (in_array($key, $typesAlreadyChecked)) {
            // Skip because it has already been checked. (By the query.)
            $pass = true;
          } else {
            // Check if it doesn't pass any for &, check if it
            // passes any for |.
            foreach ($value as $curValue) {
              if ((($key === 'guid' || $key === '!guid') && !isset($guid))
                  || (($key === 'tag' || $key === '!tag') && !isset($tags))
                  || (
                    (
                      $key === 'equal'
                      || $key === '!equal'
                      || $key === 'data'
                      || $key === '!data'
                    )
                    && in_array($curValue[1], $dataValsAreadyChecked, true)
                  )) {
                // Skip because it has already been checked (by the query).
                $pass = true;
              } else {
                // Unserialize the data for this variable.
                if (isset($sdata[$curValue[0]])) {
                  $data[$curValue[0]] = unserialize($sdata[$curValue[0]]);
                  unset($sdata[$curValue[0]]);
                }
                if ($key !== 'guid'
                    // && $key !== '!guid'
                    && $key !== 'tag'
                    // && $key !== '!tag'
                    && substr($key, 0, 1) !== '!'
                    && !(
                      ($key === 'equal' || $key === 'data')
                      && $curValue[1] == false
                    )
                    // && !(
                    //   ($key === '!equal' || $key === '!data')
                    //   && $curValue[1] == true
                    // )
                    && !key_exists($curValue[0], $data)) {
                  $pass = false;
                } else {
                  switch ($key) {
                    case 'guid':
                    case '!guid':
                      $pass = ( // <-- The outside parens are necessary!
                          ($guid == $curValue[0])
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'tag':
                    case '!tag':
                      $pass = (
                          in_array($curValue[0], $tags)
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'isset':
                    case '!isset':
                      $pass = (
                          isset($data[$curValue[0]])
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'ref':
                    case '!ref':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && $this->entityReferenceSearch(
                                $data[$curValue[0]],
                                $curValue[1]
                            )
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'strict':
                    case '!strict':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && $data[$curValue[0]] === $curValue[1]
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'equal':
                    case '!equal':
                    case 'data':
                    case '!data':
                      $pass = (
                          (
                            (
                              !isset($data[$curValue[0]])
                              && $curValue[1] == null
                            )
                            || (
                              isset($data[$curValue[0]])
                              && $data[$curValue[0]] == $curValue[1]
                            )
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'like':
                    case '!like':
                      $pass = (
                          (
                              isset($data[$curValue[0]])
                              && preg_match(
                                  '/^'.str_replace(
                                      ['%', '_'],
                                      ['.*?', '.'],
                                      preg_quote(
                                          $curValue[1],
                                          '/'
                                      )
                                  ).'$/',
                                  $data[$curValue[0]]
                              )
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'pmatch':
                    case '!pmatch':
                      // Convert a POSIX regex to a PCRE regex.
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && $this->posixRegexMatch(
                                $curValue[1],
                                $data[$curValue[0]]
                            )
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'match':
                    case '!match':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && preg_match($curValue[1], $data[$curValue[0]])
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'gt':
                    case '!gt':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && $data[$curValue[0]] > $curValue[1]
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'gte':
                    case '!gte':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && $data[$curValue[0]] >= $curValue[1]
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'lt':
                    case '!lt':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && $data[$curValue[0]] < $curValue[1]
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'lte':
                    case '!lte':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && $data[$curValue[0]] <= $curValue[1]
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                    case 'array':
                    case '!array':
                      $pass = (
                          (
                            isset($data[$curValue[0]])
                            && (array) $data[$curValue[0]] ===
                                $data[$curValue[0]]
                            && in_array($curValue[1], $data[$curValue[0]])
                          )
                          xor ($typeIsNot xor $clauseNot));
                      break;
                  }
                }
              }
              if (!($typeIsOr xor $pass)) {
                break;
              }
            }
          }
        }
        if (!($typeIsOr xor $pass)) {
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
    $className = get_class($entity);
    $ret = $this->deleteEntityByID($entity->guid, $className);
    if ($ret) {
      $entity->guid = null;
    }
    return $ret;
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
    if (!is_array($value) && !($value instanceof Traversable)) {
      return false;
    }
    if (!isset($entity)) {
      throw new Exceptions\InvalidParametersException();
    }
    // Get the GUID, if the passed $entity is an object.
    if (is_array($entity)) {
      foreach ($entity as &$curEntity) {
        if (is_object($curEntity)) {
          $curEntity = $curEntity->guid;
        }
      }
      unset($curEntity);
    } elseif (is_object($entity)) {
      $entity = [$entity->guid];
    } else {
      $entity = [(int) $entity];
    }
    if (isset($value[0]) && $value[0] === 'nymph_entity_reference') {
      return in_array($value[1], $entity);
    } else {
      // Search through multidimensional arrays looking for the reference.
      foreach ($value as $curValue) {
        if ($this->entityReferenceSearch($curValue, $entity)) {
          return true;
        }
      }
    }
    return false;
  }

  public function formatSelectors(&$selectors) {
    foreach ($selectors as &$curSelector) {
      foreach ($curSelector as $key => &$value) {
        if ($key === 0) {
          continue;
        }
        if (is_numeric($key)) {
          $tmpArr = [$value];
          $this->formatSelectors($tmpArr);
          $value = $tmpArr[0];
        } else {
          if (!is_array($value)) {
            $value = [[$value]];
          } elseif (!is_array($value[0])) {
            $value = [$value];
          }
          foreach ($value as &$curValue) {
            if (is_array($curValue)
                && isset($curValue[2])
                && $curValue[1] === null
                && is_string($curValue[2])
              ) {
              $timestamp = strtotime($curValue[2]);
              if ($timestamp !== false) {
                $curValue[1] = $timestamp;
              }
            }
          }
          unset($curValue);
        }
      }
      unset($value);
    }
    unset($curSelector);
  }

  private function iterateSelectorsForQuery(
      $selectors,
      $recurseCallback,
      $callback
  ) {
    $queryParts = [];
    foreach ($selectors as $curSelector) {
      $curSelectorQuery = '';
      foreach ($curSelector as $key => $value) {
        if ($key === 0) {
          $type = $value;
          $typeIsNot = ($type === '!&' || $type === '!|');
          $typeIsOr = ($type === '|' || $type === '!|');
          continue;
        }
        $curQuery = '';
        if (is_numeric($key)) {
          if ($curQuery) {
            $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
          }
          $curQuery .= $recurseCallback($value);
        } else {
          $callback($curQuery, $key, $value, $typeIsOr, $typeIsNot);
        }
        if ($curQuery) {
          if ($curSelectorQuery) {
            $curSelectorQuery .= $typeIsOr ? ' OR ' : ' AND ';
          }
          $curSelectorQuery .= $curQuery;
        }
      }
      if ($curSelectorQuery) {
        $queryParts[] = $curSelectorQuery;
      }
    }

    return $queryParts;
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
    $className = $options['class'] ?? '\\Nymph\\Entity';
    if (!class_exists($className)) {
      throw new Exceptions\EntityClassNotFoundException(
          "Query requested using a class that can't be found: $className."
      );
    }
    $etypeDirty = $options['etype'] ?? $className::ETYPE;
    $ret = $options['return'] ?? 'entity';

    $count = $ocount = 0;

    // Check if the requested entity is cached.
    if ($this->config['cache'] && is_int($selectors[1]['guid'])) {
      // Only safe to use the cache option with no other selectors than a GUID
      // and tags.
      if (count($selectors) === 1 &&
          $selectors[1][0] === '&' &&
          (
            (count($selectors[1]) === 2) ||
            (count($selectors[1]) === 3 && isset($selectors[1]['tag']))
          )
        ) {
        $entity = $this->pullCache($selectors[1]['guid'], $className);
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
    $query = $this->makeEntityQuery(
        $options,
        $selectors,
        $etypeDirty
    );
    $result =
        $this->query(
            $query['query'],
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
      if ($query['fullCoverage']) {
        $passed = true;
      } else {
        $passed = $this->checkData(
            $data,
            $sdata,
            $selectors,
            null,
            null,
            $typesAlreadyChecked,
            $dataValsAreadyChecked
        );
      }
      if ($passed) {
        if (isset($options['offset'])
            && !$query['limitOffsetCoverage']
            && ($ocount < $options['offset'])
          ) {
          // We must be sure this entity is actually a match before
          // incrementing the offset.
          $ocount++;
          continue;
        }
        switch ($ret) {
          case 'entity':
          default:
            if ($this->config['cache']) {
              $entity = $this->pullCache($guid, $className);
            } else {
              $entity = null;
            }
            if (!isset($entity) || $data['mdate'] > $entity->mdate) {
              $entity = call_user_func([$className, 'factory']);
              $entity->guid = $guid;
              $entity->cdate = $data['cdate'];
              unset($data['cdate']);
              $entity->mdate = $data['mdate'];
              unset($data['mdate']);
              $entity->tags = $tags;
              $entity->putData($data, $sdata);
              if ($this->config['cache']) {
                $this->pushCache($entity, $className);
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
        if (!$query['limitOffsetCoverage']) {
          $count++;
          if (isset($options['limit']) && $count >= $options['limit']) {
            break;
          }
        }
      }
    }

    $freeResultCallback($result);

    return $entities;
  }

  public function getEntity($options = [], ...$selectors) {
    // Set up options and selectors.
    if (is_int($selectors[0]) || is_numeric($selectors[0])) {
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
    $className = is_callable([$entity, '_hookObject'])
        ? get_class($entity->_hookObject())
        : get_class($entity);
    $etypeDirty = $className::ETYPE;
    $etype = $formatEtypeCallback($etypeDirty);
    if ($startTransactionCallback) {
      $startTransactionCallback();
    }
    if (!isset($entity->guid)) {
      while (true) {
        // 2^53 is the maximum number in JavaScript
        // (http://ecma262-5.com/ELS5_HTML.htm#Section_8.5)
        $newId = mt_rand(1, pow(2, 53));
        // That number might be too big on some machines. :(
        if ($newId < 1) {
          $newId = rand(1, 0x7FFFFFFF);
        }
        if ($checkGUIDCallback($newId)) {
          break;
        }
      }
      $entity->guid = $newId;
      $saveNewEntityCallback(
          $entity,
          $data,
          $sdata,
          $varlist,
          $etype,
          $etypeDirty
      );
    } else {
      // Removed any cached versions of this entity.
      if ($this->config['cache']) {
        $this->cleanCache($entity->guid);
      }
      $saveExistingEntityCallback(
          $entity,
          $data,
          $sdata,
          $varlist,
          $etype,
          $etypeDirty
      );
    }
    if ($commitTransactionCallback) {
      $commitTransactionCallback();
    }
    // Cache the entity.
    if ($this->config['cache']) {
      $this->pushCache($entity, $className);
    }
    return true;
  }

  /**
   * Pull an entity from the cache.
   *
   * @param int $guid The entity's GUID.
   * @param string $className The entity's class.
   * @return Entity|null The entity or null if it's not cached.
   * @access protected
   */
  protected function pullCache($guid, $className) {
    // Increment the entity access count.
    if (!isset($this->entityCount[$guid])) {
      $this->entityCount[$guid] = 0;
    }
    $this->entityCount[$guid]++;
    if (isset($this->entityCache[$guid][$className])) {
      return (clone $this->entityCache[$guid][$className]);
    }
    return null;
  }

  /**
   * Push an entity onto the cache.
   *
   * @param Entity &$entity The entity to push onto the cache.
   * @param string $className The class of the entity.
   * @access protected
   */
  protected function pushCache(&$entity, $className) {
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
      $this->entityCache[$entity->guid][$className] = clone $entity;
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
      $this->entityCache[$entity->guid] = [$className => (clone $entity)];
    }
    $this->entityCache[$entity->guid][$className]->clearCache();
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
    $newArray = [];
    while ($array) {
      // Look for entities ready to go in order.
      $changed = false;
      foreach ($array as $key => &$curEntity) {
        // Must break after adding one, so any following children don't go in
        // the wrong order.
        if (!isset($curEntity->$parentProperty)
            || !$curEntity->$parentProperty->inArray(
                array_merge($newArray, $array)
            )) {
          // If they have no parent (or their parent isn't in the array), they
          // go on the end.
          $newArray[] = $curEntity;
          unset($array[$key]);
          $changed = true;
          break;
        } else {
          // Else find the parent.
          $pkey = $curEntity->$parentProperty->arraySearch($newArray);
          if ($pkey !== false) {
            // And insert after the parent.
            // This makes entities go to the end of the child list.
            $ancestry = [$array[$key]->$parentProperty];
            $newKey = $pkey;
            while (isset($newArray[$newKey + 1])
                && isset($newArray[$newKey + 1]->$parentProperty)
                && $newArray[$newKey + 1]->$parentProperty->inArray(
                    $ancestry
                )) {
              $ancestry[] = $newArray[$newKey + 1];
              $newKey += 1;
            }
            // Where to place the entity.
            $newKey += 1;
            if (isset($newArray[$newKey])) {
              // If it already exists, we have to splice it in.
              array_splice($newArray, $newKey, 0, [$curEntity]);
              $newArray = array_values($newArray);
            } else {
              // Else just add it.
              $newArray[$newKey] = $curEntity;
            }
            unset($array[$key]);
            $changed = true;
            break;
          }
        }
      }
      unset($curEntity);
      if (!$changed) {
        // If there are any unexpected errors and the array isn't changed, just
        // stick the rest on the end.
        $entitiesLeft = array_splice($array, 0);
        $newArray = array_merge($newArray, $entitiesLeft);
      }
    }
    // Now push the new array out.
    $array = array_values($newArray);
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
