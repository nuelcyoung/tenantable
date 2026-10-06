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

namespace nuelcyoung\tenantable\Jobs;

use CodeIgniter\Events\Events;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Events\TenantCreated;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Queue\TenantableJob;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use nuelcyoung\tenantable\Support\TenantableConfig as ConfigResolver;

/**
 * Creates a tenant's storage outside the signup request. Central by design:
 * pushed with no tenant stamp, since it connects to the database it creates.
 */
class ProvisionTenantJob extends TenantableJob
{
    /** Queue this job is pushed onto. */
    public const QUEUE = 'tenantable';

    /** Handler name to register in `Config\Queue::$jobHandlers`. */
    public const NAME = 'tenantable:provision';

    /**
     * @param array<string, mixed> $data
     */
    protected function handle(array $data): mixed
    {
        $tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : 0;

        if ($tenantId <= 0) {
            throw new \InvalidArgumentException(
                'Tenantable: ProvisionTenantJob needs a positive tenant_id in its payload.'
            );
        }

        $model  = $this->tenantModel();
        $tenant = $model->find($tenantId);

        if ($tenant === null) {
            // Deleted before the worker ran; nothing to provision or fix.
            log_message('info', "Tenantable: tenant {$tenantId} no longer exists; provisioning skipped.");

            return false;
        }

        $config = $this->config();

        try {
            (new TenantDatabaseManager(
                $config->isDatabaseIsolation(),
                $config->defaultDatabaseGroup,
            ))->provisionTenant($tenant);
        } catch (\Throwable $e) {
            // 'failed' rather than a retry: a broken migration needs an
            // operator, and a retry loop would keep re-running DDL.
            $model->markStatus($tenantId, TenantModel::STATUS_FAILED);

            log_message(
                'critical',
                "Tenantable: provisioning tenant {$tenantId} failed: {$e->getMessage()}",
                ['exception' => $e]
            );

            throw $e;
        }

        $model->markStatus($tenantId, TenantModel::STATUS_READY);

        // Fired here, not at insert: the first moment the tenant can serve.
        Events::trigger('tenantCreated', new TenantCreated(
            $tenantId,
            ['status' => TenantModel::STATUS_READY] + $tenant
        ));

        return true;
    }

    protected function tenantModel(): TenantModel
    {
        return new TenantModel();
    }

    protected function config(): TenantableConfig
    {
        return ConfigResolver::get();
    }
}
