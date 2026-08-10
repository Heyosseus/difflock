<?php

declare(strict_types=1);

namespace Difflock\Exceptions;

use RuntimeException;

/**
 * A connection Difflock cannot introspect.
 *
 * Every driver Laravel ships returns a concrete connection with a schema builder on
 * it, so this is reserved for a custom connection that does not. Thrown rather than
 * worked around: an unreadable connection produces no schema, and a diff against no
 * schema would report every table in the database as dropped.
 */
final class UnreadableConnection extends RuntimeException {}
