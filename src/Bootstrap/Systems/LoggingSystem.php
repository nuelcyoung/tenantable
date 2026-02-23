<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;

/**
 * Injects tenant context into the logging environment so all log entries
 * emitted during a request carry the active tenant ID.
 */
class LoggingSystem implements TenantAwareInterface
{
    public function boot(?int $tenantId, ?array $tenant): void
    {
        if ($tenantId !== null) {
            $_ENV['TENANT_LOG_CONTEXT'] = json_encode([
                'tenant_id'   => $tenantId,
                'tenant_name' => $tenant['name'] ?? $tenant['subdomain'] ?? 'unknown',
            ], JSON_THROW_ON_ERROR);
        } else {
            unset($_ENV['TENANT_LOG_CONTEXT']);
        }
    }

    public function shutdown(): void
    {
        unset($_ENV['TENANT_LOG_CONTEXT']);
    }
}
