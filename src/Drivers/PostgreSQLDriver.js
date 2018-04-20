import DriverTrait from './DriverTrait';
import {InvalidParametersError, NotConfiguredError, QueryFailedError, UnableToConnectError} from './Errors';

export default class PostgreSQLDriver extends DriverTrait {
  constructor (NymphConfig) {
    super(NymphConfig);

    this.usePLPerl = this.config.PostgreSQL.use_plperl;
    this.prefix = this.config.PostgreSQL.prefix;
  }

  __destruct () {
    this.disconnect();
  }

  connect () {
    if (!is_callable('pg_connect')) {
      throw new UnableToConnectError('PostgreSQL PHP extension is not available. It probably has not ' + 'been installed. Please install and configure it in order to use ' + 'PostgreSQL.');
    }

    let connectionType = this.config.PostgreSQL.connection_type;
    let host = this.config.PostgreSQL.host;
    let port = this.config.PostgreSQL.port;
    let user = this.config.PostgreSQL.user;
    let password = this.config.PostgreSQL.password;
    let database = this.config.PostgreSQL.database;

    if (!this.connected) {
      let connectString;
      if (connectionType === 'host') {
        connectString = "host='" + addslashes(host) + "' port='" + addslashes(port) + "' dbname='" + addslashes(database) + "' user='" + addslashes(user) + "' password='" + addslashes(password) + "' connect_timeout=5";
      } else {
        connectString = "dbname='" + addslashes(database) + "' user='" + addslashes(user) + "' password='" + addslashes(password) + "' connect_timeout=5";
      }

      if (this.config.PostgreSQL.allow_persistent) {
        this.link = pg_connect(connectString + " options='-c enable_hashjoin=off -c enable_mergejoin=off'");
      } else {
        this.link = pg_connect(connectString + " options='-c enable_hashjoin=off -c enable_mergejoin=off'", PGSQL_CONNECT_FORCE_NEW);
      }

      if (this.link) {
        this.connected = true;
      } else {
        this.connected = false;

        if (host === 'localhost' && user === 'nymph' && password === 'password' && database === 'nymph' && connectionType === 'host') {
          throw new NotConfiguredError();
        } else {
          throw new UnableToConnectError('Could not connect: ' + pg_last_error());
        }
      }
    }

    return this.connected;
  }

  disconnect () {
    if (this.connected) {
      if (is_resource(this.link)) {
        pg_close(this.link);
      }

      this.connected = false;
    }

    return this.connected;
  }

