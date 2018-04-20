/**
 * QueryFailedError exception.
 *
 * This exception is thrown when a query to the database returns an error.
 */
export default class QueryFailedError extends Error {
  constructor (message, query = null, ...rest) {
    if (query) {
      message += '\nFull query: ' + query;
    }
    super(message, ...rest);
    this.query = query;
  }

  getQuery () {
    return this.query;
  }
}
