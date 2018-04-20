/**
 * Nymph driver interface.
 */
export default class DriverInterface {
  constructor () {
    for (let name of Object.getOwnPropertyNames(DriverInterface.prototype)) {
      if (name === 'constructor') continue;
      if (this[name].toString().split('\n')[0] !== DriverInterface.prototype[name].toString().split('\n')[0]) {
        throw new Error(`Function signature doesn't match: ${name}`);
      }
    }
  }
  connect () {
    throw new Error('Method not implemented.');
  }
  deleteEntity (entity) {
    throw new Error('Method not implemented.');
  }
  deleteEntityByID (guid) {
    throw new Error('Method not implemented.');
  }
  deleteUID (name) {
    throw new Error('Method not implemented.');
  }
  disconnect () {
    throw new Error('Method not implemented.');
  }
  export (filename) {
    throw new Error('Method not implemented.');
  }
  exportPrint () {
    throw new Error('Method not implemented.');
  }
  getEntities (options = [], ...selectors) {
    throw new Error('Method not implemented.');
  }
  getEntity (options = [], ...selectors) {
    throw new Error('Method not implemented.');
  }
  getUID (name) {
    throw new Error('Method not implemented.');
  }
  hsort (array, property = null, parentProperty = null, caseSensitive = false, reverse = false) {
    throw new Error('Method not implemented.');
  }
  import (filename) {
    throw new Error('Method not implemented.');
  }
  newUID (name) {
    throw new Error('Method not implemented.');
  }
  psort (array, property = null, parentProperty = null, caseSensitive = false, reverse = false) {
    throw new Error('Method not implemented.');
  }
  renameUID (oldName, newName) {
    throw new Error('Method not implemented.');
  }
  saveEntity (entity) {
    throw new Error('Method not implemented.');
  }
  setUID (name, value) {
    throw new Error('Method not implemented.');
  }
  sort (array, property = null, caseSensitive = false, reverse = false) {
    throw new Error('Method not implemented.');
  }
}
