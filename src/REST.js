import {EntityInvalidDataError} from './Errors';

export default class REST {
  run (method, action, data) {
    method = method.toUpperCase();

    if (is_callable([this, method])) {
      return this[method](action, data);
    }

    return this.httpError(405, 'Method Not Allowed');
  }

  DELETE (action = '', data = '') {
    if (!(['entity', 'entities', 'uid'].indexOf(action) !== -1)) {
      return this.httpError(400, 'Bad Request');
    }

    ob_start();

    if (['entity', 'entities'].indexOf(action) !== -1) {
      var ents = JSON.parse(data);

      if (action === 'entity') {
        ents = [ents];
      }

      var deleted = [];
      var failures = false;

      for (var delEnt of Object.values(ents)) {
        var guid = +delEnt.guid;
        var etype = delEnt.etype;

        try {
          if (Nymph.deleteEntityByID(guid, etype)) {
            deleted.push(guid);
          } else {
            failures = true;
          }
        } catch (e) {
          failures = true;
        }
      }

      if (!deleted) {
        if (failures) {
          return this.httpError(400, 'Bad Request');
        } else {
          return this.httpError(500, 'Internal Server Error');
        }
      }

      header('HTTP/1.1 200 OK', true, 200);
      header('Content-Type: application/json');

      if (action === 'entity') {
        echo(JSON.stringify(deleted[0]));
      } else {
        echo(JSON.stringify(deleted));
      }
    } else {
      if (!Nymph.deleteUID(`${data}`)) {
        return this.httpError(500, 'Internal Server Error');
      }

      header('HTTP/1.1 204 No Content', true, 204);
    }

    ob_end_flush();
    return true;
  }

  POST (action = '', data = '') {
    if (!(['entity', 'entities', 'uid', 'method'].indexOf(action) !== -1)) {
      return this.httpError(400, 'Bad Request');
    }

    ob_start();

    if (['entity', 'entities'].indexOf(action) !== -1) {
      var ents = JSON.parse(data);

      if (action === 'entity') {
        ents = [ents];
      }

      var created = [];
      var invalidData = false;

      for (var newEnt of Object.values(ents)) {
        if (+(newEnt.guid > 0)) {
          invalidData = true;
          continue;
        }

        var entity = this.loadEntity(newEnt);

        if (!entity) {
          invalidData = true;
          continue;
        }

        try {
          if (entity.save()) {
            created.push(entity);
          }
        } catch (e) {
          if (e instanceof EntityInvalidDataError) {
            invalidData = true;
          } else if (true) {
            return this.httpError(500, 'Internal Server Error', e);
          }
        }
      }

      if (!created) {
        if (invalidData) {
          return this.httpError(400, 'Bad Request');
        } else {
          return this.httpError(500, 'Internal Server Error');
        }
      }

      header('HTTP/1.1 201 Created', true, 201);
      header('Content-Type: application/json');

      if (action === 'entity') {
        echo(JSON.stringify(created[0]));
      } else {
        echo(JSON.stringify(created));
      }
    } else if (action === 'method') {
      var args = JSON.parse(data);
      args.params.forEach([this, 'referenceToEntity']);

      if (undefined !== args.static && args.static) {
        var className = args.class;

        if (!(typeof global[className] === 'function') || !(undefined !== className[clientEnabledStaticMethods])) {
          return this.httpError(400, 'Bad Request');
        }

        if (!(className[clientEnabledStaticMethods].indexOf(args.method) !== -1)) {
          return this.httpError(403, 'Forbidden');
        }

        try {
          var ret = call_user_func_array([className, args.method], args.params);
          header('Content-Type: application/json');
          echo(JSON.stringify({
            return: ret
          }));
        } catch (e) {
          return this.httpError(500, 'Internal Server Error', e);
        }
      } else {
        entity = this.loadEntity(args.entity);

        if (!entity || +(args.entity.guid > 0 && !entity.guid) || !is_callable([entity, args.method])) {
          return this.httpError(400, 'Bad Request');
        }

        if (!(entity.clientEnabledMethods().indexOf(args.method) !== -1)) {
          return this.httpError(403, 'Forbidden');
        }

        try {
          ret = call_user_func_array([entity, args.method], args.params);
          header('Content-Type: application/json');
          echo(JSON.stringify({
            entity: entity,
            return: ret
          }));
        } catch (e) {
          return this.httpError(500, 'Internal Server Error', e);
        }
      }

      header('HTTP/1.1 200 OK', true, 200);
    } else {
      try {
        var result = Nymph.newUID(`${data}`);
      } catch (e) {
        return this.httpError(500, 'Internal Server Error', e);
      }

      if (!(typeof result === 'number')) {
        return this.httpError(500, 'Internal Server Error');
      }

      header('HTTP/1.1 201 Created', true, 201);
      header('Content-Type: text/plain');
      echo(result);
    }

    ob_end_flush();
    return true;
  }

