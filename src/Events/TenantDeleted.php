<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Events;

/**
 * TenantDeleted
 *
 * Fired after a tenant row is deleted.
 * Use this to clean up tenant-specific tables, files, caches, etc.
 */
class TenantDeleted
{
    public function __construct(
        public readonly int   $tenantId,
        public readonly array $tenant  // snapshot of the row before deletion
    ) {}
}
