import DriverTrait from './DriverTrait';
import {InvalidParametersError, NotConfiguredError, QueryFailedError, UnableToConnectError} from './Errors';

export default class MySQLDriver extends DriverTrait {
  constructor (NymphConfig) {
    super(NymphConfig);

    this.prefix = this.config.MySQL.prefix;
  }

  __destruct () {
    this.disconnect();
  }

  connect () {
    if (!is_callable('mysqli_connect')) {
      throw new UnableToConnectError('MySQLi PHP extension is not available. It probably has not been ' + 'installed. Please install and configure it in order to use MySQL.');
    }

    let host = this.config.MySQL.host;
    let user = this.config.MySQL.user;
    let password = this.config.MySQL.password;
    let database = this.config.MySQL.database;
    let port = this.config.MySQL.port;

    if (!this.connected) {
      if (this.link = mysqli_connect(host, user, password, database, port)) {
        this.connected = true;
      } else {
        this.connected = false;

        if (host === 'localhost' && user === 'nymph' && password === 'password' && database === 'nymph') {
          throw new NotConfiguredError();
        } else {
          throw new UnableToConnectError('Could not connect: ' + mysqli_error(this.link));
        }
      }
    }

    return this.connected;
  }

  disconnect () {
    if (this.connected) {
      if (is_a(this.link, 'mysqli')) {
        delete this.link;
      }

      this.link = undefined;
      this.connected = false;
    }

    return this.connected;
  }

  createTables (etype = undefined) {
    this.query('SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";');

    let foreignKeyEntityTableGuid = '';
    let foreignKeyDataTableGuid = '';
    let foreignKeyDataComparisonsTableGuid = '';

    if (this.config.MySQL.foreign_keys) {
      foreignKeyEntityTableGuid = ` REFERENCES \`${this.prefix}guids\`(\`guid\`) ON DELETE CASCADE`;
      foreignKeyDataTableGuid = ` REFERENCES \`${this.prefix}entities${etype}\`(\`guid\`) ON DELETE CASCADE`;
      foreignKeyDataComparisonsTableGuid = ` REFERENCES \`${this.prefix}entities${etype}\`(\`guid\`) ON DELETE CASCADE`;
    }

    if (undefined !== etype) {
      etype = '_' + mysqli_real_escape_string(this.link, etype);
      this.query(`CREATE TABLE IF NOT EXISTS \`${this.prefix}entities${etype}\` (\`guid\` BIGINT(20) UNSIGNED NOT NULL${foreignKeyEntityTableGuid}, \`tags\` LONGTEXT, \`varlist\` LONGTEXT, \`cdate\` DECIMAL(18,6) NOT NULL, \`mdate\` DECIMAL(18,6) NOT NULL, PRIMARY KEY (\`guid\`), INDEX \`id_cdate\` USING BTREE (\`cdate\`), INDEX \`id_mdate\` USING BTREE (\`mdate\`), FULLTEXT \`id_tags\` (\`tags\`), FULLTEXT \`id_varlist\` (\`varlist\`)) ENGINE ${this.config.MySQL.engine} CHARACTER SET utf8 COLLATE utf8_bin;`);
      this.query(`CREATE TABLE IF NOT EXISTS \`${this.prefix}data${etype}\` (\`guid\` BIGINT(20) UNSIGNED NOT NULL${foreignKeyDataTableGuid}, \`name\` TEXT NOT NULL, \`value\` LONGTEXT NOT NULL, PRIMARY KEY (\`guid\`,\`name\`(255))) ENGINE ${this.config.MySQL.engine} CHARACTER SET utf8 COLLATE utf8_bin;`);
      this.query(`CREATE TABLE IF NOT EXISTS \`${this.prefix}comparisons${etype}\` (\`guid\` BIGINT(20) UNSIGNED NOT NULL${foreignKeyDataComparisonsTableGuid}, \`name\` TEXT NOT NULL, \`references\` LONGTEXT, \`eq_true\` BOOLEAN, \`eq_one\` BOOLEAN, \`eq_zero\` BOOLEAN, \`eq_negone\` BOOLEAN, \`eq_emptyarray\` BOOLEAN, \`string\` LONGTEXT, \`int\` BIGINT, \`float\` DOUBLE, \`is_int\` BOOLEAN NOT NULL, PRIMARY KEY (\`guid\`, \`name\`(255)), FULLTEXT \`id_references\` (\`references\`)) ENGINE ${this.config.MySQL.engine} CHARACTER SET utf8 COLLATE utf8_bin;`);
    } else {
      this.query(`CREATE TABLE IF NOT EXISTS \`${this.prefix}guids\` (\`guid\` BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (\`guid\`)) ENGINE ${this.config.MySQL.engine} CHARACTER SET utf8 COLLATE utf8_bin;`);
      this.query(`CREATE TABLE IF NOT EXISTS \`${this.prefix}uids\` (\`name\` TEXT NOT NULL, \`cur_uid\` BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (\`name\`(100))) ENGINE ${this.config.MySQL.engine} CHARACTER SET utf8 COLLATE utf8_bin;`);
    }

    return true;
  }

