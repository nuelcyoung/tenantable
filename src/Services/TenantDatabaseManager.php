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

        if (property_exists($dbConfig, 'central') || isset($dbConfig->central)) {
            return;
        }

        $seed = $explicitConfig;

        if ($seed === null) {
            $defaultGroup = $dbConfig->defaultGroup ?? 'default';
            $seed         = (array) ($dbConfig->{$defaultGroup} ?? []);
        }

        // Register via the custom property bag to avoid PHP 8.2+ deprecation
        $dbConfig->central = $seed; // @phpstan-ignore-line — CI4 BaseConfig allows dynamic props
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
            log_message('debug', 'Tenantable: auto-provisioning skipped (mode is not database or autoCreateDatabase is false).');
            return false;
        }

        $dbName = \nuelcyoung\tenantable\Models\TenantModel::getDatabaseName($tenant);

        if (! $this->createDatabase($dbName)) {
            return false;
        }

        if (! $config->autoMigrateTenant) {
            log_message('info', 'Tenantable: autoMigrateTenant is disabled, skipping migrations.');
            return true;
        }

        // Build the list of namespaces to migrate
        $namespaces = $this->resolveTenantMigrationNamespaces($config);

        if (empty($namespaces)) {
            log_message('warning', 'Tenantable: no tenant migration namespaces configured — skipping migrations.');
            return true;
        }

        $allPassed = true;
        foreach ($namespaces as $ns) {
            log_message('info', "Tenantable: running tenant migrations for namespace '{$ns}'.");
            if (! $this->migrateTenant($tenant, $ns)) {
                $allPassed = false;
            }
        }

        return $allPassed;
    }

    /**
     * Merge the primary namespace with any additional namespaces.
     *
     * @return string[]
     */
    protected function resolveTenantMigrationNamespaces(\nuelcyoung\tenantable\Config\Tenantable $config): array
    {
        $namespaces = [];

        if (! empty($config->tenantMigrationsNamespace)) {
            $namespaces[] = $config->tenantMigrationsNamespace;
        }

        foreach ($config->tenantMigrationsNamespaces as $ns) {
            if (! empty($ns) && ! in_array($ns, $namespaces, true)) {
                $namespaces[] = $ns;
            }
        }

        return $namespaces;
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

            $admin   = \Config\Database::connect($base, false);
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

        try {
            $config             = $this->getDefaultGroupConfig();
            $config['database'] = $dbName;

            $db    = DbConfig::connect($config, false);
            $forge = \Config\Database::forge($db);

            // Ensure migrations tracking table exists
            $this->ensureMigrationTable($db, $forge);

            // Resolve namespace → filesystem directory
            $dir = $this->resolveNamespaceDirectory($namespace);
            if ($dir === null) {
                log_message('warning', "Tenantable: cannot resolve '{$namespace}' to a directory.");
                $db->close();
                return true; // Not a fatal error — just nothing to do
            }

            // Discover migration files
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
            if (empty($files)) {
                log_message('info', "Tenantable: no migration files in '{$dir}'.");
                $db->close();
                return true;
            }

            sort($files);

            // Get already-applied migrations
            $applied = $this->getAppliedMigrations($db, $namespace);

            $ran = 0;
            foreach ($files as $file) {
                $basename = pathinfo($file, PATHINFO_FILENAME);

                if (in_array($basename, $applied, true)) {
                    continue;
                }

                // Extract class name: "2026-05-11-163105_CreateUserProfilesTable" → "CreateUserProfilesTable"
                $className = preg_replace('/^\d{4}-\d{2}-\d{2}-\d{6}_/', '', $basename);
                $fqcn      = rtrim($namespace, '\\') . '\\' . $className;

                require_once $file;

                if (! class_exists($fqcn, false)) {
                    log_message('warning', "Tenantable: class '{$fqcn}' not found in '{$file}'.");
                    continue;
                }

                /** @var \CodeIgniter\Database\Migration $migration */
                $migration = new $fqcn($forge);
                $migration->up();

                // Record as applied
                $db->table('migrations')->insert([
                    'version'   => $basename,
                    'class'     => $fqcn,
                    'group'     => 'default',
                    'namespace' => $namespace,
                    'time'      => time(),
                    'batch'     => $this->getNextBatch($db),
                ]);

                $ran++;
                log_message('info', "Tenantable: applied '{$className}' to '{$dbName}'.");
            }

            $db->close();

            if ($ran > 0) {
                log_message('info', "Tenantable: {$ran} migration(s) applied to '{$dbName}'.");
            } else {
                log_message('info', "Tenantable: '{$dbName}' is up to date for '{$namespace}'.");
            }

            return true;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: migration for '{$dbName}' failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Resolve a PSR-4 namespace to its filesystem directory by checking
     * registered autoloader mappings and their sub-paths.
     */
    protected function resolveNamespaceDirectory(string $namespace): ?string
    {
        $autoloader   = \Config\Services::autoloader();
        $registeredNs = $autoloader->getNamespace();
        $nsKey        = trim($namespace, '\\');

        // Check for an exact match first
        foreach ([$nsKey, $nsKey . '\\'] as $key) {
            if (isset($registeredNs[$key])) {
                foreach ((array) $registeredNs[$key] as $path) {
                    if (is_dir($path)) {
                        return rtrim($path, '/\\');
                    }
                }
            }
        }

        // Try to resolve from a parent namespace
        foreach ($registeredNs as $parentNs => $parentPaths) {
            $parentNs = trim($parentNs, '\\');

            if (! str_starts_with($nsKey, $parentNs . '\\')) {
                continue;
            }

            $relative = substr($nsKey, strlen($parentNs) + 1);
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);

            foreach ((array) $parentPaths as $basePath) {
                $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $relative;

                if (is_dir($fullPath)) {
                    return $fullPath;
                }
            }
        }

        return null;
    }

    /**
     * Ensure the migrations tracking table exists in the tenant database.
     */
    protected function ensureMigrationTable(BaseConnection $db, \CodeIgniter\Database\Forge $forge): void
    {
        if ($db->tableExists('migrations')) {
            return;
        }

        $forge->addField([
            'id'        => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'version'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'class'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'group'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'namespace' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'time'      => ['type' => 'INT', 'constraint' => 11, 'null' => false],
            'batch'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('migrations', true);
    }

    protected function getAppliedMigrations(BaseConnection $db, string $namespace): array
    {
        if (! $db->tableExists('migrations')) {
            return [];
        }

        return array_column(
            $db->table('migrations')
               ->where('namespace', $namespace)
               ->get()
               ->getResultArray(),
            'version'
        );
    }

    protected function getNextBatch(BaseConnection $db): int
    {
        $result = $db->table('migrations')->selectMax('batch')->get()->getRow();
        return ($result->batch ?? 0) + 1;
    }

    /**
     * Ensure a PSR-4 namespace is explicitly registered in CI4's autoloader.
     *
     * CI4's FileLocator::listNamespace() only finds files under explicitly
     * registered namespaces. Sub-namespaces like 'App\Database\Migrations\Tenant'
     * are not automatically resolvable from the parent 'App' mapping.
     * This method resolves the path from the parent and registers it.
     */
    protected function ensureNamespaceRegistered(string $namespace): void
    {
        $autoloader  = \Config\Services::autoloader();
        $registeredNs = $autoloader->getNamespace();

        // Already registered — nothing to do
        $nsKey = trim($namespace, '\\');
        if (isset($registeredNs[$nsKey]) || isset($registeredNs[$nsKey . '\\'])) {
            return;
        }

        // Try to resolve from a parent namespace
        foreach ($registeredNs as $parentNs => $parentPaths) {
            $parentNs = trim($parentNs, '\\');

            if (! str_starts_with($nsKey, $parentNs . '\\')) {
                continue;
            }

            // Convert remaining namespace segments to path segments
            $relative = substr($nsKey, strlen($parentNs) + 1);
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);

            foreach ((array) $parentPaths as $basePath) {
                $fullPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $relative;

                if (is_dir($fullPath)) {
                    $autoloader->addNamespace($nsKey, $fullPath);
                    log_message('debug', "Tenantable: registered namespace '{$nsKey}' → '{$fullPath}'.");
                    return;
                }
            }
        }

        log_message('warning', "Tenantable: could not resolve namespace '{$namespace}' to a directory.");
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
