<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace nuelcyoung\tenantable\Exceptions;

use RuntimeException;

/** Raised when a resolved tenant is not authorized for the current request. */
class TenantAccessDeniedException extends RuntimeException
{
    public static function forTenant(int $tenantId): self
    {
        return new self("Tenantable: access to tenant {$tenantId} is not authorized for this request.");
    }
}