  query (query, etypeDirty = undefined) {
    var result;

    if (!(result = mysqli_query(this.link, query))) {
      if (mysqli_errno(this.link) == 1146 && this.createTables()) {
        if (undefined !== etypeDirty) {
          this.createTables(etypeDirty);
        }

        if (!(result = mysqli_query(this.link, query))) {
          throw new QueryFailedError('Query failed: ' + mysqli_errno(this.link) + ': ' + mysqli_error(this.link), 0, undefined, query);
        }
      } else {
        throw new QueryFailedError('Query failed: ' + mysqli_errno(this.link) + ': ' + mysqli_error(this.link), 0, undefined, query);
      }
    }

    return result;
  }

  deleteEntityByID (guid, etypeDirty = undefined) {
    var etype = undefined !== etypeDirty ? '_' + mysqli_real_escape_string(this.link, etypeDirty) : '';

    if (this.config.MySQL.transactions) {
      this.query('BEGIN;');
    }

    this.query(`DELETE FROM \`${this.prefix}guids\` WHERE \`guid\`='` + +guid + "';");
    this.query(`DELETE FROM \`${this.prefix}entities${etype}\` WHERE \`guid\`='` + +guid + "';", etypeDirty);
    this.query(`DELETE FROM \`${this.prefix}data${etype}\` WHERE \`guid\`='` + +guid + "';", etypeDirty);
    this.query(`DELETE FROM \`${this.prefix}comparisons${etype}\` WHERE \`guid\`='` + +guid + "';", etypeDirty);

    if (this.config.MySQL.transactions) {
      this.query('COMMIT;');
    }

    if (this.config.cache) {
      this.cleanCache(guid);
    }

    return true;
  }

  deleteUID (name) {
    if (!name) {
      return false;
    }

    this.query(`DELETE FROM \`${this.prefix}uids\` WHERE \`name\`='` + mysqli_real_escape_string(this.link, name) + "';");
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
    var result = this.query(`SELECT * FROM \`${this.prefix}uids\` ORDER BY \`name\`;`);
    var row = mysqli_fetch_assoc(result);

    while (row) {
      row.name;
      row.cur_uid;
      writeCallback(`<${row.name}>[${row.cur_uid}]\n`);
      row = mysqli_fetch_assoc(result);
    }

    writeCallback('\n#\n');
    writeCallback('# Entities\n');
    writeCallback('#\n\n');
    result = this.query('SHOW TABLES;');
    var etypes = [];
    row = mysqli_fetch_row(result);

    while (row) {
      if (strpos(row[0], this.prefix + 'entities_') === 0) {
        etypes.push(row[0].substr((this.prefix + 'entities_').length));
      }

      row = mysqli_fetch_row(result);
    }

    for (var etype of Object.values(etypes)) {
      result = this.query(`SELECT e.*, d.\`name\` AS \`dname\`, d.\`value\` AS \`dvalue\` FROM \`${this.prefix}entities_${etype}\` e LEFT JOIN \`${this.prefix}data_${etype}\` d ON e.\`guid\`=d.\`guid\` ORDER BY e.\`guid\`;`);
      row = mysqli_fetch_assoc(result);

      while (row) {
        var guid = +row.guid;
        var tags = row.tags === '' ? [] : row.tags.split(' ');
        var cdate = +row.cdate;
        var mdate = +row.mdate;
        writeCallback(`{${guid}}<${etype}>[` + tags.join(',') + ']\n');
        writeCallback('\tcdate=' + JSON.stringify(serialize(cdate)) + '\n');
        writeCallback('\tmdate=' + JSON.stringify(serialize(mdate)) + '\n');

        if (undefined !== row.dname) {
          do {
            writeCallback(`\t${row.dname}=` + JSON.stringify(row.dvalue) + '\n');
            row = mysqli_fetch_assoc(result);
          } while (+(row.guid === guid));
        } else {
          row = mysqli_fetch_assoc(result);
        }
      }
    }
  }

