<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Events;

class TenantUpdated
{
    public function __construct(
        public readonly int   $tenantId,
        public readonly array $tenant,
        public readonly array $changedData,
        public readonly array $before = []
    ) {}
}