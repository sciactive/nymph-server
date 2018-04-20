import DriverTrait from './DriverTrait';
import {InvalidParametersError, NotConfiguredError, QueryFailedError, UnableToConnectError} from './Errors';

export default class SQLite3Driver extends DriverTrait {
  constructor (NymphConfig) {
    super(NymphConfig);

    this.prefix = this.config.SQLite3.prefix;
  }

  __destruct () {
    this.disconnect();
  }

  connect () {
    if (!(typeof SQLite3 === 'function')) {
      throw new UnableToConnectError('SQLite3 PHP extension is not available. It probably has not ' + 'been installed. Please install and configure it in order to use ' + 'SQLite3.');
    }

    var filename = this.config.SQLite3.filename;
    var busyTimeout = this.config.SQLite3.busy_timeout;
    var openFlags = this.config.SQLite3.open_flags;
    var encryptionKey = this.config.SQLite3.encryption_key;

    if (!this.connected) {
      this.link = new SQLite3(filename, openFlags, encryptionKey);

      if (this.link) {
        this.connected = true;
        this.link.busyTimeout(busyTimeout);
        this.link.exec('PRAGMA encoding = "UTF-8";');
        this.link.exec('PRAGMA foreign_keys = 1;');
        this.link.exec('PRAGMA case_sensitive_like = 1;');
        this.link.createFunction('preg_match', 'preg_match', 2, SQLITE3_DETERMINISTIC);
        this.link.createFunction('regexp', (pattern, subject) => {
          return !!this.posixRegexMatch(pattern, subject);
        }, 2, SQLITE3_DETERMINISTIC);
      } else {
        this.connected = false;

        if (filename == ':memory:') {
          throw new NotConfiguredError();
        } else {
          throw new UnableToConnectError('Could not connect.');
        }
      }
    }

    return this.connected;
  }

  disconnect () {
    if (this.connected) {
      if (is_a(this.link, 'SQLite3')) {
        this.link.exec('PRAGMA optimize;');
        this.link.close();
      }

      this.connected = false;
    }

    return this.connected;
  }

