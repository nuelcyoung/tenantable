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

        $dbName = \nuelcyoung\tenantable\Models\TenantModel::getDatabaseName($tenant);

        if (empty($dbName)) {
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

        $tenantConfig = $this->buildTenantConfig($dbName, $this->defaultSnapshot ?? []);

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

    // -------------------------------------------------------------------------
    // Automatic provisioning
    // -------------------------------------------------------------------------

    /**
     * Provision a new tenant database: create it and optionally run migrations.
     *
     * Called automatically by the tenantCreated event listener when
     * Config\Tenantable::$autoCreateDatabase is true.
     */
    public function provisionTenant(array $tenant): bool
    {
        $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);

        if (! $this->shouldAutoProvision($config)) {
            return false;
        }

        $dbName = \nuelcyoung\tenantable\Models\TenantModel::getDatabaseName($tenant);

        if (! $this->createDatabase($dbName)) {
            return false;
        }

        if ($config->autoMigrateTenant && ! empty($config->tenantMigrationsNamespace)) {
            return $this->migrateTenant($tenant, $config->tenantMigrationsNamespace);
        }

        return true;
    }

    /**
     * CREATE DATABASE IF NOT EXISTS for a tenant.
     *
     * Uses the application's default DB credentials (from .env / Config\Database).
     * The DB user must have CREATE privileges on the server.
     */
    public function createDatabase(string $databaseName): bool
    {
        try {
            $base = $this->getDefaultGroupConfig();
            $base['database'] = '';

            $admin   = \CodeIgniter\Database\Database::connect($base, false);
            $escaped = str_replace('`', '``', $databaseName);
            $admin->query("CREATE DATABASE IF NOT EXISTS `{$escaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            log_message('info', "Tenantable: database '{$databaseName}' created/ensured.");
            return true;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: CREATE DATABASE '{$databaseName}' failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Run tenant migrations against the tenant's database.
     *
     * Registers a temporary DB group, runs the migration runner
     * against it, then cleans up.
     */
    public function migrateTenant(array $tenant, string $namespace): bool
    {
        $dbName = \nuelcyoung\tenantable\Models\TenantModel::getDatabaseName($tenant);
        $alias  = 'tenant_provision_' . ($tenant['id'] ?? uniqid());

        try {
            $config             = $this->getDefaultGroupConfig();
            $config['database'] = $dbName;

            $dbConfig         = config('Database');
            $dbConfig->$alias = $config;

            $runner = \Config\Services::migrations();
            $runner->setNamespace($namespace)->setGroup($alias)->latest();

            log_message('info', "Tenantable: migrations applied to '{$dbName}'.");
            return true;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: migration for '{$dbName}' failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Whether auto-provisioning should run based on the current config.
     */
    protected function shouldAutoProvision(\nuelcyoung\tenantable\Config\Tenantable $config): bool
    {
        if (! $config->autoCreateDatabase) {
            return false;
        }

        // Resolve the effective isolation mode
        $mode = $config->isolationMode;
        if ($mode === null) {
            $mode = $config->separateDatabasePerTenant ? 'database' : 'row';
        }

        return $mode === 'database';
    }

    /**
     * Snapshot the application's default DB group config as a plain array.
     */
    public function getDefaultGroupConfig(): array
    {
        $dbConfig = config('Database');
        $group    = $dbConfig->defaultGroup ?? $this->defaultGroup;

        return (array) ($dbConfig->{$group} ?? []);
    }

    /**
     * Build a complete CI4 connection config array for the tenant.
     *
     * Credentials, host, port, driver, charset, DBDebug, etc. are inherited
     * from the snapshot of the original default group (i.e. Config\Database +
     * .env). Only `database` is overridden per tenant.
     *
     * This is by design: tenant DB credentials must never live in the tenants
     * table they protect access to. Set a single application-wide DB user in
     * .env with privileges on all tenant databases.
     */
    protected function buildTenantConfig(string $databaseName, array $base): array
    {
        $config             = $base;
        $config['database'] = $databaseName;

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
