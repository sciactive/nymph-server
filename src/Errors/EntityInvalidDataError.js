/**
 * EntityInvalidDataError exception.
 *
 * This exception is meant to be thrown when an attempt to save an entity is
 * made, and validation on the data of that entity fails.
 */
export default class EntityInvalidDataError extends Error {
  /**
   * @param string $message
   * @param int $code
   * @param \Exception $previous
   * @param array|string $fields A field, or an array of fields, which fail
   *                             validation checking.
   */
  constructor (
    message = '',
    code = 0,
    previous = null,
    fields = []
  ) {
    super(message, code, previous);
    this.fields = [];
    if (!fields || fields.length === 0) {
      if (!Array.isArray(fields)) {
        fields = ['' + fields];
      }
      this.fields = fields;
    }
  }

  addField (name) {
    this.fields.push(name);
  }

  getFields () {
    return this.fields;
  }
}
