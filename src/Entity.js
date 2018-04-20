import EntityInterface from './EntityInterface';
import Nymph from './Nymph';
import {EntityClassNotFoundError, EntityCorruptedError, InvalidParametersError} from './Errors';

export default class Entity extends EntityInterface {
  constructor (id = 0) {
    super(id);

    this.guid = undefined;
    this.cdate = undefined;
    this.mdate = undefined;
    this.tags = [];
    this.data = [];
    this.sdata = [];
    this.entityCache = [];
    this.isASleepingReference = false;
    this.sleepingReference = undefined;
    this.objectData = [];
    this.privateData = [];
    this.protectedData = [];
    this.whitelistData = false;
    this.protectedTags = [];
    this.whitelistTags = false;
    this.clientEnabledMethods = [];
    this.clientClassName = undefined;
    this.useSkipAc = false;

    if (id > 0) {
      const entity = Nymph.getEntity(
        {
          class: this.constructor.name
        }, {
          type: '&',
          guid: id
        }
      );

      if (undefined !== entity) {
        this.guid = entity.guid;
        this.tags = entity.tags;
        this.putData(entity.getData(), entity.getSData());
        return this;
      }
    }

    return undefined;
  }

  static factory () {
    var className = get_called_class();
    var args = arguments;
    var reflector = new global.ReflectionClass(className);
    var entity = reflector.newInstanceArgs(args);

    if (typeof global['\\SciActive\\Hook'] === 'function') {
      global.SciActive.Hook.hookObject(entity, className + '->', false);
    }

    return entity;
  }

  static factoryReference (reference) {
    var className = reference[2];

    if (!(typeof global[className] === 'function')) {
      throw new EntityClassNotFoundError(`factoryReference called for a class that can't be found, ${className}.`);
    }

    var entity = call_user_func([className, 'factory']);
    entity.referenceSleep(reference);
    return entity;
  }

  __get (name) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (name === 'guid' || name === 'cdate' || name === 'mdate' || name === 'tags') {
      return this[name];
    }

    if (undefined !== this.sdata[name]) {
      this.data[name] = unserialize(this.sdata[name]);
      delete this.sdata[name];
    }

    if (name.substr(-9) === '_pesource' && !(undefined !== this.sdata[name]) && undefined !== this.sdata[name.substr(0, -9)]) {
      this.data[name.substr(0, -9)] = unserialize(this.sdata[name.substr(0, -9)]);
      delete this.sdata[name.substr(0, -9)];
    }

    if (undefined !== this.entityCache[name]) {
      if (this.data[name][0] === 'nymph_entity_reference') {
        if (this.entityCache[name] === 0) {
          var className = this.data[name][2];

          if (!(typeof global[className] === 'function')) {
            throw new EntityCorruptedError("Entity reference refers to a class that can't be found, " + `${className}.`);
          }

          this.entityCache[name] = className.factoryReference(this.data[name]);
          this.entityCache[name].useSkipAc(this.useSkipAc);
        }

        return this.entityCache[name];
      } else {
        throw new EntityCorruptedError('Entity data has become corrupt and cannot be determined.');
      }
    }

    if (!(undefined !== this.data[name])) {
      return this.data[name];
    }

    try {
      if (Array.isArray(this.data[name])) {
        this.data[name].forEach([this, 'referenceToEntity']);
      } else if (isObject(this.data[name]) &&
                 !(
                   (
                     is_a(this.data[name], '\\Nymph\\Entity') ||
                     is_a(this.data[name], '\\SciActive\\HookOverride')
                   ) &&
                   is_callable([this.data[name], 'toReference'])
                 )) {
        for (var curProperty of Object.values(this.data[name])) {
          this.referenceToEntity(curProperty, undefined);
        }
      }
    } catch (e) {
      if (e instanceof EntityClassNotFoundError) {
        throw new EntityCorruptedError(e.getMessage());
      } else {
        throw e;
      }
    }

    if (name.substr(-9) === '_pesource' && !(undefined !== this.data[name])) {
      return this.data[name.substr(0, -9)];
    }

