<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database as DbConfig;

/**
 * TenantDatabaseManager
 *
 * Handles dynamic database swapping for database-per-tenant isolation.
 *
 * Strategy:
 *   - Snapshot the original default-group config on first swap.
 *   - On connectToTenant(), close any cached default connection, evict it
 *     from \Config\Database's static $instances cache, then overwrite
 *     \Config\Database::$default with the tenant's connection config.
 *   - The next call to db_connect() / Model::__construct() will lazily
 *     build a fresh default connection from the new config — so every
 *     Model in the app transparently hits the tenant's DB.
 *   - switchToDefault() reverses the swap by restoring the snapshot the
 *     same way.
 *
 * This requires the consuming app to have an authoritative \Config\Database
 * class (which all CI4 apps do).
 */
class TenantDatabaseManager
{
    protected ?array $tenantDbConfig = null;
    protected ?array $defaultSnapshot = null;
    protected bool $swapped = false;
    protected bool $enabled = false;
    protected string $defaultGroup = 'default';

    /** @var array<string, BaseConnection> */
    protected array $connections = [];

    public function __construct(bool $enabled = false, string $defaultGroup = 'default')
    {
        $this->enabled      = $enabled;
        $this->defaultGroup = $defaultGroup;
    }

    public function connectToTenant(array $tenant): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (empty($tenant['database_name'])) {
            return false;
        }

        $dbConfig = config('Database');
        $group    = $this->defaultGroup;

        if (! $this->swapped) {
            $this->defaultSnapshot = (array) ($dbConfig->{$group} ?? []);
        }

        // Make the original/central DB reachable via the 'central' group while
        // the default group is swapped to the tenant.
        self::ensureCentralGroup($this->defaultSnapshot);

        $tenantConfig = $this->buildTenantConfig($tenant, $this->defaultSnapshot ?? []);

        $this->evictCachedConnection($group);
        $dbConfig->{$group} = $tenantConfig;

        $this->connections[$group] = DbConfig::connect($group, true);
        $this->tenantDbConfig       = $tenantConfig;
        $this->swapped              = true;

        return true;
    }

    public function switchToTenant(string $subdomain): bool
    {
        $tenantModel = new \nuelcyoung\tenantable\Models\TenantModel();
        $tenant      = $tenantModel->where('subdomain', $subdomain)->first();

        if ($tenant === null) {
            return false;
        }

        return $this->connectToTenant($tenant);
    }

    /**
     * Restore the original default-group config and evict the tenant connection.
     */
    public function switchToDefault(): void
    {
        if (! $this->enabled || ! $this->swapped || $this->defaultSnapshot === null) {
            return;
        }

        $dbConfig = config('Database');
        $group    = $this->defaultGroup;

        $this->evictCachedConnection($group);
        $dbConfig->{$group} = $this->defaultSnapshot;

        $this->tenantDbConfig = null;
        $this->swapped        = false;
        unset($this->connections[$group]);
    }

    public function isConnectedToTenant(): bool
    {
        return $this->swapped;
    }

    public function getCurrentConfig(): ?array
    {
        return $this->tenantDbConfig;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Register the 'central' database group on \Config\Database so models
     * extending GlobalModel can reach the central/original DB regardless of
     * whether a tenant swap is active.
     *
     * Idempotent: never overwrites an existing 'central' group, so a
     * user-defined one is respected. When $explicitConfig is provided, it is
     * used as the seed; otherwise the current default group is mirrored.
     */
    public static function ensureCentralGroup(?array $explicitConfig = null): void
    {
        $dbConfig = config('Database');

        if (isset($dbConfig->central)) {
            return;
        }

        if ($explicitConfig !== null) {
            $dbConfig->central = $explicitConfig;
            return;
        }

        $defaultGroup      = $dbConfig->defaultGroup ?? 'default';
        $dbConfig->central = (array) ($dbConfig->{$defaultGroup} ?? []);
    }

    public function testConnection(array $config): bool
    {
        try {
            $db = DbConfig::connect($config, false);
            return $db->connect() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build a complete CI4 connection config array for the tenant, inheriting
     * unset keys (charset, DBDebug, etc.) from the snapshot of the original
     * default group.
     */
    protected function buildTenantConfig(array $tenant, array $base): array
    {
        $config = $base;

        $config['DBDriver'] = $tenant['database_driver'] ?? ($base['DBDriver'] ?? 'MySQLi');
        $config['hostname'] = $tenant['database_host']     ?? ($base['hostname'] ?? 'localhost');
        $config['username'] = $tenant['database_username'] ?? ($base['username'] ?? '');
        $config['password'] = $tenant['database_password'] ?? ($base['password'] ?? '');
        $config['database'] = $tenant['database_name'];
        $config['port']     = (int) ($tenant['database_port'] ?? ($base['port'] ?? 3306));
        $config['DBPrefix'] = $tenant['database_prefix']   ?? ($base['DBPrefix'] ?? '');

        return $config;
    }

    /**
     * Close and evict any cached connection for the given group so the next
     * db_connect($group) call rebuilds it from the (just-mutated) config.
     */
    protected function evictCachedConnection(string $group): void
    {
        try {
            $existing = DbConfig::connect($group, true);
            if ($existing instanceof BaseConnection) {
                $existing->close();
            }
        } catch (\Throwable $e) {
            // No existing connection — nothing to close.
        }

        try {
            $ref       = new \ReflectionClass(DbConfig::class);
            $prop      = $ref->getProperty('instances');
            $prop->setAccessible(true);
            $instances = (array) $prop->getValue();
            unset($instances[$group]);
            $prop->setValue(null, $instances);
        } catch (\Throwable $e) {
            // Property layout may differ across CI4 minor versions; the
            // config mutation alone still affects fresh callers.
        }
    }
}
