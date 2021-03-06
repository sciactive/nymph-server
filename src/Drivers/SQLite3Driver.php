<?php namespace Nymph\Drivers;

use Nymph\Exceptions;
use SQLite3;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * SQLite3 based Nymph driver.
 *
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @see http://nymph.io/
 */
class SQLite3Driver implements DriverInterface {
  use DriverTrait {
    DriverTrait::__construct as private __traitConstruct;
  }
  /**
   * The SQLite3 database connection for this instance.
   *
   * @var SQLite3
   */
  private $link = null;
  private $prefix;

  public function __construct($NymphConfig) {
    $this->__traitConstruct($NymphConfig);
    $this->prefix = $this->config['SQLite3']['prefix'];
  }

  /**
   * Disconnect from the database on destruction.
   */
  public function __destruct() {
    $this->disconnect();
  }

  /**
   * Connect to the SQLite3 database.
   *
   * @return bool Whether this instance is connected to a SQLite3 database
   *              after the method has run.
   */
  public function connect() {
    // Check that the SQLite3 extension is installed.
    if (!class_exists('SQLite3')) {
      throw new Exceptions\UnableToConnectException(
        'SQLite3 PHP extension is not available. It probably has not '.
          'been installed. Please install and configure it in order to use '.
          'SQLite3.'
      );
    }
    $filename = $this->config['SQLite3']['filename'];
    $busyTimeout = $this->config['SQLite3']['busy_timeout'];
    $openFlags = $this->config['SQLite3']['open_flags'];
    $encryptionKey = $this->config['SQLite3']['encryption_key'];
    // Connecting
    if (!$this->connected) {
      $this->link = new SQLite3($filename, $openFlags, $encryptionKey);
      if ($this->link) {
        $this->connected = true;
        $this->link->busyTimeout($busyTimeout);
        // Set database and connection options.
        $this->link->exec("PRAGMA encoding = \"UTF-8\";");
        $this->link->exec("PRAGMA foreign_keys = 1;");
        $this->link->exec("PRAGMA case_sensitive_like = 1;");
        // Create the preg_match and regexp functions.
        // TODO(hperrin): Add more of these functions to get rid of post-query checks.
        $this->link->createFunction(
          'preg_match',
          'preg_match',
          2,
          defined('SQLITE3_DETERMINISTIC') ? \SQLITE3_DETERMINISTIC : 0
        );
        $this->link->createFunction(
          'regexp',
          function ($pattern, $subject) {
            return !!$this->posixRegexMatch($pattern, $subject);
          },
          2,
          defined('SQLITE3_DETERMINISTIC') ? \SQLITE3_DETERMINISTIC : 0
        );
      } else {
        $this->connected = false;
        if ($filename === ':memory:') {
          throw new Exceptions\NotConfiguredException();
        } else {
          throw new Exceptions\UnableToConnectException('Could not connect.');
        }
      }
    }
    return $this->connected;
  }

  /**
   * Disconnect from the SQLite3 database.
   *
   * @return bool Whether this instance is connected to a SQLite3 database
   *              after the method has run.
   */
  public function disconnect() {
    if ($this->connected) {
      if (is_a($this->link, 'SQLite3')) {
        $this->link->exec("PRAGMA optimize;");
        $this->link->close();
      }
      $this->connected = false;
    }
    return $this->connected;
  }

  /**
   * Check if SQLite3 DB is read only and throw error if so.
   */
  private function checkReadOnlyMode() {
    if ($this->config['SQLite3']['open_flags'] & \SQLITE3_OPEN_READONLY) {
      throw new Exceptions\InvalidParametersException(
        'Attempt to write to SQLite3 DB in read only mode.'
      );
    }
  }

