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

namespace nuelcyoung\tenantable\Models;

use nuelcyoung\tenantable\Events\TenantCreated;
use nuelcyoung\tenantable\Events\TenantUpdated;
use nuelcyoung\tenantable\Events\TenantDeleted;
use nuelcyoung\tenantable\Config\Tenantable as PackageConfig;
use nuelcyoung\tenantable\Support\TenantableConfig;
use nuelcyoung\tenantable\Services\TenantableQueue;
use nuelcyoung\tenantable\Services\TenantResolverCache;

class TenantModel extends GlobalModel
{
    /** The tenant's storage exists and is migrated; it may serve traffic. */
    public const STATUS_READY = 'ready';

    /** The row exists but its database or tables do not yet. */
    public const STATUS_PROVISIONING = 'provisioning';

    /** Provisioning ran and failed. Needs an operator, not a retry loop. */
    public const STATUS_FAILED = 'failed';

    protected $table            = 'tenants';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'subdomain',
        'name',
        'domain',
        'is_active',
        'settings',
        'status',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged  = true;

    protected array $casts = [
        'is_active' => 'boolean',
        'settings'  => '?json-array',
    ];

    protected array $castHandlers = [];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'subdomain' => [
            'rules'  => 'permit_empty|min_length[2]|max_length[50]|alpha_dash|is_unique[tenants.subdomain,id,{id}]',
            'errors' => [
                'alpha_dash' => 'Tenantable.validation.subdomainAlphaDash',
                'is_unique'  => 'Tenantable.validation.subdomainUnique',
            ],
        ],
        'name' => [
            'rules'  => 'required|min_length[2]|max_length[255]',
            'errors' => [
                'required' => 'Tenantable.validation.nameRequired',
            ],
        ],
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert   = ['encodeSettings', 'stampProvisioningStatus'];
    protected $afterInsert    = ['dispatchCreated'];
    protected $beforeUpdate   = ['captureBeforeUpdate', 'encodeSettings'];
    protected $afterUpdate    = ['dispatchUpdated'];
    protected $beforeFind     = [];
    protected $afterFind      = ['decodeSettings'];
    protected $beforeDelete   = ['captureBeforeDelete'];
    protected $afterDelete    = ['dispatchDeleted'];

    private array $beforeUpdateSnapshots = [];
    private array $beforeDeleteSnapshots = [];

    /**
     * Mark a new tenant as provisioning when provisioning is deferred. Stamped
     * before the insert so the row is never briefly 'ready' without a database.
     */
    protected function stampProvisioningStatus(array $data): array
    {
        if (! $this->tenantableConfig()->provisionAsync) {
            return $data;
        }

        if (! isset($data['data']) || ! is_array($data['data'])) {
            return $data;
        }

        // An explicit status from the caller wins: imports and fixtures
        // legitimately insert already-provisioned tenants.
        $data['data']['status'] ??= self::STATUS_PROVISIONING;

        return $data;
    }

    protected function dispatchCreated(array $data): array
    {
        if (empty($data['id'])) {
            return $data;
        }

        $tenant = $this->find((int) $data['id']);

        if ($tenant === null) {
            return $data;
        }

        $config = $this->tenantableConfig();

        if ($config->provisionAsync && $this->queueProvisioning((int) $data['id'])) {
            // The job provisions, flips the status, and fires tenantCreated
            // once the tenant can serve traffic.
            return $data;
        }

        $manager = new \nuelcyoung\tenantable\Services\TenantDatabaseManager(
            $config->isDatabaseIsolation(),
            $config->defaultDatabaseGroup,
        );
        $manager->provisionTenant($tenant);

        \CodeIgniter\Events\Events::trigger('tenantCreated', new TenantCreated(
            (int) $data['id'],
            $tenant
        ));

        return $data;
    }

    /**
     * The package configuration. Overridable so provisioning behaviour can
     * be driven without publishing a config file (the bootstrappers' seam).
     */
    protected function tenantableConfig(): PackageConfig
    {
        return TenantableConfig::get();
    }

    /** The queue provisioning jobs are pushed onto. */
    protected function provisioningQueue(): TenantableQueue
    {
        return new TenantableQueue();
    }

    /**
     * Hand provisioning to the queue; false means "provision inline instead",
     * so a failed push never leaves the tenant stuck in 'provisioning'.
     */
    private function queueProvisioning(int $tenantId): bool
    {
        try {
            $pushed = $this->provisioningQueue()->pushCentral(
                \nuelcyoung\tenantable\Jobs\ProvisionTenantJob::QUEUE,
                \nuelcyoung\tenantable\Jobs\ProvisionTenantJob::NAME,
                ['tenant_id' => $tenantId]
            );
        } catch (\Throwable $e) {
            $pushed = false;

            log_message(
                'error',
                'Tenantable: $provisionAsync is enabled but tenant ' . $tenantId . ' could not be '
                . 'queued (' . $e->getMessage() . '). Provisioning inline instead.'
            );
        }

        if (! $pushed) {
            $this->markStatus($tenantId, self::STATUS_READY);

            return false;
        }

        return true;
    }

