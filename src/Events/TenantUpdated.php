<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Events;

/**
 * TenantUpdated
 *
 * Fired after an existing tenant row is updated.
 * Use this to react to plan/status/subdomain changes.
 */
class TenantUpdated
{
    public function __construct(
        public readonly int   $tenantId,
        public readonly array $tenant,       // full row after update
        public readonly array $changedData   // only the fields that changed
    ) {}
}