  /**
   * Create entity tables in the database.
   *
   * @param string $etype The entity type to create a table for. If this is
   *                      blank, the default tables are created.
   */
  private function createTables($etype = null) {
    $this->checkReadOnlyMode();
    $this->query("SAVEPOINT 'tablecreation';");
    try {
      if (isset($etype)) {
        $etype = '_'.SQLite3::escapeString($etype);

        // Create the entity table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}entities{$etype}\" (\"guid\" INTEGER PRIMARY KEY ASC NOT NULL REFERENCES \"{$this->prefix}guids\"(\"guid\") ON DELETE CASCADE, \"tags\" TEXT, \"cdate\" REAL NOT NULL, \"mdate\" REAL NOT NULL);");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}entities{$etype}_id_cdate\" ON \"{$this->prefix}entities{$etype}\" (\"cdate\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}entities{$etype}_id_mdate\" ON \"{$this->prefix}entities{$etype}\" (\"mdate\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}entities{$etype}_id_tags\" ON \"{$this->prefix}entities{$etype}\" (\"tags\");");
        // Create the data table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}data{$etype}\" (\"guid\" INTEGER NOT NULL REFERENCES \"{$this->prefix}entities{$etype}\"(\"guid\") ON DELETE CASCADE, \"name\" TEXT NOT NULL, \"value\" TEXT NOT NULL, PRIMARY KEY(\"guid\", \"name\"));");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_guid\" ON \"{$this->prefix}data{$etype}\" (\"guid\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_name\" ON \"{$this->prefix}data{$etype}\" (\"name\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_value\" ON \"{$this->prefix}data{$etype}\" (\"value\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_guid__name_user\" ON \"{$this->prefix}data{$etype}\" (\"guid\") WHERE \"name\" = 'user';");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_guid__name_group\" ON \"{$this->prefix}data{$etype}\" (\"guid\") WHERE \"name\" = 'group';");
        // Create the comparisons table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}comparisons{$etype}\" (\"guid\" INTEGER NOT NULL REFERENCES \"{$this->prefix}entities{$etype}\"(\"guid\") ON DELETE CASCADE, \"name\" TEXT NOT NULL, \"eq_true\" INTEGER, \"eq_one\" INTEGER, \"eq_zero\" INTEGER, \"eq_negone\" INTEGER, \"eq_emptyarray\" INTEGER, \"string\" TEXT, \"int\" INTEGER, \"float\" REAL, \"is_int\" INTEGER, PRIMARY KEY(\"guid\", \"name\"));");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}comparisons{$etype}_id_guid\" ON \"{$this->prefix}comparisons{$etype}\" (\"guid\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}comparisons{$etype}_id_name\" ON \"{$this->prefix}comparisons{$etype}\" (\"name\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}comparisons{$etype}_id_name__eq_true\" ON \"{$this->prefix}comparisons{$etype}\" (\"name\") WHERE \"eq_true\" = 1;");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}comparisons{$etype}_id_name__not_eq_true\" ON \"{$this->prefix}comparisons{$etype}\" (\"name\") WHERE \"eq_true\" = 0;");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}comparisons{$etype}_id_int\" ON \"{$this->prefix}comparisons{$etype}\" (\"int\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}comparisons{$etype}_id_float\" ON \"{$this->prefix}comparisons{$etype}\" (\"float\");");
        // Create the references table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}references{$etype}\" (\"guid\" INTEGER NOT NULL REFERENCES \"{$this->prefix}entities{$etype}\"(\"guid\") ON DELETE CASCADE, \"name\" TEXT NOT NULL, \"reference\" INTEGER NOT NULL, PRIMARY KEY(\"guid\", \"name\", \"reference\"));");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}references{$etype}_id_guid\" ON \"{$this->prefix}references{$etype}\" (\"guid\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}references{$etype}_id_name\" ON \"{$this->prefix}references{$etype}\" (\"name\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}references{$etype}_id_reference\" ON \"{$this->prefix}references{$etype}\" (\"reference\");");
      } else {
        // Create the GUID table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}guids\" (\"guid\" INTEGER NOT NULL PRIMARY KEY ASC);");
        // Create the UID table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}uids\" (\"name\" TEXT PRIMARY KEY NOT NULL, \"cur_uid\" INTEGER NOT NULL);");
      }
    } catch (\Exception $e) {
      $this->query("ROLLBACK TO 'tablecreation';");

      throw $e;
    }
    $this->query("RELEASE 'tablecreation';");
    return true;
  }

