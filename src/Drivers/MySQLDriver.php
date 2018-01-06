<?php namespace Nymph\Drivers;

use Nymph\Exceptions;

/**
 * MySQL based Nymph driver.
 *
 * @package Nymph
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
class MySQLDriver implements DriverInterface {
  use DriverTrait {
    DriverTrait::__construct as private __traitConstruct;
  }
  /**
   * The MySQL link identifier for this instance.
   *
   * @access private
   * @var mixed
   */
  private $link = null;
  private $prefix;

  public function __construct($NymphConfig) {
    $this->__traitConstruct($NymphConfig);
    $this->prefix = $this->config['MySQL']['prefix'];
  }

  /**
   * Disconnect from the database on destruction.
   */
  public function __destruct() {
    $this->disconnect();
  }

  /**
   * Connect to the MySQL database.
   *
   * @return bool Whether this instance is connected to a MySQL database after
   *              the method has run.
   */
  public function connect() {
    // Check that the MySQLi extension is installed.
    if (!is_callable('mysqli_connect')) {
      throw new Exceptions\UnableToConnectException(
          'MySQLi PHP extension is not available. It probably has not been ' .
          'installed. Please install and configure it in order to use MySQL.'
      );
    }
    $host = $this->config['MySQL']['host'];
    $user = $this->config['MySQL']['user'];
    $password = $this->config['MySQL']['password'];
    $database = $this->config['MySQL']['database'];
    $port = $this->config['MySQL']['port'];
    // Connecting, selecting database
    if (!$this->connected) {
      if ($this->link =
          mysqli_connect(
              $host,
              $user,
              $password,
              $database,
              $port
          )) {
        $this->connected = true;
      } else {
        $this->connected = false;
        if ($host == 'localhost'
            && $user == 'nymph'
            && $password == 'password'
            && $database == 'nymph') {
          throw new Exceptions\NotConfiguredException();
        } else {
          throw new Exceptions\UnableToConnectException(
              'Could not connect: ' .
                  mysqli_error($this->link)
          );
        }
      }
    }
    return $this->connected;
  }

  /**
   * Disconnect from the MySQL database.
   *
   * @return bool Whether this instance is connected to a MySQL database after
   *              the method has run.
   */
  public function disconnect() {
    if ($this->connected) {
      if (is_a($this->link, 'mysqli')) {
        unset($this->link);
      }
      $this->link = null;
      $this->connected = false;
    }
    return $this->connected;
  }

  /**
   * Create entity tables in the database.
   *
   * @param string $etype The entity type to create a table for. If this is
   *                      blank, the default tables are created.
   * @return bool True on success, false on failure.
   */
  private function createTables($etype = null) {
    $this->query('SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";');
    if ($this->config['MySQL']['foreign_keys']) {
      $foreignKeyEntityTableGuid = " REFERENCES `{$this->prefix}guids`(`guid`) ON DELETE CASCADE";
      $foreignKeyDataTableGuid = " REFERENCES `{$this->prefix}entities{$etype}`(`guid`) ON DELETE CASCADE";
      $foreignKeyDataComparisonsTableGuid = " REFERENCES `{$this->prefix}entities{$etype}`(`guid`) ON DELETE CASCADE";
    } else {
      $foreignKeyEntityTableGuid = '';
      $foreignKeyDataTableGuid = '';
      $foreignKeyDataComparisonsTableGuid = '';
    }
    if (isset($etype)) {
      $etype = '_'.mysqli_real_escape_string($this->link, $etype);
      // Create the entity table.
      $this->query("CREATE TABLE IF NOT EXISTS `{$this->prefix}entities{$etype}` (`guid` BIGINT(20) UNSIGNED NOT NULL{$foreignKeyEntityTableGuid}, `tags` LONGTEXT, `varlist` LONGTEXT, `cdate` DECIMAL(18,6) NOT NULL, `mdate` DECIMAL(18,6) NOT NULL, PRIMARY KEY (`guid`), FULLTEXT `id_tags` (`tags`), FULLTEXT `id_varlist` (`varlist`)) ENGINE {$this->config['MySQL']['engine']} CHARACTER SET utf8 COLLATE utf8_bin;");
      // Create the data table.
      $this->query("CREATE TABLE IF NOT EXISTS `{$this->prefix}data{$etype}` (`guid` BIGINT(20) UNSIGNED NOT NULL{$foreignKeyDataTableGuid}, `name` TEXT NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`guid`,`name`(255))) ENGINE {$this->config['MySQL']['engine']} CHARACTER SET utf8 COLLATE utf8_bin;");
      // Create the data comparisons table.
      $this->query("CREATE TABLE IF NOT EXISTS `{$this->prefix}comparisons{$etype}` (`guid` BIGINT(20) UNSIGNED NOT NULL{$foreignKeyDataComparisonsTableGuid}, `name` TEXT NOT NULL, `references` LONGTEXT, `eq_true` BOOLEAN, `eq_one` BOOLEAN, `eq_zero` BOOLEAN, `eq_negone` BOOLEAN, `eq_emptyarray` BOOLEAN, `string` LONGTEXT, `int` BIGINT, `float` DOUBLE, `is_int` BOOLEAN NOT NULL, PRIMARY KEY (`guid`, `name`(255)), FULLTEXT `id_references` (`references`)) ENGINE {$this->config['MySQL']['engine']} CHARACTER SET utf8 COLLATE utf8_bin;");
    } else {
      // Create the GUID table.
      $this->query("CREATE TABLE IF NOT EXISTS `{$this->prefix}guids` (`guid` BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (`guid`)) ENGINE {$this->config['MySQL']['engine']} CHARACTER SET utf8 COLLATE utf8_bin;");
      // Create the UID table.
      $this->query("CREATE TABLE IF NOT EXISTS `{$this->prefix}uids` (`name` TEXT NOT NULL, `cur_uid` BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (`name`(100))) ENGINE {$this->config['MySQL']['engine']} CHARACTER SET utf8 COLLATE utf8_bin;");
    }
    return true;
  }

  private function query($query, $etypeDirty = null) {
    // error_log("\n\nQuery: ".$query);
    if (!($result = mysqli_query($this->link, $query))) {
      // If the tables don't exist yet, create them.
      if (mysqli_errno($this->link) == 1146 && $this->createTables()) {
        if (isset($etypeDirty)) {
          $this->createTables($etypeDirty);
        }
        if (!($result = mysqli_query($this->link, $query))) {
          throw new Exceptions\QueryFailedException(
              'Query failed: ' .
                  mysqli_errno($this->link) . ': ' .
                  mysqli_error($this->link),
              0,
              null,
              $query
          );
        }
      } else {
        throw new Exceptions\QueryFailedException(
            'Query failed: ' .
                mysqli_errno($this->link) . ': ' .
                mysqli_error($this->link),
            0,
            null,
            $query
        );
      }
    }
    return $result;
  }

  public function deleteEntityByID($guid, $etypeDirty = null) {
    $etype = isset($etypeDirty) ? '_'.mysqli_real_escape_string($this->link, $etypeDirty) : '';
    if ($this->config['MySQL']['transactions']) {
      $this->query("BEGIN;");
    }
    $this->query("DELETE FROM `{$this->prefix}guids` WHERE `guid`='".((int) $guid)."';");
    $this->query("DELETE FROM `{$this->prefix}entities{$etype}` WHERE `guid`='".((int) $guid)."';", $etypeDirty);
    $this->query("DELETE FROM `{$this->prefix}data{$etype}` WHERE `guid`='".((int) $guid)."';", $etypeDirty);
    $this->query("DELETE FROM `{$this->prefix}comparisons{$etype}` WHERE `guid`='".((int) $guid)."';", $etypeDirty);
    if ($this->config['MySQL']['transactions']) {
      $this->query("COMMIT;");
    }
    // Remove any cached versions of this entity.
    if ($this->config['cache']) {
      $this->cleanCache($guid);
    }
    return true;
  }

  public function deleteUID($name) {
    if (!$name) {
      return false;
    }
    $this->query("DELETE FROM `{$this->prefix}uids` WHERE `name`='".mysqli_real_escape_string($this->link, $name)."';");
    return true;
  }

  private function exportEntities($writeCallback) {
    $writeCallback("# Nymph Entity Exchange\n");
    $writeCallback("# Nymph Version ".\Nymph\Nymph::VERSION."\n");
    $writeCallback("# nymph.io\n");
    $writeCallback("#\n");
    $writeCallback("# Generation Time: ".date('r')."\n");

    $writeCallback("#\n");
    $writeCallback("# UIDs\n");
    $writeCallback("#\n\n");

    // Export UIDs.
    $result = $this->query("SELECT * FROM `{$this->prefix}uids` ORDER BY `name`;");
    $row = mysqli_fetch_assoc($result);
    while ($row) {
      $row['name'];
      $row['cur_uid'];
      $writeCallback("<{$row['name']}>[{$row['cur_uid']}]\n");
      // Make sure that $row is incremented :)
      $row = mysqli_fetch_assoc($result);
    }

    $writeCallback("\n#\n");
    $writeCallback("# Entities\n");
    $writeCallback("#\n\n");

    // Get the etypes.
    $result = $this->query("SHOW TABLES;");
    $etypes = [];
    $row = mysqli_fetch_row($result);
    while ($row) {
      if (strpos($row[0], $this->prefix.'entities_') === 0) {
        $etypes[] = substr($row[0], strlen($this->prefix.'entities_'));
      }
      $row = mysqli_fetch_row($result);
    }

    foreach ($etypes as $etype) {
      // Export entities.
      $result = $this->query("SELECT e.*, d.`name` AS `dname`, d.`value` AS `dvalue` FROM `{$this->prefix}entities_{$etype}` e LEFT JOIN `{$this->prefix}data_{$etype}` d ON e.`guid`=d.`guid` ORDER BY e.`guid`;");
      $row = mysqli_fetch_assoc($result);
      while ($row) {
        $guid = (int) $row['guid'];
        $tags = $row['tags'] === '' ? [] : explode(' ', $row['tags']);
        $cdate = (float) $row['cdate'];
        $mdate = (float) $row['mdate'];
        $writeCallback("{{$guid}}<{$etype}>[".implode(',', $tags)."]\n");
        $writeCallback("\tcdate=".json_encode(serialize($cdate))."\n");
        $writeCallback("\tmdate=".json_encode(serialize($mdate))."\n");
        if (isset($row['dname'])) {
          // This do will keep going and adding the data until the
          // next entity is reached. $row will end on the next entity.
          do {
            $writeCallback(
                "\t{$row['dname']}=".json_encode($row['dvalue'])."\n"
            );
            $row = mysqli_fetch_assoc($result);
          } while ((int) $row['guid'] === $guid);
        } else {
          // Make sure that $row is incremented :)
          $row = mysqli_fetch_assoc($result);
        }
      }
    }
  }

  /**
   * Generate the MySQL query.
   * @param array $options The options array.
   * @param array $selectors The formatted selector array.
   * @param string $etypeDirty
   * @param bool $subquery Whether only a subquery should be returned.
   * @param array &$data_aliases The data alias array to use for subqueries.
   * @return string The SQL query.
   */
  private function makeEntityQuery(
      $options,
      $selectors,
      $etypeDirty,
      $subquery = false,
      &$data_aliases = []
  ) {
    $sort = $options['sort'] ?? 'cdate';
    $etype = '_'.mysqli_real_escape_string($this->link, $etypeDirty);
    $query_parts = $this->iterateSelectorsForQuery($selectors, function ($value) use ($options, $etypeDirty, &$data_aliases) {
      return $this->makeEntityQuery(
          $options,
          [$value],
          $etypeDirty,
          true,
          $data_aliases
      );
    }, function (&$cur_query, $key, $value, $type_is_or, $type_is_not) use (&$data_aliases) {
      $clause_not = $key[0] === '!';
      // Any options having to do with data only return if the
      // entity has the specified variables.
      foreach ($value as $cur_value) {
        switch ($key) {
          case 'guid':
          case '!guid':
            foreach ($cur_value as $cur_guid) {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`guid`=\''.(int) $cur_guid.'\'';
            }
            break;
          case 'tag':
          case '!tag':
            if ($type_is_not xor $clause_not) {
              if ($type_is_or) {
                foreach ($cur_value as $cur_tag) {
                  if ($cur_query) {
                    $cur_query .= ' OR ';
                  }
                  $cur_query .= 'e.`tags` NOT REGEXP \'[[:<:]]' .
                      mysqli_real_escape_string($this->link, $cur_tag) .
                      '[[:>:]]\'';
                }
              } else {
                $cur_query .= 'e.`tags` NOT REGEXP \'[[:<:]](' .
                    mysqli_real_escape_string(
                        $this->link,
                        implode('|', $cur_value)
                    ).')[[:>:]]\'';
              }
            } else {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $groupQuery = '';
              foreach ($cur_value as $cur_tag) {
                $groupQuery .= ($type_is_or ? ' ' : ' +').$cur_tag;
              }
              $cur_query .= 'MATCH (e.`tags`) AGAINST (\'' .
                  mysqli_real_escape_string($this->link, $groupQuery) .
                  '\' IN BOOLEAN MODE)';
            }
            break;
          case 'isset':
          case '!isset':
            if ($type_is_not xor $clause_not) {
              foreach ($cur_value as $cur_var) {
                if ($cur_query) {
                  $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                }
                $cur_query .= '(e.`varlist` NOT REGEXP \'[[:<:]]' .
                    mysqli_real_escape_string($this->link, $cur_var) .
                    '[[:>:]]\'';
                $where_clause = '`name`=\'' .
                    mysqli_real_escape_string($this->link, $cur_var) .
                    '\' AND `value`=\'N;\'';
                $alias = $this->addDataAlias('data', $where_clause, $data_aliases);
                $cur_query .= ' OR (e.`guid`='.$alias.'.`guid`)';
                $cur_query .= ')';
              }
            } else {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $groupQuery = '';
              foreach ($cur_value as $cur_var) {
                $groupQuery .= ($type_is_or ? ' ' : ' +').$cur_var;
              }
              $cur_query .= 'MATCH (e.`varlist`) AGAINST (\'' .
                  mysqli_real_escape_string($this->link, $groupQuery) .
                  '\' IN BOOLEAN MODE)';
            }
            break;
          case 'ref':
          case '!ref':
            $guids = [];
            if ((array) $cur_value[1] === $cur_value[1]) {
              if (key_exists('guid', $cur_value[1])) {
                $guids[] = (int) $cur_value[1]['guid'];
              } else {
                foreach ($cur_value[1] as $cur_entity) {
                  if ((object) $cur_entity === $cur_entity) {
                    $guids[] = (int) $cur_entity->guid;
                  } elseif ((array) $cur_entity === $cur_entity) {
                    $guids[] = (int) $cur_entity['guid'];
                  } else {
                    $guids[] = (int) $cur_entity;
                  }
                }
              }
            } elseif ((object) $cur_value[1] === $cur_value[1]) {
              $guids[] = (int) $cur_value[1]->guid;
            } else {
              $guids[] = (int) $cur_value[1];
            }
            if ($cur_query) {
              $cur_query .= $type_is_or ? ' OR ' : ' AND ';
            }
            if ($type_is_not xor $clause_not) {
              $cur_query .= '(e.`varlist` NOT REGEXP \'[[:<:]]' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '[[:>:]]\' OR (';
              $noPrepend = true;
              foreach ($guids as $cur_qguid) {
                if (!$noPrepend) {
                  $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                } else {
                  $noPrepend = false;
                }
                $where_clause = '`name`=\'' .
                    mysqli_real_escape_string($this->link, $cur_value[0]) .
                    '\' AND `references` NOT REGEXP \'[[:<:]]' .
                    mysqli_real_escape_string($this->link, $cur_qguid) .
                    '[[:>:]]\'';
                $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
                $cur_query .= '(e.`guid`='.$alias.'.`guid`)';
              }
              $cur_query .= '))';
            } else {
              $groupQuery = '';
              foreach ($guids as $cur_qguid) {
                $groupQuery .= ($type_is_or ? ' ' : ' +').$cur_qguid;
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND MATCH (`references`) AGAINST (\'' .
                  mysqli_real_escape_string($this->link, $groupQuery) .
                  '\' IN BOOLEAN MODE)';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(e.`guid`='.$alias.'.`guid`)';
            }
            break;
          case 'strict':
          case '!strict':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`cdate`='.((float) $cur_value[1]);
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`mdate`='.((float) $cur_value[1]);
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              if (is_callable([$cur_value[1], 'toReference'])) {
                $svalue = serialize($cur_value[1]->toReference());
              } else {
                $svalue = serialize($cur_value[1]);
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`value`=\'' .
                  mysqli_real_escape_string($this->link, $svalue).'\'';
              $alias = $this->addDataAlias('data', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'like':
          case '!like':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`cdate` LIKE \'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\')';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`mdate` LIKE \'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\')';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`string` LIKE \'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\'';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'ilike':
          case '!ilike':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`cdate` LIKE LOWER(\'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\'))';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`mdate` LIKE LOWER(\'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\'))';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'LOWER(`string`) LIKE LOWER(\'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\')';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'pmatch':
          case '!pmatch':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`cdate` REGEXP \'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\')';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`mdate` REGEXP \'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\')';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`string` REGEXP \'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\'';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'ipmatch':
          case '!ipmatch':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`cdate` REGEXP LOWER(\'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\'))';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e.`mdate` REGEXP LOWER(\'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\'))';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'LOWER(`string`) REGEXP LOWER(\'' .
                  mysqli_real_escape_string($this->link, $cur_value[1]) .
                  '\')';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'match':
          case '!match':
            if (!($type_is_not xor $clause_not)) {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= 'MATCH (e.`varlist`) AGAINST (\'+' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' IN BOOLEAN MODE)';
            }
            break;
          case 'gt':
          case '!gt':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`cdate`>'.((float) $cur_value[1]);
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`mdate`>'.((float) $cur_value[1]);
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '((`is_int`=TRUE AND `int` > ' .
                  ((int) $cur_value[1]) . ') OR (' .
                  '`is_int`=FALSE AND `float` > ' .
                  ((float) $cur_value[1]) . '))';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'gte':
          case '!gte':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`cdate`>='.((float) $cur_value[1]);
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`mdate`>='.((float) $cur_value[1]);
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '((`is_int`=TRUE AND `int` >= ' .
                  ((int) $cur_value[1]) . ') OR (' .
                  '`is_int`=FALSE AND `float` >= ' .
                  ((float) $cur_value[1]) . '))';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'lt':
          case '!lt':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`cdate`<'.((float) $cur_value[1]);
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`mdate`<'.((float) $cur_value[1]);
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '((`is_int`=TRUE AND `int` < ' .
                  ((int) $cur_value[1]) . ') OR (' .
                  '`is_int`=FALSE AND `float` < ' .
                  ((float) $cur_value[1]) . '))';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          case 'lte':
          case '!lte':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`cdate`<='.((float) $cur_value[1]);
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e.`mdate`<='.((float) $cur_value[1]);
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '((`is_int`=TRUE AND `int` <= ' .
                  ((int) $cur_value[1]) . ') OR (' .
                  '`is_int`=FALSE AND `float` <= ' .
                  ((float) $cur_value[1]) . '))';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
            }
            break;
          // Cases after this point contains special values where
          // it can be solved by the query, but if those values
          // don't match, just check the variable exists.
          case 'data':
          case '!data':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."cdate"='.((float) $cur_value[1]);
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."mdate"='.((float) $cur_value[1]);
              break;
            } elseif ($cur_value[1] === true || $cur_value[1] === false) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`eq_true`=' . ($cur_value[1] ? 'TRUE' : 'FALSE');
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
              break;
            } elseif ($cur_value[1] === 1) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`eq_one`=TRUE';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
              break;
            } elseif ($cur_value[1] === 0) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`eq_zero`=TRUE';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ).'(e.`guid`='.$alias.'.`guid`))';
              break;
            } elseif ($cur_value[1] === -1) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`eq_negone`=TRUE';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ).'(e.`guid`='.$alias.'.`guid`))';
              break;
            } elseif ($cur_value[1] === []) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $where_clause = '`name`=\'' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' AND '.(($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '`eq_emptyarray`=TRUE';
              $alias = $this->addDataAlias('comparisons', $where_clause, $data_aliases);
              $cur_query .= '(' .
                  (
                    ($type_is_not xor $clause_not)
                        ? 'e.`varlist` NOT REGEXP \'[[:<:]]' .
                            mysqli_real_escape_string(
                                $this->link,
                                $cur_value[0]
                            ).'[[:>:]]\' OR '
                        : ''
                  ) .
                  '(e.`guid`='.$alias.'.`guid`))';
              break;
            }
            // Fall through.
          case 'array':
          case '!array':
            if (!($type_is_not xor $clause_not)) {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= 'MATCH (e.`varlist`) AGAINST (\'+' .
                  mysqli_real_escape_string($this->link, $cur_value[0]) .
                  '\' IN BOOLEAN MODE)';
            }
            break;
        }
      }
    });

    switch ($sort) {
      case 'guid':
        $sort = 'e.`guid`';
        break;
      case 'mdate':
        $sort = 'e.`mdate`';
        break;
      case 'cdate':
      default:
        $sort = 'e.`cdate`';
        break;
    }
    if ($query_parts) {
      if ($subquery) {
        $query = "((".implode(') AND (', $query_parts)."))";
      } else {
        if ($data_aliases) {
          $data_part = '';
          foreach ($data_aliases as $cur_alias) {
            $data_part .= " LEFT JOIN (SELECT `guid` FROM `{$this->prefix}{$cur_alias['table']}{$etype}` WHERE {$cur_alias['where_clause']}) {$cur_alias['alias']} ON e.`guid`={$cur_alias['alias']}.`guid`";
          }
        } else {
          $data_part = '';
        }
        $query = "SELECT e.`guid`, e.`tags`, e.`cdate`, e.`mdate`, d.`name`, d.`value` FROM `{$this->prefix}entities{$etype}` e LEFT JOIN `{$this->prefix}data{$etype}` d ON e.`guid`=d.`guid`{$data_part} WHERE (".implode(') AND (', $query_parts).") ORDER BY ".(isset($options['reverse']) && $options['reverse'] ? $sort.' DESC' : $sort).";";
      }
    } else {
      if ($subquery) {
        $query = '';
      } else {
        $query = "SELECT e.`guid`, e.`tags`, e.`cdate`, e.`mdate`, d.`name`, d.`value` FROM `{$this->prefix}entities{$etype}` e LEFT JOIN `{$this->prefix}data{$etype}` d ON e.`guid`=d.`guid` ORDER BY ".(isset($options['reverse']) && $options['reverse'] ? $sort.' DESC' : $sort).";";
      }
    }

    return $query;
  }

  public function getEntities($options = [], ...$selectors) {
    return $this->getEntitesRowLike(
        $options,
        $selectors,
        [
          'ref', '!ref',
          'guid', '!guid',
          'tag', '!tag',
          'isset', '!isset',
          'strict', '!strict',
          'like', '!like',
          'ilike', '!ilike',
          'pmatch', '!pmatch',
          'ipmatch', '!ipmatch',
          'gt', '!gt',
          'gte', '!gte',
          'lt', '!lt',
          'lte', '!lte'
        ],
        [true, false, 1, 0, -1, []],
        'mysqli_fetch_row',
        'mysqli_free_result',
        function ($row) {
          return (int) $row[0];
        },
        function ($row) {
          return [
            'tags' => $row[1] !== '' ? explode(' ', $row[1]) : [],
            'cdate' => (float) $row[2],
            'mdate' => (float) $row[3]
          ];
        },
        function ($row) {
          return [
            'name' => $row[4],
            'svalue' => $row[5]
          ];
        }
    );
  }

  public function getUID($name) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $result = $this->query("SELECT `cur_uid` FROM `{$this->prefix}uids` WHERE `name`='".mysqli_real_escape_string($this->link, $name)."';");
    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);
    return isset($row[0]) ? (int) $row[0] : null;
  }

  public function import($filename) {
    return $this->importFromFile($filename, function ($guid, $tags, $data, $etype) {
      $this->query("REPLACE INTO `{$this->prefix}guids` (`guid`) VALUES ({$guid});");
      $this->query("REPLACE INTO `{$this->prefix}entities_{$etype}` (`guid`, `tags`, `varlist`, `cdate`, `mdate`) VALUES ({$guid}, '".mysqli_real_escape_string($this->link, implode(' ', $tags))."', '".mysqli_real_escape_string($this->link, implode(' ', array_keys($data)))."', ".unserialize($data['cdate']).", ".unserialize($data['mdate']).");", $etype);
      $this->query("DELETE FROM `{$this->prefix}data_{$etype}` WHERE `guid`='{$guid}';");
      $this->query("DELETE FROM `{$this->prefix}comparisons_{$etype}` WHERE `guid`='{$guid}';");
      unset($data['cdate'], $data['mdate']);
      if ($data) {
        foreach ($data as $name => $value) {
          $this->query("INSERT INTO `{$this->prefix}data_{$etype}` (`guid`, `name`, `value`) VALUES ({$guid}, '".mysqli_real_escape_string($this->link, $name)."', '".mysqli_real_escape_string($this->link, $value)."');", $etype);
          $query = "INSERT INTO `{$this->prefix}comparisons_{$etype}` (`guid`, `name`, `references`, `eq_true`, `eq_one`, `eq_zero`, `eq_negone`, `eq_emptyarray`, `string`, `int`, `float`, `is_int`) VALUES " .
            $this->makeInsertValuesSQL($guid, $name, $value, unserialize($value)) . ';';
          $this->query($query, $etype);
        }
      }
    }, function ($name, $cur_uid) {
      $this->query("INSERT INTO `{$this->prefix}uids` (`name`, `cur_uid`) VALUES ('".mysqli_real_escape_string($this->link, $name)."', ".((int) $cur_uid).") ON DUPLICATE KEY UPDATE `cur_uid`=".((int) $cur_uid).";");
    }, $this->config['MySQL']['transactions'] ? function () {
      $this->query("BEGIN;");
    } : null, $this->config['MySQL']['transactions'] ? function () {
      $this->query("COMMIT;");
    } : null);
  }

  public function newUID($name) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $result = $this->query("SELECT GET_LOCK('{$this->prefix}uids_".mysqli_real_escape_string($this->link, $name)."', 10);");
    if (mysqli_fetch_row($result)[0] != 1) {
      return null;
    }
    if ($this->config['MySQL']['transactions']) {
      $this->query("BEGIN;");
    }
    $this->query("INSERT INTO `{$this->prefix}uids` (`name`, `cur_uid`) VALUES ('".mysqli_real_escape_string($this->link, $name)."', 1) ON DUPLICATE KEY UPDATE `cur_uid`=`cur_uid`+1;");
    $result = $this->query("SELECT `cur_uid` FROM `{$this->prefix}uids` WHERE `name`='".mysqli_real_escape_string($this->link, $name)."';");
    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);
    if ($this->config['MySQL']['transactions']) {
      $this->query("COMMIT;");
    }
    $this->query("SELECT RELEASE_LOCK('{$this->prefix}uids_".mysqli_real_escape_string($this->link, $name)."');");
    return isset($row[0]) ? (int) $row[0] : null;
  }

  public function renameUID($oldName, $newName) {
    if (!$oldName || !$newName) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query("UPDATE `{$this->prefix}uids` SET `name`='".mysqli_real_escape_string($this->link, $newName)."' WHERE `name`='".mysqli_real_escape_string($this->link, $oldName)."';");
    return true;
  }

  public function saveEntity(&$entity) {
    $insertData = function ($entity, $data, $sdata, $etype, $etypeDirty) {
      $runInsertQuery = function ($name, $value, $svalue) use ($entity, $etype, $etypeDirty) {
        $this->query("INSERT INTO `{$this->prefix}data{$etype}` (`guid`, `name`, `value`) VALUES (".((int) $entity->guid).", '".mysqli_real_escape_string($this->link, $name)."', '".mysqli_real_escape_string($this->link, $svalue)."');", $etypeDirty);
        $this->query(
            "INSERT INTO `{$this->prefix}comparisons{$etype}` (`guid`, `name`, `references`, `eq_true`, `eq_one`, `eq_zero`, `eq_negone`, `eq_emptyarray`, `string`, `int`, `float`, `is_int`) VALUES ".
              $this->makeInsertValuesSQL($entity->guid, $name, $svalue, $value) . ';',
            $etypeDirty
        );
      };
      foreach ($data as $name => $value) {
        $runInsertQuery($name, $value, serialize($value));
      }
      foreach ($sdata as $name => $svalue) {
        $runInsertQuery($name, unserialize($svalue), $svalue);
      }
    };
    return $this->saveEntityRowLike($entity, function ($etypeDirty) {
      return '_'.mysqli_real_escape_string($this->link, $etypeDirty);
    }, function ($guid) {
      $result = $this->query("SELECT `guid` FROM `{$this->prefix}guids` WHERE `guid`='{$guid}';");
      $row = mysqli_fetch_row($result);
      mysqli_free_result($result);
      return !isset($row[0]);
    }, function ($entity, $data, $sdata, $varlist, $etype, $etypeDirty) use ($insertData) {
      $this->query("INSERT INTO `{$this->prefix}guids` (`guid`) VALUES ({$entity->guid});");
      $this->query("INSERT INTO `{$this->prefix}entities{$etype}` (`guid`, `tags`, `varlist`, `cdate`, `mdate`) VALUES ({$entity->guid}, '".mysqli_real_escape_string($this->link, implode(' ', array_diff($entity->tags, [''])))."', '".mysqli_real_escape_string($this->link, implode(' ', $varlist))."', ".((float) $entity->cdate).", ".((float) $entity->mdate).");", $etypeDirty);
      $insertData($entity, $data, $sdata, $etype, $etypeDirty);
    }, function ($entity, $data, $sdata, $varlist, $etype, $etypeDirty) use ($insertData) {
      if ($this->config['MySQL']['row_locking']) {
        $this->query("SELECT 1 FROM `{$this->prefix}entities{$etype}` WHERE `guid`='".((int) $entity->guid)."' GROUP BY 1 FOR UPDATE;");
        $this->query("SELECT 1 FROM `{$this->prefix}data{$etype}` WHERE `guid`='".((int) $entity->guid)."' GROUP BY 1 FOR UPDATE;");
        $this->query("SELECT 1 FROM `{$this->prefix}comparisons{$etype}` WHERE `guid`='".((int) $entity->guid)."' GROUP BY 1 FOR UPDATE;");
      }
      if ($this->config['MySQL']['table_locking']) {
        $this->query("LOCK TABLES `{$this->prefix}entities{$etype}` WRITE, `{$this->prefix}data{$etype}` WRITE, `{$this->prefix}comparisons{$etype}` WRITE;");
      }
      $this->query("UPDATE `{$this->prefix}entities{$etype}` SET `tags`='".mysqli_real_escape_string($this->link, implode(' ', array_diff($entity->tags, [''])))."', `varlist`='".mysqli_real_escape_string($this->link, implode(' ', $varlist))."', `mdate`=".((float) $entity->mdate)." WHERE `guid`='".((int) $entity->guid)."';", $etypeDirty);
      $this->query("DELETE FROM `{$this->prefix}data{$etype}` WHERE `guid`='".((int) $entity->guid)."';");
      $this->query("DELETE FROM `{$this->prefix}comparisons{$etype}` WHERE `guid`='".((int) $entity->guid)."';");
      $insertData($entity, $data, $sdata, $etype, $etypeDirty);
      if ($this->config['MySQL']['table_locking']) {
        $this->query("UNLOCK TABLES;");
      }
    }, $this->config['MySQL']['transactions'] ? function () {
      $this->query("BEGIN;");
    } : null, $this->config['MySQL']['transactions'] ? function () {
      $this->query("COMMIT;");
    } : null);
  }

  public function setUID($name, $value) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query("INSERT INTO `{$this->prefix}uids` (`name`, `cur_uid`) VALUES ('".mysqli_real_escape_string($this->link, $name)."', ".((int) $value).") ON DUPLICATE KEY UPDATE `cur_uid`=".((int) $value).";");
    return true;
  }

  private function addDataAlias($table, $where_clause, &$data_aliases) {
    do {
      $new_alias = 'd'.rand(1, 9999999999);
    } while (in_array($new_alias, $data_aliases));
    $data_aliases[] = [
      'table' => $table,
      'where_clause' => $where_clause,
      'alias' => $new_alias
    ];
    return $new_alias;
  }

  private function makeInsertValuesSQL($guid, $name, $svalue, $uvalue) {
    preg_match_all(
        '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
        $svalue,
        $references,
        PREG_PATTERN_ORDER
    );
    return sprintf(
        "(%u, '%s', '%s', %s, %s, %s, %s, %s, %s, %d, %f, %s)",
        (int) $guid,
        mysqli_real_escape_string($this->link, $name),
        mysqli_real_escape_string(
            $this->link,
            implode(' ', $references[1])
        ),
        $uvalue == true ? 'TRUE' : 'FALSE',
        (!is_object($uvalue) && $uvalue == 1) ? 'TRUE' : 'FALSE',
        (!is_object($uvalue) && $uvalue == 0) ? 'TRUE' : 'FALSE',
        (!is_object($uvalue) && $uvalue == -1) ? 'TRUE' : 'FALSE',
        $uvalue == [] ? 'TRUE' : 'FALSE',
        is_string($uvalue)
            ? '\''.mysqli_real_escape_string($this->link, $uvalue).'\''
            : 'NULL',
        is_object($uvalue) ? 1 : ((int) $uvalue),
        is_object($uvalue) ? 1 : ((float) $uvalue),
        is_int($uvalue) ? 'TRUE' : 'FALSE'
    );
  }
}
