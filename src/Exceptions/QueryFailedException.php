<?php namespace Nymph\Exceptions;

/**
 * QueryFailedException exception.
 *
 * This exception is thrown when a query to the database returns an error.
 *
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://nymph.io/
 */
class QueryFailedException extends \Exception {
  protected $query;
  public function __construct($message, $code, $previous, $query = null) {
    if ($query) {
      $message .= "\nFull query: ".$query;
    }
    parent::__construct($message, $code, $previous);
    $this->query = $query;
  }
  final public function getQuery() {
    return $this->query;
  }
}
