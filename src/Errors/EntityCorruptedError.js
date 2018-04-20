/**
 * EntityCorruptedError exception.
 *
 * This exception is thrown when at least some of an entity's data is in a state
 * that can't be properly unserialized/determined. This includes when an entity
 * reference refers to a class that cannot be found.
 */
export default class EntityCorruptedError extends Error {}
