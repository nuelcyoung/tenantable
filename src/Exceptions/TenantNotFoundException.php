<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Exceptions;

use RuntimeException;

/**
 * Tenant Not Found Exception
 * 
 * Thrown when a tenant cannot be found by subdomain or ID.
 */
class TenantNotFoundException extends RuntimeException
{
    /**
     * Create a new TenantNotFoundException instance.
     */
    public static function forSubdomain(string $subdomain): self
    {
        return new self("Tenant not found for subdomain: {$subdomain}");
    }

    /**
     * Create a new TenantNotFoundException instance for ID.
     */
    public static function forId(int $id): self
    {
        return new self("Tenant not found with ID: {$id}");
    }
}
