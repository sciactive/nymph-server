<?php namespace Nymph\Exceptions;

/**
 * EntityCorruptedException exception.
 *
 * This exception is thrown when at least some of an entity's data is in a state
 * that can't be properly unserialized/determined. This includes when an entity
 * reference refers to a class that cannot be found.
 *
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @see http://nymph.io/
 */
class EntityCorruptedException extends \Exception {
}