  createTables (etype = undefined) {
    this.query('ROLLBACK; BEGIN;');

    if (undefined !== etype) {
      etype = '_' + pg_escape_string(this.link, etype);
      this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}entities${etype}" ( "guid" BIGINT NOT NULL, "tags" TEXT[], "varlist" TEXT[], "cdate" NUMERIC(18,6) NOT NULL, "mdate" NUMERIC(18,6) NOT NULL, PRIMARY KEY ("guid") ) WITH ( OIDS=FALSE ); ` + `ALTER TABLE "${this.prefix}entities${etype}" OWNER TO "` + pg_escape_string(this.link, this.config.PostgreSQL.user) + '"; ' + `DROP INDEX IF EXISTS "${this.prefix}entities${etype}_id_cdate"; ` + `CREATE INDEX "${this.prefix}entities${etype}_id_cdate" ON "${this.prefix}entities${etype}" USING btree ("cdate"); ` + `DROP INDEX IF EXISTS "${this.prefix}entities${etype}_id_mdate"; ` + `CREATE INDEX "${this.prefix}entities${etype}_id_mdate" ON "${this.prefix}entities${etype}" USING btree ("mdate"); ` + `DROP INDEX IF EXISTS "${this.prefix}entities${etype}_id_tags"; ` + `CREATE INDEX "${this.prefix}entities${etype}_id_tags" ON "${this.prefix}entities${etype}" USING gin ("tags"); ` + `DROP INDEX IF EXISTS "${this.prefix}entities${etype}_id_varlist"; ` + `CREATE INDEX "${this.prefix}entities${etype}_id_varlist" ON "${this.prefix}entities${etype}" USING gin ("varlist");`);
      this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}data${etype}" ( "guid" BIGINT NOT NULL, "name" TEXT NOT NULL, "value" TEXT NOT NULL, PRIMARY KEY ("guid", "name"), FOREIGN KEY ("guid") REFERENCES "${this.prefix}entities${etype}" ("guid") MATCH SIMPLE ON UPDATE NO ACTION ON DELETE CASCADE ) WITH ( OIDS=FALSE ); ` + `ALTER TABLE "${this.prefix}data${etype}" OWNER TO "` + pg_escape_string(this.link, this.config.PostgreSQL.user) + '"; ' + `DROP INDEX IF EXISTS "${this.prefix}data${etype}_id_guid"; ` + `CREATE INDEX "${this.prefix}data${etype}_id_guid" ON "${this.prefix}data${etype}" USING btree ("guid"); ` + `DROP INDEX IF EXISTS "${this.prefix}data${etype}_id_name"; ` + `CREATE INDEX "${this.prefix}data${etype}_id_name" ON "${this.prefix}data${etype}" USING btree ("name"); ` + `DROP INDEX IF EXISTS "${this.prefix}data${etype}_id_guid_name__user"; ` + `CREATE INDEX "${this.prefix}data${etype}_id_guid_name__user" ON "${this.prefix}data${etype}" USING btree ("guid") WHERE "name" = 'user'::text; ` + `DROP INDEX IF EXISTS "${this.prefix}data${etype}_id_guid_name__group"; ` + `CREATE INDEX "${this.prefix}data${etype}_id_guid_name__group" ON "${this.prefix}data${etype}" USING btree ("guid") WHERE "name" = 'group'::text;`);
      this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}comparisons${etype}" ( "guid" BIGINT NOT NULL, "name" TEXT NOT NULL, "references" BIGINT[], "eq_true" BOOLEAN, "eq_one" BOOLEAN, "eq_zero" BOOLEAN, "eq_negone" BOOLEAN, "eq_emptyarray" BOOLEAN, "string" TEXT, "int" BIGINT, "float" DOUBLE PRECISION, "is_int" BOOLEAN NOT NULL, PRIMARY KEY ("guid", "name"), FOREIGN KEY ("guid") REFERENCES "${this.prefix}entities${etype}" ("guid") MATCH SIMPLE ON UPDATE NO ACTION ON DELETE CASCADE ) WITH ( OIDS=FALSE ); ` + `ALTER TABLE "${this.prefix}comparisons${etype}" OWNER TO "` + pg_escape_string(this.link, this.config.PostgreSQL.user) + '"; ' + `DROP INDEX IF EXISTS "${this.prefix}comparisons${etype}_id_guid"; ` + `CREATE INDEX "${this.prefix}comparisons${etype}_id_guid" ON "${this.prefix}comparisons${etype}" USING btree ("guid"); ` + `DROP INDEX IF EXISTS "${this.prefix}comparisons${etype}_id_name"; ` + `CREATE INDEX "${this.prefix}comparisons${etype}_id_name" ON "${this.prefix}comparisons${etype}" USING btree ("name"); ` + `DROP INDEX IF EXISTS "${this.prefix}comparisons${etype}_id_references"; ` + `CREATE INDEX "${this.prefix}comparisons${etype}_id_references" ON "${this.prefix}comparisons${etype}" USING gin ("references"); ` + `DROP INDEX IF EXISTS "${this.prefix}comparisons${etype}_id_guid_name_eq_true"; ` + `CREATE INDEX "${this.prefix}comparisons${etype}_id_guid_name_eq_true" ON "${this.prefix}comparisons${etype}" USING btree ("guid", "name") WHERE "eq_true" = TRUE; ` + `DROP INDEX IF EXISTS "${this.prefix}comparisons${etype}_id_guid_name_not_eq_true"; ` + `CREATE INDEX "${this.prefix}comparisons${etype}_id_guid_name_not_eq_true" ON "${this.prefix}comparisons${etype}" USING btree ("guid", "name") WHERE "eq_true" <> TRUE; `);
    } else {
      this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}guids" ( "guid" BIGINT NOT NULL, PRIMARY KEY ("guid")); ` + `ALTER TABLE "${this.prefix}guids" OWNER TO "` + pg_escape_string(this.link, this.config.PostgreSQL.user) + '";');
      this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}uids" ( "name" TEXT NOT NULL, "cur_uid" BIGINT NOT NULL, PRIMARY KEY ("name") ) WITH ( OIDS = FALSE ); ` + `ALTER TABLE "${this.prefix}uids" OWNER TO "` + pg_escape_string(this.link, this.config.PostgreSQL.user) + '";');

      if (this.usePLPerl) {
        this.query('CREATE OR REPLACE PROCEDURAL LANGUAGE plperl;');
        this.query(`CREATE OR REPLACE FUNCTION ${this.prefix}match_perl( TEXT, TEXT, TEXT ) RETURNS BOOL AS $code$ ` + 'my ($str, $pattern, $mods) = @_; ' + "if ($pattern eq '') { " + 'return true; ' + '} ' + "if ($mods eq '') { " + 'if ($str =~ /($pattern)/) { ' + 'return true; ' + '} else { ' + 'return false; ' + '} ' + '} else { ' + 'if ($str =~ /(?$mods)($pattern)/) { ' + 'return true; ' + '} else { ' + 'return false; ' + '} ' + '} $code$ LANGUAGE plperl IMMUTABLE STRICT COST 10000;');
      }
    }

    this.query('COMMIT;');
    return true;
  }

  query (query, etypeDirty = undefined) {
    while (pg_get_result(this.link)) {
      continue;
    }

    if (!pg_send_query(this.link, query)) {
      throw new QueryFailedError('Query failed: ' + pg_last_error(), 0, undefined, query);
    }

    if (!(result = pg_get_result(this.link))) {
      throw new QueryFailedError('Query failed: ' + pg_last_error(), 0, undefined, query);
    }

    if (error = pg_result_error_field(result, PGSQL_DIAG_SQLSTATE)) {
      if (error == '42P01' && this.createTables()) {
        if (undefined !== etypeDirty) {
          this.createTables(etypeDirty);
        }

        if (!(result = pg_query(this.link, query))) {
          throw new QueryFailedError('Query failed: ' + pg_last_error(), 0, undefined, query);
        }
      } else {
        throw new QueryFailedError('Query failed: ' + pg_last_error(), 0, undefined, query);
      }
    }

    return result;
  }

  deleteEntityByID (guid, etypeDirty = undefined) {
    guid = +guid;
    var etype = undefined !== etypeDirty ? '_' + pg_escape_string(this.link, etypeDirty) : '';
    this.query(`DELETE FROM "${this.prefix}entities${etype}" WHERE "guid"=${guid}; DELETE FROM "${this.prefix}data${etype}" WHERE "guid"=${guid}; DELETE FROM "${this.prefix}comparisons${etype}" WHERE "guid"=${guid};`, etypeDirty);
    this.query(`DELETE FROM "${this.prefix}guids" WHERE "guid"=${guid};`);

    if (this.config.cache) {
      this.cleanCache(guid);
    }

    return true;
  }

  deleteUID (name) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID');
    }

    this.query(`DELETE FROM "${this.prefix}uids" WHERE "name"='` + pg_escape_string(this.link, name) + "';");
    return true;
  }

  exportEntities (writeCallback) {
    writeCallback('# Nymph Entity Exchange\n');
    writeCallback('# Nymph Version ' + global.Nymph.Nymph.VERSION + '\n');
    writeCallback('# nymph.io\n');
    writeCallback('#\n');
    writeCallback('# Generation Time: ' + date('r') + '\n');
    writeCallback('#\n');
    writeCallback('# UIDs\n');
    writeCallback('#\n\n');
    var result = this.query(`SELECT * FROM "${this.prefix}uids" ORDER BY "name";`);
    var row = pg_fetch_assoc(result);

    while (row) {
      row.name;
      row.cur_uid;
      writeCallback(`<${row.name}>[${row.cur_uid}]\n`);
      row = pg_fetch_assoc(result);
    }

    writeCallback('\n#\n');
    writeCallback('# Entities\n');
    writeCallback('#\n\n');
    result = this.query('SELECT relname FROM pg_stat_user_tables ORDER BY relname;');
    var etypes = [];
    row = pg_fetch_array(result);

    while (row) {
      if (strpos(row[0], this.prefix + 'entities_') === 0) {
        etypes.push(row[0].substr((this.prefix + 'entities_').length));
      }

      row = pg_fetch_array(result);
    }

    for (var etype of Object.values(etypes)) {
      result = this.query(`SELECT e.*, d."name" AS "dname", d."value" AS "dvalue" FROM "${this.prefix}entities_${etype}" e LEFT JOIN "${this.prefix}data_${etype}" d ON e."guid"=d."guid" ORDER BY e."guid";`);
      row = pg_fetch_assoc(result);

      while (row) {
        var guid = +row.guid;
        var tags = row.tags.substr(1, -1).split(',');
        var cdate = +row.cdate;
        var mdate = +row.mdate;
        writeCallback(`{${guid}}<${etype}>[` + tags.join(',') + ']\n');
        writeCallback('\tcdate=' + JSON.stringify(serialize(cdate)) + '\n');
        writeCallback('\tmdate=' + JSON.stringify(serialize(mdate)) + '\n');

        if (undefined !== row.dname) {
          do {
            writeCallback(`\t${row.dname}=` + JSON.stringify(row.dvalue) + '\n');
            row = pg_fetch_assoc(result);
          } while (+(row.guid === guid));
        } else {
          row = pg_fetch_assoc(result);
        }
      }
    }
  }

  makeEntityQuery (options, selectors, etypeDirty, subquery = false) {
    var fullQueryCoverage = true;
    var sort = options.sort ? options.sort : 'cdate';
    var etype = '_' + pg_escape_string(this.link, etypeDirty);
    var queryParts = this.iterateSelectorsForQuery(selectors, value => {
      var subquery = this.makeEntityQuery(options, [value], etypeDirty, true);
      fullQueryCoverage = fullQueryCoverage && subquery.fullCoverage;
      return subquery.query;
    }, (curQuery, key, value, typeIsOr, typeIsNot) => {
      var clauseNot = key[0] === '!';

      for (var curValue of Object.values(value)) {
        switch (key) {
          case 'guid':
          case '!guid':
            for (var curGuid of Object.values(curValue)) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid"=' + +curGuid;
            }

            break;

          case 'tag':
          case '!tag':
            for (var curTag of Object.values(curValue)) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "'{" + pg_escape_string(this.link, curTag) + "}' <@ ie.\"tags\"";
            }

            break;

          case 'isset':
          case '!isset':
            for (var curVar of Object.values(curValue)) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "'{" + pg_escape_string(this.link, curVar) + "}' <@ ie.\"varlist\"";

              if (xor(typeIsNot, clauseNot)) {
                curQuery += ' OR ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'data' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curVar) + "' AND \"value\"='N;')";
              }

              curQuery += ')';
            }

            break;

          case 'ref':
          case '!ref':
            var guids = [];

            if (Array.isArray(curValue[1])) {
              if ('guid' in curValue[1]) {
                guids.push(+curValue[1].guid);
              } else {
                for (var curEntity of Object.values(curValue[1])) {
                  if (isObject(curEntity)) {
                    guids.push(+curEntity.guid);
                  } else if (Array.isArray(curEntity)) {
                    guids.push(+curEntity.guid);
                  } else {
                    guids.push(+curEntity);
                  }
                }
              }
            } else if (isObject(curValue[1])) {
              guids.push(+curValue[1].guid);
            } else {
              guids.push(+curValue[1]);
            }

            if (guids) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND (";
              curQuery += "'{";
              curQuery += guids.join("}' <@ \"references\"" + (typeIsOr ? ' OR ' : ' AND ') + "'{");
              curQuery += "}' <@ \"references\"";
              curQuery += '))';
            }

            break;

          case 'strict':
          case '!strict':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."cdate"=' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."mdate"=' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              if (is_callable([curValue[1], 'toReference'])) {
                var svalue = serialize(curValue[1].toReference());
              } else {
                svalue = serialize(curValue[1]);
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'data' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"value\"='" + pg_escape_string(this.link, strpos(svalue, '\\0') !== false ? '~' + addcslashes(svalue, String.fromCharCode(0) + '\\') : svalue) + "')";
            }

            break;

          case 'like':
          case '!like':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" LIKE '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" LIKE '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"string\" LIKE '" + pg_escape_string(this.link, curValue[1]) + "')";
            }

            break;

          case 'ilike':
          case '!ilike':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" ILIKE '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" ILIKE '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"string\" ILIKE '" + pg_escape_string(this.link, curValue[1]) + "')";
            }

            break;

          case 'pmatch':
          case '!pmatch':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" ~ '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" ~ '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"string\" ~ '" + pg_escape_string(this.link, curValue[1]) + "')";
            }

            break;

          case 'ipmatch':
          case '!ipmatch':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" ~* '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" ~* '" + pg_escape_string(this.link, curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"string\" ~* '" + pg_escape_string(this.link, curValue[1]) + "')";
            }

            break;

          case 'match':
          case '!match':
            if (this.usePLPerl) {
              var lastslashpos = strrpos(curValue[1], '/');
              var regex = curValue[1].substr(1, lastslashpos - 1);
              var mods = curValue[1].substr(lastslashpos + 1) ? curValue[1].substr(lastslashpos + 1) : '';

              if (curValue[0] == 'cdate') {
                if (curQuery) {
                  curQuery += typeIsOr ? ' OR ' : ' AND ';
                }

                curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + this.prefix + "match_perl(ie.\"cdate\", '" + pg_escape_string(this.link, regex) + "', '" + pg_escape_string(this.link, mods) + "')";
                break;
              } else if (curValue[0] == 'mdate') {
                if (curQuery) {
                  curQuery += typeIsOr ? ' OR ' : ' AND ';
                }

                curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + this.prefix + "match_perl(ie.\"mdate\", '" + pg_escape_string(this.link, regex) + "', '" + pg_escape_string(this.link, mods) + "')";
                break;
              } else {
                if (curQuery) {
                  curQuery += typeIsOr ? ' OR ' : ' AND ';
                }

                curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"string\" IS NOT NULL AND " + this.prefix + "match_perl(\"string\", '" + pg_escape_string(this.link, regex) + "', '" + pg_escape_string(this.link, mods) + "'))";
              }
            } else {
              if (!(xor(typeIsNot, clauseNot))) {
                if (curQuery) {
                  curQuery += typeIsOr ? ' OR ' : ' AND ';
                }

                curQuery += "'{" + pg_escape_string(this.link, curValue[0]) + "}' <@ ie.\"varlist\"";
              }

              fullQueryCoverage = false;
              break;
            }

            break;

          case 'gt':
          case '!gt':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."cdate">' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."mdate">' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND " + '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" > ' + +curValue[1] + ') OR ' + '("is_int"=FALSE AND "float" IS NOT NULL AND "float" > ' + +curValue[1] + ')))';
            }

            break;

          case 'gte':
          case '!gte':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."cdate">=' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."mdate">=' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND " + '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" >= ' + +curValue[1] + ') OR ' + '("is_int"=FALSE AND "float" IS NOT NULL AND "float" >= ' + +curValue[1] + ')))';
            }

            break;

          case 'lt':
          case '!lt':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."cdate"<' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."mdate"<' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND " + '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" < ' + +curValue[1] + ') OR ' + '("is_int"=FALSE AND "float" IS NOT NULL AND "float" < ' + +curValue[1] + ')))';
            }

            break;

          case 'lte':
          case '!lte':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."cdate"<=' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."mdate"<=' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND " + '(("is_int"=TRUE AND "int" IS NOT NULL AND "int" <= ' + +curValue[1] + ') OR ' + '("is_int"=FALSE AND "float" IS NOT NULL AND "float" <= ' + +curValue[1] + ')))';
            }

            break;

          case 'equal':
          case '!equal':
          case 'data':
          case '!data':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."cdate"=' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."mdate"=' + +curValue[1];
              break;
            } else if (curValue[1] === true || curValue[1] === false) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"eq_true\"=" + (curValue[1] ? 'TRUE' : 'FALSE') + ')';
              break;
            } else if (curValue[1] === 1) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"eq_one\"=TRUE)";
              break;
            } else if (curValue[1] === 0) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"eq_zero\"=TRUE)";
              break;
            } else if (curValue[1] === -1) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"eq_negone\"=TRUE)";
              break;
            } else if (curValue[1] === []) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + pg_escape_string(this.link, curValue[0]) + "' AND \"eq_emptyarray\"=TRUE)";
              break;
            }
            // Fall through.
          case 'array':
          case '!array':
            if (!(xor(typeIsNot, clauseNot))) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += "'{" + pg_escape_string(this.link, curValue[0]) + "}' <@ ie.\"varlist\"";
            }

            fullQueryCoverage = false;
            break;
        }
      }
    });

    switch (sort) {
      case 'guid':
        sort = '"guid"';
        break;

      case 'mdate':
        sort = '"mdate"';
        break;

      case 'cdate':
      default:
        sort = '"cdate"';
        break;
    }

    if (queryParts) {
      if (subquery) {
        var query = '((' + queryParts.join(') AND (') + '))';
      } else {
        var limit = '';

        if (fullQueryCoverage && 'limit' in options) {
          limit = ' LIMIT ' + +options.limit;
        }

        var offset = '';

        if (fullQueryCoverage && 'offset' in options) {
          offset = ' OFFSET ' + +options.offset;
        }

        query = `SELECT e."guid", e."tags", e."cdate", e."mdate", d."name", d."value" FROM "${this.prefix}entities${etype}" e LEFT JOIN "${this.prefix}data${etype}" d USING ("guid") INNER JOIN (SELECT "guid" FROM "${this.prefix}entities${etype}" ie WHERE (` + queryParts.join(') AND (') + ') ORDER BY ie.' + (undefined !== options.reverse && options.reverse ? sort + ' DESC' : sort) + `${limit}${offset}) f USING ("guid");`;
      }
    } else {
      if (subquery) {
        query = '';
      } else {
        limit = '';

        if ('limit' in options) {
          limit = ' LIMIT ' + +options.limit;
        }

        offset = '';

        if ('offset' in options) {
          offset = ' OFFSET ' + +options.offset;
        }

        if (limit || offset) {
          query = `SELECT e."guid", e."tags", e."cdate", e."mdate", d."name", d."value" FROM "${this.prefix}entities${etype}" e LEFT JOIN "${this.prefix}data${etype}" d USING ("guid") INNER JOIN (SELECT "guid" FROM "${this.prefix}entities${etype}" ie ORDER BY ie.` + (undefined !== options.reverse && options.reverse ? sort + ' DESC' : sort) + `${limit}${offset}) f USING ("guid");`;
        } else {
          query = `SELECT e."guid", e."tags", e."cdate", e."mdate", d."name", d."value" FROM "${this.prefix}entities${etype}" e LEFT JOIN "${this.prefix}data${etype}" d USING ("guid") ORDER BY ` + (undefined !== options.reverse && options.reverse ? sort + ' DESC' : sort) + ';';
        }
      }
    }

    return {
      fullCoverage: fullQueryCoverage,
      limitOffsetCoverage: fullQueryCoverage,
      query: query
    };
  }

  getEntities (options = [], selectors) {
    var typesAlreadyChecked = ['ref', '!ref', 'guid', '!guid', 'tag', '!tag', 'isset', '!isset', 'strict', '!strict', 'like', '!like', 'ilike', '!ilike', 'pmatch', '!pmatch', 'ipmatch', '!ipmatch', 'gt', '!gt', 'gte', '!gte', 'lt', '!lt', 'lte', '!lte'];

    if (this.usePLPerl) {
      typesAlreadyChecked.push('match');
      typesAlreadyChecked.push('!match');
    }

    return this.getEntitesRowLike(options, selectors, typesAlreadyChecked, [true, false, 1, 0, -1, []], 'pg_fetch_row', 'pg_free_result', row => {
      return +row[0];
    }, row => {
      return {
        tags: row[1].length > 2 ? row[1].substr(1, -1).split(',') : [],
        cdate: +row[2],
        mdate: +row[3]
      };
    }, row => {
      return {
        name: row[4],
        svalue: row[5][0] === '~' ? stripcslashes(row[5].substr(1)) : row[5]
      };
    });
  }

  getUID (name) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    var result = this.query(`SELECT "cur_uid" FROM "${this.prefix}uids" WHERE "name"='` + pg_escape_string(this.link, name) + "';");
    var row = pg_fetch_row(result);
    pg_free_result(result);
    return undefined !== row[0] ? +row[0] : undefined;
  }

  import (filename) {
    return this.importFromFile(filename, (guid, tags, data, etype) => {
      this.query(`DELETE FROM "${this.prefix}guids" WHERE "guid"=${guid}; INSERT INTO "${this.prefix}guids" ("guid") VALUES (${guid});`);
      this.query(`DELETE FROM "${this.prefix}entities_${etype}" WHERE "guid"=${guid}; INSERT INTO "${this.prefix}entities_${etype}" ("guid", "tags", "varlist", "cdate", "mdate") VALUES (${guid}, '` + pg_escape_string(this.link, '{' + tags.join(',') + '}') + "', '" + pg_escape_string(this.link, '{' + Object.keys(data).join(',') + '}') + "', " + unserialize(data.cdate) + ', ' + unserialize(data.mdate) + ');', etype);
      this.query(`DELETE FROM "${this.prefix}data_${etype}" WHERE "guid"=${guid};`);
      this.query(`DELETE FROM "${this.prefix}comparisons_${etype}" WHERE "guid"=${guid};`);
      delete data.cdate;

      if (data) {
        var query = '';

        for (var name in data) {
          var value = data[name];
          query += `INSERT INTO "${this.prefix}data_${etype}" ("guid", "name", "value") VALUES `;
          query += this.makeInsertValuesSQLData(guid, name, value) + '; ';
          query += `INSERT INTO "${this.prefix}comparisons_${etype}" ("guid", "name", "references", "eq_true", "eq_one", "eq_zero", "eq_negone", "eq_emptyarray", "string", "int", "float", "is_int") VALUES `;
          query += this.makeInsertValuesSQL(guid, name, value, unserialize(value)) + '; ';
        }

        this.query(query, etype);
      }
    }, (name, curUid) => {
      this.query(`DELETE FROM "${this.prefix}uids" WHERE "name"='` + pg_escape_string(this.link, name) + `'; INSERT INTO "${this.prefix}uids" ("name", "cur_uid") VALUES ('` + pg_escape_string(this.link, name) + "', " + +curUid + ');');
    }, () => {
      this.query('BEGIN;');
    }, () => {
      this.query('COMMIT;');
    });
  }

  newUID (name) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query('BEGIN;');
    var result = this.query(`SELECT "cur_uid" FROM "${this.prefix}uids" WHERE "name"='` + pg_escape_string(this.link, name) + "' FOR UPDATE;");
    var row = pg_fetch_row(result);
    var curUid = is_numeric(row[0]) ? +row[0] : undefined;
    pg_free_result(result);

    if (!(typeof curUid === 'number')) {
      curUid = 1;
      this.query(`INSERT INTO "${this.prefix}uids" ("name", "cur_uid") VALUES ('` + pg_escape_string(this.link, name) + `', ${curUid});`);
    } else {
      curUid++;
      this.query(`UPDATE "${this.prefix}uids" SET "cur_uid"=${curUid} WHERE "name"='` + pg_escape_string(this.link, name) + "';");
    }

    this.query('COMMIT;');
    return curUid;
  }

  renameUID (oldName, newName) {
    if (!oldName || !newName) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query(`UPDATE "${this.prefix}uids" SET "name"='` + pg_escape_string(this.link, newName) + "' WHERE \"name\"='" + pg_escape_string(this.link, oldName) + "';");
    return true;
  }

  saveEntity (entity) {
    var insertData = (entity, data, sdata, etype, etypeDirty) => {
      var values = [];

      for (var name in data) {
        var uvalue = data[name];
        var svalue = serialize(uvalue);
        values.push(`INSERT INTO "${this.prefix}data${etype}" ("guid", "name", "value") VALUES ` + this.makeInsertValuesSQLData(entity.guid, name, svalue) + ';');
        values.push(`INSERT INTO "${this.prefix}comparisons${etype}" ("guid", "name", "references", "eq_true", "eq_one", "eq_zero", "eq_negone", "eq_emptyarray", "string", "int", "float", "is_int") VALUES ` + this.makeInsertValuesSQL(entity.guid, name, svalue, uvalue) + ';');
      }

      for (var name in sdata) {
        var svalue = sdata[name];
        var uvalue = unserialize(svalue);
        values.push(`INSERT INTO "${this.prefix}data${etype}" ("guid", "name", "value") VALUES ` + this.makeInsertValuesSQLData(entity.guid, name, svalue) + ';');
        values.push(`INSERT INTO "${this.prefix}comparisons${etype}" ("guid", "name", "references", "eq_true", "eq_one", "eq_zero", "eq_negone", "eq_emptyarray", "string", "int", "float", "is_int") VALUES ` + this.makeInsertValuesSQL(entity.guid, name, svalue, uvalue) + ';');
      }

      this.query(values.join(' '), etypeDirty);
    };

    return this.saveEntityRowLike(entity, etypeDirty => {
      return '_' + pg_escape_string(this.link, etypeDirty);
    }, guid => {
      var result = this.query(`SELECT "guid" FROM "${this.prefix}guids" WHERE "guid"=${guid};`);
      var row = pg_fetch_row(result);
      pg_free_result(result);
      return !(undefined !== row[0]);
    }, (entity, data, sdata, varlist, etype, etypeDirty) => {
      this.query(`INSERT INTO "${this.prefix}guids" ("guid") VALUES (${entity.guid});`);
      this.query(`INSERT INTO "${this.prefix}entities${etype}" ("guid", "tags", "varlist", "cdate", "mdate") VALUES (${entity.guid}, '` + pg_escape_string(this.link, '{' + array_diff(entity.tags, ['']).join(',') + '}') + "', '" + pg_escape_string(this.link, '{' + varlist.join(',') + '}') + "', " + +entity.cdate + ', ' + +entity.mdate + ');', etypeDirty);
      insertData(entity, data, sdata, etype, etypeDirty);
    }, (entity, data, sdata, varlist, etype, etypeDirty) => {
      this.query(`UPDATE "${this.prefix}entities${etype}" SET "tags"='` + pg_escape_string(this.link, '{' + array_diff(entity.tags, ['']).join(',') + '}') + "', \"varlist\"='" + pg_escape_string(this.link, '{' + varlist.join(',') + '}') + "', \"cdate\"=" + +entity.cdate + ', "mdate"=' + +entity.mdate + ` WHERE "guid"=${entity.guid};`, etypeDirty);
      this.query(`DELETE FROM "${this.prefix}data${etype}" WHERE "guid"=${entity.guid};`);
      this.query(`DELETE FROM "${this.prefix}comparisons${etype}" WHERE "guid"=${entity.guid};`);
      insertData(entity, data, sdata, etype, etypeDirty);
    }, () => {
      this.query('BEGIN;');
    }, () => {
      pg_get_result(this.link);
      this.query('COMMIT;');
    });
  }

  setUID (name, value) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query(`DELETE FROM "${this.prefix}uids" WHERE "name"='` + pg_escape_string(this.link, name) + `'; INSERT INTO "${this.prefix}uids" ("name", "cur_uid") VALUES ('` + pg_escape_string(this.link, name) + "', " + +value + ');');
    return true;
  }

  makeInsertValuesSQLData (guid, name, svalue) {
    return sprintf("(%u, '%s', '%s')", +guid, pg_escape_string(this.link, name), pg_escape_string(this.link, strpos(svalue, '\\0') !== false ? '~' + addcslashes(svalue, String.fromCharCode(0) + '\\') : svalue));
  }

  makeInsertValuesSQL (guid, name, svalue, uvalue) {
    preg_match_all('/a:3:\\{i:0;s:22:"nymph_entity_reference";i:1;i:(\\d+);/', svalue, references, PREG_PATTERN_ORDER);
    return sprintf("(%u, '%s', '%s', %s, %s, %s, %s, %s, %s, %d, %f, %s)", +guid, pg_escape_string(this.link, name), pg_escape_string(this.link, '{' + references[1].join(',') + '}'), uvalue == true ? 'TRUE' : 'FALSE', !(typeof uvalue === 'object') && uvalue == 1 ? 'TRUE' : 'FALSE', !(typeof uvalue === 'object') && uvalue == 0 ? 'TRUE' : 'FALSE', !(typeof uvalue === 'object') && uvalue == -1 ? 'TRUE' : 'FALSE', uvalue == [] ? 'TRUE' : 'FALSE', typeof uvalue === 'string' ? "'" + pg_escape_string(this.link, uvalue) + "'" : 'NULL', typeof uvalue === 'object' ? 1 : +uvalue, typeof uvalue === 'object' ? 1 : +uvalue, typeof uvalue === 'number' ? 'TRUE' : 'FALSE');
  }
}