  PUT (action = '', data = '') {
    if (!(['entity', 'entities', 'uid'].indexOf(action) !== -1)) {
      return this.httpError(400, 'Bad Request');
    }

    ob_start();

    if (action === 'uid') {
      var args = JSON.parse(data);

      if (!(undefined !== args.name) || !(undefined !== args.value) || !(typeof args.name === 'string') || !is_numeric(args.value)) {
        return this.httpError(400, 'Bad Request');
      }

      try {
        var result = Nymph.setUID(args.name, +args.value);
      } catch (e) {
        return this.httpError(500, 'Internal Server Error', e);
      }

      if (!result) {
        return this.httpError(500, 'Internal Server Error');
      }

      header('Content-Type: text/plain');
      echo(JSON.stringify(result));
    } else {
      var ents = JSON.parse(data);

      if (action === 'entity') {
        ents = [ents];
      }

      var saved = [];
      var invalidData = false;
      var notfound = false;
      var lastError;

      for (var newEnt of Object.values(ents)) {
        if (!is_numeric(newEnt.guid) || +(newEnt.guid <= 0)) {
          invalidData = true;
          continue;
        }

        var entity = this.loadEntity(newEnt);

        if (!entity) {
          invalidData = true;
          continue;
        }

        try {
          if (entity.save()) {
            saved.push(entity);
          }
        } catch (e) {
          if (e instanceof EntityInvalidDataError) {
            invalidData = true;
          } else {
            lastError = e;
          }
        }
      }

      if (!saved) {
        if (invalidData) {
          return this.httpError(400, 'Bad Request');
        } else if (notfound) {
          return this.httpError(404, 'Not Found');
        } else {
          return this.httpError(500, 'Internal Server Error', lastError);
        }
      }

      header('Content-Type: application/json');

      if (action === 'entity') {
        echo(JSON.stringify(saved[0]));
      } else {
        echo(JSON.stringify(saved));
      }
    }

    header('HTTP/1.1 200 OK', true, 200);
    ob_end_flush();
    return true;
  }

  GET (action = '', data = '') {
    if (!(['entity', 'entities', 'uid'].indexOf(action) !== -1)) {
      return this.httpError(400, 'Bad Request');
    }

    var actionMap = {
      entity: 'getEntity',
      entities: 'getEntities',
      uid: 'getUID'
    };
    var method = actionMap[action];

    if (['entity', 'entities'].indexOf(action) !== -1) {
      var args = JSON.parse(data);

      if (!Array.isArray(args)) {
        return this.httpError(400, 'Bad Request');
      }

      var count = count(args);

      if (count < 1 || !Array.isArray(args[0])) {
        return this.httpError(400, 'Bad Request');
      }

      if (!(undefined !== args[0].class) || !(typeof global[args[0].class] === 'function')) {
        return this.httpError(400, 'Bad Request');
      }

      args[0].source = 'client';
      args[0].skip_ac = false;

      if (count > 1) {
        for (var i = 1; i < count; i++) {
          var newArg = REST.translateSelector(args[0].class, args[i]);

          if (newArg === false) {
            return this.httpError(400, 'Bad Request');
          }

          args[i] = newArg;
        }
      }

      try {
        var result = call_user_func_array(`\\Nymph\\Nymph::${method}`, args);
      } catch (e) {
        return this.httpError(500, 'Internal Server Error', e);
      }

      if (!result) {
        if (action === 'entity' || Nymph[config.empty_list_error]) {
          return this.httpError(404, 'Not Found');
        }
      }

      header('Content-Type: application/json');
      echo(JSON.stringify(result));
      return true;
    } else {
      try {
        result = Nymph[method](`${data}`);
      } catch (e) {
        return this.httpError(500, 'Internal Server Error', e);
      }

      if (result === undefined) {
        return this.httpError(404, 'Not Found');
      } else if (!(typeof result === 'number')) {
        return this.httpError(500, 'Internal Server Error');
      }

      header('Content-Type: text/plain');
      echo(result);
      return true;
    }
  }

