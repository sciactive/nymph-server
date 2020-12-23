<?php namespace Nymph\Drivers;

use Nymph\Exceptions;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * PostgreSQL based Nymph driver.
 *
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @see http://nymph.io/
 */
class PostgreSQLDriver implements DriverInterface {
  use DriverTrait {
    DriverTrait::__construct as private __traitConstruct;
  }
  /**
   * The PostgreSQL link identifier for this instance.
   *
   * @var mixed
   */
  private $link = null;
  /**
   * Whether to use PL/Perl.
   *
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
        'PostgreSQL PHP extension is not available. It probably has not '.
          'been installed. Please install and configure it in order to use '.
          'PostgreSQL.'
      );
    }
    $connectionType = $this->config['PostgreSQL']['connection_type'];
    $host = $this->config['PostgreSQL']['host'];
    $port = $this->config['PostgreSQL']['port'];
    $user = $this->config['PostgreSQL']['user'];
    $password = $this->config['PostgreSQL']['password'];
    $database = $this->config['PostgreSQL']['database'];
    // Connecting, selecting database
    if (!$this->connected) {
      if ($connectionType === 'host') {
        $connectString = (
          'host=\''.addslashes($host).
            '\' port=\''.addslashes($port).
            '\' dbname=\''.addslashes($database).
            '\' user=\''.addslashes($user).
            '\' password=\''.addslashes($password).
            '\' connect_timeout=5'
        );
      } else {
        $connectString = (
          'dbname=\''.addslashes($database).
            '\' user=\''.addslashes($user).
            '\' password=\''.addslashes($password).
            '\' connect_timeout=5'
        );
      }
      if ($this->config['PostgreSQL']['allow_persistent']) {
        $this->link = pg_connect(
          $connectString.
            ' options=\'-c enable_hashjoin=off -c enable_mergejoin=off\''
        );
      } else {
        $this->link = pg_connect(
          $connectString.
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
        if ($host === 'localhost'
          && $user === 'nymph'
          && $password === 'password'
          && $database === 'nymph'
          && $connectionType === 'host'
        ) {
          throw new Exceptions\NotConfiguredException();
        } else {
          throw new Exceptions\UnableToConnectException(
            'Could not connect: '.
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
      $this->query(
        "CREATE TABLE IF NOT EXISTS \"{$this->prefix}entities{$etype}\" ( \"guid\" BIGINT NOT NULL, \"tags\" TEXT[], \"cdate\" NUMERIC(18,6) NOT NULL, \"mdate\" NUMERIC(18,6) NOT NULL, PRIMARY KEY (\"guid\") ) WITH ( OIDS=FALSE ); ".
          "ALTER TABLE \"{$this->prefix}entities{$etype}\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\"; ".
          "DROP INDEX IF EXISTS \"{$this->prefix}entities{$etype}_id_cdate\"; ".
          "CREATE INDEX \"{$this->prefix}entities{$etype}_id_cdate\" ON \"{$this->prefix}entities{$etype}\" USING btree (\"cdate\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}entities{$etype}_id_mdate\"; ".
          "CREATE INDEX \"{$this->prefix}entities{$etype}_id_mdate\" ON \"{$this->prefix}entities{$etype}\" USING btree (\"mdate\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}entities{$etype}_id_tags\"; ".
          "CREATE INDEX \"{$this->prefix}entities{$etype}_id_tags\" ON \"{$this->prefix}entities{$etype}\" USING gin (\"tags\");"
      );
      // Create the data table.
      $this->query(
        "CREATE TABLE IF NOT EXISTS \"{$this->prefix}data{$etype}\" ( \"guid\" BIGINT NOT NULL, \"name\" TEXT NOT NULL, \"value\" TEXT NOT NULL, PRIMARY KEY (\"guid\", \"name\"), FOREIGN KEY (\"guid\") REFERENCES \"{$this->prefix}entities{$etype}\" (\"guid\") MATCH SIMPLE ON UPDATE NO ACTION ON DELETE CASCADE ) WITH ( OIDS=FALSE ); ".
          "ALTER TABLE \"{$this->prefix}data{$etype}\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\"; ".
          "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid\"; ".
          "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_name\"; ".
          "CREATE INDEX \"{$this->prefix}data{$etype}_id_name\" ON \"{$this->prefix}data{$etype}\" USING btree (\"name\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid_name__user\"; ".
          "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid_name__user\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\") WHERE \"name\" = 'user'::text; ".
          "DROP INDEX IF EXISTS \"{$this->prefix}data{$etype}_id_guid_name__group\"; ".
          "CREATE INDEX \"{$this->prefix}data{$etype}_id_guid_name__group\" ON \"{$this->prefix}data{$etype}\" USING btree (\"guid\") WHERE \"name\" = 'group'::text;"
      );
      $this->query(
        "CREATE TABLE IF NOT EXISTS \"{$this->prefix}comparisons{$etype}\" ( \"guid\" BIGINT NOT NULL, \"name\" TEXT NOT NULL, \"eq_true\" BOOLEAN, \"eq_one\" BOOLEAN, \"eq_zero\" BOOLEAN, \"eq_negone\" BOOLEAN, \"eq_emptyarray\" BOOLEAN, \"string\" TEXT, \"int\" BIGINT, \"float\" DOUBLE PRECISION, \"is_int\" BOOLEAN NOT NULL, PRIMARY KEY (\"guid\", \"name\"), FOREIGN KEY (\"guid\") REFERENCES \"{$this->prefix}entities{$etype}\" (\"guid\") MATCH SIMPLE ON UPDATE NO ACTION ON DELETE CASCADE ) WITH ( OIDS=FALSE ); ".
          "ALTER TABLE \"{$this->prefix}comparisons{$etype}\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\"; ".
          "DROP INDEX IF EXISTS \"{$this->prefix}comparisons{$etype}_id_guid\"; ".
          "CREATE INDEX \"{$this->prefix}comparisons{$etype}_id_guid\" ON \"{$this->prefix}comparisons{$etype}\" USING btree (\"guid\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}comparisons{$etype}_id_name\"; ".
          "CREATE INDEX \"{$this->prefix}comparisons{$etype}_id_name\" ON \"{$this->prefix}comparisons{$etype}\" USING btree (\"name\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}comparisons{$etype}_id_guid_name_eq_true\"; ".
          "CREATE INDEX \"{$this->prefix}comparisons{$etype}_id_guid_name_eq_true\" ON \"{$this->prefix}comparisons{$etype}\" USING btree (\"guid\", \"name\") WHERE \"eq_true\" = TRUE; ".
          "DROP INDEX IF EXISTS \"{$this->prefix}comparisons{$etype}_id_guid_name_not_eq_true\"; ".
          "CREATE INDEX \"{$this->prefix}comparisons{$etype}_id_guid_name_not_eq_true\" ON \"{$this->prefix}comparisons{$etype}\" USING btree (\"guid\", \"name\") WHERE \"eq_true\" <> TRUE; "
      );
      $this->query(
        "CREATE TABLE IF NOT EXISTS \"{$this->prefix}references{$etype}\" ( \"guid\" BIGINT NOT NULL, \"name\" TEXT NOT NULL, \"reference\" BIGINT NOT NULL, PRIMARY KEY (\"guid\", \"name\", \"reference\"), FOREIGN KEY (\"guid\") REFERENCES \"{$this->prefix}entities{$etype}\" (\"guid\") MATCH SIMPLE ON UPDATE NO ACTION ON DELETE CASCADE ) WITH ( OIDS=FALSE ); ".
          "ALTER TABLE \"{$this->prefix}references{$etype}\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\"; ".
          "DROP INDEX IF EXISTS \"{$this->prefix}references{$etype}_id_guid\"; ".
          "CREATE INDEX \"{$this->prefix}references{$etype}_id_guid\" ON \"{$this->prefix}references{$etype}\" USING btree (\"guid\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}references{$etype}_id_name\"; ".
          "CREATE INDEX \"{$this->prefix}references{$etype}_id_name\" ON \"{$this->prefix}references{$etype}\" USING btree (\"name\"); ".
          "DROP INDEX IF EXISTS \"{$this->prefix}references{$etype}_id_references\"; ".
          "CREATE INDEX \"{$this->prefix}references{$etype}_id_reference\" ON \"{$this->prefix}references{$etype}\" USING btree (\"reference\"); "
      );
    } else {
      // Create the GUID table.
      $this->query(
        "CREATE TABLE IF NOT EXISTS \"{$this->prefix}guids\" ( \"guid\" BIGINT NOT NULL, PRIMARY KEY (\"guid\")); ".
          "ALTER TABLE \"{$this->prefix}guids\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\";"
      );
      // Create the UID table.
      $this->query(
        "CREATE TABLE IF NOT EXISTS \"{$this->prefix}uids\" ( \"name\" TEXT NOT NULL, \"cur_uid\" BIGINT NOT NULL, PRIMARY KEY (\"name\") ) WITH ( OIDS = FALSE ); ".
          "ALTER TABLE \"{$this->prefix}uids\" OWNER TO \"".pg_escape_string($this->link, $this->config['PostgreSQL']['user'])."\";"
      );
      if ($this->usePLPerl) {
        // Create the perl_match function. It's separated into two calls so
        // Postgres will ignore the error if plperl already exists.
        $this->query("CREATE OR REPLACE PROCEDURAL LANGUAGE plperl;");
        $this->query(
          "CREATE OR REPLACE FUNCTION {$this->prefix}match_perl( TEXT, TEXT, TEXT ) RETURNS BOOL AS \$code$ ".
            "my (\$str, \$pattern, \$mods) = @_; ".
            "if (\$pattern eq \'\') { ".
              "return true; ".
            "} ".
            "if (\$mods eq \'\') { ".
              "if (\$str =~ /(\$pattern)/) { ".
                "return true; ".
              "} else { ".
                "return false; ".
              "} ".
            "} else { ".
              "if (\$str =~ /(?\$mods)(\$pattern)/) { ".
                "return true; ".
              "} else { ".
                "return false; ".
              "} ".
            "} \$code$ LANGUAGE plperl IMMUTABLE STRICT COST 10000;"
        );
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
        'Query failed: '.pg_last_error(),
        0,
        null,
        $query
      );
    }
    if (!($result = pg_get_result($this->link))) {
      throw new Exceptions\QueryFailedException(
        'Query failed: '.pg_last_error(),
        0,
        null,
        $query
      );
    }
    if ($error = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE)) {
      // If the tables don't exist yet, create them.
      if ($error === '42P01' && $this->createTables()) {
        if (isset($etypeDirty)) {
          $this->createTables($etypeDirty);
        }
        if (!($result = pg_query($this->link, $query))) {
          throw new Exceptions\QueryFailedException(
            'Query failed: '.pg_last_error(),
            0,
            null,
            $query
          );
        }
      } else {
        throw new Exceptions\QueryFailedException(
          'Query failed: '.pg_last_error(),
          0,
          null,
          $query
        );
      }
    }
    return $result;
  }

  public function deleteEntityByID($guid, $className = null) {
    $etypeDirty = isset($className) ? $className::ETYPE : null;
    $guid = (int) $guid;
    $etype = isset($etypeDirty) ? '_'.pg_escape_string($this->link, $etypeDirty) : '';
    $this->query(
      "DELETE FROM \"{$this->prefix}entities{$etype}\" WHERE \"guid\"={$guid}; ".
        "DELETE FROM \"{$this->prefix}data{$etype}\" WHERE \"guid\"={$guid}; ".
        "DELETE FROM \"{$this->prefix}comparisons{$etype}\" WHERE \"guid\"={$guid}; ".
        "DELETE FROM \"{$this->prefix}references{$etype}\" WHERE \"guid\"={$guid};",
      $etypeDirty
    );
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
          } while ($row && (int) $row['guid'] === $guid);
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
    $fullQueryCoverage = true;
    $sort = $options['sort'] ?? 'cdate';
    $etype = '_'.pg_escape_string($this->link, $etypeDirty);
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
                  '\'{'.pg_escape_string($this->link, $curTag).
                  '}\' <@ ie."tags"';
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
                  pg_escape_string($this->link, $curVar).
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
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "reference"='.
                  pg_escape_string($this->link, (int) $curQguid).')';
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
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "value"=\''.
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
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" LIKE \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" LIKE \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.$this->prefix.'comparisons'.
                  $etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "string" LIKE \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
              }
              break;
            case 'ilike':
            case '!ilike':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" ILIKE \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" ILIKE \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.$this->prefix.'comparisons'.
                  $etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "string" ILIKE \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
              }
              break;
            case 'pmatch':
            case '!pmatch':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" ~ \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" ~ \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.$this->prefix.'comparisons'.
                  $etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "string" ~ \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
              }
              break;
            case 'ipmatch':
            case '!ipmatch':
              if ($curValue[0] === 'cdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."cdate" ~* \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } elseif ($curValue[0] === 'mdate') {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  '(ie."mdate" ~* \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
                break;
              } else {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.$this->prefix.'comparisons'.
                  $etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "string" ~* \''.
                  pg_escape_string($this->link, $curValue[1]).'\')';
              }
              break;
            case 'match':
            case '!match':
              if ($this->usePLPerl) {
                $lastslashpos = strrpos($curValue[1], '/');
                $regex = substr($curValue[1], 1, $lastslashpos - 1);
                $mods = substr($curValue[1], $lastslashpos + 1) ?: '';
                if ($curValue[0] === 'cdate') {
                  if ($curQuery) {
                    $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                  }
                  $curQuery .=
                    (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                    $this->prefix.'match_perl(ie."cdate", \''.
                    pg_escape_string($this->link, $regex).
                    '\', \''.pg_escape_string($this->link, $mods).'\')';
                  break;
                } elseif ($curValue[0] === 'mdate') {
                  if ($curQuery) {
                    $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                  }
                  $curQuery .=
                    (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                    $this->prefix.'match_perl(ie."mdate", \''.
                    pg_escape_string($this->link, $regex).
                    '\', \''.pg_escape_string($this->link, $mods).'\')';
                  break;
                } else {
                  if ($curQuery) {
                    $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                  }
                  $curQuery .=
                    (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                    'ie."guid" IN (SELECT "guid" FROM "'.
                    $this->prefix.'comparisons'.$etype.
                    '" WHERE "name"=\''.
                    pg_escape_string($this->link, $curValue[0]).
                    '\' AND "string" IS NOT NULL AND '.
                    $this->prefix.'match_perl("string", \''.
                    pg_escape_string($this->link, $regex).
                    '\', \''.pg_escape_string($this->link, $mods).'\'))';
                }
              } else {
                if (!($typeIsNot xor $clauseNot)) {
                  if ($curQuery) {
                    $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                  }
                  $curQuery .=
                    (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                    'ie."guid" IN (SELECT "guid" FROM "'.
                    $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                    pg_escape_string($this->link, $curValue[0]).
                    '\' AND "string" IS NOT NULL)';
                }
                // If usePLPerl is false, the query can't cover match clauses.
                $fullQueryCoverage = false;
                break;
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
                  $this->prefix.'comparisons'.$etype.
                  '" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).'\' AND '.
                  '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" > '.
                  ((int) $curValue[1]).') OR '.
                  '("is_int"=FALSE AND "float" IS NOT NULL AND "float" > '.
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
                  $this->prefix.'comparisons'.$etype.
                  '" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).'\' AND '.
                  '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" >= '.
                  ((int) $curValue[1]).') OR '.
                  '("is_int"=FALSE AND "float" IS NOT NULL AND "float" >= '.
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
                  $this->prefix.'comparisons'.$etype.
                  '" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).'\' AND '.
                  '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" < '.
                  ((int) $curValue[1]).') OR '.
                  '("is_int"=FALSE AND "float" IS NOT NULL AND "float" < '.
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
                  $this->prefix.'comparisons'.$etype.
                  '" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).'\' AND '.
                  '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" <= '.
                  ((int) $curValue[1]).') OR '.
                  '("is_int"=FALSE AND "float" IS NOT NULL AND "float" <= '.
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
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "eq_true"='.
                  ($curValue[1] ? 'TRUE' : 'FALSE').')';
                break;
              } elseif ($curValue[1] === 1) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "eq_one"=TRUE)';
                break;
              } elseif ($curValue[1] === 0) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "eq_zero"=TRUE)';
                break;
              } elseif ($curValue[1] === -1) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "eq_negone"=TRUE)';
                break;
              } elseif ($curValue[1] === []) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'comparisons'.$etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).
                  '\' AND "eq_emptyarray"=TRUE)';
                break;
              }
              // Fall through.
            case 'array':
            case '!array':
              if (!($typeIsNot xor $clauseNot)) {
                if ($curQuery) {
                  $curQuery .= $typeIsOr ? ' OR ' : ' AND ';
                }
                $curQuery .= (($typeIsNot xor $clauseNot) ? 'NOT ' : '').
                  'ie."guid" IN (SELECT "guid" FROM "'.
                  $this->prefix.'data'.$etype.'" WHERE "name"=\''.
                  pg_escape_string($this->link, $curValue[0]).'\')';
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
            SELECT \"guid\" FROM \"{$this->prefix}entities{$etype}\" ie
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
              SELECT \"guid\" FROM \"{$this->prefix}entities{$etype}\" ie
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
    $result = $this->query("SELECT \"cur_uid\" FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."';");
    $row = pg_fetch_row($result);
    pg_free_result($result);
    return isset($row[0]) ? (int) $row[0] : null;
  }

  public function import($filename) {
    return $this->importFromFile(
      $filename,
      function ($guid, $tags, $data, $etype) {
        $queries = [];
        $queries[] = "DELETE FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid}; INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$guid});";
        $queries[] = "DELETE FROM \"{$this->prefix}entities_{$etype}\" WHERE \"guid\"={$guid}; INSERT INTO \"{$this->prefix}entities_{$etype}\" (\"guid\", \"tags\", \"cdate\", \"mdate\") VALUES ({$guid}, '".pg_escape_string($this->link, '{'.implode(',', $tags).'}')."', ".unserialize($data['cdate']).", ".unserialize($data['mdate']).");";
        $queries[] = "DELETE FROM \"{$this->prefix}data_{$etype}\" WHERE \"guid\"={$guid};";
        $queries[] = "DELETE FROM \"{$this->prefix}comparisons_{$etype}\" WHERE \"guid\"={$guid};";
        $queries[] = "DELETE FROM \"{$this->prefix}references_{$etype}\" WHERE \"guid\"={$guid};";
        unset($data['cdate'], $data['mdate']);
        if ($data) {
          foreach ($data as $name => $value) {
            $queries[] = "INSERT INTO \"{$this->prefix}data_{$etype}\" (\"guid\", \"name\", \"value\") VALUES ".$this->makeInsertValuesData($guid, $name, $value).';';
            $queries[] = "INSERT INTO \"{$this->prefix}comparisons_{$etype}\" (\"guid\", \"name\", \"eq_true\", \"eq_one\", \"eq_zero\", \"eq_negone\", \"eq_emptyarray\", \"string\", \"int\", \"float\", \"is_int\") VALUES ".$this->makeInsertValuesComparisons($guid, $name, unserialize($value)).';';
            $references = $this->makeInsertValuesReferences($guid, $name, $value);
            if ($references) {
              $queries[] = "INSERT INTO \"{$this->prefix}references_{$etype}\" (\"guid\", \"name\", \"reference\") VALUES {$references};";
            }
          }
        }
        $this->query(implode(' ', $queries), $etype);
      },
      function ($name, $curUid) {
        $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."'; INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".pg_escape_string($this->link, $name)."', ".((int) $curUid).");");
      },
      function () {
        $this->query('BEGIN;');
      },
      function () {
        $this->query('COMMIT;');
      }
    );
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
    $curUid = ($row && is_numeric($row[0])) ? (int) $row[0] : null;
    pg_free_result($result);
    if (!is_int($curUid)) {
      $curUid = 1;
      $this->query("INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".pg_escape_string($this->link, $name)."', {$curUid});");
    } else {
      $curUid++;
      $this->query("UPDATE \"{$this->prefix}uids\" SET \"cur_uid\"={$curUid} WHERE \"name\"='".pg_escape_string($this->link, $name)."';");
    }
    $this->query('COMMIT;');
    return $curUid;
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
    $insertData = function ($guid, $data, $sdata, $etype, $etypeDirty) {
      $fullData = [];
      foreach ($data as $name => $value) {
        $fullData[$name] = [$value, serialize($value)];
      }
      foreach ($sdata as $name => $svalue) {
        $fullData[$name] = [unserialize($svalue), $svalue];
      }
      $queries = [];
      foreach ($fullData as $name => $values) {
        list($value, $svalue) = $values;
        $queries[] = (
          "INSERT INTO \"{$this->prefix}data{$etype}\" (\"guid\", \"name\", \"value\") VALUES ".
            $this->makeInsertValuesData($guid, $name, $svalue).';'
        );
        $queries[] = (
          "INSERT INTO \"{$this->prefix}comparisons{$etype}\" (\"guid\", \"name\", \"eq_true\", \"eq_one\", \"eq_zero\", \"eq_negone\", \"eq_emptyarray\", \"string\", \"int\", \"float\", \"is_int\") VALUES ".
            $this->makeInsertValuesComparisons($guid, $name, $value).';'
        );
        $referenceValues = $this->makeInsertValuesReferences($guid, $name, $svalue);
        if ($referenceValues) {
          $queries[] = "INSERT INTO \"{$this->prefix}references{$etype}\" (\"guid\", \"name\", \"reference\") VALUES {$referenceValues};";
        }
      }
      $this->query(implode(' ', $queries), $etypeDirty);
    };
    return $this->saveEntityRowLike(
      $entity,
      function ($etypeDirty) {
        return '_'.pg_escape_string($this->link, $etypeDirty);
      },
      function ($guid) {
        $result = $this->query("SELECT \"guid\" FROM \"{$this->prefix}guids\" WHERE \"guid\"={$guid};");
        $row = pg_fetch_row($result);
        pg_free_result($result);
        return !isset($row[0]);
      },
      function ($entity, $guid, $tags, $data, $sdata, $cdate, $etype, $etypeDirty) use ($insertData) {
        $this->query("INSERT INTO \"{$this->prefix}guids\" (\"guid\") VALUES ({$guid});");
        $this->query("INSERT INTO \"{$this->prefix}entities{$etype}\" (\"guid\", \"tags\", \"cdate\", \"mdate\") VALUES ({$guid}, '".pg_escape_string($this->link, '{'.implode(',', $tags).'}')."', ".((float) $cdate).", ".((float) $cdate).");", $etypeDirty);
        $insertData($guid, $data, $sdata, $etype, $etypeDirty);
        return true;
      },
      function ($entity, $guid, $tags, $data, $sdata, $mdate, $etype, $etypeDirty) use ($insertData) {
        $result = $this->query("UPDATE \"{$this->prefix}entities{$etype}\" SET \"tags\"='".pg_escape_string($this->link, '{'.implode(',', $tags).'}')."', \"mdate\"=".((float) $mdate)." WHERE \"guid\"={$guid} AND abs(\"mdate\" - ".((float) $entity->mdate).") < 0.001;", $etypeDirty);
        $changed = pg_affected_rows($result);
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
        $this->query("BEGIN;");
      },
      function ($success) {
        pg_get_result($this->link); // Clear any pending result.
        if ($success) {
          $this->query("COMMIT;");
        } else {
          $this->query("ROLLBACK;");
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
    $this->query("DELETE FROM \"{$this->prefix}uids\" WHERE \"name\"='".pg_escape_string($this->link, $name)."'; INSERT INTO \"{$this->prefix}uids\" (\"name\", \"cur_uid\") VALUES ('".pg_escape_string($this->link, $name)."', ".((int) $value).");");
    return true;
  }

  private function makeInsertValuesData($guid, $name, $svalue) {
    return sprintf(
      "(%u, '%s', '%s')",
      (int) $guid,
      pg_escape_string($this->link, $name),
      pg_escape_string(
        $this->link,
        (strpos($svalue, "\0") !== false
          ? '~'.addcslashes($svalue, chr(0).'\\')
          : $svalue)
      )
    );
  }

  private function makeInsertValuesComparisons($guid, $name, $uvalue) {
    return sprintf(
      "(%u, '%s', %s, %s, %s, %s, %s, %s, %d, %f, %s)",
      (int) $guid,
      pg_escape_string($this->link, $name),
      $uvalue == true ? 'TRUE' : 'FALSE',
      (!is_object($uvalue) && $uvalue == 1) ? 'TRUE' : 'FALSE',
      (!is_object($uvalue) && $uvalue == 0) ? 'TRUE' : 'FALSE',
      (!is_object($uvalue) && $uvalue == -1) ? 'TRUE' : 'FALSE',
      $uvalue == [] ? 'TRUE' : 'FALSE',
      is_string($uvalue)
        ? '\''.pg_escape_string($this->link, $uvalue).'\''
        : 'NULL',
      is_object($uvalue) ? 1 : ((int) $uvalue),
      is_object($uvalue) ? 1 : ((float) $uvalue),
      is_int($uvalue) ? 'TRUE' : 'FALSE'
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
        pg_escape_string($this->link, $name),
        (int) $curRef
      );
    }
    return implode(',', $values);
  }
}
