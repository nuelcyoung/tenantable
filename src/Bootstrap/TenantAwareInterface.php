<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

interface TenantAwareInterface
{
    public function boot(?int $tenantId, ?array $tenant): void;

    public function shutdown(): void;
}