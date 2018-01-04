<?php namespace Nymph\Drivers;

use Nymph\Exceptions;
use SQLite3;

/**
 * SQLite3 based Nymph driver.
 *
 * @package Nymph
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
class SQLite3Driver implements DriverInterface {
  use DriverTrait {
    DriverTrait::__construct as private __traitConstruct;
  }
  /**
   * The SQLite3 database connection for this instance.
   *
   * @access private
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
          'SQLite3 PHP extension is not available. It probably has not ' .
          'been installed. Please install and configure it in order to use ' .
          'SQLite3.'
      );
    }
    $filename = $this->config['SQLite3']['filename'];
    $busy_timeout = $this->config['SQLite3']['busy_timeout'];
    $open_flags = $this->config['SQLite3']['open_flags'];
    $encryption_key = $this->config['SQLite3']['encryption_key'];
    // Connecting
    if (!$this->connected) {
      $this->link = new SQLite3($filename, $open_flags, $encryption_key);
      if ($this->link) {
        $this->connected = true;
        $this->link->busyTimeout($busy_timeout);
        // Set database and connection options.
        $this->link->exec("PRAGMA encoding = \"UTF-8\";");
        $this->link->exec("PRAGMA foreign_keys = 1;");
        $this->link->exec("PRAGMA case_sensitive_like = 1;");
        // Create the preg_match and regexp functions.
        // TODO(hperrin): Add more of these functions to get rid of post-query checks.
        $this->link->createFunction('preg_match', 'preg_match', 2, SQLITE3_DETERMINISTIC);
        $this->link->createFunction('regexp', function($pattern, $subject) {
          return !!preg_match(
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
                  $pattern
              ) . '~',
              $subject
          );
        }, 2, SQLITE3_DETERMINISTIC);
      } else {
        $this->connected = false;
        if ($filename == ':memory:') {
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
   * Create entity tables in the database.
   *
   * @param string $etype The entity type to create a table for. If this is
   *                      blank, the default tables are created.
   */
  private function createTables($etype = null) {
    $this->query("SAVEPOINT 'tablecreation';");
    try {
      if (isset($etype)) {
        $etype = '_'.SQLite3::escapeString($etype);

        // Create the entity table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}entities{$etype}\" (\"guid\" INTEGER PRIMARY KEY ASC NOT NULL REFERENCES \"{$this->prefix}guids\"(\"guid\") ON DELETE CASCADE, \"tags\" TEXT, \"varlist\" TEXT, \"cdate\" REAL NOT NULL, \"mdate\" REAL NOT NULL);");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}entities{$etype}_id_cdate\" ON \"{$this->prefix}entities{$etype}\" (\"cdate\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}entities{$etype}_id_mdate\" ON \"{$this->prefix}entities{$etype}\" (\"mdate\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}entities{$etype}_id_tags\" ON \"{$this->prefix}entities{$etype}\" (\"tags\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}entities{$etype}_id_varlist\" ON \"{$this->prefix}entities{$etype}\" (\"varlist\");");
        // Create the data table.
        $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}data{$etype}\" (\"guid\" INTEGER NOT NULL REFERENCES \"{$this->prefix}entities{$etype}\"(\"guid\") ON DELETE CASCADE, \"name\" TEXT NOT NULL, \"value\" TEXT NOT NULL, \"references\" TEXT, \"compare_true\" INTEGER, \"compare_one\" INTEGER, \"compare_zero\" INTEGER, \"compare_negone\" INTEGER, \"compare_emptyarray\" INTEGER, \"compare_string\" TEXT, \"compare_int\" INTEGER, \"compare_float\" REAL, PRIMARY KEY(\"guid\", \"name\"));");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_guid\" ON \"{$this->prefix}data{$etype}\" (\"guid\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_name\" ON \"{$this->prefix}data{$etype}\" (\"name\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_references\" ON \"{$this->prefix}data{$etype}\" (\"references\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_name__compare_true\" ON \"{$this->prefix}data{$etype}\" (\"name\") WHERE \"compare_true\" = 1;");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_name__not_compare_true\" ON \"{$this->prefix}data{$etype}\" (\"name\") WHERE \"compare_true\" = 0;");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_value\" ON \"{$this->prefix}data{$etype}\" (\"value\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_compare_int\" ON \"{$this->prefix}data{$etype}\" (\"compare_int\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_compare_float\" ON \"{$this->prefix}data{$etype}\" (\"compare_float\");");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_guid__name_user\" ON \"{$this->prefix}data{$etype}\" (\"guid\") WHERE \"name\" = 'user';");
        $this->query("CREATE INDEX IF NOT EXISTS \"{$this->prefix}data{$etype}_id_guid__name_group\" ON \"{$this->prefix}data{$etype}\" (\"guid\") WHERE \"name\" = 'group';");
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
            'Query failed: ' . $this->link->lastErrorCode() . ' - '
              . $this->link->lastErrorMsg(),
            0,
            null,
            $query
        );
      }
    } catch (\Exception $e) {
      $errorCode = $this->link->lastErrorCode();
      $errorMsg = $this->link->lastErrorMsg();
      if ($errorCode === 1 && preg_match('/^no such table: /', $errorMsg) && $this->createTables()) {
        if (isset($etypeDirty)) {
          $this->createTables($etypeDirty);
        }
        if (!($result = $this->link->query($query))) {
          throw new Exceptions\QueryFailedException(
              'Query failed: ' . $this->link->lastErrorCode() . ' - '
                . $this->link->lastErrorMsg(),
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

  public function deleteEntityByID($guid, $etypeDirty = null) {
    $guid = (int) $guid;
    $etype = isset($etypeDirty) ? '_'.SQLite3::escapeString($etypeDirty) : '';
    $this->query("SAVEPOINT 'deleteentity';");
    $this->query("DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
    $this->query("DELETE FROM \"{$this->prefix}entities{$etype}\" WHERE \"guid\"={$guid};", $etypeDirty);
    $this->query("DELETE FROM \"{$this->prefix}data{$etype}\" WHERE \"guid\"={$guid};", $etypeDirty);
    $this->query("RELEASE 'deleteentity';");
    // Removed any cached versions of this entity.
    if ($this->config['cache']) {
      $this->cleanCache($guid);
    }
    return true;
  }

  public function deleteUID($name) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException('Name not given for UID');
    }
    $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
    return true;
  }

  public function export($filename) {
    if (!$fhandle = fopen($filename, 'w')) {
      throw new Exceptions\InvalidParametersException(
          'Provided filename is not writeable.'
      );
    }
    fwrite($fhandle, "# Nymph Entity Exchange\n");
    fwrite($fhandle, "# Nymph Version ".\Nymph\Nymph::VERSION."\n");
    fwrite($fhandle, "# nymph.io\n");
    fwrite($fhandle, "#\n");
    fwrite($fhandle, "# Generation Time: ".date('r')."\n");

    fwrite($fhandle, "#\n");
    fwrite($fhandle, "# UIDs\n");
    fwrite($fhandle, "#\n\n");

    // Export UIDs.
    $result = $this->query("SELECT * FROM \"{$this->prefix}uids\" ORDER BY \"name\";");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    while ($row) {
      $row['name'];
      $row['cur_uid'];
      fwrite($fhandle, "<{$row['name']}>[{$row['cur_uid']}]\n");
      // Make sure that $row is incremented :)
      $row = $result->fetchArray(SQLITE3_ASSOC);
    }
    $result->finalize();

    fwrite($fhandle, "\n#\n");
    fwrite($fhandle, "# Entities\n");
    fwrite($fhandle, "#\n\n");

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
        fwrite($fhandle, "{{$guid}}<{$etype}>[".implode(',', $tags)."]\n");
        fwrite($fhandle, "\tcdate=".json_encode(serialize($cdate))."\n");
        fwrite($fhandle, "\tmdate=".json_encode(serialize($mdate))."\n");
        if (isset($row['dname'])) {
          // This do will keep going and adding the data until the
          // next entity is reached. $row will end on the next entity.
          do {
            fwrite(
                $fhandle,
                "\t{$row['dname']}=".json_encode($row['dvalue'])."\n"
            );
            $row = $result->fetchArray(SQLITE3_ASSOC);
          } while ((int) $row['guid'] === $guid);
        } else {
          // Make sure that $row is incremented :)
          $row = $result->fetchArray(SQLITE3_ASSOC);
        }
      }
      $result->finalize();
    }
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
    echo "# Nymph Entity Exchange\n";
    echo "# Nymph Version ".\Nymph\Nymph::VERSION."\n";
    echo "# nymph.io\n";
    echo "#\n";
    echo "# Generation Time: ".date('r')."\n";

    echo "#\n";
    echo "# UIDs\n";
    echo "#\n\n";

    // Export UIDs.
    $result = $this->query("SELECT * FROM \"{$this->prefix}uids\" ORDER BY \"name\";");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    while ($row) {
      $row['name'];
      $row['cur_uid'];
      echo "<{$row['name']}>[{$row['cur_uid']}]\n";
      // Make sure that $row is incremented :)
      $row = $result->fetchArray(SQLITE3_ASSOC);
    }
    $result->finalize();

    echo "\n#\n";
    echo "# Entities\n";
    echo "#\n\n";

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
        echo "{{$guid}}<{$etype}>[".implode(',', $tags)."]\n";
        echo "\tcdate=".json_encode(serialize($cdate))."\n";
        echo "\tmdate=".json_encode(serialize($mdate))."\n";
        if (isset($row['dname'])) {
          // This do will keep going and adding the data until the
          // next entity is reached. $row will end on the next entity.
          do {
            echo "\t{$row['dname']}=".json_encode(($row['dvalue'][0] === '~'
                ? stripcslashes(substr($row['dvalue'], 1))
                : $row['dvalue']))."\n";
            $row = $result->fetchArray(SQLITE3_ASSOC);
          } while ((int) $row['guid'] === $guid);
        } else {
          // Make sure that $row is incremented :)
          $row = $result->fetchArray(SQLITE3_ASSOC);
        }
      }
      $result->finalize();
    }
    return true;
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
    $sort = $options['sort'] ?? 'cdate';
    $etype = '_'.SQLite3::escapeString($etypeDirty);
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
          $cur_query .=
              $this->makeEntityQuery(
                  $options,
                  [$value],
                  $etypeDirty,
                  true
              );
        } else {
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
                      'e."guid"='.(int) $cur_guid;
                }
                break;
              case 'tag':
              case '!tag':
                foreach ($cur_value as $cur_tag) {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."tags" LIKE \'%,' .
                      str_replace(
                          ['%', '_', ':'],
                          [':%', ':_', '::'],
                          SQLite3::escapeString($cur_tag)
                      ) . ',%\' ESCAPE \':\'';
                }
                break;
              case 'isset':
              case '!isset':
                foreach ($cur_value as $cur_var) {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= '(' .
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."varlist" LIKE \'%,' .
                      str_replace(
                          ['%', '_', ':'],
                          [':%', ':_', '::'],
                          SQLite3::escapeString($cur_var)
                      ) . ',%\' ESCAPE \':\'';
                  if ($type_is_not xor $clause_not) {
                    $cur_query .= ' OR e."guid" IN (SELECT "guid" FROM "' .
                        $this->prefix.'data'.$etype.'" WHERE "name"=\'' .
                        SQLite3::escapeString($cur_var) .
                        '\' AND "value"=\'N;\')';
                  }
                  $cur_query .= ')';
                }
                break;
              case 'ref':
              case '!ref':
                $guids = [];
                if ((array) $cur_value[1] === $cur_value[1]) {
                  foreach ($cur_value[1] as $cur_entity) {
                    if ((object) $cur_entity === $cur_entity) {
                      $guids[] = (int) $cur_entity->guid;
                    } elseif ((array) $cur_entity === $cur_entity) {
                      $guids[] = (int) $cur_entity['guid'];
                    } else {
                      $guids[] = (int) $cur_entity;
                    }
                  }
                } elseif ((object) $cur_value[1] === $cur_value[1]) {
                  $guids[] = (int) $cur_value[1]->guid;
                } elseif ((array) $cur_value[1] === $cur_value[1]) {
                  $guids[] = (int) $cur_value[1]['guid'];
                } else {
                  $guids[] = (int) $cur_value[1];
                }
                if ($guids) {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]).'\' AND (';
                  $cur_query .= '"references" LIKE \'%,';
                  $cur_query .=
                      implode(
                          ',%\' ESCAPE \':\'' .
                              ($type_is_or ? ' OR ' : ' AND ') . '"references" LIKE \'%,',
                          $guids
                      );
                  $cur_query .= ',%\' ESCAPE \':\'';
                  $cur_query .= '))';
                }
                break;
              case 'strict':
              case '!strict':
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
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  if (is_callable([$cur_value[1], 'toReference'])) {
                    $svalue = serialize($cur_value[1]->toReference());
                  } else {
                    $svalue = serialize($cur_value[1]);
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "value"=\'' .
                      SQLite3::escapeString(
                          (
                            strpos($svalue, "\0") !== false
                            ? '~'.addcslashes($svalue, chr(0).'\\')
                            : $svalue
                          )
                      ).'\')';
                }
                break;
              case 'like':
              case '!like':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."cdate" LIKE \'' .
                      SQLite3::escapeString($cur_value[1]) .
                      '\' ESCAPE \'\\\')';
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."mdate" LIKE \'' .
                      SQLite3::escapeString($cur_value[1]) .
                      '\' ESCAPE \'\\\')';
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_string" LIKE \'' .
                      SQLite3::escapeString($cur_value[1]) .
                      '\' ESCAPE \'\\\')';
                }
                break;
              case 'ilike':
              case '!ilike':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."cdate" LIKE \'' .
                      SQLite3::escapeString($cur_value[1]) .
                      '\' ESCAPE \'\\\')';
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."mdate" LIKE \'' .
                      SQLite3::escapeString($cur_value[1]) .
                      '\' ESCAPE \'\\\')';
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND lower("compare_string") LIKE lower(\'' .
                      SQLite3::escapeString($cur_value[1]) .
                      '\') ESCAPE \'\\\')';
                }
                break;
              case 'pmatch':
              case '!pmatch':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."cdate" REGEXP \'' .
                      SQLite3::escapeString($cur_value[1]).'\')';
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."mdate" REGEXP \'' .
                      SQLite3::escapeString($cur_value[1]).'\')';
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_string" REGEXP \'' .
                      SQLite3::escapeString($cur_value[1]).'\')';
                }
                break;
              case 'ipmatch':
              case '!ipmatch':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."cdate" REGEXP \'' .
                      SQLite3::escapeString($cur_value[1]).'\')';
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      '(e."mdate" REGEXP \'' .
                      SQLite3::escapeString($cur_value[1]).'\')';
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND lower("compare_string") REGEXP lower(\'' .
                      SQLite3::escapeString($cur_value[1]).'\'))';
                }
                break;
              case 'match':
              case '!match':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .=
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'preg_match(\'' . SQLite3::escapeString($cur_value[1]) .
                      '\', e."cdate")';
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .=
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'preg_match(\'' . SQLite3::escapeString($cur_value[1]) .
                      '\', e."mdate")';
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .=
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "' .
                      $this->prefix.'data'.$etype .
                      '" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_string" IS NOT NULL AND ' .
                      'preg_match(\'' . SQLite3::escapeString($cur_value[1]) .
                      '\', "compare_string"))';
                }
                break;
              case 'gt':
              case '!gt':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."cdate">'.((float) $cur_value[1]);
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."mdate">'.((float) $cur_value[1]);
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .=
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "' .
                      $this->prefix.'data'.$etype .
                      '" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) . '\' AND ' .
                      '(("compare_int" IS NOT NULL AND "compare_int" > ' .
                      ((int) $cur_value[1]) .
                      ' AND substr("value", 0, 1)=\'i\') OR ' .
                      '("compare_float" IS NOT NULL AND "compare_float" > ' .
                      ((float) $cur_value[1]) .
                      ' AND NOT substr("value", 0, 1)=\'i\')))';
                }
                break;
              case 'gte':
              case '!gte':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."cdate">='.((float) $cur_value[1]);
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."mdate">='.((float) $cur_value[1]);
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .=
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "' .
                      $this->prefix.'data'.$etype .
                      '" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) . '\' AND ' .
                      '(("compare_int" IS NOT NULL AND "compare_int" >= ' .
                      ((int) $cur_value[1]) .
                      ' AND substr("value", 0, 1)=\'i\') OR ' .
                      '("compare_float" IS NOT NULL AND "compare_float" >= ' .
                      ((float) $cur_value[1]) .
                      ' AND NOT substr("value", 0, 1)=\'i\')))';
                }
                break;
              case 'lt':
              case '!lt':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."cdate"<'.((float) $cur_value[1]);
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."mdate"<'.((float) $cur_value[1]);
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .=
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "' .
                      $this->prefix.'data'.$etype .
                      '" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) . '\' AND ' .
                      '(("compare_int" IS NOT NULL AND "compare_int" < ' .
                      ((int) $cur_value[1]) .
                      ' AND substr("value", 0, 1)=\'i\') OR ' .
                      '("compare_float" IS NOT NULL AND "compare_float" < ' .
                      ((float) $cur_value[1]) .
                      ' AND NOT substr("value", 0, 1)=\'i\')))';
                }
                break;
              case 'lte':
              case '!lte':
                if ($cur_value[0] == 'cdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."cdate"<='.((float) $cur_value[1]);
                  break;
                } elseif ($cur_value[0] == 'mdate') {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."mdate"<='.((float) $cur_value[1]);
                  break;
                } else {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .=
                      (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "' .
                      $this->prefix.'data'.$etype .
                      '" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) . '\' AND ' .
                      '(("compare_int" IS NOT NULL AND "compare_int" <= ' .
                      ((int) $cur_value[1]) .
                      ' AND substr("value", 0, 1)=\'i\') OR ' .
                      '("compare_float" IS NOT NULL AND "compare_float" <= ' .
                      ((float) $cur_value[1]) .
                      ' AND NOT substr("value", 0, 1)=\'i\')))';
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
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_true"=' .
                      ($cur_value[1] ? '1' : '0').')';
                  break;
                } elseif ($cur_value[1] === 1) {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_one"=1)';
                  break;
                } elseif ($cur_value[1] === 0) {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_zero"=1)';
                  break;
                } elseif ($cur_value[1] === -1) {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_negone"=1)';
                  break;
                } elseif ($cur_value[1] === []) {
                  if ($cur_query) {
                    $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
                  }
                  $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                      'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                      $etype.'" WHERE "name"=\'' .
                      SQLite3::escapeString($cur_value[0]) .
                      '\' AND "compare_emptyarray"=1)';
                  break;
                }
              case 'array':
              case '!array':
                if (!($type_is_not xor $clause_not)) {
                  if ($cur_query) {
                    $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                  }
                  $cur_query .= 'e."varlist" LIKE \'%,' .
                      str_replace(
                          ['%', '_', ':'],
                          [':%', ':_', '::'],
                          SQLite3::escapeString($cur_value[0])
                      ) . ',%\' ESCAPE \':\'';
                }
                break;
            }
          }
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

    switch ($sort) {
      case 'guid':
        $sort = 'e."guid"';
        break;
      case 'mdate':
        $sort = 'e."mdate"';
        break;
      case 'cdate':
      default:
        $sort = 'e."cdate"';
        break;
    }
    if ($query_parts) {
      if ($subquery) {
        $query = "((".implode(') AND (', $query_parts)."))";
      } else {
        $query = "SELECT e.\"guid\", e.\"tags\", e.\"cdate\", e.\"mdate\", d.\"name\", d.\"value\" FROM \"{$this->prefix}entities{$etype}\" e LEFT JOIN \"{$this->prefix}data{$etype}\" d USING (\"guid\") WHERE (".implode(') AND (', $query_parts).") ORDER BY ".(isset($options['reverse']) && $options['reverse'] ? $sort.' DESC' : $sort).";";
      }
    } else {
      if ($subquery) {
        $query = '';
      } else {
        $query = "SELECT e.\"guid\", e.\"tags\", e.\"cdate\", e.\"mdate\", d.\"name\", d.\"value\" FROM \"{$this->prefix}entities{$etype}\" e LEFT JOIN \"{$this->prefix}data{$etype}\" d USING (\"guid\") ORDER BY ".(isset($options['reverse']) && $options['reverse'] ? $sort.' DESC' : $sort).";";
      }
    }

    return $query;
  }

  public function getEntities($options = [], ...$selectors) {
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

    $typesAlreadyChecked =
        [
          'ref',
          '!ref',
          'guid',
          '!guid',
          'tag',
          '!tag',
          'isset',
          '!isset',
          'strict',
          '!strict',
          'like',
          '!like',
          'ilike',
          '!ilike',
          'match',
          '!match',
          'pmatch',
          '!pmatch',
          'ipmatch',
          '!ipmatch',
          'gt',
          '!gt',
          'gte',
          '!gte',
          'lt',
          '!lt',
          'lte',
          '!lte'
        ];
    $dataValsAreadyChecked = [true, false, 1, 0, -1, []];

    $row = $result->fetchArray(SQLITE3_NUM);
    while ($row) {
      $guid = (int) $row[0];
      $tags = $row[1];
      $data = ['cdate' => (float) $row[2], 'mdate' => (float) $row[3]];
      // Serialized data.
      $sdata = [];
      if (isset($row[4])) {
        // This do will keep going and adding the data until the
        // next entity is reached. $row will end on the next entity.
        do {
          $sdata[$row[4]] =
              ($row[5][0] === '~'
                  ? stripcslashes(substr($row[5], 1))
                  : $row[5]);
          $row = $result->fetchArray(SQLITE3_NUM);
        } while ((int) $row[0] === $guid);
      } else {
        // Make sure that $row is incremented :)
        $row = $result->fetchArray(SQLITE3_NUM);
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
              if (strlen($tags) > 2) {
                $entity->tags = explode(',', substr($tags, 1, -1));
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

    $result->finalize();

    return $entities;
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
    if (!$fhandle = fopen($filename, 'r')) {
      throw new Exceptions\InvalidParametersException(
          'Provided filename is unreadable.'
      );
    }
    $guid = null;
    $line = '';
    $data = [];
    $this->query("SAVEPOINT 'import';");
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
          $this->query("DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
          $this->query("DELETE FROM \"{$this->prefix}entities_{$etype}\" WHERE \"guid\"={$guid};");
          $this->query("DELETE FROM \"{$this->prefix}data_{$etype}\" WHERE \"guid\"={$guid};", $etype);
          $this->query("INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$guid});");
          $this->query("INSERT INTO \"{$this->prefix}entities_{$etype}\" (\"guid\", \"tags\", \"varlist\", \"cdate\", \"mdate\") VALUES ({$guid}, '".SQLite3::escapeString(','.$tags.',')."', '".SQLite3::escapeString(','.implode(',', array_keys($data)).',')."', ".unserialize($data['cdate']).", ".unserialize($data['mdate']).");", $etype);
          unset($data['cdate'], $data['mdate']);
          if ($data) {
            $query = "INSERT INTO \"{$this->prefix}data_{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES ";
            foreach ($data as $name => $value) {
              preg_match_all(
                  '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
                  $value,
                  $references,
                  PREG_PATTERN_ORDER
              );
              $uvalue = unserialize($value);
              $query .= sprintf(
                  "(%u, '%s', '%s', '%s', %s, %s, %s, %s, %s, %s, %d, %f),",
                  $guid,
                  SQLite3::escapeString($name),
                  SQLite3::escapeString(
                      (strpos($value, "\0") !== false
                          ? '~'.addcslashes($value, chr(0).'\\')
                          : $value)
                  ),
                  SQLite3::escapeString(','.implode(',', $references[1]).','),
                  $uvalue == true ? '1' : '0',
                  (!is_object($uvalue) && $uvalue == 1) ? '1' : '0',
                  (!is_object($uvalue) && $uvalue == 0) ? '1' : '0',
                  (!is_object($uvalue) && $uvalue == -1) ? '1' : '0',
                  $uvalue == [] ? '1' : '0',
                  is_string($uvalue)
                      ? '\''.SQLite3::escapeString($uvalue).'\''
                      : 'NULL',
                  is_object($uvalue) ? 1 : ((int) $uvalue),
                  is_object($uvalue) ? 1 : ((float) $uvalue)
              );
            }
            $query = substr($query, 0, -1).';';
            $this->query($query, $etype);
          }
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
        $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($matches[1])."';");
        $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".SQLite3::escapeString($matches[1])."', ".((int) $matches[2]).");");
      }
      $line = '';
      // Clear the entity cache.
      $this->entityCache = [];
    }
    // Save the last entity.
    if ($guid) {
      $this->query("DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
      $this->query("DELETE FROM \"{$this->prefix}entities_{$etype}\" WHERE \"guid\"={$guid};");
      $this->query("DELETE FROM \"{$this->prefix}data_{$etype}\" WHERE \"guid\"={$guid};", $etype);
      $this->query("INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$guid});");
      $this->query("INSERT INTO \"{$this->prefix}entities_{$etype}\" (\"guid\", \"tags\", \"varlist\", \"cdate\", \"mdate\") VALUES ({$guid}, '".SQLite3::escapeString(','.$tags.',')."', '".SQLite3::escapeString(','.implode(',', array_keys($data)).',')."', ".unserialize($data['cdate']).", ".unserialize($data['mdate']).");", $etype);
      unset($data['cdate'], $data['mdate']);
      if ($data) {
        foreach ($data as $name => $value) {
          preg_match_all(
              '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
              $value,
              $references,
              PREG_PATTERN_ORDER
          );
          $uvalue = unserialize($value);
          $this->query("INSERT INTO \"{$this->prefix}data_{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES " .
            sprintf(
                "(%u, '%s', '%s', '%s', %s, %s, %s, %s, %s, %s, %d, %f);",
                $guid,
                SQLite3::escapeString($name),
                SQLite3::escapeString(
                    (strpos($value, "\0") !== false
                        ? '~'.addcslashes($value, chr(0).'\\')
                        : $value)
                ),
                SQLite3::escapeString(','.implode(',', $references[1]).','),
                $uvalue == true ? '1' : '0',
                (!is_object($uvalue) && $uvalue == 1) ? '1' : '0',
                (!is_object($uvalue) && $uvalue == 0) ? '1' : '0',
                (!is_object($uvalue) && $uvalue == -1) ? '1' : '0',
                $uvalue == [] ? '1' : '0',
                is_string($uvalue)
                    ? '\''.SQLite3::escapeString($uvalue).'\''
                    : 'NULL',
                is_object($uvalue) ? 1 : ((int) $uvalue),
                is_object($uvalue) ? 1 : ((float) $uvalue)
            ), $etype);
        }
      }
    }
    $this->query("RELEASE 'import';");
    return true;
  }

  public function newUID($name) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query("SAVEPOINT 'newuid';");
    $result = $this->query("SELECT \"cur_uid\" FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
    $row = $result->fetchArray(SQLITE3_NUM);
    $cur_uid = is_numeric($row[0]) ? (int) $row[0] : null;
    $result->finalize();
    if (!is_int($cur_uid)) {
      $cur_uid = 1;
      $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".SQLite3::escapeString($name)."', {$cur_uid});");
    } else {
      $cur_uid++;
      $this->query("UPDATE \"{$this->prefix}uids\" SET \"cur_uid\"={$cur_uid} WHERE \"name\"='".SQLite3::escapeString($name)."';");
    }
    $this->query("RELEASE 'newuid';");
    return $cur_uid;
  }

  public function renameUID($oldName, $newName) {
    if (!$oldName || !$newName) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query("UPDATE \"{$this->prefix}uids\" SET \"name\"='".SQLite3::escapeString($newName)."' WHERE \"name\"='".SQLite3::escapeString($oldName)."';");
    return true;
  }

  public function saveEntity(&$entity) {
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
    $etype = '_'.SQLite3::escapeString($etypeDirty);
    $this->query("BEGIN;");
    if (!isset($entity->guid)) {
      while (true) {
        // 2^53 is the maximum number in JavaScript
        // (http://ecma262-5.com/ELS5_HTML.htm#Section_8.5)
        $new_id = mt_rand(1, pow(2, 53));
        // That number might be too big on some machines. :(
        if ($new_id < 1) {
          $new_id = rand(1, 0x7FFFFFFF);
        }
        $result = $this->query("SELECT \"guid\" FROM \"{$this->prefix}guids\" WHERE \"guid\"={$new_id};", $etypeDirty);
        $row = $result->fetchArray(SQLITE3_NUM);
        $result->finalize();
        if (!isset($row[0])) {
          break;
        }
      }
      $entity->guid = $new_id;
      $this->query("INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$new_id});");
      $this->query("INSERT INTO \"{$this->prefix}entities{$etype}\" (\"guid\", \"tags\", \"varlist\", \"cdate\", \"mdate\") VALUES ({$entity->guid}, '".SQLite3::escapeString(','.implode(',', array_diff($entity->tags, [''])).',')."', '".SQLite3::escapeString(','.implode(',', $varlist).',')."', ".((float) $entity->cdate).", ".((float) $entity->mdate).");", $etypeDirty);
      $values = [];
      foreach ($data as $name => $value) {
        $svalue = serialize($value);
        preg_match_all(
            '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
            $svalue,
            $references,
            PREG_PATTERN_ORDER
        );
        $this->query("INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES ".
          sprintf(
              '(%u, \'%s\', \'%s\', \'%s\', %s, %s, %s, %s, %s, %s, %d, %f);',
              $entity->guid,
              SQLite3::escapeString($name),
              SQLite3::escapeString(
                  (strpos($svalue, "\0") !== false
                      ? '~'.addcslashes($svalue, chr(0).'\\')
                      : $svalue)
              ),
              SQLite3::escapeString(','.implode(',', $references[1]).','),
              $value == true ? '1' : '0',
              (!is_object($value) && $value == 1) ? '1' : '0',
              (!is_object($value) && $value == 0) ? '1' : '0',
              (!is_object($value) && $value == -1) ? '1' : '0',
              $value == [] ? '1' : '0',
              is_string($value)
                  ? '\''.SQLite3::escapeString($value).'\''
                  : 'NULL',
              is_object($value) ? 1 : ((int) $value),
              is_object($value) ? 1 : ((float) $value)
          ), $etypeDirty);
      }
      foreach ($sdata as $name => $value) {
        preg_match_all(
            '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
            $value,
            $references,
            PREG_PATTERN_ORDER
        );
        $uvalue = unserialize($value);
        $this->query("INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES ".
          sprintf(
              '(%u, \'%s\', \'%s\', \'%s\', %s, %s, %s, %s, %s, %s, %d, %f);',
              $entity->guid,
              SQLite3::escapeString($name),
              SQLite3::escapeString(
                  (strpos($value, "\0") !== false
                      ? '~'.addcslashes($value, chr(0).'\\')
                      : $value)
              ),
              SQLite3::escapeString(','.implode(',', $references[1]).','),
              $uvalue == true ? '1' : '0',
              (!is_object($uvalue) && $uvalue == 1) ? '1' : '0',
              (!is_object($uvalue) && $uvalue == 0) ? '1' : '0',
              (!is_object($uvalue) && $uvalue == -1) ? '1' : '0',
              $uvalue == [] ? '1' : '0',
              is_string($uvalue)
                  ? '\''.SQLite3::escapeString($uvalue).'\''
                  : 'NULL',
              is_object($uvalue) ? 1 : ((int) $uvalue),
              is_object($uvalue) ? 1 : ((float) $uvalue)
          ), $etypeDirty);
      }
    } else {
      // Removed any cached versions of this entity.
      if ($this->config['cache']) {
        $this->cleanCache($entity->guid);
      }
      $this->query("UPDATE \"{$this->prefix}entities{$etype}\" SET \"tags\"='".SQLite3::escapeString(','.implode(',', array_diff($entity->tags, [''])).',')."', \"varlist\"='".SQLite3::escapeString(','.implode(',', $varlist).',')."', \"cdate\"=".((float) $entity->cdate).", \"mdate\"=".((float) $entity->mdate)." WHERE \"guid\"={$entity->guid};", $etypeDirty);
      $this->query("DELETE FROM \"{$this->prefix}data{$etype}\" WHERE \"guid\"={$entity->guid};");
      $values = [];
      foreach ($data as $name => $value) {
        $svalue = serialize($value);
        preg_match_all(
            '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
            $svalue,
            $references,
            PREG_PATTERN_ORDER
        );
        $this->query("INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES ".
          sprintf(
              '(%u, \'%s\', \'%s\', \'%s\', %s, %s, %s, %s, %s, %s, %d, %f);',
              (int) $entity->guid,
              SQLite3::escapeString($name),
              SQLite3::escapeString(
                  (strpos($svalue, "\0") !== false
                      ? '~'.addcslashes($svalue, chr(0).'\\')
                      : $svalue)
              ),
              SQLite3::escapeString(','.implode(',', $references[1]).','),
              $value == true ? '1' : '0',
              (!is_object($value) && $value == 1) ? '1' : '0',
              (!is_object($value) && $value == 0) ? '1' : '0',
              (!is_object($value) && $value == -1) ? '1' : '0',
              $value == [] ? '1' : '0',
              is_string($value)
                  ? '\''.SQLite3::escapeString($value).'\''
                  : 'NULL',
              is_object($value) ? 1 : ((int) $value),
              is_object($value) ? 1 : ((float) $value)
          ), $etypeDirty);
      }
      foreach ($sdata as $name => $value) {
        preg_match_all(
            '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
            $value,
            $references,
            PREG_PATTERN_ORDER
        );
        $uvalue = unserialize($value);
        $this->query("INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES ".
          sprintf(
              '(%u, \'%s\', \'%s\', \'%s\', %s, %s, %s, %s, %s, %s, %d, %f);',
              (int) $entity->guid,
              SQLite3::escapeString($name),
              SQLite3::escapeString(
                  (strpos($value, "\0") !== false
                      ? '~'.addcslashes($value, chr(0).'\\')
                      : $value)
              ),
              SQLite3::escapeString(','.implode(',', $references[1]).','),
              $uvalue == true ? '1' : '0',
              (!is_object($uvalue) && $uvalue == 1) ? '1' : '0',
              (!is_object($uvalue) && $uvalue == 0) ? '1' : '0',
              (!is_object($uvalue) && $uvalue == -1) ? '1' : '0',
              $uvalue == [] ? '1' : '0',
              is_string($uvalue)
                  ? '\''.SQLite3::escapeString($uvalue).'\''
                  : 'NULL',
              is_object($uvalue) ? 1 : ((int) $uvalue),
              is_object($uvalue) ? 1 : ((float) $uvalue)
          ), $etypeDirty);
      }
    }
    $this->query("COMMIT;");
    // Cache the entity.
    if ($this->config['cache']) {
      $this->pushCache($entity, $class);
    }
    return true;
  }

  public function setUID($name, $value) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".SQLite3::escapeString($name)."';");
    $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".SQLite3::escapeString($name)."', ".((int) $value).");");
    return true;
  }
}
