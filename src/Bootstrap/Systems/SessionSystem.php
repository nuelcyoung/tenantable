<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;

class SessionSystem implements TenantAwareInterface
{
    protected string $originalSavePath = '';

    public function boot(?int $tenantId, ?array $tenant): void
    {
        $config = config('Session');

        if ($config === null) {
            return;
        }

        $this->originalSavePath = $config->savePath ?? '';

        $tenantSavePath = WRITEPATH . 'session/tenant_' . ($tenantId ?? 'global');

        if (!is_dir($tenantSavePath)) {
            mkdir($tenantSavePath, 0755, true);
        }

        $config->savePath = $tenantSavePath;
    }

    public function shutdown(): void
    {
        $config = config('Session');

        if ($config !== null && $this->originalSavePath !== '') {
            $config->savePath = $this->originalSavePath;
        }

        $this->originalSavePath = '';
    }
}