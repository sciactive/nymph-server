<?php namespace Nymph\Drivers;

use Nymph\Exceptions;

/**
 * PostgreSQL based Nymph driver.
 *
 * @package Nymph
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
class PostgreSQLDriver implements DriverInterface {
  use DriverTrait {
    DriverTrait::__construct as private __traitConstruct;
  }
  /**
   * The PostgreSQL link identifier for this instance.
   *
   * @access private
   * @var mixed
   */
  private $link = null;
  /**
   * Whether to use PL/Perl.
   *
   * @access private
   * @var string
   */
  private $usePLPerl;
  private $prefix;

  public function __construct($NymphConfig) {
    $this->__traitConstruct($NymphConfig);
    $this->usePLPerl = $this->config['PostgreSQL']['use_plperl'];
    $this->prefix = $this->config['PostgreSQL']['prefix'];
  }

  /**
   * Disconnect from the database on destruction.
   */
  public function __destruct() {
    $this->disconnect();
  }

  /**
   * Connect to the PostgreSQL database.
   *
   * @return bool Whether this instance is connected to a PostgreSQL database
   *              after the method has run.
   */
  public function connect() {
    // Check that the PostgreSQL extension is installed.
    if (!is_callable('pg_connect')) {
      throw new Exceptions\UnableToConnectException(
          'PostgreSQL PHP extension is not available. It probably has not ' .
          'been installed. Please install and configure it in order to use ' .
          'PostgreSQL.'
      );
    }
    $connection_type = $this->config['PostgreSQL']['connection_type'];
    $host = $this->config['PostgreSQL']['host'];
    $port = $this->config['PostgreSQL']['port'];
    $user = $this->config['PostgreSQL']['user'];
    $password = $this->config['PostgreSQL']['password'];
    $database = $this->config['PostgreSQL']['database'];
    // Connecting, selecting database
    if (!$this->connected) {
      if ($connection_type == 'host') {
        $connect_string = 'host=\'' . addslashes($host) .
            '\' port=\'' . addslashes($port) .
            '\' dbname=\'' . addslashes($database) .
            '\' user=\'' . addslashes($user) .
            '\' password=\'' . addslashes($password) .
            '\' connect_timeout=5';
      } else {
        $connect_string = 'dbname=\'' . addslashes($database) .
            '\' user=\'' . addslashes($user) .
            '\' password=\'' . addslashes($password) .
            '\' connect_timeout=5';
      }
      if ($this->config['PostgreSQL']['allow_persistent']) {
        $this->link = pg_connect(
            $connect_string .
                ' options=\'-c enable_hashjoin=off -c enable_mergejoin=off\''
        );
      } else {
        $this->link = pg_connect(
            $connect_string .
                ' options=\'-c enable_hashjoin=off -c enable_mergejoin=off\'',
            PGSQL_CONNECT_FORCE_NEW
        );
        // Don't think this is necessary, but if put in options, will guarantee
        // connection is new. " -c timezone='.round(rand(10001000, 10009999)).'"
      }
      if ($this->link) {
        $this->connected = true;
      } else {
        $this->connected = false;
        if ($host == 'localhost'
            && $user == 'nymph'
            && $password == 'password'
            && $database == 'nymph'
            && $connection_type == 'host') {
          throw new Exceptions\NotConfiguredException();
        } else {
          throw new Exceptions\UnableToConnectException(
              'Could not connect: ' .
                  pg_last_error()
          );
        }
      }
    }
    return $this->connected;
  }

  /**
   * Disconnect from the PostgreSQL database.
   *
   * @return bool Whether this instance is connected to a PostgreSQL database
   *              after the method has run.
   */
  public function disconnect() {
    if ($this->connected) {
      if (is_resource($this->link)) {
        pg_close($this->link);
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
    $this->query('ROLLBACK; BEGIN;');
    if (isset($etype)) {
      $etype = '_'.pg_escape_string($this->link, $etype);
      // Create the entity table.
      $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}entities{$etype}\" ( guid bigint NOT NULL, tags text[], varlist text[], cdate numeric(18,6) NOT NULL, mdate numeric(18,6) NOT NULL, PRIMARY KEY (guid) ) WITH ( OIDS=FALSE ); "
        . "ALTER TABLE \"{$this->prefix}entities{$etype}\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\"; "
        . "DROP INDEX IF EXISTS \"{$this->prefix}entities{$etype}_id_cdate\"; "
        . "CREATE INDEX \"{$this->prefix}entities{$etype}_id_cdate\" ON \"{$this->prefix}entities{$etype}\" USING btree (cdate); "
        . "DROP INDEX IF EXISTS \"{$this->prefix}entities{$etype}_id_mdate\"; "
        . "CREATE INDEX \"{$this->prefix}entities{$etype}_id_mdate\" ON \"{$this->prefix}entities{$etype}\" USING btree (mdate); "
        . "DROP INDEX IF EXISTS \"{$this->prefix}entities{$etype}_id_tags\"; "
        . "CREATE INDEX \"{$this->prefix}entities{$etype}_id_tags\" ON \"{$this->prefix}entities{$etype}\" USING gin (tags); "
        . "DROP INDEX IF EXISTS \"{$this->prefix}entities{$etype}_id_varlist\"; "
        . "CREATE INDEX \"{$this->prefix}entities{$etype}_id_varlist\" ON \"{$this->prefix}entities{$etype}\" USING gin (varlist);");
      // Create the data table.
      $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}data{$etype}\" ( guid bigint NOT NULL, \"name\" text NOT NULL, \"value\" text NOT NULL, \"references\" bigint[], compare_true boolean, compare_one boolean, compare_zero boolean, compare_negone boolean, compare_emptyarray boolean, compare_string text, compare_int bigint, compare_float double precision, PRIMARY KEY (guid, \"name\"), FOREIGN KEY (guid) REFERENCES \"{$this->prefix}entities{$etype}\" (guid) MATCH SIMPLE ON UPDATE NO ACTION ON DELETE CASCADE ) WITH ( OIDS=FALSE ); "
        . "ALTER TABLE \"{$this->prefix}data{$etype}\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\"; "
        . "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid\"; "
        . "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\"); "
        . "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_name\"; "
        . "CREATE INDEX \"{$this->prefix}data{$etype}_id_name\" ON \"{$this->prefix}data{$etype}\" USING btree (\"name\"); "
        . "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_references\"; "
        . "CREATE INDEX \"{$this->prefix}data{$etype}_id_references\" ON \"{$this->prefix}data{$etype}\" USING gin (\"references\"); "
        . "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid_name_compare_true\"; "
        . "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid_name_compare_true\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\", \"name\") WHERE \"compare_true\" = TRUE; "
        . "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid_name_not_compare_true\"; "
        . "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid_name_not_compare_true\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\", \"name\") WHERE \"compare_true\" <> TRUE; "
        . "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid_name__user\"; "
        . "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid_name__user\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\") WHERE \"name\" = 'user'::text; "
        . "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid_name__group\"; "
        . "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid_name__group\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\") WHERE \"name\" = 'group'::text;");
    } else {
      // Create the GUID table.
      $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}guids\" ( \"guid\" bigint NOT NULL, PRIMARY KEY (\"guid\")); "
        . "ALTER TABLE \"{$this->prefix}guids\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\";");
      // Create the UID table.
      $this->query("CREATE TABLE IF NOT EXISTS \"{$this->prefix}uids\" ( \"name\" text NOT NULL, cur_uid bigint NOT NULL, PRIMARY KEY (\"name\") ) WITH ( OIDS = FALSE ); "
        . "ALTER TABLE \"{$this->prefix}uids\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\";");
      if ($this->usePLPerl) {
        // Create the perl_match function. It's separated into two calls so
        // Postgres will ignore the error if plperl already exists.
        $this->query("CREATE OR REPLACE PROCEDURAL LANGUAGE plperl;");
        $this->query("CREATE OR REPLACE FUNCTION {$this->prefix}match_perl( TEXT, TEXT, TEXT ) RETURNS BOOL AS \$code$ "
          . "my (\$str, \$pattern, \$mods) = @_; "
          . "if (\$pattern eq \'\') { "
            . "return true; "
          . "} "
          . "if (\$mods eq \'\') { "
            . "if (\$str =~ /(\$pattern)/) { "
              . "return true; "
            . "} else { "
              . "return false; "
            . "} "
          . "} else { "
            . "if (\$str =~ /(?\$mods)(\$pattern)/) { "
              . "return true; "
            . "} else { "
              . "return false; "
            . "} "
          . "} \$code$ LANGUAGE plperl IMMUTABLE STRICT COST 10000;");
      }
    }
    $this->query('COMMIT;');
    return true;
  }

  private function query($query, $etypeDirty = null) {
    while (pg_get_result($this->link)) {
      // Clear the connection of all results.
      continue;
    }
    if (!(pg_send_query($this->link, $query))) {
      throw new Exceptions\QueryFailedException(
          'Query failed: ' . pg_last_error(),
          0,
          null,
          $query
      );
    }
    if (!($result = pg_get_result($this->link))) {
      throw new Exceptions\QueryFailedException(
          'Query failed: ' . pg_last_error(),
          0,
          null,
          $query
      );
    }
    if ($error = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE)) {
      // If the tables don't exist yet, create them.
      if ($error == '42P01' && $this->createTables()) {
        if (isset($etypeDirty)) {
          $this->createTables($etypeDirty);
        }
        if (!($result = pg_query($this->link, $query))) {
          throw new Exceptions\QueryFailedException(
              'Query failed: ' . pg_last_error(),
              0,
              null,
              $query
          );
        }
      } else {
        throw new Exceptions\QueryFailedException(
            'Query failed: ' . pg_last_error(),
            0,
            null,
            $query
        );
      }
    }
    return $result;
  }

  public function deleteEntityByID($guid, $etypeDirty = null) {
    $guid = (int) $guid;
    $etype = isset($etypeDirty) ? '_'.pg_escape_string($this->link, $etypeDirty) : '';
    $this->query("DELETE FROM \"{$this->prefix}entities{$etype}\" WHERE \"guid\"={$guid}; DELETE FROM \"{$this->prefix}data{$etype}\" WHERE \"guid\"={$guid};", $etypeDirty);
    $this->query("DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
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
    $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."';");
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
    $result = $this->query("SELECT * FROM \"{$this->prefix}uids\" ORDER BY \"name\";");
    $row = pg_fetch_assoc($result);
    while ($row) {
      $row['name'];
      $row['cur_uid'];
      $writeCallback("<{$row['name']}>[{$row['cur_uid']}]\n");
      // Make sure that $row is incremented :)
      $row = pg_fetch_assoc($result);
    }

    $writeCallback("\n#\n");
    $writeCallback("# Entities\n");
    $writeCallback("#\n\n");

    // Get the etypes.
    $result = $this->query("SELECT relname FROM pg_stat_user_tables ORDER BY relname;");
    $etypes = [];
    $row = pg_fetch_array($result);
    while ($row) {
      if (strpos($row[0], $this->prefix.'entities_') === 0) {
        $etypes[] = substr($row[0], strlen($this->prefix.'entities_'));
      }
      $row = pg_fetch_array($result);
    }

    foreach ($etypes as $etype) {
      // Export entities.
      $result = $this->query("SELECT e.*, d.\"name\" AS \"dname\", d.\"value\" AS \"dvalue\" FROM \"{$this->prefix}entities_{$etype}\" e LEFT JOIN \"{$this->prefix}data_{$etype}\" d ON e.\"guid\"=d.\"guid\" ORDER BY e.\"guid\";");
      $row = pg_fetch_assoc($result);
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
            $row = pg_fetch_assoc($result);
          } while ((int) $row['guid'] === $guid);
        } else {
          // Make sure that $row is incremented :)
          $row = pg_fetch_assoc($result);
        }
      }
    }
  }

  /**
   * Generate the PostgreSQL query.
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
    $etype = '_'.pg_escape_string($this->link, $etypeDirty);
    $query_parts = $this->iterateSelectorsForQuery($selectors, function ($value) use ($options, $etypeDirty) {
      return $this->makeEntityQuery(
          $options,
          [$value],
          $etypeDirty,
          true
      );
    }, function (&$cur_query, $key, $value, $type_is_or, $type_is_not) use ($etype) {
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
                  '\'{'.pg_escape_string($this->link, $cur_tag) .
                  '}\' <@ e."tags"';
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
                  '\'{'.pg_escape_string($this->link, $cur_var) .
                  '}\' <@ e."varlist"';
              if ($type_is_not xor $clause_not) {
                $cur_query .= ' OR e."guid" IN (SELECT "guid" FROM "' .
                    $this->prefix.'data'.$etype.'" WHERE "name"=\'' .
                    pg_escape_string($this->link, $cur_var) .
                    '\' AND "value"=\'N;\')';
              }
              $cur_query .= ')';
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
            if ($guids) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]).'\' AND (';
              //$cur_query .= '(POSITION(\'a:3:{i:0;s:22:"nymph_entity_reference";i:1;i:';
              //$cur_query .= implode(';\' IN "value") != 0) '.($type_is_or ? 'OR' : 'AND').' (POSITION(\'a:3:{i:0;s:22:"nymph_entity_reference";i:1;i:', $guids);
              //$cur_query .= ';\' IN "value") != 0)';
              $cur_query .= '\'{';
              $cur_query .=
                  implode(
                      '}\' <@ "references"' .
                          ($type_is_or ? ' OR ' : ' AND ') . '\'{',
                      $guids
                  );
              $cur_query .= '}\' <@ "references"';
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
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "value"=\'' .
                  pg_escape_string(
                      $this->link,
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
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e."mdate" LIKE \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_string" LIKE \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
            }
            break;
          case 'ilike':
          case '!ilike':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e."cdate" ILIKE \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e."mdate" ILIKE \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_string" ILIKE \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
            }
            break;
          case 'pmatch':
          case '!pmatch':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e."cdate" ~ \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e."mdate" ~ \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_string" ~ \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
            }
            break;
          case 'ipmatch':
          case '!ipmatch':
            if ($cur_value[0] == 'cdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e."cdate" ~* \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } elseif ($cur_value[0] == 'mdate') {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  '(e."mdate" ~* \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
              break;
            } else {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_string" ~* \'' .
                  pg_escape_string($this->link, $cur_value[1]).'\')';
            }
            break;
          case 'match':
          case '!match':
            if ($this->usePLPerl) {
              $lastslashpos = strrpos($cur_value[1], '/');
              $regex = substr($cur_value[1], 1, $lastslashpos - 1);
              $mods = substr($cur_value[1], $lastslashpos + 1) ?: '';
              if ($cur_value[0] == 'cdate') {
                if ($cur_query) {
                  $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                }
                $cur_query .=
                    (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                    $this->prefix.'match_perl(e."cdate", \'' .
                    pg_escape_string($this->link, $regex) .
                    '\', \''.pg_escape_string($this->link, $mods).'\')';
                break;
              } elseif ($cur_value[0] == 'mdate') {
                if ($cur_query) {
                  $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                }
                $cur_query .=
                    (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                    $this->prefix.'match_perl(e."mdate", \'' .
                    pg_escape_string($this->link, $regex) .
                    '\', \''.pg_escape_string($this->link, $mods).'\')';
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
                    pg_escape_string($this->link, $cur_value[0]) .
                    '\' AND "compare_string" IS NOT NULL AND ' .
                    $this->prefix.'match_perl("compare_string", \'' .
                    pg_escape_string($this->link, $regex) .
                    '\', \''.pg_escape_string($this->link, $mods).'\'))';
              }
            } else {
              if (!($type_is_not xor $clause_not)) {
                if ($cur_query) {
                  $cur_query .= $type_is_or ? ' OR ' : ' AND ';
                }
                $cur_query .= '\'{' .
                    pg_escape_string($this->link, $cur_value[0]) .
                    '}\' <@ e."varlist"';
              }
              break;
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
                  pg_escape_string($this->link, $cur_value[0]) . '\' AND ' .
                  '(("compare_int" IS NOT NULL AND "compare_int" > ' .
                  ((int) $cur_value[1]) .
                  ' AND substring("value", 0, 1)=\'i\') OR ' .
                  '("compare_float" IS NOT NULL AND "compare_float" > ' .
                  ((float) $cur_value[1]) .
                  ' AND NOT substring("value", 0, 1)=\'i\')))';
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
                  pg_escape_string($this->link, $cur_value[0]) . '\' AND ' .
                  '(("compare_int" IS NOT NULL AND "compare_int" >= ' .
                  ((int) $cur_value[1]) .
                  ' AND substring("value", 0, 1)=\'i\') OR ' .
                  '("compare_float" IS NOT NULL AND "compare_float" >= ' .
                  ((float) $cur_value[1]) .
                  ' AND NOT substring("value", 0, 1)=\'i\')))';
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
                  pg_escape_string($this->link, $cur_value[0]) . '\' AND ' .
                  '(("compare_int" IS NOT NULL AND "compare_int" < ' .
                  ((int) $cur_value[1]) .
                  ' AND substring("value", 0, 1)=\'i\') OR ' .
                  '("compare_float" IS NOT NULL AND "compare_float" < ' .
                  ((float) $cur_value[1]) .
                  ' AND NOT substring("value", 0, 1)=\'i\')))';
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
                  pg_escape_string($this->link, $cur_value[0]) . '\' AND ' .
                  '(("compare_int" IS NOT NULL AND "compare_int" <= ' .
                  ((int) $cur_value[1]) .
                  ' AND substring("value", 0, 1)=\'i\') OR ' .
                  '("compare_float" IS NOT NULL AND "compare_float" <= ' .
                  ((float) $cur_value[1]) .
                  ' AND NOT substring("value", 0, 1)=\'i\')))';
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
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_true"=' .
                  ($cur_value[1] ? 'TRUE' : 'FALSE').')';
              break;
            } elseif ($cur_value[1] === 1) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_one"=TRUE)';
              break;
            } elseif ($cur_value[1] === 0) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_zero"=TRUE)';
              break;
            } elseif ($cur_value[1] === -1) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_negone"=TRUE)';
              break;
            } elseif ($cur_value[1] === []) {
              if ($cur_query) {
                $cur_query .= ($type_is_or ? ' OR ' : ' AND ');
              }
              $cur_query .= (($type_is_not xor $clause_not) ? 'NOT ' : '') .
                  'e."guid" IN (SELECT "guid" FROM "'.$this->prefix.'data' .
                  $etype.'" WHERE "name"=\'' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '\' AND "compare_emptyarray"=TRUE)';
              break;
            }
          case 'array':
          case '!array':
            if (!($type_is_not xor $clause_not)) {
              if ($cur_query) {
                $cur_query .= $type_is_or ? ' OR ' : ' AND ';
              }
              $cur_query .= '\'{' .
                  pg_escape_string($this->link, $cur_value[0]) .
                  '}\' <@ e."varlist"';
            }
            break;
        }
      }
    });

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
    $typesAlreadyChecked = [
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
    ];
    if ($this->usePLPerl) {
      $typesAlreadyChecked[] = 'match';
      $typesAlreadyChecked[] = '!match';
    }
    return $this->getEntitesRowLike(
        $options,
        $selectors,
        $typesAlreadyChecked,
        [true, false, 1, 0, -1, []],
        'pg_fetch_row',
        'pg_free_result',
        function ($row) {
          return (int) $row[0];
        },
        function ($row) {
          return [
            'tags' => strlen($row[1]) > 2 ? explode(',', substr($row[1], 1, -1)) : [],
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
    $result = $this->query("SELECT \"cur_uid\" FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."';");
    $row = pg_fetch_row($result);
    pg_free_result($result);
    return isset($row[0]) ? (int) $row[0] : null;
  }

  public function import($filename) {
    return $this->importFromFile($filename, function ($guid, $tags, $data, $etype) {
      $this->query("DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid}; INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$guid});");
      $this->query("DELETE FROM \"{$this->prefix}entities_{$etype}\" WHERE \"guid\"={$guid}; INSERT INTO \"{$this->prefix}entities_{$etype}\" (\"guid\", \"tags\", \"varlist\", \"cdate\", \"mdate\") VALUES ({$guid}, '".pg_escape_string($this->link, '{'.implode(',', $tags).'}')."', '".pg_escape_string($this->link, '{'.implode(',', array_keys($data)).'}')."', ".unserialize($data['cdate']).", ".unserialize($data['mdate']).");", $etype);
      $this->query("DELETE FROM \"{$this->prefix}data_{$etype}\" WHERE \"guid\"={$guid};");
      unset($data['cdate'], $data['mdate']);
      if ($data) {
        $query = '';
        foreach ($data as $name => $value) {
          $query .= "INSERT INTO \"{$this->prefix}data_{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES ";
          $query .= $this->makeInsertValuesSQL($guid, $name, $value, unserialize($value)) . '; ';
        }
        $this->query($query, $etype);
      }
    }, function ($name, $cur_uid) {
      $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."'; INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".pg_escape_string($this->link, $name)."', ".((int) $cur_uid).");");
    }, function () {
      $this->query('BEGIN;');
    }, function () {
      $this->query('COMMIT;');
    });
  }

  public function newUID($name) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query('BEGIN;');
    $result = $this->query("SELECT \"cur_uid\" FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."' FOR UPDATE;");
    $row = pg_fetch_row($result);
    $cur_uid = is_numeric($row[0]) ? (int) $row[0] : null;
    pg_free_result($result);
    if (!is_int($cur_uid)) {
      $cur_uid = 1;
      $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".pg_escape_string($this->link, $name)."', {$cur_uid});");
    } else {
      $cur_uid++;
      $this->query("UPDATE \"{$this->prefix}uids\" SET \"cur_uid\"={$cur_uid} WHERE \"name\"='".pg_escape_string($this->link, $name)."';");
    }
    $this->query('COMMIT;');
    return $cur_uid;
  }

  public function renameUID($oldName, $newName) {
    if (!$oldName || !$newName) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query("UPDATE \"{$this->prefix}uids\" SET \"name\"='".pg_escape_string($this->link, $newName)."' WHERE \"name\"='".pg_escape_string($this->link, $oldName)."';");
    return true;
  }

  public function saveEntity(&$entity) {
    $insertData = function ($entity, $data, $sdata, $etype, $etypeDirty) {
      $values = [];
      foreach ($data as $name => $value) {
        $values[] = "INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES " .
          $this->makeInsertValuesSQL($entity->guid, $name, serialize($value), $value) . ';';
      }
      foreach ($sdata as $name => $value) {
        $values[] = "INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\", \"references\", \"compare_true\", \"compare_one\", \"compare_zero\", \"compare_negone\", \"compare_emptyarray\", \"compare_string\", \"compare_int\", \"compare_float\") VALUES " .
          $this->makeInsertValuesSQL($entity->guid, $name, $value, unserialize($value)) . ';';
      }
      $this->query(implode(' ', $values), $etypeDirty);
    };
    return $this->saveEntityRowLike($entity, function ($etypeDirty) {
      return '_'.pg_escape_string($this->link, $etypeDirty);
    }, function ($guid) {
      $result = $this->query("SELECT \"guid\" FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
      $row = pg_fetch_row($result);
      pg_free_result($result);
      return !isset($row[0]);
    }, function ($entity, $data, $sdata, $varlist, $etype, $etypeDirty) use ($insertData) {
      $this->query("INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$entity->guid});");
      $this->query("INSERT INTO \"{$this->prefix}entities{$etype}\" (\"guid\", \"tags\", \"varlist\", \"cdate\", \"mdate\") VALUES ({$entity->guid}, '".pg_escape_string($this->link, '{'.implode(',', array_diff($entity->tags, [''])).'}')."', '".pg_escape_string($this->link, '{'.implode(',', $varlist).'}')."', ".((float) $entity->cdate).", ".((float) $entity->mdate).");", $etypeDirty);
      $insertData($entity, $data, $sdata, $etype, $etypeDirty);
    }, function ($entity, $data, $sdata, $varlist, $etype, $etypeDirty) use ($insertData) {
      $this->query("UPDATE \"{$this->prefix}entities{$etype}\" SET \"tags\"='".pg_escape_string($this->link, '{'.implode(',', array_diff($entity->tags, [''])).'}')."', \"varlist\"='".pg_escape_string($this->link, '{'.implode(',', $varlist).'}')."', \"cdate\"=".((float) $entity->cdate).", \"mdate\"=".((float) $entity->mdate)." WHERE \"guid\"={$entity->guid};", $etypeDirty);
      $this->query("DELETE FROM \"{$this->prefix}data{$etype}\" WHERE \"guid\"={$entity->guid};");
      $insertData($entity, $data, $sdata, $etype, $etypeDirty);
    }, function () {
      $this->query("BEGIN;");
    }, function () {
      pg_get_result($this->link); // Clear any pending result.
      $this->query("COMMIT;");
    });
  }

  public function setUID($name, $value) {
    if (!$name) {
      throw new Exceptions\InvalidParametersException(
          'Name not given for UID.'
      );
    }
    $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."'; INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".pg_escape_string($this->link, $name)."', ".((int) $value).");");
    return true;
  }

  private function makeInsertValuesSQL($guid, $name, $svalue, $uvalue) {
    preg_match_all(
        '/a:3:\{i:0;s:22:"nymph_entity_reference";i:1;i:(\d+);/',
        $svalue,
        $references,
        PREG_PATTERN_ORDER
    );
    return sprintf(
        "(%u, '%s', '%s', '%s', %s, %s, %s, %s, %s, %s, %d, %f)",
        (int) $guid,
        pg_escape_string($this->link, $name),
        pg_escape_string(
            $this->link,
            (strpos($svalue, "\0") !== false
                ? '~'.addcslashes($svalue, chr(0).'\\')
                : $svalue)
        ),
        pg_escape_string(
            $this->link,
            '{'.implode(',', $references[1]).'}'
        ),
        $uvalue == true ? 'TRUE' : 'FALSE',
        (!is_object($uvalue) && $uvalue == 1) ? 'TRUE' : 'FALSE',
        (!is_object($uvalue) && $uvalue == 0) ? 'TRUE' : 'FALSE',
        (!is_object($uvalue) && $uvalue == -1) ? 'TRUE' : 'FALSE',
        $uvalue == [] ? 'TRUE' : 'FALSE',
        is_string($uvalue)
            ? '\''.pg_escape_string($this->link, $uvalue).'\''
            : 'NULL',
        is_object($uvalue) ? 1 : ((int) $uvalue),
        is_object($uvalue) ? 1 : ((float) $uvalue)
    );
  }
}
