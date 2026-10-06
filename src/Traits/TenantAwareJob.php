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

namespace nuelcyoung\tenantable\Traits;

use nuelcyoung\tenantable\Services\TenantableQueue;

/**
 * Restores the tenant a queued job was pushed under. Kept separate from
 * Queue\TenantableJob so it works on any base class and can be tested without
 * a queue installed. The handler runs inside tenancy_run(), which boots the
 * tenant's database/prefix/cache/storage and tears them down afterwards.
 */
trait TenantAwareJob
{
    /**
     * Run $handler in the payload's tenant, leaving the context as found. The
     * handler receives the payload without the package's tenant key.
     *
     * @param array<string, mixed>                  $payload
     * @param callable(array<string, mixed>): mixed $handler
     */
    protected function runInTenantContext(array $payload, callable $handler): mixed
    {
        $tenantId = TenantableQueue::tenantId($payload);
        $data     = TenantableQueue::strip($payload);

        if ($tenantId === null) {
            // Pushed centrally, so run centrally; never inherit a previous
            // job's tenant in a long-lived worker.
            return central(static fn (): mixed => $handler($data));
        }

        // An unresolvable tenant throws out of tenancy_run(), failing the
        // job; swallowing it would run tenant work untenanted.
        return tenancy_run($tenantId, static fn (): mixed => $handler($data));
    }
}