    /**
     * Set a tenant's provisioning status without firing the update pipeline:
     * update() would emit events and invalidate caches at every step.
     */
    public function markStatus(int $tenantId, string $status): bool
    {
        if (! in_array($status, [self::STATUS_READY, self::STATUS_PROVISIONING, self::STATUS_FAILED], true)) {
            throw new \InvalidArgumentException("Tenantable: unknown tenant status '{$status}'.");
        }

        $updated = $this->db->table($this->table)
            ->where('id', $tenantId)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

        if ($updated) {
            // A request during provisioning cached the row as not-ready; that
            // entry must go or the tenant stays unreachable until the TTL ends.
            $this->flushResolverCacheFor($tenantId);
        }

        return (bool) $updated;
    }

    private function flushResolverCacheFor(int $tenantId): void
    {
        $tenant = $this->find($tenantId);

        if ($tenant === null) {
            return;
        }

        $cache = TenantResolverCache::getInstance();

        if (! empty($tenant['domain'])) {
            $cache->flushHost((string) $tenant['domain']);
        }

        if (empty($tenant['subdomain'])) {
            return;
        }

        $subdomain = (string) $tenant['subdomain'];
        $cache->flushSubdomain($subdomain);

        // Cached under the full hostname on every central domain, not just the
        // primary one; a cache-wide flush would drop every other tenant too.
        foreach (TenantableConfig::get()->centralDomains() as $domain) {
            $cache->flushHost("{$subdomain}.{$domain}");
        }
    }

    protected function captureBeforeUpdate(array $data): array
    {
        if (empty($data['id'])) {
            // Bulk updates have no row id, so flush everything instead.
            TenantResolverCache::getInstance()->flush();
        }

        if (!empty($data['id'])) {
            $tenant = $this->find((int) $data['id']);
            if ($tenant !== null) {
                $this->beforeUpdateSnapshots[(int) $data['id']] = $tenant;
            }
        }

        return $data;
    }

    protected function dispatchUpdated(array $data): array
    {
        if (!empty($data['id'])) {
            $id     = (int) $data['id'];
            $tenant = $this->find($id);
            $before = $this->beforeUpdateSnapshots[$id] ?? [];

            if ($tenant !== null) {
                $changed = array_diff_assoc(
                    array_intersect_key($tenant, $data['data'] ?? []),
                    $before
                );

                \CodeIgniter\Events\Events::trigger('tenantUpdated', new TenantUpdated(
                    $id,
                    $tenant,
                    $changed,
                    $before
                ));
            }

            unset($this->beforeUpdateSnapshots[$id]);
        }

        return $data;
    }

    protected function captureBeforeDelete(array $data): array
    {
        if (empty($data['id'])) {
            TenantResolverCache::getInstance()->flush();
        }

        if (!empty($data['id'])) {
            $tenant = $this->find((int) $data['id']);
            if ($tenant !== null) {
                $this->beforeDeleteSnapshots[(int) $data['id']] = $tenant;
            }
        }

        return $data;
    }

    protected function dispatchDeleted(array $data): array
    {
        if (!empty($data['id'])) {
            $id     = (int) $data['id'];
            $tenant = $this->beforeDeleteSnapshots[$id] ?? [];

            \CodeIgniter\Events\Events::trigger('tenantDeleted', new TenantDeleted($id, $tenant));

            unset($this->beforeDeleteSnapshots[$id]);
        }

        return $data;
    }

    // CI4 < 4.5 ignores casts for settings, so encode/decode JSON manually.
    // On 4.5+ these no-op (native cast handles it).

    protected function encodeSettings(array $data): array
    {
        if (self::modelCastsSupported() || ! isset($data['data']['settings'])) {
            return $data;
        }

        if (is_array($data['data']['settings'])) {
            $data['data']['settings'] = json_encode($data['data']['settings']);
        }

        return $data;
    }

    protected function decodeSettings(array $data): array
    {
        if (self::modelCastsSupported() || ! isset($data['data'])) {
            return $data;
        }

        if (($data['singleton'] ?? false) === true) {
            $data['data'] = $this->decodeSettingsRow($data['data']);
        } elseif (is_array($data['data'])) {
            foreach ($data['data'] as $key => $row) {
                $data['data'][$key] = $this->decodeSettingsRow($row);
            }
        }

        return $data;
    }

    /**
     * @param mixed $row
     * @return mixed
     */
    private function decodeSettingsRow($row)
    {
        if (is_array($row) && isset($row['settings']) && is_string($row['settings'])) {
            $decoded = json_decode($row['settings'], true);
            if (is_array($decoded)) {
                $row['settings'] = $decoded;
            }
        }

        return $row;
    }

    private static function modelCastsSupported(): bool
    {
        return version_compare(\CodeIgniter\CodeIgniter::CI_VERSION, '4.5.0', '>=');
    }

    public static function getDatabaseName(array $tenant): string
    {
        $config    = TenantableConfig::get();
        $generator = $config->databaseNameGenerator ?? null;

        $name = is_callable($generator)
            ? (string) $generator($tenant)
            : 'tenant_' . ($tenant['id'] ?? '');

        return self::assertValidDatabaseName($name);
    }

    /** Restrict database names to [A-Za-z0-9_]{1,64}. */
    public static function assertValidDatabaseName(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                "Tenantable: refusing to use unsafe tenant database name '{$name}'. " .
                'Database names must match [A-Za-z0-9_] and be 1-64 characters. ' .
                'Check your Config\\Tenantable::$databaseNameGenerator.'
            );
        }

        return $name;
    }
}
