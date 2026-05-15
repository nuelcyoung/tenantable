<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Exceptions;

use RuntimeException;

class TenantNotFoundException extends RuntimeException
{
    public static function forSubdomain(string $subdomain): self
    {
        return new self("Tenant not found for subdomain: {$subdomain}");
    }

    public static function forId(int $id): self
    {
        return new self("Tenant not found with ID: {$id}");
    }
}