  static translateSelector (className, selector) {
    var restricted = [];

    if (undefined !== className[searchRestrictedData]) {
      restricted = className[searchRestrictedData];
    }

    var filterClauses = (clause, value) => {
      var unrestrictedClauses = ['guid', 'tag'];
      var scalarClauses = ['isset'];

      if (!restricted || unrestrictedClauses.indexOf(clause) !== -1) {
        return value;
      }

      if (scalarClauses.indexOf(clause) !== -1) {
        if (Array.isArray(value)) {
          return Object.values(array_diff(value, restricted));
        } else {
          return restricted.indexOf(value) !== -1 ? undefined : value;
        }
      } else {
        if (Array.isArray(value[0])) {
          return Object.values(value.filter(arr => {
            return !(restricted.indexOf(arr[0]) !== -1);
          }));
        } else {
          return restricted.indexOf(value[0]) !== -1 ? undefined : value;
        }
      }
    };

    var newSel = [];

    for (var key in selector) {
      var val = selector[key];

      if (key === 'type' || key === 0) {
        var tmpArg = [val];
        newSel = array_merge(tmpArg, newSel);
      } else if (is_numeric(key)) {
        if (undefined !== val.type || undefined !== val[0] && ['&', '!&', '|', '!|'].indexOf(val[0]) !== -1) {
          var tmpSel = REST.translateSelector(className, val);

          if (tmpSel === false) {
            return false;
          }

          newSel.push(tmpSel);
        } else {
          for (var k2 in val) {
            var v2 = val[k2];

            if (k2 in newSel) {
              return false;
            }

            var value = filterClauses(k2, v2);

            if (value) {
              newSel[k2] = value;
            }
          }
        }
      } else {
        value = filterClauses(key, val);

        if (value) {
          newSel[key] = value;
        }
      }
    }

    if (!(undefined !== newSel[0]) || !(['&', '!&', '|', '!|'].indexOf(newSel[0]) !== -1)) {
      return false;
    }

    return newSel;
  }

  loadEntity (entityData) {
    if (!(typeof global[entityData.class] === 'function') || entityData.class === 'Entity') {
      return false;
    }

    if (+(entityData.guid > 0)) {
      var entity = Nymph.getEntity({
        class: entityData.class
      }, {
        0: '&',
        guid: +entityData.guid
      });

      if (entity === undefined) {
        return false;
      }
    } else if (is_callable([entityData.class, 'factory'])) {
      entity = call_user_func([entityData.class, 'factory']);
    } else {
      entity = new entityData.class();
    }

    entity.jsonAcceptTags(entityData.tags);

    if (undefined !== entityData.cdate) {
      entity.cdate = +entityData.cdate;
    }

    if (undefined !== entityData.mdate) {
      entity.mdate = +entityData.mdate;
    }

    entity.jsonAcceptData(entityData.data);
    return entity;
  }

  httpError (errorCode, message, exception = undefined) {
    header(`HTTP/1.1 ${errorCode} ${message}`, true, errorCode);

    if (exception) {
      echo(JSON.stringify({
        textStatus: `${errorCode} ${message}`,
        exception: exception.constructor.name,
        code: exception.getCode(),
        message: exception.getMessage()
      }));
    } else {
      echo(JSON.stringify({
        textStatus: `${errorCode} ${message}`
      }));
    }

    return false;
  }

  referenceToEntity (item, key) {
    if (Array.isArray(item)) {
      if (undefined !== item[0] && item[0] === 'nymph_entity_reference') {
        item = call_user_func([item[2], 'factoryReference'], item);
      } else {
        item.forEach([this, 'referenceToEntity']);
      }
    } else if (isObject(item) &&
               !(
                 (
                   is_a(item, '\\Nymph\\Entity') ||
                   is_a(item, '\\SciActive\\HookOverride')
                 ) &&
                 is_callable([item, 'toReference'])
               )) {
      for (var curProperty of Object.values(item)) {
        this.referenceToEntity(curProperty, undefined);
      }
    }
  }
}
