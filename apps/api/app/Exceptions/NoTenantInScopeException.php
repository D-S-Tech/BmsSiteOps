<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to create or modify a tenant-scoped model without
 * a tenant in scope. This is a programmer error, not a user error — it means
 * the calling code forgot to set the current tenant before touching the model.
 *
 * Catching this exception is almost never correct. Fix the calling code.
 */
class NoTenantInScopeException extends RuntimeException {}
