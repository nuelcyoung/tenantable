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

/**
 * Thrown when a tenant row exists but its storage does not yet, or never
 * finished being created.
 *
 * Extends TenantInactiveException so apps that already render an "unavailable"
 * page keep working: both mean the tenant exists but cannot serve you now.
 */
class TenantNotReadyException extends TenantInactiveException
{
    public static function forStatus(int $id, string $status): self
    {
        return $status === 'failed'
            ? new self(
                "Tenant with ID {$id} failed to provision. Check the logs and re-run provisioning "
                . 'before it can serve traffic.'
            )
            : new self("Tenant with ID {$id} is still being provisioned.");
    }
}