  makeEntityQuery (options, selectors, etypeDirty, subquery = false) {
    var fullQueryCoverage = true;
    var sort = options.sort ? options.sort : 'cdate';
    var etype = '_' + mysqli_real_escape_string(this.link, etypeDirty);
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

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "ie.`guid`='" + +(curGuid + "'");
            }

            break;

          case 'tag':
          case '!tag':
            if (xor(typeIsNot, clauseNot)) {
              if (typeIsOr) {
                for (var curTag of Object.values(curValue)) {
                  if (curQuery) {
                    curQuery += ' OR ';
                  }

                  curQuery += "ie.`tags` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curTag) + "[[:>:]]'";
                }
              } else {
                curQuery += "ie.`tags` NOT REGEXP '[[:<:]](" + mysqli_real_escape_string(this.link, curValue.join('|')) + ")[[:>:]]'";
              }
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              var groupQuery = '';

              for (var curTag of Object.values(curValue)) {
                groupQuery += (typeIsOr ? ' ' : ' +') + curTag;
              }

              curQuery += "MATCH (ie.`tags`) AGAINST ('" + mysqli_real_escape_string(this.link, groupQuery) + "' IN BOOLEAN MODE)";
            }

            break;

          case 'isset':
          case '!isset':
            if (xor(typeIsNot, clauseNot)) {
              for (var curVar of Object.values(curValue)) {
                if (curQuery) {
                  curQuery += typeIsOr ? ' OR ' : ' AND ';
                }

                curQuery += "(ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curVar) + "[[:>:]]'";
                curQuery += ' OR ' + this.makeDataPart('data', etype, "`name`='" + mysqli_real_escape_string(this.link, curVar) + "' AND `value`='N;'") + ')';
              }
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              groupQuery = '';

              for (var curVar of Object.values(curValue)) {
                groupQuery += (typeIsOr ? ' ' : ' +') + curVar;
              }

              curQuery += "MATCH (ie.`varlist`) AGAINST ('" + mysqli_real_escape_string(this.link, groupQuery) + "' IN BOOLEAN MODE)";
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

            if (curQuery) {
              curQuery += typeIsOr ? ' OR ' : ' AND ';
            }

            if (xor(typeIsNot, clauseNot)) {
              curQuery += "(ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR (";
              var noPrepend = true;

              for (var curQguid of Object.values(guids)) {
                if (!noPrepend) {
                  curQuery += typeIsOr ? ' OR ' : ' AND ';
                } else {
                  noPrepend = false;
                }

                curQuery += this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND `references` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curQguid) + "[[:>:]]'");
              }

