<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Events;

/**
 * TenancyEnded
 *
 * Fired when tenant context is cleared (end of request, or explicit clear()).
 * Fired BEFORE TenantBootstrap::shutdown() runs.
 */
class TenancyEnded
{
    public function __construct(
        public readonly ?int   $tenantId,  // null if context was already cleared
        public readonly ?array $tenant
    ) {}
}
