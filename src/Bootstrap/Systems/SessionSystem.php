<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;

/**
 * Isolates sessions per tenant using separate save-path directories.
 *
 * M-4 – ⚠ IMPORTANT LIMITATION: This system can only isolate sessions when
 *          it runs BEFORE CI4's session service is initialized. If CI4's
 *          Session service has already started (session_start() was called),
 *          changing $config->savePath here has no effect on the current
 *          request. To use this system effectively:
 *            1. Register TenantFilter as early as possible (before session init)
 *            2. Alternatively, implement a custom CI4 Session driver that reads
 *               the tenant ID from the subdomain directly.
 */
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
