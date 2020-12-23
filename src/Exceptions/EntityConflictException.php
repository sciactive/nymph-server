<?php namespace Nymph\Exceptions;

/**
 * EntityConflictException exception.
 *
 * This exception is thrown when a conflict between requested changes and
 * changes in the database are detected.
 *
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @see http://nymph.io/
 */
class EntityConflictException extends \Exception {
}
