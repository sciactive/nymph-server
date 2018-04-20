import DriverInterface from './DriverInterface';
import {EntityClassNotFoundError, InvalidParametersError, UnableToConnectError} from './Errors';

export default class DriverTrait extends DriverInterface {
  constructor (nymphConfig) {
    super();
    this.connected = false;
    this.entityCache = [];
    this.entityCount = [];
    this.config = nymphConfig;
    this.connect();
  }

  posixRegexMatch (pattern, subject) {
    return preg_match('~' + str_replace(
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
        '[:xdigit:]'
      ], [
        '\\~',
        '\\b(?=\\w)',
        '(?<=\\w)\\b',
        '[A-Za-z0-9]',
        '[A-Za-z]',
        '[\\x00-\\x7F]',
        '\\s',
        '[\\000\\001\\002\\003\\004\\005\\006\\007\\008\\009\\010\\011\\012\\013\\014\\015\\016\\017\\018\\019\\020\\021\\022\\023\\024\\025\\026\\027\\028\\029\\030\\031\\032\\033\\034\\035\\036\\037\\177]',
        '\\d',
        "[A-Za-z0-9!\"#$%&'()*+,\\-./:;<=>?@[\\\\]^_`{|}\\~]",
        '[a-z]',
        "[A-Za-z0-9!\"#$%&'()*+,\\-./:;<=>?@[\\\\]^_`{|}\\~]",
        "[!\"#$%&'()*+,\\-./:;<=>?@[\\\\]^_`{|}\\~]",
        '[\t\n\\x0B\f\r ]',
        '[A-Z]',
        '[A-Za-z0-9_]',
        '[0-9A-Fa-f]'
      ],
      pattern
    ) + '~', subject);
  }

  export (filename) {
    var fhandle;

    if (!(fhandle = fopen(filename, 'w'))) {
      throw new InvalidParametersError('Provided filename is not writeable.');
    }

    this.exportEntities(output => {
      fwrite(fhandle, output);
    });
    return fclose(fhandle);
  }

  exportPrint () {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=entities.nex;');

    while (ob_end_clean()) {
      continue;
    }

    this.exportEntities(output => {
      echo(output);
    });
    return true;
  }

  importFromFile (filename, saveEntityCallback, saveUIDCallback, startTransactionCallback = undefined, commitTransactionCallback = undefined) {
    var fhandle;

    if (!(fhandle = fopen(filename, 'r'))) {
      throw new InvalidParametersError('Provided filename is unreadable.');
    }

    var guid;
    var line = '';
    var data = [];

    if (startTransactionCallback) {
      startTransactionCallback();
    }

    while (!feof(fhandle)) {
      line += fgets(fhandle, 8192);

      if (line.substr(-1) !== '\n') {
        continue;
      }

      if (preg_match('/^\\s*#/S', line)) {
        line = '';
        continue;
      }

      var matches = [];

      if (preg_match('/^\\s*{(\\d+)}<([\\w-_]+)>\\[([\\w,]*)\\]\\s*$/S', line, matches)) {
        if (guid) {
          saveEntityCallback(guid, tags.split(','), data, etype);
          guid = undefined;
          var tags = [];
          data = [];
        }

        guid = +matches[1];
        var etype = matches[2];
        tags = matches[3];
      } else if (preg_match('/^\\s*([\\w,]+)\\s*=\\s*(\\S.*\\S)\\s*$/S', line, matches)) {
        if (guid) {
          data[matches[1]] = JSON.parse(matches[2]);
        }
      } else if (preg_match('/^\\s*<([^>]+)>\\[(\\d+)\\]\\s*$/S', line, matches)) {
        saveUIDCallback(matches[1], matches[2]);
      }

      line = '';
      this.entityCache = [];
    }

    if (guid) {
      saveEntityCallback(guid, tags.split(','), data, etype);
    }

    if (commitTransactionCallback) {
      commitTransactionCallback();
    }

    return true;
  }

  checkData (data, sdata, selectors, guid = undefined, tags = undefined, typesAlreadyChecked = [], dataValsAreadyChecked = []) {
    for (var curSelector of Object.values(selectors)) {
      var pass = false;

      for (var key in curSelector) {
        var value = curSelector[key];

        if (key === 0) {
          var type = value;
          var typeIsNot = type === '!&' || type === '!|';
          var typeIsOr = type === '|' || type === '!|';
          pass = !typeIsOr;
          continue;
        }

        if (is_numeric(key)) {
          var tmpArr = [value];
          pass = this.checkData(data, sdata, tmpArr);
        } else {
          var clauseNot = key[0] === '!';

          if (typesAlreadyChecked.indexOf(key) !== -1) {
            pass = true;
          } else {
            for (var curValue of Object.values(value)) {
              if ((key === 'guid' || key === '!guid') && !(undefined !== guid) || (key === 'tag' || key === '!tag') && !(undefined !== tags) || (key === 'equal' || key === '!equal' || key === 'data' || key === '!data') && dataValsAreadyChecked.indexOf(curValue[1]) !== -1) {
                pass = true;
              } else {
                if (undefined !== sdata[curValue[0]]) {
                  data[curValue[0]] = unserialize(sdata[curValue[0]]);
                  delete sdata[curValue[0]];
                }

                if (key !== 'guid' && key !== 'tag' && key.substr(0, 1) !== '!' && !((key === 'equal' || key === 'data') && curValue[1] == false) && !(curValue[0] in data)) {
                  pass = false;
                } else {
                  switch (key) {
                    case 'guid':
                    case '!guid':
                      pass = xor(guid == curValue[0], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'tag':
                    case '!tag':
                      pass = xor(tags.indexOf(curValue[0]) !== -1, (xor(typeIsNot, clauseNot)));
                      break;

                    case 'isset':
                    case '!isset':
                      pass = xor(undefined !== data[curValue[0]], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'ref':
                    case '!ref':
                      pass = xor(undefined !== data[curValue[0]] && this.entityReferenceSearch(data[curValue[0]], curValue[1]), (xor(typeIsNot, clauseNot)));
                      break;

                    case 'strict':
                    case '!strict':
                      pass = xor(undefined !== data[curValue[0]] && data[curValue[0]] === curValue[1], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'equal':
                    case '!equal':
                    case 'data':
                    case '!data':
                      pass = xor(!(undefined !== data[curValue[0]]) && curValue[1] == undefined || undefined !== data[curValue[0]] && data[curValue[0]] == curValue[1], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'like':
                    case '!like':
                      pass = xor(undefined !== data[curValue[0]] && preg_match('/^' + str_replace(['%', '_'], ['.*?', '.'], preg_quote(curValue[1], '/')) + '$/', data[curValue[0]]), (xor(typeIsNot, clauseNot)));
                      break;

                    case 'pmatch':
                    case '!pmatch':
                      pass = xor(undefined !== data[curValue[0]] && this.posixRegexMatch(curValue[1], data[curValue[0]]), (xor(typeIsNot, clauseNot)));
                      break;

                    case 'match':
                    case '!match':
                      pass = xor(undefined !== data[curValue[0]] && preg_match(curValue[1], data[curValue[0]]), (xor(typeIsNot, clauseNot)));
                      break;

                    case 'gt':
                    case '!gt':
                      pass = xor(undefined !== data[curValue[0]] && data[curValue[0]] > curValue[1], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'gte':
                    case '!gte':
                      pass = xor(undefined !== data[curValue[0]] && data[curValue[0]] >= curValue[1], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'lt':
                    case '!lt':
                      pass = xor(undefined !== data[curValue[0]] && data[curValue[0]] < curValue[1], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'lte':
                    case '!lte':
                      pass = xor(undefined !== data[curValue[0]] && data[curValue[0]] <= curValue[1], (xor(typeIsNot, clauseNot)));
                      break;

                    case 'array':
                    case '!array':
                      pass = xor(undefined !== data[curValue[0]] && Array.isArray(data[curValue[0]]) && data[curValue[0]].indexOf(curValue[1]) !== -1, (xor(typeIsNot, clauseNot)));
                      break;
                  }
                }
              }

              if (!(xor(typeIsOr, pass))) {
                break;
              }
            }
          }
        }

        if (!(xor(typeIsOr, pass))) {
          break;
        }
      }

      if (!pass) {
        return false;
      }
    }

    return true;
  }

  cleanCache (guid) {
    delete this.entityCache[guid];
  }

  deleteEntity (entity) {
    var className = entity.constructor.name;
    var ret = this.deleteEntityByID(entity.guid, className.ETYPE);

    if (ret) {
      entity.guid = undefined;
    }

    return ret;
  }

  entityReferenceSearch (value, entity) {
    if (!Array.isArray(value) && !(value instanceof Traversable)) {
      return false;
    }

    if (!(undefined !== entity)) {
      throw new InvalidParametersError();
    }

    if (Array.isArray(entity)) {
      for (var curEntity of Object.values(entity)) {
        if (isObject(curEntity)) {
          var curEntity = curEntity.guid;
        }
      }
    } else if (isObject(entity)) {
      entity = [entity.guid];
    } else {
      entity = [+entity];
    }

    if (undefined !== value[0] && value[0] === 'nymph_entity_reference') {
      return entity.indexOf(value[1]) !== -1;
    } else {
      for (var curValue of Object.values(value)) {
        if (this.entityReferenceSearch(curValue, entity)) {
          return true;
        }
      }
    }

    return false;
  }

  formatSelectors (selectors) {
    for (var curSelector of Object.values(selectors)) {
      for (var key in curSelector) {
        var value = curSelector[key];

        if (key === 0) {
          continue;
        }

        if (is_numeric(key)) {
          var tmpArr = [value];
          this.formatSelectors(tmpArr);
          var value = tmpArr[0];
        } else {
          if (!Array.isArray(value)) {
            value = [[value]];
          } else if (!Array.isArray(value[0])) {
            value = [value];
          }

          for (var curValue of Object.values(value)) {
            if (Array.isArray(curValue) && undefined !== curValue[2] && curValue[1] === undefined && typeof curValue[2] === 'string') {
              var timestamp = strtotime(curValue[2]);

              if (timestamp !== false) {
                curValue[1] = timestamp;
              }
            }
          }
        }
      }
    }
  }

  iterateSelectorsForQuery (selectors, recurseCallback, callback) {
    var queryParts = [];

    for (var curSelector of Object.values(selectors)) {
      var curSelectorQuery = '';

      for (var key in curSelector) {
        var value = curSelector[key];

        if (key === 0) {
          var type = value;
          var typeIsNot = type === '!&' || type === '!|';
          var typeIsOr = type === '|' || type === '!|';
          continue;
        }

        var curQuery = '';

        if (is_numeric(key)) {
          if (curQuery) {
            curQuery += typeIsOr ? ' OR ' : ' AND ';
          }

          curQuery += recurseCallback(value);
        } else {
          callback(curQuery, key, value, typeIsOr, typeIsNot);
        }

        if (curQuery) {
          if (curSelectorQuery) {
            curSelectorQuery += typeIsOr ? ' OR ' : ' AND ';
          }

          curSelectorQuery += curQuery;
        }
      }

      if (curSelectorQuery) {
        queryParts.push(curSelectorQuery);
      }
    }

    return queryParts;
  }

  getEntitesRowLike (options, selectors, typesAlreadyChecked, dataValsAreadyChecked, rowFetchCallback, freeResultCallback, getGUIDCallback, getTagsAndDatesCallback, getDataNameAndSValueCallback) {
    if (!this.connected) {
      throw new UnableToConnectError();
    }

    for (var key in selectors) {
      var selector = selectors[key];

      if (!selector || count(selector) === 1 && undefined !== selector[0] && ['&', '!&', '|', '!|'].indexOf(selector[0]) !== -1) {
        delete selectors[key];
        continue;
      }

      if (!(undefined !== selector[0]) || !(['&', '!&', '|', '!|'].indexOf(selector[0]) !== -1)) {
        throw new InvalidParametersError('Invalid query selector passed: ' + print_r(selector, true));
      }
    }

    var entities = [];
    var className = options.class || '\\Nymph\\Entity';

    if (!(typeof global[className] === 'function')) {
      throw new EntityClassNotFoundError(`Query requested using a class that can't be found: ${className}.`);
    }

    var etypeDirty = options.etype || className.ETYPE;
    var ret = options.return || 'entity';
    var count = ocount = 0;

    if (this.config.cache && typeof selectors[1].guid === 'number') {
      if (count(selectors) === 1 && selectors[1][0] === '&' && (count(selectors[1]) === 2 || count(selectors[1]) === 3 && undefined !== selectors[1].tag)) {
        var entity = this.pullCache(selectors[1].guid, className);

        if (undefined !== entity && (!(undefined !== selectors[1].tag) || entity.hasTag(selectors[1].tag))) {
          entity.useSkipAc(Bool(options.skip_ac));
          return [entity];
        }
      }
    }

    this.formatSelectors(selectors);
    var query = this.makeEntityQuery(options, selectors, etypeDirty);
    var result = this.query(query.query, etypeDirty);
    var row = rowFetchCallback(result);

    while (row) {
      var guid = getGUIDCallback(row);
      var tagsAndDates = getTagsAndDatesCallback(row);
      var tags = tagsAndDates.tags;
      var data = {
        cdate: tagsAndDates.cdate,
        mdate: tagsAndDates.mdate
      };
      var dataNameAndSValue = getDataNameAndSValueCallback(row);
      var sdata = [];

      if (undefined !== dataNameAndSValue.name) {
        do {
          dataNameAndSValue = getDataNameAndSValueCallback(row);
          sdata[dataNameAndSValue.name] = dataNameAndSValue.svalue;
          row = rowFetchCallback(result);
        } while (getGUIDCallback(row) === guid);
      } else {
        row = rowFetchCallback(result);
      }

      if (query.fullCoverage) {
        var passed = true;
      } else {
        passed = this.checkData(data, sdata, selectors, undefined, undefined, typesAlreadyChecked, dataValsAreadyChecked);
      }

      if (passed) {
        if (undefined !== options.offset && !query.limitOffsetCoverage && ocount < options.offset) {
          ocount++;
          continue;
        }

        switch (ret) {
          case 'entity':
          default:
            if (this.config.cache) {
              entity = this.pullCache(guid, className);
            } else {
              entity = undefined;
            }

            if (!(undefined !== entity) || data.mdate > entity.mdate) {
              entity = call_user_func([className, 'factory']);
              entity.guid = guid;
              entity.cdate = data.cdate;
              delete data.cdate;
              entity.mdate = data.mdate;
              delete data.mdate;

              if (tags) {
                entity.tags = tags;
              }

              entity.putData(data, sdata);

              if (this.config.cache) {
                this.pushCache(entity, className);
              }
            }

            if (undefined !== options.skip_ac) {
              entity.useSkipAc(!!options.skip_ac);
            }

            entities.push(entity);
            break;

          case 'guid':
            entities.push(guid);
            break;
        }

        if (!query.limitOffsetCoverage) {
          count++;

          if (undefined !== options.limit && count >= options.limit) {
            break;
          }
        }
      }
    }

    freeResultCallback(result);
    return entities;
  }

  getEntity (options = [], selectors) {
    if (isInt(selectors[0]) || is_numeric(selectors[0])) {
      selectors[0] = {
        0: '&',
        guid: +selectors[0]
      };
    }

    options.limit = 1;
    var entities = this.getEntities(options, ...selectors);

    if (!entities) {
      return undefined;
    }

    return entities[0];
  }

  saveEntityRowLike (entity, formatEtypeCallback, checkGUIDCallback, saveNewEntityCallback, saveExistingEntityCallback, startTransactionCallback = undefined, commitTransactionCallback = undefined) {
    if (!(undefined !== entity.guid)) {
      entity.cdate = Date.now() / 1000;
    }

    entity.mdate = Date.now() / 1000;
    var data = entity.getData();
    var sdata = entity.getSData();
    var varlist = [...Object.keys(data), ...Object.keys(sdata)];
    var className = is_callable([entity, '_hookObject']) ? entity._hookObject().constructor.name : entity.constructor.name;
    var etypeDirty = className.ETYPE;
    var etype = formatEtypeCallback(etypeDirty);

    if (startTransactionCallback) {
      startTransactionCallback();
    }

    if (!(undefined !== entity.guid)) {
      while (true) {
        var newId = mt_rand(1, Math.pow(2, 53));

        if (newId < 1) {
          newId = rand(1, 2147483647);
        }

        if (checkGUIDCallback(newId)) {
          break;
        }
      }

      entity.guid = newId;
      saveNewEntityCallback(entity, data, sdata, varlist, etype, etypeDirty);
    } else {
      if (this.config.cache) {
        this.cleanCache(entity.guid);
      }

      saveExistingEntityCallback(entity, data, sdata, varlist, etype, etypeDirty);
    }

    if (commitTransactionCallback) {
      commitTransactionCallback();
    }

    if (this.config.cache) {
      this.pushCache(entity, className);
    }

    return true;
  }

  pullCache (guid, className) {
    if (!(undefined !== this.entityCount[guid])) {
      this.entityCount[guid] = 0;
    }

    this.entityCount[guid]++;

    if (undefined !== this.entityCache[guid][className]) {
      return clone(this.entityCache[guid][className]);
    }

    return undefined;
  }

  pushCache (entity, className) {
    if (!(undefined !== entity.guid)) {
      return;
    }

    if (!(undefined !== this.entityCount[entity.guid])) {
      this.entityCount[entity.guid] = 0;
    }

    this.entityCount[entity.guid]++;

    if (this.entityCount[entity.guid] < this.config.cache_threshold) {
      return;
    }

    if (Array.isArray(this.entityCache[entity.guid])) {
      this.entityCache[entity.guid][className] = clone(entity);
    } else {
      while (this.config.cache_limit && this.entityCache.length >= this.config.cache_limit) {
        asort(this.entityCount);
        {
          let _tmp_2 = this.entityCount;

          for (var key in _tmp_2) {
            var val = _tmp_2[key];

            if (undefined !== this.entityCache[key]) {
              break;
            }
          }
        }

        if (undefined !== this.entityCache[key]) {
          delete this.entityCache[key];
        }
      }

      this.entityCache[entity.guid] = {
        [className]: clone(entity)
      };
    }

    this.entityCache[entity.guid][className].clearCache();
  }

  hsort (array, property = undefined, parentProperty = undefined, caseSensitive = false, reverse = false) {
    this.sort(array, property, caseSensitive, reverse);

    if (!(undefined !== parentProperty)) {
      return;
    }

    var newArray = [];

    while (array) {
      var changed = false;

      for (var key in array) {
        var curEntity = array[key];

        if (!(undefined !== curEntity[parentProperty]) || !curEntity[parentProperty].inArray([...newArray, ...array])) {
          newArray.push(curEntity);
          delete array[key];
          changed = true;
          break;
        } else {
          var pkey = curEntity[parentProperty].arraySearch(newArray);

          if (pkey !== false) {
            var ancestry = [array[key][parentProperty]];
            var newKey = pkey;

            while (undefined !== newArray[newKey + 1] && undefined !== newArray[newKey + 1][parentProperty] && newArray[newKey + 1][parentProperty].inArray(ancestry)) {
              ancestry.push(newArray[newKey + 1]);
              newKey += 1;
            }

            newKey += 1;

            if (undefined !== newArray[newKey]) {
              newArray.splice(newKey, 0, [curEntity]);
              newArray = Object.values(newArray);
            } else {
              newArray[newKey] = curEntity;
            }

            delete array[key];
            changed = true;
            break;
          }
        }
      }

      if (!changed) {
        var entitiesLeft = array.splice(0);
        newArray = [...newArray, ...entitiesLeft];
      }
    }

    array = Object.values(newArray);
  }

  psort (array, property = undefined, parentProperty = undefined, caseSensitive = false, reverse = false) {
    if (undefined !== property) {
      this.sortProperty = property;
      this.sortParent = parentProperty;
      this.sortCaseSensitive = caseSensitive;
      global.usort(array, [this, 'sortProperty']);
    }

    if (reverse) {
      array = array.reverse();
    }
  }

  sort (array, property = undefined, caseSensitive = false, reverse = false) {
    if (undefined !== property) {
      this.sortProperty = property;
      this.sortParent = undefined;
      this.sortCaseSensitive = caseSensitive;
      global.usort(array, [this, 'sortProperty']);
    }

    if (reverse) {
      array = array.reverse();
    }
  }

  sortProperty (a, b) {
    var property = this.sortProperty;
    var parent = this.sortParent;

    if (undefined !== parent && (undefined !== a[parent][property] || undefined !== b[parent][property])) {
      if (!this.sortCaseSensitive && typeof a[parent][property] === 'string' && typeof b[parent][property] === 'string') {
        var aprop = a[parent][property].toUpperCase();
        var bprop = b[parent][property].toUpperCase();

        if (aprop > bprop) {
          return 1;
        }

        if (aprop < bprop) {
          return -1;
        }
      } else {
        if (a[parent][property] > b[parent][property]) {
          return 1;
        }

        if (a[parent][property] < b[parent][property]) {
          return -1;
        }
      }
    }

    if (!this.sortCaseSensitive && typeof a[property] === 'string' && typeof b[property] === 'string') {
      aprop = a[property].toUpperCase();
      bprop = b[property].toUpperCase();

      if (aprop > bprop) {
        return 1;
      }

      if (aprop < bprop) {
        return -1;
      }
    } else {
      if (a[property] > b[property]) {
        return 1;
      }

      if (a[property] < b[property]) {
        return -1;
      }
    }

    return 0;
  }
}
