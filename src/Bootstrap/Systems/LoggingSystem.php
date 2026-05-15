<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap\Systems;

use nuelcyoung\tenantable\Bootstrap\TenantAwareInterface;

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
