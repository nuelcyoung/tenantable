<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Events;

class TenancyInitialized
{
    public function __construct(
        public readonly int   $tenantId,
        public readonly array $tenant
    ) {}
}