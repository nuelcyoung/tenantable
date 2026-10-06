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
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Support\FrameworkState;

/**
 * Keeps the queue central while a tenant is booted. In database isolation the
 * default group is repointed at the tenant's database, so a queue on that
 * group would silently write jobs where no worker looks for them.
 */
class QueueSystem implements TenantAwareInterface
{
    /** One warning per process; a worker boots a tenant per job. */
    private static bool $warned = false;

    /** Whether this instance ever saw the queue riding the tenant group. */
    private bool $queueFollowedTenant = false;

    public function boot(?int $tenantId, ?array $tenant): void
    {
        if ($tenantId === null) {
            return;
        }

        $group = $this->tenantSwappedQueueGroup();

        if ($group === null) {
            return;
        }

        $this->queueFollowedTenant = true;

        if (! self::$warned) {
            self::$warned = true;

            log_message(
                'error',
                "Tenantable: Config\\Queue uses the database handler on connection group '{$group}', "
                . 'which is the group repointed at each tenant database in database isolation mode. '
                . 'Jobs pushed while a tenant is active would be written to that tenant database and '
                . 'never picked up. Give the queue its own central group in Config\\Database and set '
                . "Config\\Queue::\$database['dbGroup'] to it."
            );
        }

        // The handler caches the connection it was built with; a handler
        // built under one tenant must not be reused under the next.
        $this->resetSharedQueue();
    }

    public function shutdown(): void
    {
        if (! $this->queueFollowedTenant) {
            return;
        }

        // The handler built during the tenant boot holds that tenant's
        // connection; leaving it cached would hand it to central work.
        $this->resetSharedQueue();
        $this->queueFollowedTenant = false;
    }

    /** Test seam: the warning is intentionally once-per-process. */
    public static function resetWarningState(): void
    {
        self::$warned = false;
    }

    /**
     * The queue's connection group when it is the one tenancy swaps, else null.
     */
    private function tenantSwappedQueueGroup(): ?string
    {
        return self::sharedTenantGroup($this->queueConfig(), $this->tenantableConfig());
    }

    /**
     * The group a database-backed queue shares with tenancy, or null when
     * there is nothing to worry about. Static so tenants:doctor reports
     * the same finding the bootstrapper logs.
     */
    public static function sharedTenantGroup(?object $queueConfig, ?TenantableConfig $tenantable): ?string
    {
        if ($queueConfig === null || $tenantable === null) {
            return null;
        }

        if (! $tenantable->isDatabaseIsolation()) {
            return null;
        }

        if (($queueConfig->handler ?? null) !== 'database') {
            return null;
        }

        $database = $queueConfig->database ?? null;
        $group    = is_array($database) ? ($database['dbGroup'] ?? null) : null;

        if (! is_string($group) || $group === '') {
            return null;
        }

        return $group === $tenantable->defaultDatabaseGroup ? $group : null;
    }

    protected function tenantableConfig(): ?TenantableConfig
    {
        $config = config(TenantableConfig::class);

        return $config instanceof TenantableConfig ? $config : null;
    }

    protected function queueConfig(): ?object
    {
        if (! class_exists('Config\\Queue')) {
            return null;
        }

        $config = config('Queue');

        return is_object($config) ? $config : null;
    }

    protected function resetSharedQueue(): void
    {
        if (! class_exists('Config\\Services')) {
            return;
        }

        FrameworkState::resetSharedService('queue');
    }
}