              curQuery += '))';
            } else {
              groupQuery = '';

              for (var curQguid of Object.values(guids)) {
                groupQuery += (typeIsOr ? ' ' : ' +') + curQguid;
              }

              curQuery += this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND MATCH (`references`) AGAINST ('" + mysqli_real_escape_string(this.link, groupQuery) + "' IN BOOLEAN MODE)");
            }

            break;

          case 'strict':
          case '!strict':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`cdate`=' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`mdate`=' + +curValue[1];
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

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('data', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "`value`='" + mysqli_real_escape_string(this.link, svalue) + "'") + ')';
            }

            break;

          case 'like':
          case '!like':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`cdate` LIKE '" + mysqli_real_escape_string(this.link, curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`mdate` LIKE '" + mysqli_real_escape_string(this.link, curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "`string` LIKE '" + mysqli_real_escape_string(this.link, curValue[1]) + "'") + ')';
            }

            break;

          case 'ilike':
          case '!ilike':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`cdate` LIKE LOWER('" + mysqli_real_escape_string(this.link, curValue[1]) + "'))";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`mdate` LIKE LOWER('" + mysqli_real_escape_string(this.link, curValue[1]) + "'))";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "LOWER(`string`) LIKE LOWER('" + mysqli_real_escape_string(this.link, curValue[1]) + "')") + ')';
            }

            break;

          case 'pmatch':
          case '!pmatch':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`cdate` REGEXP '" + mysqli_real_escape_string(this.link, curValue[1]) + "')";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`mdate` REGEXP '" + mysqli_real_escape_string(this.link, curValue[1]) + "')";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "`string` REGEXP '" + mysqli_real_escape_string(this.link, curValue[1]) + "'") + ')';
            }

            break;

          case 'ipmatch':
          case '!ipmatch':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`cdate` REGEXP LOWER('" + mysqli_real_escape_string(this.link, curValue[1]) + "'))";
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "(ie.`mdate` REGEXP LOWER('" + mysqli_real_escape_string(this.link, curValue[1]) + "'))";
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + "LOWER(`string`) REGEXP LOWER('" + mysqli_real_escape_string(this.link, curValue[1]) + "')") + ')';
            }

            break;

          case 'gt':
          case '!gt':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`cdate`>' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`mdate`>' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '((`is_int`=TRUE AND `int` > ' + +curValue[1] + ') OR (' + '`is_int`=FALSE AND `float` > ' + +curValue[1] + '))') + ')';
            }

            break;

          case 'gte':
          case '!gte':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`cdate`>=' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`mdate`>=' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '((`is_int`=TRUE AND `int` >= ' + +curValue[1] + ') OR (' + '`is_int`=FALSE AND `float` >= ' + +curValue[1] + '))') + ')';
            }

            break;

          case 'lt':
          case '!lt':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`cdate`<' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`mdate`<' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '((`is_int`=TRUE AND `int` < ' + +curValue[1] + ') OR (' + '`is_int`=FALSE AND `float` < ' + +curValue[1] + '))') + ')';
            }

            break;

          case 'lte':
          case '!lte':
            if (curValue[0] == 'cdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`cdate`<=' + +curValue[1];
              break;
            } else if (curValue[0] == 'mdate') {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + 'ie.`mdate`<=' + +curValue[1];
              break;
            } else {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '((`is_int`=TRUE AND `int` <= ' + +curValue[1] + ') OR (' + '`is_int`=FALSE AND `float` <= ' + +curValue[1] + '))') + ')';
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

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '`eq_true`=' + (curValue[1] ? 'TRUE' : 'FALSE')) + ')';
              break;
            } else if (curValue[1] === 1) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '`eq_one`=TRUE') + ')';
              break;
            } else if (curValue[1] === 0) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '`eq_zero`=TRUE') + ')';
              break;
            } else if (curValue[1] === -1) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '`eq_negone`=TRUE') + ')';
              break;
            } else if (curValue[1] === []) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += '(' + (xor(typeIsNot, clauseNot) ? "ie.`varlist` NOT REGEXP '[[:<:]]" + mysqli_real_escape_string(this.link, curValue[0]) + "[[:>:]]' OR " : '') + this.makeDataPart('comparisons', etype, "`name`='" + mysqli_real_escape_string(this.link, curValue[0]) + "' AND " + (xor(typeIsNot, clauseNot) ? 'NOT ' : '') + '`eq_emptyarray`=TRUE') + ')';
              break;
            }
            // Fall through.
          case 'match':
          case '!match':
          case 'array':
          case '!array':
            if (!(xor(typeIsNot, clauseNot))) {
              if (curQuery) {
                curQuery += typeIsOr ? ' OR ' : ' AND ';
              }

              curQuery += "MATCH (ie.`varlist`) AGAINST ('+" + mysqli_real_escape_string(this.link, curValue[0]) + "' IN BOOLEAN MODE)";
            }

            fullQueryCoverage = false;
            break;
        }
      }
    });

    switch (sort) {
      case 'guid':
        sort = '`guid`';
        break;

      case 'mdate':
        sort = '`mdate`';
        break;

      case 'cdate':
      default:
        sort = '`cdate`';
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

        query = `SELECT e.\`guid\`, e.\`tags\`, e.\`cdate\`, e.\`mdate\`, d.\`name\`, d.\`value\` FROM \`${this.prefix}entities${etype}\` e LEFT JOIN \`${this.prefix}data${etype}\` d ON e.\`guid\`=d.\`guid\` INNER JOIN (SELECT ie.\`guid\` FROM \`${this.prefix}entities${etype}\` ie WHERE (` + queryParts.join(') AND (') + ') ORDER BY ie.' + (undefined !== options.reverse && options.reverse ? sort + ' DESC' : sort) + `${limit}${offset}) f ON e.\`guid\`=f.\`guid\`;`;
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
          query = `SELECT e.\`guid\`, e.\`tags\`, e.\`cdate\`, e.\`mdate\`, d.\`name\`, d.\`value\` FROM \`${this.prefix}entities${etype}\` e LEFT JOIN \`${this.prefix}data${etype}\` d ON e.\`guid\`=d.\`guid\` INNER JOIN (SELECT ie.\`guid\` FROM \`${this.prefix}entities${etype}\` ie ORDER BY ie.` + (undefined !== options.reverse && options.reverse ? sort + ' DESC' : sort) + `${limit}${offset}) f ON e.\`guid\`=f.\`guid\`;`;
        } else {
          query = `SELECT e.\`guid\`, e.\`tags\`, e.\`cdate\`, e.\`mdate\`, d.\`name\`, d.\`value\` FROM \`${this.prefix}entities${etype}\` e LEFT JOIN \`${this.prefix}data${etype}\` d ON e.\`guid\`=d.\`guid\` ORDER BY e.` + (undefined !== options.reverse && options.reverse ? sort + ' DESC' : sort) + ';';
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
    return this.getEntitesRowLike(options, selectors, ['ref', '!ref', 'guid', '!guid', 'tag', '!tag', 'isset', '!isset', 'strict', '!strict', 'like', '!like', 'ilike', '!ilike', 'pmatch', '!pmatch', 'ipmatch', '!ipmatch', 'gt', '!gt', 'gte', '!gte', 'lt', '!lt', 'lte', '!lte'], [true, false, 1, 0, -1, []], 'mysqli_fetch_row', 'mysqli_free_result', row => {
      return +row[0];
    }, row => {
      return {
        tags: row[1] !== '' ? row[1].split(' ') : [],
        cdate: +row[2],
        mdate: +row[3]
      };
    }, row => {
      return {
        name: row[4],
        svalue: row[5]
      };
    });
  }

  getUID (name) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    var result = this.query(`SELECT \`cur_uid\` FROM \`${this.prefix}uids\` WHERE \`name\`='` + mysqli_real_escape_string(this.link, name) + "';");
    var row = mysqli_fetch_row(result);
    mysqli_free_result(result);
    return undefined !== row[0] ? +row[0] : undefined;
  }

  import (filename) {
    return this.importFromFile(filename, (guid, tags, data, etype) => {
      this.query(`REPLACE INTO \`${this.prefix}guids\` (\`guid\`) VALUES (${guid});`);
      this.query(`REPLACE INTO \`${this.prefix}entities_${etype}\` (\`guid\`, \`tags\`, \`varlist\`, \`cdate\`, \`mdate\`) VALUES (${guid}, '` + mysqli_real_escape_string(this.link, tags.join(' ')) + "', '" + mysqli_real_escape_string(this.link, Object.keys(data).join(' ')) + "', " + unserialize(data.cdate) + ', ' + unserialize(data.mdate) + ');', etype);
      this.query(`DELETE FROM \`${this.prefix}data_${etype}\` WHERE \`guid\`='${guid}';`);
      this.query(`DELETE FROM \`${this.prefix}comparisons_${etype}\` WHERE \`guid\`='${guid}';`);
      delete data.cdate;

      if (data) {
        for (var name in data) {
          var value = data[name];
          this.query(`INSERT INTO \`${this.prefix}data_${etype}\` (\`guid\`, \`name\`, \`value\`) VALUES (${guid}, '` + mysqli_real_escape_string(this.link, name) + "', '" + mysqli_real_escape_string(this.link, value) + "');", etype);
          var query = `INSERT INTO \`${this.prefix}comparisons_${etype}\` (\`guid\`, \`name\`, \`references\`, \`eq_true\`, \`eq_one\`, \`eq_zero\`, \`eq_negone\`, \`eq_emptyarray\`, \`string\`, \`int\`, \`float\`, \`is_int\`) VALUES ` + this.makeInsertValuesSQL(guid, name, value, unserialize(value)) + ';';
          this.query(query, etype);
        }
      }
    }, (name, curUid) => {
      this.query(`INSERT INTO \`${this.prefix}uids\` (\`name\`, \`cur_uid\`) VALUES ('` + mysqli_real_escape_string(this.link, name) + "', " + +curUid + ') ON DUPLICATE KEY UPDATE `cur_uid`=' + +curUid + ';');
    }, this.config.MySQL.transactions ? () => {
      this.query('BEGIN;');
    } : undefined, this.config.MySQL.transactions ? () => {
      this.query('COMMIT;');
    } : undefined);
  }

  newUID (name) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    var result = this.query(`SELECT GET_LOCK('${this.prefix}uids_` + mysqli_real_escape_string(this.link, name) + "', 10);");

    if (mysqli_fetch_row(result)[0] != 1) {
      return undefined;
    }

    if (this.config.MySQL.transactions) {
      this.query('BEGIN;');
    }

    this.query(`INSERT INTO \`${this.prefix}uids\` (\`name\`, \`cur_uid\`) VALUES ('` + mysqli_real_escape_string(this.link, name) + "', 1) ON DUPLICATE KEY UPDATE `cur_uid`=`cur_uid`+1;");
    result = this.query(`SELECT \`cur_uid\` FROM \`${this.prefix}uids\` WHERE \`name\`='` + mysqli_real_escape_string(this.link, name) + "';");
    var row = mysqli_fetch_row(result);
    mysqli_free_result(result);

    if (this.config.MySQL.transactions) {
      this.query('COMMIT;');
    }

    this.query(`SELECT RELEASE_LOCK('${this.prefix}uids_` + mysqli_real_escape_string(this.link, name) + "');");
    return undefined !== row[0] ? +row[0] : undefined;
  }

  renameUID (oldName, newName) {
    if (!oldName || !newName) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query(`UPDATE \`${this.prefix}uids\` SET \`name\`='` + mysqli_real_escape_string(this.link, newName) + "' WHERE `name`='" + mysqli_real_escape_string(this.link, oldName) + "';");
    return true;
  }

  saveEntity (entity) {
    var insertData = (entity, data, sdata, etype, etypeDirty) => {
      var runInsertQuery = (name, value, svalue) => {
        this.query(`INSERT INTO \`${this.prefix}data${etype}\` (\`guid\`, \`name\`, \`value\`) VALUES (` + +entity.guid + ", '" + mysqli_real_escape_string(this.link, name) + "', '" + mysqli_real_escape_string(this.link, svalue) + "');", etypeDirty);
        this.query(`INSERT INTO \`${this.prefix}comparisons${etype}\` (\`guid\`, \`name\`, \`references\`, \`eq_true\`, \`eq_one\`, \`eq_zero\`, \`eq_negone\`, \`eq_emptyarray\`, \`string\`, \`int\`, \`float\`, \`is_int\`) VALUES ` + this.makeInsertValuesSQL(entity.guid, name, svalue, value) + ';', etypeDirty);
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
      return '_' + mysqli_real_escape_string(this.link, etypeDirty);
    }, guid => {
      var result = this.query(`SELECT \`guid\` FROM \`${this.prefix}guids\` WHERE \`guid\`='${guid}';`);
      var row = mysqli_fetch_row(result);
      mysqli_free_result(result);
      return !(undefined !== row[0]);
    }, (entity, data, sdata, varlist, etype, etypeDirty) => {
      this.query(`INSERT INTO \`${this.prefix}guids\` (\`guid\`) VALUES (${entity.guid});`);
      this.query(`INSERT INTO \`${this.prefix}entities${etype}\` (\`guid\`, \`tags\`, \`varlist\`, \`cdate\`, \`mdate\`) VALUES (${entity.guid}, '` + mysqli_real_escape_string(this.link, array_diff(entity.tags, ['']).join(' ')) + "', '" + mysqli_real_escape_string(this.link, varlist.join(' ')) + "', " + +entity.cdate + ', ' + +entity.mdate + ');', etypeDirty);
      insertData(entity, data, sdata, etype, etypeDirty);
    }, (entity, data, sdata, varlist, etype, etypeDirty) => {
      if (this.config.MySQL.row_locking) {
        this.query(`SELECT 1 FROM \`${this.prefix}entities${etype}\` WHERE \`guid\`='` + +entity.guid + "' GROUP BY 1 FOR UPDATE;");
        this.query(`SELECT 1 FROM \`${this.prefix}data${etype}\` WHERE \`guid\`='` + +entity.guid + "' GROUP BY 1 FOR UPDATE;");
        this.query(`SELECT 1 FROM \`${this.prefix}comparisons${etype}\` WHERE \`guid\`='` + +entity.guid + "' GROUP BY 1 FOR UPDATE;");
      }

      if (this.config.MySQL.table_locking) {
        this.query(`LOCK TABLES \`${this.prefix}entities${etype}\` WRITE, \`${this.prefix}data${etype}\` WRITE, \`${this.prefix}comparisons${etype}\` WRITE;`);
      }

      this.query(`UPDATE \`${this.prefix}entities${etype}\` SET \`tags\`='` + mysqli_real_escape_string(this.link, array_diff(entity.tags, ['']).join(' ')) + "', `varlist`='" + mysqli_real_escape_string(this.link, varlist.join(' ')) + "', `mdate`=" + +entity.mdate + " WHERE `guid`='" + +entity.guid + "';", etypeDirty);
      this.query(`DELETE FROM \`${this.prefix}data${etype}\` WHERE \`guid\`='` + +entity.guid + "';");
      this.query(`DELETE FROM \`${this.prefix}comparisons${etype}\` WHERE \`guid\`='` + +entity.guid + "';");
      insertData(entity, data, sdata, etype, etypeDirty);

      if (this.config.MySQL.table_locking) {
        this.query('UNLOCK TABLES;');
      }
    }, this.config.MySQL.transactions ? () => {
      this.query('BEGIN;');
    } : undefined, this.config.MySQL.transactions ? () => {
      this.query('COMMIT;');
    } : undefined);
  }

  setUID (name, value) {
    if (!name) {
      throw new InvalidParametersError('Name not given for UID.');
    }

    this.query(`INSERT INTO \`${this.prefix}uids\` (\`name\`, \`cur_uid\`) VALUES ('` + mysqli_real_escape_string(this.link, name) + "', " + +value + ') ON DUPLICATE KEY UPDATE `cur_uid`=' + +value + ';');
    return true;
  }

  makeDataPart (table, etype, whereClause) {
    return `ie.\`guid\` IN (SELECT \`guid\` FROM \`${this.prefix}${table}${etype}\` WHERE ${whereClause})`;
  }

  makeInsertValuesSQL (guid, name, svalue, uvalue) {
    preg_match_all('/a:3:\\{i:0;s:22:"nymph_entity_reference";i:1;i:(\\d+);/', svalue, references, PREG_PATTERN_ORDER);
    return sprintf("(%u, '%s', '%s', %s, %s, %s, %s, %s, %s, %d, %f, %s)", +guid, mysqli_real_escape_string(this.link, name), mysqli_real_escape_string(this.link, references[1].join(' ')), uvalue == true ? 'TRUE' : 'FALSE', !(typeof uvalue === 'object') && uvalue == 1 ? 'TRUE' : 'FALSE', !(typeof uvalue === 'object') && uvalue == 0 ? 'TRUE' : 'FALSE', !(typeof uvalue === 'object') && uvalue == -1 ? 'TRUE' : 'FALSE', uvalue == [] ? 'TRUE' : 'FALSE', typeof uvalue === 'string' ? "'" + mysqli_real_escape_string(this.link, uvalue) + "'" : 'NULL', typeof uvalue === 'object' ? 1 : +uvalue, typeof uvalue === 'object' ? 1 : +uvalue, typeof uvalue === 'number' ? 'TRUE' : 'FALSE');
  }
}
