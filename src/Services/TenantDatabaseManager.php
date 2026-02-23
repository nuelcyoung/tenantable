<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Database;
use Config\Database as DbConfig;

/**
 * TenantDatabaseManager
 *
 * Handles dynamic database connections for multi-tenant applications.
 * Switches between tenant databases based on current tenant.
 *
 * FIX 2.3 – Fixed wrong Database::connect() API usage in establishConnection().
 * FIX 2.4 – Fixed switchToDefault() passing `false` to db_connect().
 */
class TenantDatabaseManager
{
    /**
     * Current tenant DB connection config.
     */
    protected ?array $tenantDbConfig = null;

    /**
     * Whether multi-database mode is enabled.
     */
    protected bool $enabled = false;

    /**
     * Default database group name.
     */
    protected string $defaultGroup = 'default';

    /**
     * Active tenant connections, keyed by subdomain alias.
     *
     * @var array<string, BaseConnection>
     */
    protected array $connections = [];

    public function __construct(bool $enabled = false, string $defaultGroup = 'default')
    {
        $this->enabled      = $enabled;
        $this->defaultGroup = $defaultGroup;
    }

    // -------------------------------------------------------------------------
    // Connection management
    // -------------------------------------------------------------------------

    /**
     * Connect to a tenant's own database.
     *
     * Returns true if a dedicated connection was established,
     * false if multi-database mode is disabled or tenant has no separate DB.
     */
    public function connectToTenant(array $tenant): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (empty($tenant['database_name'])) {
            return false;
        }

        $alias = $tenant['subdomain'] ?? 'tenant_' . ($tenant['id'] ?? 'unknown');

        $this->tenantDbConfig = [
            'DBDriver' => 'MySQLi',
            'DBPrefix' => '',
            'hostname' => $tenant['database_host']     ?? 'localhost',
            'username' => $tenant['database_username'] ?? '',
            'password' => $tenant['database_password'] ?? '',
            'database' => $tenant['database_name'],
            'port'     => (int) ($tenant['database_port'] ?? 3306),
        ];

        $this->connections[$alias] = $this->establishConnection($this->tenantDbConfig, $alias);

        return true;
    }

    /**
     * Switch to a specific tenant by subdomain (looks up DB credentials first).
     */
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
     * Switch back to the default database group (for superadmin queries).
     *
     * FIX 2.4 – Was calling `db_connect(false)` which is not valid.
     *            Now correctly reconnects using the configured default group name.
     */
    public function switchToDefault(): void
    {
        if ($this->enabled) {
            db_connect($this->defaultGroup);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Establish (and return) a new named database connection.
     *
     * FIX 2.3 – The original code called:
     *   $db = Database::connect($config);
     *   Database::connect($db, $alias);   // ← invalid, second arg is not an alias
     *
     * The correct approach is to pass the config array directly to
     * Database::connect() with the alias (group name) as the second argument.
     *
     * @param array  $config  CI4 database config array
     * @param string $alias   Group name / alias for this connection
     * @return BaseConnection
     */
    protected function establishConnection(array $config, string $alias): BaseConnection
    {
        // CI4's Database::connect() accepts a config array as the first argument
        // and an optional $getShared flag (bool). To create a named/aliased
        // connection we store it in DbConfig so db_connect($alias) works later.
        $dbConfig         = config('Database');
        $dbConfig->$alias = $config;

        return Database::connect($alias, false); // false → create fresh connection
    }

    // -------------------------------------------------------------------------
    // Status / Helpers
    // -------------------------------------------------------------------------

    public function isConnectedToTenant(): bool
    {
        return $this->tenantDbConfig !== null;
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
     * Test whether a database config is reachable.
     */
    public function testConnection(array $config): bool
    {
        try {
            $db = Database::connect($config, false);
            return $db->connect() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