  private function query($query, $etypeDirty = null) {
    try {
      if (!($result = $this->link->query($query))) {
        throw new Exceptions\QueryFailedException(
          'Query failed: '.$this->link->lastErrorCode().' - '.
            $this->link->lastErrorMsg(),
          0,
          null,
          $query
        );
      }
    } catch (\Exception $e) {
      $errorCode = $this->link->lastErrorCode();
      $errorMsg = $this->link->lastErrorMsg();
      if ($errorCode === 1
        && preg_match('/^no such table: /', $errorMsg)
        && $this->createTables()
      ) {
        if (isset($etypeDirty)) {
          $this->createTables($etypeDirty);
        }
        if (!($result = $this->link->query($query))) {
          throw new Exceptions\QueryFailedException(
            'Query failed: '.$this->link->lastErrorCode().' - '.
              $this->link->lastErrorMsg(),
            0,
            null,
            $query
          );
        }
      } else {
        throw $e;
      }
    }
    return $result;
  }

  public function deleteEntityByID($guid, $className = null) {
    $etypeDirty = isset($className) ? $className::ETYPE : null;
    $this->checkReadOnlyMode();
    $guid = (int) $guid;
    $etype = isset($etypeDirty) ? '_'.SQLite3::escapeString($etypeDirty) : '';
    $this->query("SAVEPOINT 'deleteentity';");
    $this->query("DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
    $this->query("DELETE FROM \"{$this->prefix}entities{$etype}\" WHERE \"guid\"={$guid};", $etypeDirty);
    $this->query("DELETE FROM \"{$this->prefix}data{$etype}\" WHERE \"guid\"={$guid};", $etypeDirty);
    $this->query("DELETE FROM \"{$this->prefix}comparisons{$etype}\" WHERE \"guid\"={$guid};", $etypeDirty);
    $this->query("DELETE FROM \"{$this->prefix}references{$etype}\" WHERE \"guid\"={$guid};", $etypeDirty);
    $this->query("RELEASE 'deleteentity';");
    // Remove any cached versions of this entity.
    if ($this->config['cache']) {
      $this->cleanCache($guid);
    }
    return true;
  }

  public function deleteUID($name) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException('Name not given for UID');
    }
    $this->checkReadOnlyMode();
    $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
    return true;
  }

  public function exportEntities($writeCallback) {
    $writeCallback("# Nymph Entity Exchange\n");
    $writeCallback("# Nymph Version ".\Nymph\Nymph::VERSION."\n");
    $writeCallback("# nymph.io\n");
    $writeCallback("#\n");
    $writeCallback("# Generation Time: ".date('r')."\n");

    $writeCallback("#\n");
    $writeCallback("# UIDs\n");
    $writeCallback("#\n\n");

    // Export UIDs.
    $result = $this->query("SELECT * FROM \"{$this->prefix}uids\" ORDER BY \"name\";");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    while ($row) {
      $row['name'];
      $row['cur_uid'];
      $writeCallback("<{$row['name']}>[{$row['cur_uid']}]\n");
      // Make sure that $row is incremented :)
      $row = $result->fetchArray(SQLITE3_ASSOC);
    }
    $result->finalize();

    $writeCallback("\n#\n");
    $writeCallback("# Entities\n");
    $writeCallback("#\n\n");

    // Get the etypes.
    $result = $this->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name;");
    $etypes = [];
    $row = $result->fetchArray(SQLITE3_NUM);
    while ($row) {
      if (strpos($row[0], $this->prefix.'entities_') === 0) {
        $etypes[] = substr($row[0], strlen($this->prefix.'entities_'));
      }
      $row = $result->fetchArray(SQLITE3_NUM);
    }
    $result->finalize();

    foreach ($etypes as $etype) {
      // Export entities.
      $result = $this->query("SELECT e.*, d.\"name\" AS \"dname\", d.\"value\" AS \"dvalue\" FROM \"{$this->prefix}entities_{$etype}\" e LEFT JOIN \"{$this->prefix}data_{$etype}\" d ON e.\"guid\"=d.\"guid\" ORDER BY e.\"guid\";");
      $row = $result->fetchArray(SQLITE3_ASSOC);
      while ($row) {
        $guid = (int) $row['guid'];
        $tags = explode(',', substr($row['tags'], 1, -1));
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
            $row = $result->fetchArray(SQLITE3_ASSOC);
          } while ($row && (int) $row['guid'] === $guid);
        } else {
          // Make sure that $row is incremented :)
          $row = $result->fetchArray(SQLITE3_ASSOC);
        }
      }
      $result->finalize();
    }
  }

  /**
   * Generate the SQLite3 query.
   * @param array $options The options array.
   * @param array $selectors The formatted selector array.
   * @param string $etypeDirty
   * @param bool $subquery Whether only a subquery should be returned.
   * @return string The SQL query.
   */
  private function makeEntityQuery(
    $options,
    $selectors,
    $etypeDirty,
    $subquery = false
  ) {
    $fullQueryCoverage = true;
    $sort = $options['sort'] ?? 'cdate';
    $etype = '_'.SQLite3::escapeString($etypeDirty);
    $queryParts = $this->iterateSelectorsForQuery(
      $selectors,
      function ($value) use ($options, $etypeDirty, &$fullQueryCoverage) {
        $subquery = $this->makeEntityQuery(
          $options,
          [$value],
          $etypeDirty,
          true
        );
        $fullQueryCoverage = $fullQueryCoverage && $subquery['fullCoverage'];
        return $subquery['query'];
      },
      function (&$curQuery, $key, $value, $typeIsOr, $typeIsNot) use ($etype, &$fullQueryCoverage) {
        $clauseNot = $key[0] === '!';
        // Any options having to do with data only return if the
        // entity has the specified variables.
        foreach ($value as $curValue) {
          switch ($key) {
            case 'guid':
            case '!guid':
              foreach ($curValue as $curGuid) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid"='.(int) $curGuid;
              }
              break;
            case 'tag':
            case '!tag':
              foreach ($curValue as $curTag) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."tags" LIKE \'%,'.
                  str_replace(
                    ['%', '_', ':'],
                    [':%', ':_', '::'],
                    SQLite3::escapeString($curTag)
                  ).',%\' ESCAPE \':\'';
              }
              break;
            case 'isset':
            case '!isset':
              foreach ($curValue as $curVar) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= 'ie."guid" '.
                  (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'IN (SELECT "guid" FROM "'.
                  $this->prefix.'data'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curVar).
                  '\' AND "value"!=\'N;\')';
              }
              break;
            case 'ref':
            case '!ref':
              $guids = [];
              if (is_array($curValue[1])) {
                if (key_exists('guid', $curValue[1])) {
                  $guids[] = (int) $curValue[1]['guid'];
                } else {
                  foreach ($curValue[1] as $curEntity) {
                    if (is_object($curEntity)) {
                      $guids[] = (int) $curEntity->guid;
                    } elseif (is_array($curEntity)) {
                      $guids[] = (int) $curEntity['guid'];
                    } else {
                      $guids[] = (int) $curEntity;
                    }
                  }
                }
              } elseif (is_object($curValue[1])) {
                $guids[] = (int) $curValue[1]->guid;
              } else {
                $guids[] = (int) $curValue[1];
              }
              foreach ($guids as $curQguid) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'references'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).'\' AND "reference"='.
                  SQLite3::escapeString((int) $curQguid).')';
              }
              break;
            case 'strict':
            case '!strict':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."cdate"='.((float) $curValue[1]);
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."mdate"='.((float) $curValue[1]);
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                if (is_callable([$curValue[1], 'toReference'])) {
                  $svalue = serialize($curValue[1]->toReference());
                } else {
                  $svalue = serialize($curValue[1]);
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data'.
                  $etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "value"=\''.
                  SQLite3::escapeString(
                    strpos($svalue, "\0") !== false
                      ? '~'.addcslashes($svalue, chr(0).'\\')
                      : $svalue
                  ).'\')';
              }
              break;
            case 'like':
            case '!like':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" LIKE \''.
                  SQLite3::escapeString($curValue[1]).
                  '\' ESCAPE \'\\\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" LIKE \''.
                  SQLite3::escapeString($curValue[1]).
                  '\' ESCAPE \'\\\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "string" LIKE \''.
                  SQLite3::escapeString($curValue[1]).
                  '\' ESCAPE \'\\\')';
              }
              break;
            case 'ilike':
            case '!ilike':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" LIKE \''.
                  SQLite3::escapeString($curValue[1]).
                  '\' ESCAPE \'\\\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" LIKE \''.
                  SQLite3::escapeString($curValue[1]).
                  '\' ESCAPE \'\\\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND lower("string") LIKE lower(\''.
                  SQLite3::escapeString($curValue[1]).
                  '\') ESCAPE \'\\\')';
              }
              break;
            case 'pmatch':
            case '!pmatch':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" REGEXP \''.
                  SQLite3::escapeString($curValue[1]).'\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" REGEXP \''.
                  SQLite3::escapeString($curValue[1]).'\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "string" REGEXP \''.
                  SQLite3::escapeString($curValue[1]).'\')';
              }
              break;
            case 'ipmatch':
            case '!ipmatch':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" REGEXP \''.
                  SQLite3::escapeString($curValue[1]).'\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" REGEXP \''.
                  SQLite3::escapeString($curValue[1]).'\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND lower("string") REGEXP lower(\''.
                  SQLite3::escapeString($curValue[1]).'\'))';
              }
              break;
            case 'match':
            case '!match':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'preg_match(\''.SQLite3::escapeString($curValue[1]).
                  '\', ie."cdate")';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'preg_match(\''.SQLite3::escapeString($curValue[1]).
                  '\', ie."mdate")';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.
                  '" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "string" IS NOT NULL AND '.
                  'preg_match(\''.SQLite3::escapeString($curValue[1]).
                  '\', "string"))';
              }
              break;
            case 'gt':
            case '!gt':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."cdate">'.((float) $curValue[1]);
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."mdate">'.((float) $curValue[1]);
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).'\' AND '.
                  '(("is_int"=\'1\' AND "int" IS NOT NULL AND "int" > '.
                  ((int) $curValue[1]).') OR ('.
                  'NOT "is_int"=\'1\' AND "float" IS NOT NULL AND "float" > '.
                  ((float) $curValue[1]).')))';
              }
              break;
            case 'gte':
            case '!gte':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."cdate">='.((float) $curValue[1]);
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."mdate">='.((float) $curValue[1]);
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).'\' AND '.
                  '(("is_int"=\'1\' AND "int" IS NOT NULL AND "int" >= '.
                  ((int) $curValue[1]).') OR ('.
                  'NOT "is_int"=\'1\' AND "float" IS NOT NULL AND "float" >= '.
                  ((float) $curValue[1]).')))';
              }
              break;
            case 'lt':
            case '!lt':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."cdate"<'.((float) $curValue[1]);
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."mdate"<'.((float) $curValue[1]);
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).'\' AND '.
                  '(("is_int"=\'1\' AND "int" IS NOT NULL AND "int" < '.
                  ((int) $curValue[1]).') OR ('.
                  'NOT "is_int"=\'1\' AND "float" IS NOT NULL AND "float" < '.
                  ((float) $curValue[1]).')))';
              }
              break;
            case 'lte':
            case '!lte':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."cdate"<='.((float) $curValue[1]);
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."mdate"<='.((float) $curValue[1]);
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).'\' AND '.
                  '(("is_int"=\'1\' AND "int" IS NOT NULL AND "int" <= '.
                  ((int) $curValue[1]).') OR ('.
                  'NOT "is_int"=\'1\' AND "float" IS NOT NULL AND "float" <= '.
                  ((float) $curValue[1]).')))';
              }
              break;
            // Cases after this point contains special values where
            // it can be solved by the query, but if those values
            // don't match, just check the variable exists.
            case 'equal':
            case '!equal':
            case 'data':
            case '!data':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."cdate"='.((float) $curValue[1]);
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."mdate"='.((float) $curValue[1]);
                break;
              } elseif ($curValue[1] === true || $curValue[1] === false) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "eq_true"='.
                  ($curValue[1] ? '1' : '0').')';
                break;
              } elseif ($curValue[1] === 1) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "eq_one"=1)';
                break;
              } elseif ($curValue[1] === 0) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "eq_zero"=1)';
                break;
              } elseif ($curValue[1] === -1) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "eq_negone"=1)';
                break;
              } elseif ($curValue[1] === []) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).
                  '\' AND "eq_emptyarray"=1)';
                break;
              }
              // Fall through.
            case 'array':
            case '!array':
              if (!($typeIsNot xor $clauseNot)) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= 'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'data'.$etype.'" WHERE "name"=\''.
                  SQLite3::escapeString($curValue[0]).'\')';
              }
              $fullQueryCoverage = false;
              break;
          }
        }
      }
    );

    switch ($sort) {
      case 'guid':
        $sort = '"guid"';
        break;
      case 'mdate':
        $sort = '"mdate"';
        break;
      case 'cdate':
      default:
        $sort = '"cdate"';
        break;
    }
    if (isset($options['reverse']) && $options['reverse']) {
      $sort .= ' DESC';
    }

    if ($queryParts) {
      if ($subquery) {
        $query = "((".implode(') AND (', $queryParts)."))";
      } else {
        $limit = "";
        if ($fullQueryCoverage && key_exists('limit', $options)) {
          $limit = " LIMIT ".((int) $options['limit']);
        }
        $offset = "";
        if ($fullQueryCoverage && key_exists('offset', $options)) {
          $offset = " OFFSET ".((int) $options['offset']);
        }
        $whereClause = implode(') AND (', $queryParts);
        $query = (
          "SELECT e.\"guid\", e.\"tags\", e.\"cdate\", e.\"mdate\", d.\"name\", d.\"value\"
          FROM \"{$this->prefix}entities{$etype}\" e
          LEFT JOIN \"{$this->prefix}data{$etype}\" d USING (\"guid\")
          INNER JOIN (
            SELECT \"guid\"
            FROM \"{$this->prefix}entities{$etype}\" ie
            WHERE ({$whereClause})
            ORDER BY ie.{$sort}{$limit}{$offset}
          ) f USING (\"guid\")
          ORDER BY {$sort};"
        );
      }
    } else {
      if ($subquery) {
        $query = '';
      } else {
        $limit = "";
        if (key_exists('limit', $options)) {
          $limit = " LIMIT ".((int) $options['limit']);
        }
        $offset = "";
        if (key_exists('offset', $options)) {
          $offset = " OFFSET ".((int) $options['offset']);
        }
        if ($limit || $offset) {
          $query = (
            "SELECT e.\"guid\", e.\"tags\", e.\"cdate\", e.\"mdate\", d.\"name\", d.\"value\"
            FROM \"{$this->prefix}entities{$etype}\" e
            LEFT JOIN \"{$this->prefix}data{$etype}\" d USING (\"guid\")
            INNER JOIN (
              SELECT \"guid\"
              FROM \"{$this->prefix}entities{$etype}\" ie
              ORDER BY ie.{$sort}{$limit}{$offset}
            ) f USING (\"guid\")
            ORDER BY {$sort};"
          );
        } else {
          $query = (
            "SELECT e.\"guid\", e.\"tags\", e.\"cdate\", e.\"mdate\", d.\"name\", d.\"value\"
            FROM \"{$this->prefix}entities{$etype}\" e
            LEFT JOIN \"{$this->prefix}data{$etype}\" d USING (\"guid\")
            ORDER BY {$sort};"
          );
        }
      }
    }

    return [
      'fullCoverage' => $fullQueryCoverage,
      'limitOffsetCoverage' => $fullQueryCoverage,
      'query' => $query
    ];
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
        'match', '!match',
        'pmatch', '!pmatch',
        'ipmatch', '!ipmatch',
        'gt', '!gt',
        'gte', '!gte',
        'lt', '!lt',
        'lte', '!lte'
      ],
      [true, false, 1, 0, -1, []],
      function (&$result) {
        return $result->fetchArray(SQLITE3_NUM);
      },
      function (&$result) {
        $result->finalize();
      },
      function ($row) {
        return (int) $row[0];
      },
      function ($row) {
        return [
          'tags' => (strlen($row[1]) > 2
            ? explode(',', substr($row[1], 1, -1))
            : []),
          'cdate' => (float) $row[2],
          'mdate' => (float) $row[3]
        ];
      },
      function ($row) {
        return [
          'name' => $row[4],
          'svalue' => ($row[5][0] === '~'
            ? stripcslashes(substr($row[5], 1))
            : $row[5])
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
    $result = $this->query("SELECT \"cur_uid\" FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
    $row = $result->fetchArray(SQLITE3_NUM);
    $result->finalize();
    return isset($row[0]) ? (int) $row[0] : null;
  }

  public function import($filename) {
    $this->checkReadOnlyMode();
    return $this->importFromFile(
      $filename,
      function ($guid, $tags, $data, $etype) {
        $this->query("DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
        $this->query("DELETE FROM \"{$this->prefix}entities_{$etype}\" WHERE \"guid\"={$guid};");
        $this->query("DELETE FROM \"{$this->prefix}data_{$etype}\" WHERE \"guid\"={$guid};", $etype);
        $this->query("DELETE FROM \"{$this->prefix}comparisons_{$etype}\" WHERE \"guid\"={$guid};", $etype);
        $this->query("DELETE FROM \"{$this->prefix}references_{$etype}\" WHERE \"guid\"={$guid};", $etype);
        $this->query("INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$guid});");
        $this->query("INSERT INTO \"{$this->prefix}entities_{$etype}\" (\"guid\", \"tags\", \"cdate\", \"mdate\") VALUES ({$guid}, '".SQLite3::escapeString(','.implode(',', $tags).',')."', ".unserialize($data['cdate']).", ".unserialize($data['mdate']).");", $etype);
        unset($data['cdate'], $data['mdate']);
        if ($data) {
          foreach ($data as $name => $value) {
            $this->query(
              "INSERT INTO \"{$this->prefix}data_{$etype}\" (\"guid\", \"name\", \"value\") VALUES ".
                $this->makeInsertValuesData($guid, $name, $value).';',
              $etype
            );
            $this->query(
              "INSERT INTO \"{$this->prefix}comparisons_{$etype}\" (\"guid\", \"name\", \"eq_true\", \"eq_one\", \"eq_zero\", \"eq_negone\", \"eq_emptyarray\", \"string\", \"int\", \"float\", \"is_int\") VALUES ".
                $this->makeInsertValuesComparisons($guid, $name, unserialize($value)).';',
              $etype
            );
            $references = $this->makeInsertValuesReferences($guid, $name, $value);
            if ($references) {
              $this->query(
                "INSERT INTO \"{$this->prefix}references_{$etype}\" (\"guid\", \"name\", \"reference\") VALUES {$references};",
                $etype
              );
            }
          }
        }
      },
      function ($name, $curUid) {
        $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
        $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".SQLite3::escapeString($name)."', ".((int) $curUid).");");
      },
      function () {
        $this->query("SAVEPOINT 'import';");
      },
      function () {
        $this->query("RELEASE 'import';");
      }
    );
  }

  public function newUID($name) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
        'Name not given for UID.'
      );
    }
    $this->checkReadOnlyMode();
    $this->query("SAVEPOINT 'newuid';");
    $result = $this->query("SELECT \"cur_uid\" FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
    $row = $result->fetchArray(SQLITE3_NUM);
    $curUid = ($row && is_numeric($row[0])) ? (int) $row[0] : null;
    $result->finalize();
    if (!is_int($curUid)) {
      $curUid = 1;
      $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".SQLite3::escapeString($name)."', {$curUid});");
    } else {
      $curUid++;
      $this->query("UPDATE \"{$this->prefix}uids\" SET \"cur_uid\"={$curUid} WHERE \"name\"='".SQLite3::escapeString($name)."';");
    }
    $this->query("RELEASE 'newuid';");
    return $curUid;
  }

  public function renameUID($oldName, $newName) {
    if (!$oldName || !$newName) {
      throw new Exceptions\InvalidParametersException(
        'Name not given for UID.'
      );
    }
    $this->checkReadOnlyMode();
    $this->query("UPDATE \"{$this->prefix}uids\" SET \"name\"='".SQLite3::escapeString($newName)."' WHERE \"name\"='".SQLite3::escapeString($oldName)."';");
    return true;
  }

  public function saveEntity(&$entity) {
    $this->checkReadOnlyMode();
    $insertData = function ($guid, $data, $sdata, $etype, $etypeDirty) {
      $runInsertQuery = function ($name, $value, $svalue) use ($guid, $etype, $etypeDirty) {
        $this->query(
          "INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\") VALUES ".
            $this->makeInsertValuesData($guid, $name, serialize($value)).';',
          $etypeDirty
        );
        $this->query(
          "INSERT INTO \"{$this->prefix}comparisons{$etype}\" (\"guid\", \"name\", \"eq_true\", \"eq_one\", \"eq_zero\", \"eq_negone\", \"eq_emptyarray\", \"string\", \"int\", \"float\", \"is_int\") VALUES ".
            $this->makeInsertValuesComparisons($guid, $name, $value).';',
          $etypeDirty
        );
        $referenceValues = $this->makeInsertValuesReferences($guid, $name, serialize($value));
        if ($referenceValues) {
          $this->query(
            "INSERT INTO \"{$this->prefix}references{$etype}\" (\"guid\", \"name\", \"reference\") VALUES {$referenceValues};",
            $etypeDirty
          );
        }
      };
      foreach ($data as $name => $value) {
        $runInsertQuery($name, $value, serialize($value));
      }
      foreach ($sdata as $name => $svalue) {
        $runInsertQuery($name, unserialize($svalue), $svalue);
      }
    };
    return $this->saveEntityRowLike(
      $entity,
      function ($etypeDirty) {
        return '_'.SQLite3::escapeString($etypeDirty);
      },
      function ($guid) {
        $result = $this->query("SELECT \"guid\" FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
        $row = $result->fetchArray(SQLITE3_NUM);
        $result->finalize();
        return !isset($row[0]);
      },
      function ($entity, $guid, $tags, $data, $sdata, $cdate, $etype, $etypeDirty) use ($insertData) {
        $this->query("INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$guid});");
        $this->query("INSERT INTO \"{$this->prefix}entities{$etype}\" (\"guid\", \"tags\", \"cdate\", \"mdate\") VALUES ({$guid}, '".SQLite3::escapeString(','.implode(',', $tags).',')."', ".((float) $cdate).", ".((float) $cdate).");", $etypeDirty);
        $insertData($guid, $data, $sdata, $etype, $etypeDirty);
        return true;
      },
      function ($entity, $guid, $tags, $data, $sdata, $mdate, $etype, $etypeDirty) use ($insertData) {
        $this->query("UPDATE \"{$this->prefix}entities{$etype}\" SET \"tags\"='".SQLite3::escapeString(','.implode(',', $tags).',')."', \"mdate\"=".((float) $mdate)." WHERE \"guid\"={$guid} AND abs(\"mdate\" - ".((float) $entity->mdate).") < 0.001;", $etypeDirty);
        $changed = $this->link->changes();
        $success = false;
        if ($changed === 1) {
          $this->query("DELETE FROM \"{$this->prefix}data{$etype}\" WHERE \"guid\"={$guid};");
          $this->query("DELETE FROM \"{$this->prefix}comparisons{$etype}\" WHERE \"guid\"={$guid};");
          $this->query("DELETE FROM \"{$this->prefix}references{$etype}\" WHERE \"guid\"={$guid};");
          $insertData($guid, $data, $sdata, $etype, $etypeDirty);
          $success = true;
        }
        return $success;
      },
      function () {
        $this->query("SAVEPOINT 'save';");
      },
      function ($success) {
        if ($success) {
          $this->query("RELEASE 'save';");
        } else {
          $this->query("ROLLBACK TO 'save';");
        }
      }
    );
  }

  public function setUID($name, $value) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
        'Name not given for UID.'
      );
    }
    $this->checkReadOnlyMode();
    $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
    $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".SQLite3::escapeString($name)."', ".((int) $value).");");
    return true;
  }

  private function makeInsertValuesData($guid, $name, $svalue) {
    return sprintf(
      "(%u, '%s', '%s')",
      (int) $guid,
      SQLite3::escapeString($name),
      SQLite3::escapeString(
        strpos($svalue, "\0") !== false
          ? '~'.addcslashes($svalue, chr(0).'\\')
          : $svalue
      )
    );
  }

  private function makeInsertValuesComparisons($guid, $name, $uvalue) {
    return sprintf(
      "(%u, '%s', %s, %s, %s, %s, %s, %s, %d, %f, %s)",
      (int) $guid,
      SQLite3::escapeString($name),
      $uvalue == true ? '1' : '0',
      (!is_object($uvalue) && $uvalue == 1) ? '1' : '0',
      (!is_object($uvalue) && $uvalue == 0) ? '1' : '0',
      (!is_object($uvalue) && $uvalue == -1) ? '1' : '0',
      $uvalue == [] ? '1' : '0',
      is_string($uvalue)
        ? '\''.SQLite3::escapeString($uvalue).'\''
        : 'NULL',
      is_object($uvalue) ? 1 : ((int) $uvalue),
      is_object($uvalue) ? 1 : ((float) $uvalue),
      is_int($uvalue) ? '1' : '0'
    );
  }

  private function makeInsertValuesReferences($guid, $name, $svalue) {
    preg_match_all(
      '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
      $svalue,
      $references,
      PREG_PATTERN_ORDER
    );
    $values = [];
    foreach ($references[1] as $curRef) {
      $values[] = sprintf(
        "(%u, '%s', %u)",
        (int) $guid,
        SQLite3::escapeString($name),
        (int) $curRef
      );
    }
    return implode(',', $values);
  }
}