    return this.data[name];
  }

  __isset (name) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (name === 'guid' || name === 'cdate' || name === 'mdate' || name === 'tags') {
      return undefined !== this[name];
    }

    if (undefined !== this.sdata[name]) {
      this.data[name] = unserialize(this.sdata[name]);
      delete this.sdata[name];
    }

    return undefined !== this.data[name];
  }

  __set (name, value) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (name === 'guid') {
      return this[name] = undefined !== value ? +value : undefined;
    }

    if (name === 'cdate' || name === 'mdate') {
      return this[name] = Math.floor(+value * 10000) / 10000;
    }

    if (name === 'tags') {
      return this[name] = Array.from(value);
    }

    if (undefined !== this.sdata[name]) {
      delete this.sdata[name];
    }

    if ((is_a(value, '\\Nymph\\Entity') || is_a(value, '\\SciActive\\HookOverride')) && is_callable([value, 'toReference'])) {
      var saveValue = value.toReference();

      if (Array.isArray(saveValue)) {
        this.entityCache[name] = value;
      } else if (undefined !== this.entityCache[name]) {
        delete this.entityCache[name];
      }

      this.data[name] = saveValue;
      return value;
    } else {
      if (undefined !== this.entityCache[name]) {
        delete this.entityCache[name];
      }

      saveValue = value;

      if (Array.isArray(saveValue)) {
        array_walk_recursive(saveValue, [this, 'entityToReference']);
      }

      return this.data[name] = saveValue;
    }
  }

  __unset (name) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (name === 'guid') {
      delete this[name];
      return;
    }

    if (name === 'cdate') {
      this[name] = undefined;
      return;
    }

    if (name === 'mdate') {
      this[name] = undefined;
      return;
    }

    if (name === 'tags') {
      this[name] = [];
      return;
    }

    if (undefined !== this.entityCache[name]) {
      delete this.entityCache[name];
    }

    delete this.data[name];
    delete this.sdata[name];
  }

  addTag () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    var tagArray = arguments;

    if (Array.isArray(tagArray[0])) {
      tagArray = tagArray[0];
    }

    if (!tagArray) {
      return;
    }

    for (var tag of Object.values(tagArray)) {
      this.tags.push(tag);
    }

    this.tags = Object.keys(array_flip(this.tags));
  }

  arraySearch (array, strict = false) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (!Array.isArray(array)) {
      return false;
    }

    for (var key in array) {
      var curEntity = array[key];

      if (strict ? this.equals(curEntity) : this.is(curEntity)) {
        return key;
      }
    }

    return false;
  }

  clearCache () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    for (var value of Object.values(this.data)) {
      if (Array.isArray(value)) {
        array_walk_recursive(value, [this, 'entityToReference']);
      }
    }

    {
      let _tmp_0 = this.entityCache;

      for (var key in _tmp_0) {
        var value = _tmp_0[key];

        if (strpos(key, 'referenceGuid__') === 0) {
          delete this.entityCache[key];
        } else {
          var value = 0;
        }
      }
    }
  }

  clientEnabledMethods () {
    return this.clientEnabledMethods;
  }

  delete () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    return Nymph.deleteEntity(this);
  }

  entityToReference (item, key) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if ((is_a(item, '\\Nymph\\Entity') || is_a(item, '\\SciActive\\HookOverride')) && undefined !== item.guid && is_callable([item, 'toReference'])) {
      if (!(undefined !== this.entityCache[`referenceGuid__${item.guid}`])) {
        this.entityCache[`referenceGuid__${item.guid}`] = clone(item);
      }

      item = item.toReference();
    }
  }

  equals (object) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (is_a(object, '\\SciActive\\HookOverride')) {
      var testObject = object._hookObject();
    } else {
      testObject = object;
    }

    if (!is_a(testObject, '\\Nymph\\Entity')) {
      return false;
    }

    if (undefined !== this.guid || undefined !== testObject.guid) {
      if (this.guid != testObject.guid) {
        return false;
      }
    }

    if (testObject.constructor.name != this.constructor.name) {
      return false;
    }

    if (testObject.cdate != this.cdate) {
      return false;
    }

    if (testObject.mdate != this.mdate) {
      return false;
    }

    var obData = testObject.getData(true);
    var myData = this.getData(true);
    return obData == myData;
  }

  getData (includeSData = false) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (includeSData) {
      {
        let _tmp_1 = this.sdata;

        for (var key in _tmp_1) {
          var value = _tmp_1[key];
          this.data[key] = unserialize(value);
          delete this.sdata[key];
        }
      }
    }

    return this.data.map([this, 'getDataReference']);
  }

  getDataReference (item) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if ((is_a(item, '\\Nymph\\Entity') || is_a(item, '\\SciActive\\HookOverride')) && is_callable([item, 'toReference'])) {
      return item.toReference();
    } else if (Array.isArray(item)) {
      return item.map([this, 'getDataReference']);
    } else if (isObject(item)) {
      for (var curProperty of Object.values(item)) {
        var curProperty = this.getDataReference(curProperty);
      }
    }

    return item;
  }

  getSData () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    return this.sdata;
  }

  getValidatable () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    {
      let _tmp_2 = this.sdata;

      for (var key in _tmp_2) {
        var value = _tmp_2[key];
        this.data[key] = unserialize(value);
        delete this.sdata[key];
      }
    }
    var data = [];
    {
      let _tmp_3 = this.data;

      for (var key in _tmp_3) {
        var value = _tmp_3[key];
        data[key] = value;
      }
    }
    data.guid = this.guid;
    data.cdate = this.cdate;
    data.mdate = this.mdate;
    data.tags = this.tags;
    data.forEach([this, 'referenceToEntity']);
    array_walk_recursive(data, item => {
      if (is_a(item, '\\SciActive\\HookOverride') && is_callable([item, '_hookObject'])) {
        item = item._hookObject();
      }
    });
    return Object(data);
  }

  getTags () {
    return this.tags;
  }

  hasTag () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (!Array.isArray(this.tags)) {
      return false;
    }

    var tagArray = arguments;

    if (!tagArray) {
      return false;
    }

    if (Array.isArray(tagArray[0])) {
      tagArray = tagArray[0];
    }

    for (var tag of Object.values(tagArray)) {
      if (!(this.tags.indexOf(tag) !== -1)) {
        return false;
      }
    }

    return true;
  }

  inArray (array, strict = false) {
    return this.arraySearch(array, strict) !== false;
  }

  is (object) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (is_a(object, '\\SciActive\\HookOverride')) {
      var testObject = object._hookObject();
    } else {
      testObject = object;
    }

    if (!is_a(testObject, '\\Nymph\\Entity')) {
      return false;
    }

    if (undefined !== this.guid || undefined !== testObject.guid) {
      return this.guid == testObject.guid;
    } else if (!is_callable([testObject, 'getData'])) {
      return false;
    } else {
      var obData = testObject.getData(true);
      var myData = this.getData(true);
      return obData == myData;
    }
  }

  jsonSerialize (clientClassName = true) {
    if (this.isASleepingReference) {
      return this.sleepingReference;
    }

    var object = Object([]);
    object.guid = this.guid;
    object.cdate = this.cdate;
    object.mdate = this.mdate;
    object.tags = this.tags;
    object.data = [];
    {
      let _tmp_5 = this.getData(true);

      for (var key in _tmp_5) {
        var val = _tmp_5[key];

        if (!(this.privateData.indexOf(key) !== -1)) {
          object.data[key] = val;
        }
      }
    }
    object.class = clientClassName && undefined !== this.clientClassName ? this.clientClassName : this.constructor.name;
    return object;
  }

  jsonAcceptTags (tags) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    var currentTags = this.getTags();
    var protectedTags = array_intersect(this.protectedTags, currentTags);
    tags = array_diff(tags, this.protectedTags);

    if (this.whitelistTags !== false) {
      tags = array_intersect(tags, this.whitelistTags);
    }

    this.removeTag(currentTags);
    this.addTag(Object.keys(array_flip(array_merge(tags, protectedTags))));
  }

  jsonAcceptData (data) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    for (var name of Object.values(this.objectData)) {
      if (undefined !== data[name] && Array.from(data[name] === data)) {
        data[name] = Object(data[name]);
      }
    }

    var privateData = {};

    for (var name of Object.values(this.privateData)) {
      if (name in this.data || name in this.sdata) {
        privateData[name] = this[name];
      }

      if (name in data) {
        delete data[name];
      }
    }

    var protectedData = {};

    for (var name of Object.values(this.protectedData)) {
      if (name in this.data || name in this.sdata) {
        protectedData[name] = this[name];
      }

      if (name in data) {
        delete data[name];
      }
    }

    var nonWhitelistData = {};

    if (this.whitelistData !== false) {
      nonWhitelistData = this.getData(true);

      for (var name in data) {
        var val = data[name];

        if (!(this.whitelistData.indexOf(name) !== -1)) {
          delete data[name];
        }
      }
    }

    data = {...nonWhitelistData, ...data, ...protectedData, ...privateData};
    this.putData(data);
  }

  putData (data, sdata = []) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (!Array.isArray(data)) {
      data = [];
    }

    this.entityCache = [];

    for (var name in data) {
      var value = data[name];

      if (Array.isArray(value) && undefined !== value[0] && value[0] === 'nymph_entity_reference') {
        this.entityCache[name] = 0;
      }
    }

    for (var name in sdata) {
      var value = sdata[name];

      if (strpos(value, 'a:3:{i:0;s:22:"nymph_entity_reference";') === 0) {
        this.entityCache[name] = 0;
      }
    }

    this.data = data;
    this.sdata = sdata;
  }

  referenceSleep (reference) {
    if (reference.length !== 3 || reference[0] !== 'nymph_entity_reference' || !(typeof reference[1] === 'number') || !(typeof reference[2] === 'string')) {
      throw new InvalidParametersError('referenceSleep expects parameter 1 to be a valid Nymph entity ' + 'reference.');
    }

    var thisClass = this.constructor.name;

    if (reference[2] !== thisClass) {
      throw new InvalidParametersError('referenceSleep can only be called with an entity reference of the ' + `same class. Given class: ${reference[2]}; this class: ${thisClass}.`);
    }

    this.isASleepingReference = true;
    this.guid = reference[1];
    this.sleepingReference = reference;
  }

  referenceToEntity (item, key) {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (Array.isArray(item)) {
      if (undefined !== item[0] && item[0] === 'nymph_entity_reference') {
        if (!(undefined !== this.entityCache[`referenceGuid__${item[1]}`])) {
          if (!(typeof global[item[2]] === 'function')) {
            throw new EntityClassNotFoundError('Tried to load entity reference that refers to a class that ' + `can't be found, ${item[2]}.`);
          }

          this.entityCache[`referenceGuid__${item[1]}`] = call_user_func([item[2], 'factoryReference'], item);
        }

        item = this.entityCache[`referenceGuid__${item[1]}`];
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

  referenceWake () {
    if (!this.isASleepingReference) {
      return true;
    }

    if (!(typeof global[this.sleepingReference[2]] === 'function')) {
      throw new EntityClassNotFoundError('Tried to wake sleeping reference entity that refers to a class ' + `that can't be found, ${this.sleepingReference[2]}.`);
    }

    var entity = Nymph.getEntity({
      class: this.sleepingReference[2],
      skip_ac: this.useSkipAc
    }, {
      0: '&',
      guid: this.sleepingReference[1]
    });

    if (!(undefined !== entity)) {
      return false;
    }

    this.isASleepingReference = false;
    this.sleepingReference = undefined;
    this.guid = entity.guid;
    this.tags = entity.tags;
    this.putData(entity.getData(), entity.getSData());
    return true;
  }

  refresh () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    if (!(undefined !== this.guid)) {
      return false;
    }

    var refresh = Nymph.getEntity({
      class: this.constructor.name
    }, {
      0: '&',
      guid: this.guid
    });

    if (!(undefined !== refresh)) {
      return 0;
    }

    this.clearCache();
    this.tags = refresh.tags;
    this.cdate = refresh.cdate;
    this.mdate = refresh.mdate;
    this.putData(refresh.getData(), refresh.getSData());
    return true;
  }

  removeTag () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    var tagArray = arguments;

    if (Array.isArray(tagArray[0])) {
      tagArray = tagArray[0];
    }

    for (var tag of Object.values(tagArray)) {
      {
        let _tmp_6 = this.tags;

        for (var curKey in _tmp_6) {
          var curTag = _tmp_6[curKey];

          if (curTag === tag) {
            delete this.tags[curKey];
          }
        }
      }
    }

    this.tags = Object.values(this.tags);
  }

  save () {
    if (this.isASleepingReference) {
      this.referenceWake();
    }

    return Nymph.saveEntity(this);
  }

  toReference () {
    if (this.isASleepingReference) {
      return this.sleepingReference;
    }

    if (!(undefined !== this.guid)) {
      return this;
    }

    return ['nymph_entity_reference', this.guid, this.constructor.name];
  }

  useSkipAc (useSkipAc) {
    this.useSkipAc = !!useSkipAc;
  }
}

Entity.ETYPE = 'entity';
Entity.searchRestrictedData = [];
Entity.clientEnabledStaticMethods = [];