  createTables (etype = undefined) {
    this.query("SAVEPOINT 'tablecreation';");

    try {
      if (undefined !== etype) {
        etype = '_' + SQLite3.escapeString(etype);
        this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}entities${etype}" ("guid" INTEGER PRIMARY KEY ASC NOT NULL REFERENCES "${this.prefix}guids"("guid") ON DELETE CASCADE, "tags" TEXT, "varlist" TEXT, "cdate" REAL NOT NULL, "mdate" REAL NOT NULL);`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}entities${etype}_id_cdate" ON "${this.prefix}entities${etype}" ("cdate");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}entities${etype}_id_mdate" ON "${this.prefix}entities${etype}" ("mdate");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}entities${etype}_id_tags" ON "${this.prefix}entities${etype}" ("tags");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}entities${etype}_id_varlist" ON "${this.prefix}entities${etype}" ("varlist");`);
        this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}data${etype}" ("guid" INTEGER NOT NULL REFERENCES "${this.prefix}entities${etype}"("guid") ON DELETE CASCADE, "name" TEXT NOT NULL, "value" TEXT NOT NULL, PRIMARY KEY("guid", "name"));`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}data${etype}_id_guid" ON "${this.prefix}data${etype}" ("guid");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}data${etype}_id_name" ON "${this.prefix}data${etype}" ("name");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}data${etype}_id_value" ON "${this.prefix}data${etype}" ("value");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}data${etype}_id_guid__name_user" ON "${this.prefix}data${etype}" ("guid") WHERE "name" = 'user';`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}data${etype}_id_guid__name_group" ON "${this.prefix}data${etype}" ("guid") WHERE "name" = 'group';`);
        this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}comparisons${etype}" ("guid" INTEGER NOT NULL REFERENCES "${this.prefix}entities${etype}"("guid") ON DELETE CASCADE, "name" TEXT NOT NULL, "references" TEXT, "eq_true" INTEGER, "eq_one" INTEGER, "eq_zero" INTEGER, "eq_negone" INTEGER, "eq_emptyarray" INTEGER, "string" TEXT, "int" INTEGER, "float" REAL, "is_int" INTEGER, PRIMARY KEY("guid", "name"));`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}comparisons${etype}_id_guid" ON "${this.prefix}comparisons${etype}" ("guid");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}comparisons${etype}_id_name" ON "${this.prefix}comparisons${etype}" ("name");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}comparisons${etype}_id_references" ON "${this.prefix}comparisons${etype}" ("references");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}comparisons${etype}_id_name__eq_true" ON "${this.prefix}comparisons${etype}" ("name") WHERE "eq_true" = 1;`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}comparisons${etype}_id_name__not_eq_true" ON "${this.prefix}comparisons${etype}" ("name") WHERE "eq_true" = 0;`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}comparisons${etype}_id_int" ON "${this.prefix}comparisons${etype}" ("int");`);
        this.query(`CREATE INDEX IF NOT EXISTS "${this.prefix}comparisons${etype}_id_float" ON "${this.prefix}comparisons${etype}" ("float");`);
      } else {
        this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}guids" ("guid" INTEGER NOT NULL PRIMARY KEY ASC);`);
        this.query(`CREATE TABLE IF NOT EXISTS "${this.prefix}uids" ("name" TEXT PRIMARY KEY NOT NULL, "cur_uid" INTEGER NOT NULL);`);
      }
    } catch (e) {
      this.query("ROLLBACK TO 'tablecreation';");
      throw e;
    }

    this.query("RELEASE 'tablecreation';");
    return true;
  }

  query (query, etypeDirty = undefined) {
    try {
      var result;

      if (!(result = this.link.query(query))) {
        throw new QueryFailedError('Query failed: ' + this.link.lastErrorCode() + ' - ' + this.link.lastErrorMsg(), 0, undefined, query);
      }
    } catch (e) {
      var errorCode = this.link.lastErrorCode();
      var errorMsg = this.link.lastErrorMsg();

      if (errorCode === 1 && preg_match('/^no such table: /', errorMsg) && this.createTables()) {
        if (undefined !== etypeDirty) {
          this.createTables(etypeDirty);
        }

        if (!(result = this.link.query(query))) {
          throw new QueryFailedError('Query failed: ' + this.link.lastErrorCode() + ' - ' + this.link.lastErrorMsg(), 0, undefined, query);
        }
      } else {
        throw e;
      }
    }

    return result;
  }

  deleteEntityByID (guid, etypeDirty = undefined) {
    guid = +guid;
    var etype = undefined !== etypeDirty ? '_' + SQLite3.escapeString(etypeDirty) : '';
    this.query("SAVEPOINT 'deleteentity';");
    this.query(`DELETE FROM "${this.prefix}guids" WHERE "guid"=${guid};`);
    this.query(`DELETE FROM "${this.prefix}entities${etype}" WHERE "guid"=${guid};`, etypeDirty);
    this.query(`DELETE FROM "${this.prefix}data${etype}" WHERE "guid"=${guid};`, etypeDirty);
    this.query(`DELETE FROM "${this.prefix}comparisons${etype}" WHERE "guid"=${guid};`, etypeDirty);
    this.query("RELEASE 'deleteentity';");

    if (this.config.cache) {
      this.cleanCache(guid);
    }

    return true;
  }

  deleteUID (name) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID');
    }

    this.query(`DELETE FROM "${this.prefix}uids" WHERE "name"='` + SQLite3.escapeString(name) + "';");
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
    var row = result.fetchArray(SQLITE3_ASSOC);

    while (row) {
      row.name;
      row.cur_uid;
      writeCallback(`<${row.name}>[${row.cur_uid}]\n`);
      row = result.fetchArray(SQLITE3_ASSOC);
    }

    result.finalize();
    writeCallback('\n#\n');
    writeCallback('# Entities\n');
    writeCallback('#\n\n');
    result = this.query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name;");
    var etypes = [];
    row = result.fetchArray(SQLITE3_NUM);

    while (row) {
      if (strpos(row[0], this.prefix + 'entities_') === 0) {
        etypes.push(row[0].substr((this.prefix + 'entities_').length));
      }

      row = result.fetchArray(SQLITE3_NUM);
    }

    result.finalize();

    for (var etype of Object.values(etypes)) {
      result = this.query(`SELECT e.*, d."name" AS "dname", d."value" AS "dvalue" FROM "${this.prefix}entities_${etype}" e LEFT JOIN "${this.prefix}data_${etype}" d ON e."guid"=d."guid" ORDER BY e."guid";`);
      row = result.fetchArray(SQLITE3_ASSOC);

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
            row = result.fetchArray(SQLITE3_ASSOC);
          } while (+(row.guid === guid));
        } else {
          row = result.fetchArray(SQLITE3_ASSOC);
        }
      }

      result.finalize();
    }
  }

  makeEntityQuery (options, selectors, etypeDirty, subquery = false) {
    var fullQueryCoverage = true;
    var sort = options.sort ? options.sort : 'cdate';
    var etype = '_' + SQLite3.escapeString(etypeDirty);
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "ie.\"tags\" LIKE '%," + str_replace(['%', '_', ':'], [':%', ':_', '::'], SQLite3.escapeString(curTag)) + ",%' ESCAPE ':'";
            }

            break;

          case 'isset':
          case '!isset':
            for (var curVar of Object.values(curValue)) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "ie.\"varlist\" LIKE '%," + str_replace(['%', '_', ':'], [':%', ':_', '::'], SQLite3.escapeString(curVar)) + ",%' ESCAPE ':'";

              if (xor(typeIsNot, clauseNot)) {
                curQuery += ' OR ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'data' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curVar) + "' AND \"value\"='N;')";
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND (";
              curQuery += "\"references\" LIKE '%,";
              curQuery += guids.join(",%' ESCAPE ':'" + (typeIsOr ? ' OR ' : ' AND ') + "\"references\" LIKE '%,");
              curQuery += ",%' ESCAPE ':'";
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'data' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"value\"='" + SQLite3.escapeString(strpos(svalue, '\\0') !== false ? '~' + addcslashes(svalue, String.fromCharCode(0) + '\\') : svalue) + "')";
            }

            break;

          case 'like':
          case '!like':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" LIKE '" + SQLite3.escapeString(curValue[1]) + "' ESCAPE '\\')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" LIKE '" + SQLite3.escapeString(curValue[1]) + "' ESCAPE '\\')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"string\" LIKE '" + SQLite3.escapeString(curValue[1]) + "' ESCAPE '\\')";
            }

            break;

          case 'ilike':
          case '!ilike':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" LIKE '" + SQLite3.escapeString(curValue[1]) + "' ESCAPE '\\')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" LIKE '" + SQLite3.escapeString(curValue[1]) + "' ESCAPE '\\')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND lower(\"string\") LIKE lower('" + SQLite3.escapeString(curValue[1]) + "') ESCAPE '\\')";
            }

            break;

          case 'pmatch':
          case '!pmatch':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" REGEXP '" + SQLite3.escapeString(curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" REGEXP '" + SQLite3.escapeString(curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"string\" REGEXP '" + SQLite3.escapeString(curValue[1]) + "')";
            }

            break;

          case 'ipmatch':
          case '!ipmatch':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"cdate\" REGEXP '" + SQLite3.escapeString(curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.\"mdate\" REGEXP '" + SQLite3.escapeString(curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND lower(\"string\") REGEXP lower('" + SQLite3.escapeString(curValue[1]) + "'))";
            }

            break;

          case 'match':
          case '!match':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "preg_match('" + SQLite3.escapeString(curValue[1]) + "', ie.\"cdate\")";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "preg_match('" + SQLite3.escapeString(curValue[1]) + "', ie.\"mdate\")";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"string\" IS NOT NULL AND " + "preg_match('" + SQLite3.escapeString(curValue[1]) + "', \"string\"))";
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND " + "((\"is_int\"='1' AND \"int\" IS NOT NULL AND \"int\" > " + +curValue[1] + ') OR (' + "NOT \"is_int\"='1' AND \"float\" IS NOT NULL AND \"float\" > " + +curValue[1] + ')))';
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND " + "((\"is_int\"='1' AND \"int\" IS NOT NULL AND \"int\" >= " + +curValue[1] + ') OR (' + "NOT \"is_int\"='1' AND \"float\" IS NOT NULL AND \"float\" >= " + +curValue[1] + ')))';
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND " + "((\"is_int\"='1' AND \"int\" IS NOT NULL AND \"int\" < " + +curValue[1] + ') OR (' + "NOT \"is_int\"='1' AND \"float\" IS NOT NULL AND \"float\" < " + +curValue[1] + ')))';
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND " + "((\"is_int\"='1' AND \"int\" IS NOT NULL AND \"int\" <= " + +curValue[1] + ') OR (' + "NOT \"is_int\"='1' AND \"float\" IS NOT NULL AND \"float\" <= " + +curValue[1] + ')))';
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"eq_true\"=" + (curValue[1] ? '1' : '0') + ')';
              break;
            } else if (curValue[1] === 1) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"eq_one\"=1)";
              break;
            } else if (curValue[1] === 0) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"eq_zero\"=1)";
              break;
            } else if (curValue[1] === -1) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"eq_negone\"=1)";
              break;
            } else if (curValue[1] === []) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie."guid" IN (SELECT "guid" FROM "' + this.prefix + 'comparisons' + etype + "\" WHERE \"name\"='" + SQLite3.escapeString(curValue[0]) + "' AND \"eq_emptyarray\"=1)";
              break;
            }
            // Fall through.
          case 'array':
          case '!array':
            if (!(xor(typeIsNot, clauseNot))) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += "ie.\"varlist\" LIKE '%," + str_replace(['%', '_', ':'], [':%', ':_', '::'], SQLite3.escapeString(curValue[0])) + ",%' ESCAPE ':'";
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
    return this.getEntitesRowLike(options, selectors, ['ref', '!ref', 'guid', '!guid', 'tag', '!tag', 'isset', '!isset', 'strict', '!strict', 'like', '!like', 'ilike', '!ilike', 'match', '!match', 'pmatch', '!pmatch', 'ipmatch', '!ipmatch', 'gt', '!gt', 'gte', '!gte', 'lt', '!lt', 'lte', '!lte'], [true, false, 1, 0, -1, []], result => {
      return result.fetchArray(SQLITE3_NUM);
    }, result => {
      result.finalize();
    }, row => {
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

    var result = this.query(`SELECT "cur_uid" FROM "${this.prefix}uids" WHERE "name"='` + SQLite3.escapeString(name) + "';");
    var row = result.fetchArray(SQLITE3_NUM);
    result.finalize();
    return undefined !== row[0] ? +row[0] : undefined;
  }

  import (filename) {
    return this.importFromFile(filename, (guid, tags, data, etype) => {
      this.query(`DELETE FROM "${this.prefix}guids" WHERE "guid"=${guid};`);
      this.query(`DELETE FROM "${this.prefix}entities_${etype}" WHERE "guid"=${guid};`);
      this.query(`DELETE FROM "${this.prefix}data_${etype}" WHERE "guid"=${guid};`, etype);
      this.query(`DELETE FROM "${this.prefix}comparisons_${etype}" WHERE "guid"=${guid};`, etype);
      this.query(`INSERT INTO "${this.prefix}guids" ("guid") VALUES (${guid});`);
      this.query(`INSERT INTO "${this.prefix}entities_${etype}" ("guid", "tags", "varlist", "cdate", "mdate") VALUES (${guid}, '` + SQLite3.escapeString(',' + tags.join(',') + ',') + "', '" + SQLite3.escapeString(',' + Object.keys(data).join(',') + ',') + "', " + unserialize(data.cdate) + ', ' + unserialize(data.mdate) + ');', etype);
      delete data.cdate;

      if (data) {
        for (var name in data) {
          var value = data[name];
          this.query(`INSERT INTO "${this.prefix}data_${etype}" ("guid", "name", "value") VALUES ` + this.makeInsertValuesSQLData(guid, name, value) + ';', etype);
          this.query(`INSERT INTO "${this.prefix}comparisons_${etype}" ("guid", "name", "references", "eq_true", "eq_one", "eq_zero", "eq_negone", "eq_emptyarray", "string", "int", "float", "is_int") VALUES ` + this.makeInsertValuesSQL(guid, name, value, unserialize(value)) + ';', etype);
        }
      }
    }, (name, curUid) => {
      this.query(`DELETE FROM "${this.prefix}uids" WHERE "name"='` + SQLite3.escapeString(name) + "';");
      this.query(`INSERT INTO "${this.prefix}uids" ("name", "cur_uid") VALUES ('` + SQLite3.escapeString(name) + "', " + +curUid + ');');
    }, () => {
      this.query("SAVEPOINT 'import';");
    }, () => {
      this.query("RELEASE 'import';");
    });
  }

  newUID (name) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query("SAVEPOINT 'newuid';");
    var result = this.query(`SELECT "cur_uid" FROM "${this.prefix}uids" WHERE "name"='` + SQLite3.escapeString(name) + "';");
    var row = result.fetchArray(SQLITE3_NUM);
    var curUid = is_numeric(row[0]) ? +row[0] : undefined;
    result.finalize();

    if (!(typeof curUid === 'number')) {
      curUid = 1;
      this.query(`INSERT INTO "${this.prefix}uids" ("name", "cur_uid") VALUES ('` + SQLite3.escapeString(name) + `', ${curUid});`);
    } else {
      curUid++;
      this.query(`UPDATE "${this.prefix}uids" SET "cur_uid"=${curUid} WHERE "name"='` + SQLite3.escapeString(name) + "';");
    }

    this.query("RELEASE 'newuid';");
    return curUid;
  }

  renameUID (oldName, newName) {
    if (!oldName || !newName) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query(`UPDATE "${this.prefix}uids" SET "name"='` + SQLite3.escapeString(newName) + "' WHERE \"name\"='" + SQLite3.escapeString(oldName) + "';");
    return true;
  }

  saveEntity (entity) {
    var insertData = (entity, data, sdata, etype, etypeDirty) => {
      var runInsertQuery = (name, value, svalue) => {
        this.query(`INSERT INTO "${this.prefix}data${etype}" ("guid", "name", "value") VALUES ` + this.makeInsertValuesSQLData(entity.guid, name, serialize(value)) + ';', etypeDirty);
        this.query(`INSERT INTO "${this.prefix}comparisons${etype}" ("guid", "name", "references", "eq_true", "eq_one", "eq_zero", "eq_negone", "eq_emptyarray", "string", "int", "float", "is_int") VALUES ` + this.makeInsertValuesSQL(entity.guid, name, serialize(value), value) + ';', etypeDirty);
      };

      for (var name in data) {
        var value = data[name];
        runInsertQuery(name, value, serialize(value));
      }

      for (var name in sdata) {
        var svalue = sdata[name];
        runInsertQuery(name, unserialize(svalue), svalue);
      }
    };

    return this.saveEntityRowLike(entity, etypeDirty => {
      return '_' + SQLite3.escapeString(etypeDirty);
    }, guid => {
      var result = this.query(`SELECT "guid" FROM "${this.prefix}guids" WHERE "guid"=${guid};`);
      var row = result.fetchArray(SQLITE3_NUM);
      result.finalize();
      return !(undefined !== row[0]);
    }, (entity, data, sdata, varlist, etype, etypeDirty) => {
      this.query(`INSERT INTO "${this.prefix}guids" ("guid") VALUES (${entity.guid});`);
      this.query(`INSERT INTO "${this.prefix}entities${etype}" ("guid", "tags", "varlist", "cdate", "mdate") VALUES (${entity.guid}, '` + SQLite3.escapeString(',' + array_diff(entity.tags, ['']).join(',') + ',') + "', '" + SQLite3.escapeString(',' + varlist.join(',') + ',') + "', " + +entity.cdate + ', ' + +entity.mdate + ');', etypeDirty);
      insertData(entity, data, sdata, etype, etypeDirty);
    }, (entity, data, sdata, varlist, etype, etypeDirty) => {
      this.query(`UPDATE "${this.prefix}entities${etype}" SET "tags"='` + SQLite3.escapeString(',' + array_diff(entity.tags, ['']).join(',') + ',') + "', \"varlist\"='" + SQLite3.escapeString(',' + varlist.join(',') + ',') + "', \"cdate\"=" + +entity.cdate + ', "mdate"=' + +entity.mdate + ` WHERE "guid"=${entity.guid};`, etypeDirty);
      this.query(`DELETE FROM "${this.prefix}data${etype}" WHERE "guid"=${entity.guid};`);
      this.query(`DELETE FROM "${this.prefix}comparisons${etype}" WHERE "guid"=${entity.guid};`);
      insertData(entity, data, sdata, etype, etypeDirty);
    }, () => {
      this.query('BEGIN;');
    }, () => {
      this.query('COMMIT;');
    });
  }

  setUID (name, value) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query(`DELETE FROM "${this.prefix}uids" WHERE "name"='` + SQLite3.escapeString(name) + "';");
    this.query(`INSERT INTO "${this.prefix}uids" ("name", "cur_uid") VALUES ('` + SQLite3.escapeString(name) + "', " + +value + ');');
    return true;
  }

  makeInsertValuesSQLData (guid, name, svalue) {
    return sprintf("(%u, '%s', '%s')", +guid, SQLite3.escapeString(name), SQLite3.escapeString(strpos(svalue, '\\0') !== false ? '~' + addcslashes(svalue, String.fromCharCode(0) + '\\') : svalue));
  }

  makeInsertValuesSQL (guid, name, svalue, uvalue) {
    preg_match_all('/a:3:\\{i:0;s:22:"nymph_entity_reference";i:1;i:(\\d+);/', svalue, references, PREG_PATTERN_ORDER);
    return sprintf("(%u, '%s', '%s', %s, %s, %s, %s, %s, %s, %d, %f, %s)", +guid, SQLite3.escapeString(name), SQLite3.escapeString(',' + references[1].join(',') + ','), uvalue == true ? '1' : '0', !(typeof uvalue === 'object') && uvalue == 1 ? '1' : '0', !(typeof uvalue === 'object') && uvalue == 0 ? '1' : '0', !(typeof uvalue === 'object') && uvalue == -1 ? '1' : '0', uvalue == [] ? '1' : '0', typeof uvalue === 'string' ? "'" + SQLite3.escapeString(uvalue) + "'" : 'NULL', typeof uvalue === 'object' ? 1 : +uvalue, typeof uvalue === 'object' ? 1 : +uvalue, typeof uvalue === 'number' ? '1' : '0');
  }
}
