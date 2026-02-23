<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Exceptions;

use RuntimeException;

/**
 * Tenant Inactive Exception
 * 
 * Thrown when a tenant exists but is marked as inactive.
 */
class TenantInactiveException extends RuntimeException
{
    /**
     * Create a new TenantInactiveException instance.
     */
    public static function forSubdomain(string $subdomain): self
    {
        return new self("Tenant '{$subdomain}' is currently inactive");
    }

    /**
     * Create a new TenantInactiveException instance for ID.
     */
    public static function forId(int $id): self
    {
        return new self("Tenant with ID {$id} is currently inactive");
    }
}
