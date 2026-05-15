<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;

class CacheSystem implements TenantAwareInterface
{
    protected string $originalPrefix = '';

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $config = config('Cache');

        if ($config === null) {
            return;
        }

        $this->originalPrefix = $config->prefix ?? '';

        $config->prefix = $tenantId !== null
            ? "tenant_{$tenantId}_"
            : '';
    }

    public function shutdown(): void
    {
        $config = config('Cache');

        if ($config !== null) {
            $config->prefix = $this->originalPrefix;
        }

        $this->originalPrefix = '';
    }
}