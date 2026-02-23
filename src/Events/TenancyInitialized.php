<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Events;

/**
 * TenancyInitialized
 *
 * Fired when tenant context is set on TenantManager (i.e. tenancy "begins"
 * for the current request/job/CLI run).
 *
 * This is fired AFTER all TenantBootstrap systems have booted.
 */
class TenancyInitialized
{
    public function __construct(
        public readonly int   $tenantId,
        public readonly array $tenant
    ) {}
}
