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

namespace nuelcyoung\tenantable\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Config as DbConnectionFactory;
use Config\Database as DbConfig;
use nuelcyoung\tenantable\Support\FrameworkState;
use nuelcyoung\tenantable\Support\TenantableConfig;

class TenantDatabaseManager
{
    /** Tracks per-tenant migration state in prefix mode. */
    public const TENANT_MIGRATIONS_TABLE = 'tenant_migrations';

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

        try {
            self::ensureCentralGroup($this->defaultSnapshot);

            $tenantConfig = $this->buildTenantConfig($dbName, $this->defaultSnapshot ?? []);

            $this->evictCachedConnection($group);
            $dbConfig->{$group} = $tenantConfig;

            $this->connections[$group] = DbConfig::connect($group, true);
            $this->tenantDbConfig       = $tenantConfig;
            $this->swapped              = true;

            return true;
        } catch (\Throwable $e) {
            // Revert to central on failure. Never leave a partial tenant config.
            $this->evictCachedConnection($group);
            $dbConfig->{$group} = $this->defaultSnapshot ?? [];
            $this->tenantDbConfig = null;
            $this->swapped        = false;
            unset($this->connections[$group]);

            throw $e;
        }
    }

    public function switchToTenant(string $subdomain): bool
    {
        $tenantModel = new \nuelcyoung\tenantable\Models\TenantModel();
        $tenant      = $tenantModel->where('subdomain', $subdomain)->first();

        if ($tenant === null) {
            return false;
        }

        // CI4 < 4.5 stores is_active as int.
        if (empty($tenant['is_active'])) {
            log_message('warning', "Tenantable: refusing to switch to inactive tenant '{$subdomain}'.");
            return false;
        }

        return $this->connectToTenant($tenant);
    }

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

    public static function ensureCentralGroup(?array $explicitConfig = null): void
    {
        if (self::$centralConfig !== null) {
            return;
        }

        $seed = $explicitConfig;

        if ($seed === null) {
            $dbConfig     = config('Database');
            $defaultGroup = $dbConfig->defaultGroup ?? 'default';
            $seed         = (array) ($dbConfig->{$defaultGroup} ?? []);
        }

        self::$centralConfig = $seed;
    }

    private static ?array $centralConfig = null;

    public static function getCentralConnection(): BaseConnection
    {
        self::ensureCentralGroup();

        $config = self::$centralConfig ?? [];

        return DbConnectionFactory::connect($config, false);
    }

    public function testConnection(array $config): bool
    {
        try {
            $db = DbConnectionFactory::connect($config, false);
            return $db->connect() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function provisionTenant(array $tenant): bool
    {
        $config = TenantableConfig::get();

        if ($config->resolvedIsolationMode() === 'prefix') {
            if (! $config->autoMigrateTenant) {
                log_message('info', 'Tenantable: autoMigrateTenant disabled, skipping prefix provisioning.');
                return false;
            }

            return $this->provisionPrefixTenant($tenant, $config);
        }

        if (! $this->shouldAutoProvision($config)) {
            log_message('debug', 'Tenantable: auto-provisioning skipped.');
            return false;
        }

        $dbName = \nuelcyoung\tenantable\Models\TenantModel::getDatabaseName($tenant);

        if (! $this->createDatabase($dbName)) {
            return false;
        }

        if (! $config->autoMigrateTenant) {
            log_message('info', 'Tenantable: autoMigrateTenant disabled, skipping migrations.');
            return true;
        }

        $namespaces = $this->resolveTenantMigrationNamespaces($config);

        if (empty($namespaces)) {
            log_message('warning', 'Tenantable: no tenant migration namespaces configured.');
            return true;
        }

        $allPassed = true;
        foreach ($namespaces as $ns) {
            log_message('info', "Tenantable: running migrations for namespace '{$ns}'.");
            if (! $this->migrateTenant($tenant, $ns)) {
                $allPassed = false;
            }
        }

        return $allPassed;
    }

    protected function resolveTenantMigrationNamespaces(\nuelcyoung\tenantable\Config\Tenantable $config): array
    {
        return $config->tenantMigrationNamespaces();
    }

    /** Create a tenant database. */
    public function createDatabase(string $databaseName): bool
    {
        $databaseName = \nuelcyoung\tenantable\Models\TenantModel::assertValidDatabaseName($databaseName);

        $admin = null;

        try {
            $base             = $this->getDefaultGroupConfig();
            $configuredDriver = strtolower((string) ($base['DBDriver'] ?? ''));

            if ($configuredDriver === 'sqlite3') {
                // SQLite databases are files; no admin connection needed.
                return $this->createSqliteDatabase($databaseName);
            }

            if (in_array($configuredDriver, ['mysqli', 'mysql', 'mariadb'], true)) {
                // MySQL lets you connect without a database selected.
                $base['database'] = '';
            } elseif ($configuredDriver === 'postgre') {
                // PostgreSQL needs an existing database to connect to first.
                $base['database'] = $this->getPostgreAdminDatabase();
            } else {
                log_message(
                    'error',
                    "Tenantable: automatic CREATE DATABASE is only supported on MySQL/MariaDB or PostgreSQL; " .
                    "driver '" . ($configuredDriver === '' ? '(unknown)' : $configuredDriver) . "' is not supported. " .
                    "Create '{$databaseName}' manually."
                );
                return false;
            }

            $admin  = DbConnectionFactory::connect($base, false);
            $driver = strtolower($admin->DBDriver);

            if ($driver === 'postgre') {
                if (! $this->createPostgreDatabase($admin, $databaseName)) {
                    log_message('error', "Tenantable: CREATE DATABASE '{$databaseName}' failed.");
                    return false;
                }
            } elseif (in_array($driver, ['mysqli', 'mysql', 'mariadb'], true)) {
                $escaped = str_replace('`', '``', $databaseName);
                $admin->query("CREATE DATABASE IF NOT EXISTS `{$escaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } else {
                log_message(
                    'error',
                    "Tenantable: automatic CREATE DATABASE is only supported on MySQL/MariaDB or PostgreSQL; " .
                    "driver '" . ($driver === '' ? '(unknown)' : $driver) . "' is not supported. " .
                    "Create '{$databaseName}' manually."
                );
                return false;
            }

            log_message('info', "Tenantable: database '{$databaseName}' created.");
            return true;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: CREATE DATABASE '{$databaseName}' failed: {$e->getMessage()}");
            return false;
        } finally {
            if ($admin instanceof BaseConnection) {
                $admin->close();
            }
        }
    }

    /** The PostgreSQL database used during provisioning. */
    protected function getPostgreAdminDatabase(): string
    {
        $database = trim(TenantableConfig::get()->postgresAdminDatabase);

        return $database === '' ? 'postgres' : $database;
    }

    /** Create a PostgreSQL database. Falls back to checking if it already exists. */
    protected function createPostgreDatabase(BaseConnection $admin, string $databaseName): bool
    {
        try {
            $forge = (new \CodeIgniter\Database\Database())->loadForge($admin);

            if ($forge->createDatabase($databaseName, true)) {
                return true;
            }
        } catch (\Throwable $e) {
            // Another process may have created it first. Treat as success.
            if ($this->postgreDatabaseExists($admin, $databaseName)) {
                return true;
            }

            throw $e;
        }

        return $this->postgreDatabaseExists($admin, $databaseName);
    }

    protected function postgreDatabaseExists(BaseConnection $admin, string $databaseName): bool
    {
        try {
            $result = $admin->query('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]);

            return $result !== false && $result->getRow() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Does a tenant database exist? SQLite checks the file; SQL drivers use an admin connection. */
    public function databaseExists(string $databaseName): bool
    {
        $databaseName = \nuelcyoung\tenantable\Models\TenantModel::assertValidDatabaseName($databaseName);

        $base   = $this->getDefaultGroupConfig();
        $driver = strtolower((string) ($base['DBDriver'] ?? ''));

        if ($driver === 'sqlite3') {
            return is_file($this->sqliteDatabasePath($databaseName));
        }

        $admin = null;

        try {
            if (in_array($driver, ['mysqli', 'mysql', 'mariadb'], true)) {
                $base['database'] = '';
            } elseif ($driver === 'postgre') {
                $base['database'] = $this->getPostgreAdminDatabase();
            } else {
                return false;
            }

            $admin = DbConnectionFactory::connect($base, false);
            $actualDriver = strtolower($admin->DBDriver);

            if ($actualDriver === 'postgre') {
                return $this->postgreDatabaseExists($admin, $databaseName);
            }

            $escaped = str_replace('`', '``', $databaseName);
            $result  = $admin->query(
                "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$escaped}'"
            );

            return $result !== false && $result->getRow() !== null;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: databaseExists('{$databaseName}') failed: {$e->getMessage()}");
            return false;
        } finally {
            if ($admin instanceof BaseConnection) {
                $admin->close();
            }
        }
    }

    /**
     * Delete a tenant database. Not wired into tenant deletion automatically;
     * call it explicitly (e.g. from a tenantDeleted listener) to remove data.
     */
    public function deleteDatabase(string $databaseName): bool
    {
        $databaseName = \nuelcyoung\tenantable\Models\TenantModel::assertValidDatabaseName($databaseName);

        $base   = $this->getDefaultGroupConfig();
        $driver = strtolower((string) ($base['DBDriver'] ?? ''));

        if ($driver === 'sqlite3') {
            $path = $this->sqliteDatabasePath($databaseName);

            if (! is_file($path)) {
                return true;
            }

            if (@unlink($path)) {
                log_message('info', "Tenantable: SQLite database '{$databaseName}' deleted.");
                return true;
            }

            log_message('error', "Tenantable: cannot delete SQLite database '{$path}'.");
            return false;
        }

        $admin = null;

        try {
            if (in_array($driver, ['mysqli', 'mysql', 'mariadb'], true)) {
                $base['database'] = '';
            } elseif ($driver === 'postgre') {
                $base['database'] = $this->getPostgreAdminDatabase();
            } else {
                log_message('error', "Tenantable: deleting a tenant database is only supported on MySQL/MariaDB, PostgreSQL or SQLite.");
                return false;
            }

            $admin = DbConnectionFactory::connect($base, false);
            $actualDriver = strtolower($admin->DBDriver);

            if ($actualDriver === 'postgre') {
                // Connections to the target database block DROP; terminate them first.
                $admin->query(
                    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()",
                    [$databaseName]
                );
                $forge = (new \CodeIgniter\Database\Database())->loadForge($admin);

                if (! $forge->dropDatabase($databaseName)) {
                    return $this->postgreDatabaseExists($admin, $databaseName) === false;
                }

                return true;
            }

            $escaped = str_replace('`', '``', $databaseName);
            $admin->query("DROP DATABASE IF EXISTS `{$escaped}`");

            return true;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: DROP DATABASE '{$databaseName}' failed: {$e->getMessage()}");
            return false;
        } finally {
            if ($admin instanceof BaseConnection) {
                $admin->close();
            }
        }
    }

    /** True when the default group uses the SQLite3 driver. */
    public function isSqliteDriver(array $config): bool
    {
        return strtolower((string) ($config['DBDriver'] ?? '')) === 'sqlite3';
    }

    /** Directory holding tenant SQLite database files. */
    public function sqliteDatabasesDirectory(): string
    {
        try {
            $configured = TenantableConfig::get()->sqliteDatabasesPath;
        } catch (\Throwable $e) {
            $configured = null;
        }

        $directory = is_string($configured) && $configured !== ''
            ? $configured
            : rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'tenant_databases';

        return rtrim($directory, '/\\');
    }

    /** File path for a tenant SQLite database. */
    public function sqliteDatabasePath(string $databaseName): string
    {
        return $this->sqliteDatabasesDirectory()
            . DIRECTORY_SEPARATOR
            . \nuelcyoung\tenantable\Models\TenantModel::assertValidDatabaseName($databaseName)
            . '.sqlite';
    }

    /** Point a connection config at a tenant database: name for SQL drivers, file path for SQLite. */
    public function applyTenantDatabase(array $config, string $databaseName): array
    {
        $databaseName = \nuelcyoung\tenantable\Models\TenantModel::assertValidDatabaseName($databaseName);

        $config['database'] = $this->isSqliteDriver($config)
            ? $this->sqliteDatabasePath($databaseName)
            : $databaseName;

        return $config;
    }

    /** Create the SQLite file for a tenant database. Idempotent. */
    protected function createSqliteDatabase(string $databaseName): bool
    {
        $path = $this->sqliteDatabasePath($databaseName);

        try {
            if (is_file($path)) {
                return true;
            }

            $directory = dirname($path);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                log_message('error', "Tenantable: cannot create SQLite directory '{$directory}'.");
                return false;
            }

            // A zero-byte file is a valid empty SQLite database; the driver
            // writes the header on first connection.
            if (@touch($path)) {
                log_message('info', "Tenantable: SQLite database '{$databaseName}' created ('{$path}').");
                return true;
            }

            log_message('error', "Tenantable: cannot create SQLite database '{$path}'.");

            return false;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: CREATE DATABASE '{$databaseName}' failed: {$e->getMessage()}");
            return false;
        }
    }

    public function migrateTenant(array $tenant, string $namespace): bool
    {
        $dbName = \nuelcyoung\tenantable\Models\TenantModel::getDatabaseName($tenant);

        $db = null;

        try {
            $config = $this->applyTenantDatabase($this->getDefaultGroupConfig(), $dbName);

            $db    = DbConnectionFactory::connect($config, false);
            $forge = (new \CodeIgniter\Database\Database())->loadForge($db);

            $this->ensureMigrationTable($db, $forge);

            $dir = $this->resolveNamespaceDirectory($namespace);
            if ($dir === null) {
                log_message('warning', "Tenantable: cannot resolve '{$namespace}' to a directory.");
                return true;
            }

            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
            if (empty($files)) {
                log_message('info', "Tenantable: no migration files in '{$dir}'.");
                return true;
            }

            sort($files);

            $applied = $this->getAppliedMigrations($db, $namespace);

            $ran = 0;
            foreach ($files as $file) {
                $basename = pathinfo($file, PATHINFO_FILENAME);
                // Match CI4's version format to avoid running twice.
                $version  = $this->extractMigrationVersion($basename);

                // Check both formats to avoid re-running after an upgrade.
                if (in_array($version, $applied, true) || in_array($basename, $applied, true)) {
                    continue;
                }

                $className = $this->extractClassName($file);
                if ($className === null) {
                    log_message('warning', "Tenantable: no class found in '{$file}'.");
                    continue;
                }

                $fqcn = rtrim($namespace, '\\') . '\\' . $className;

                require_once $file;

                if (! class_exists($fqcn, false)) {
                    log_message('warning', "Tenantable: class '{$fqcn}' not found in '{$file}'.");
                    continue;
                }

                // Wrap in a transaction. MySQL DDL auto-commits anyway.
                $db->transStart();

                /** @var \CodeIgniter\Database\Migration $migration */
                $migration = new $fqcn($forge);
                $migration->up();

                $db->table('migrations')->insert([
                    'version'   => $version,
                    'class'     => $fqcn,
                    'group'     => 'default',
                    'namespace' => $namespace,
                    'time'      => time(),
                    'batch'     => $this->getNextBatch($db),
                ]);

                $db->transComplete();

                if ($db->transStatus() === false) {
                    log_message('error', "Tenantable: migration '{$className}' rolled back on '{$dbName}'.");
                    return false;
                }

                $ran++;
                log_message('info', "Tenantable: applied '{$className}' to '{$dbName}'.");
            }

            if ($ran > 0) {
                log_message('info', "Tenantable: {$ran} migration(s) applied to '{$dbName}'.");
            } else {
                log_message('info', "Tenantable: '{$dbName}' is up to date for '{$namespace}'.");
            }

            return true;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: migration for '{$dbName}' failed: {$e->getMessage()}");
            return false;
        } finally {
            // Close the per-tenant connection.
            if ($db instanceof BaseConnection) {
                $db->close();
            }
        }
    }

    /** Create a tenant's prefixed tables. Called on creation or for backfills. */
    public function provisionPrefixTenant(
        array $tenant,
        ?\nuelcyoung\tenantable\Config\Tenantable $config = null,
        ?BaseConnection $db = null,
    ): bool {
        $config ??= TenantableConfig::get();

        $namespaces = $config->tenantMigrationNamespaces();

        if (empty($namespaces)) {
            log_message('warning', 'Tenantable: no tenant migration namespaces configured.');
            return true;
        }

        $allPassed = true;

        foreach ($namespaces as $ns) {
            if ($this->migrateTenantTables($tenant, $ns, $db) === null) {
                $allPassed = false;
            }
        }

        return $allPassed;
    }

    /**
     * Run migrations for one tenant in prefix mode. The framework's
     * MigrationRunner can't be used here: all tenants share one database.
     */
    public function migrateTenantTables(array $tenant, string $namespace, ?BaseConnection $db = null): ?int
    {
        $tenantId = (int) ($tenant['id'] ?? 0);

        if ($tenantId <= 0) {
            log_message('error', 'Tenantable: migrateTenantTables() requires a positive tenant id.');
            return null;
        }

        $ownsConnection = $db === null;

        try {
            $db ??= \Config\Database::connect();
            $forge = (new \CodeIgniter\Database\Database())->loadForge($db);

            $this->ensureTenantMigrationsTable($db, $forge);

            $files = $this->findTenantMigrationFiles($namespace);
            if ($files === []) {
                log_message('warning', "Tenantable: no migration files found for '{$namespace}'.");
                return 0;
            }

            $applied = $this->getAppliedTenantMigrations($db, $tenantId, $namespace);

            // Point the table manager at this tenant so migrations resolve correctly.
            $tableManager      = TenantTableManager::getInstance();
            $previousId        = $tableManager->getTenantId();
            $previousSubdomain = $tableManager->getSubdomain();

            $tableManager->setTenant($tenantId, isset($tenant['subdomain']) ? (string) $tenant['subdomain'] : null);

            // Run unprefixed or Forge would prepend a second prefix on
            // connections already bound to a prefix-aware model.
            $previousPrefix = $db->getPrefix();

            if ($previousPrefix !== '') {
                $db->setPrefix('');
            }

            $ran = 0;

            try {
                foreach ($files as $file) {
                    $basename = pathinfo($file, PATHINFO_FILENAME);
                    // Match CI4's version format to avoid running twice.
                    $version = $this->extractMigrationVersion($basename);

                    if (in_array($version, $applied, true) || in_array($basename, $applied, true)) {
                        continue;
                    }

                    $className = $this->extractClassName($file);
                    if ($className === null) {
                        log_message('warning', "Tenantable: no class found in '{$file}'.");
                        continue;
                    }

                    $fqcn = rtrim($namespace, '\\') . '\\' . $className;

                    require_once $file;

                    if (! class_exists($fqcn, false)) {
                        log_message('warning', "Tenantable: class '{$fqcn}' not found in '{$file}'.");
                        continue;
                    }

                    // Wrap in a transaction. MySQL DDL auto-commits anyway.
                    $db->transStart();

                    /** @var \CodeIgniter\Database\Migration $migration */
                    $migration = new $fqcn($forge);
                    $migration->up();

                    $db->table(self::TENANT_MIGRATIONS_TABLE)->insert([
                        'tenant_id' => $tenantId,
                        'version'   => $version,
                        'class'     => $fqcn,
                        'namespace' => $namespace,
                        'time'      => time(),
                        'batch'     => $this->getNextTenantBatch($db, $tenantId),
                    ]);

                    $db->transComplete();

                    if ($db->transStatus() === false) {
                        log_message('error', "Tenantable: prefix migration '{$className}' rolled back for tenant {$tenantId}.");
                        return null;
                    }

                    $ran++;
                    log_message('info', "Tenantable: applied '{$className}' for tenant {$tenantId} (prefix mode).");
                }
            } finally {
                // Put back the caller's tenant context.
                if ($previousId !== null) {
                    $tableManager->setTenant($previousId, $previousSubdomain);
                } else {
                    $tableManager->clear();
                }

                // Restore the connection prefix suppressed above; for bound
                // connections the manager restore already applied the right one.
                if ($db->getPrefix() !== $previousPrefix) {
                    $db->setPrefix($previousPrefix);
                }
            }

            if ($ran > 0) {
                log_message('info', "Tenantable: {$ran} prefix migration(s) applied for tenant {$tenantId}.");
            }

            return $ran;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: prefix migrations for tenant {$tenantId} failed: {$e->getMessage()}");
            return null;
        } finally {
            if ($ownsConnection && $db instanceof BaseConnection) {
                $db->close();
            }
        }
    }

    /** Sorted migration file paths for a namespace. */
    public function findTenantMigrationFiles(string $namespace): array
    {
        $dir = $this->resolveNamespaceDirectory($namespace);
        if ($dir === null) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    protected function ensureTenantMigrationsTable(BaseConnection $db, \CodeIgniter\Database\Forge $forge): void
    {
        if ($db->tableExists(self::TENANT_MIGRATIONS_TABLE)) {
            return;
        }

        $forge->addField([
            'id'        => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => false],
            'version'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'class'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'namespace' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'time'      => ['type' => 'INT', 'constraint' => 11, 'null' => false],
            'batch'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
        ]);
        $forge->addKey('id', true);
        $forge->addKey(['tenant_id', 'namespace']);
        $forge->createTable(self::TENANT_MIGRATIONS_TABLE, true);
    }

    protected function getAppliedTenantMigrations(BaseConnection $db, int $tenantId, string $namespace): array
    {
        if (! $db->tableExists(self::TENANT_MIGRATIONS_TABLE)) {
            return [];
        }

        return array_column(
            $db->table(self::TENANT_MIGRATIONS_TABLE)
               ->where('tenant_id', $tenantId)
               ->where('namespace', $namespace)
               ->get()
               ->getResultArray(),
            'version'
        );
    }

    protected function getNextTenantBatch(BaseConnection $db, int $tenantId): int
    {
        $result = $db->table(self::TENANT_MIGRATIONS_TABLE)
            ->selectMax('batch')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRow();

        return ((int) ($result->batch ?? 0)) + 1;
    }

    protected function resolveNamespaceDirectory(string $namespace): ?string
    {
        $autoloader   = \Config\Services::autoloader();
        $registeredNs = $autoloader->getNamespace();
        $nsKey        = trim($namespace, '\\');

        foreach ([$nsKey, $nsKey . '\\'] as $key) {
            if (isset($registeredNs[$key])) {
                foreach ((array) $registeredNs[$key] as $path) {
                    if (is_dir($path)) {
                        return rtrim($path, '/\\');
                    }
                }
            }
        }

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

    protected function ensureNamespaceRegistered(string $namespace): void
    {
        $autoloader  = \Config\Services::autoloader();
        $registeredNs = $autoloader->getNamespace();

        $nsKey = trim($namespace, '\\');
        if (isset($registeredNs[$nsKey]) || isset($registeredNs[$nsKey . '\\'])) {
            return;
        }

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
                    $autoloader->addNamespace($nsKey, $fullPath);
                    log_message('debug', "Tenantable: registered namespace '{$nsKey}' → '{$fullPath}'.");
                    return;
                }
            }
        }

        log_message('warning', "Tenantable: could not resolve namespace '{$namespace}' to a directory.");
    }

    protected function shouldAutoProvision(\nuelcyoung\tenantable\Config\Tenantable $config): bool
    {
        if (! $config->autoCreateDatabase) {
            return false;
        }

        return $config->isDatabaseIsolation();
    }

    public function getDefaultGroupConfig(): array
    {
        $dbConfig = config('Database');
        $group    = $dbConfig->defaultGroup ?? $this->defaultGroup;

        return (array) ($dbConfig->{$group} ?? []);
    }

    protected function buildTenantConfig(string $databaseName, array $base): array
    {
        return $this->applyTenantDatabase($base, $databaseName);
    }

    /**
     * Drop the shared connection for a group via FrameworkState; a stale one
     * would keep serving the previous tenant's database.
     */
    protected function evictCachedConnection(string $group): void
    {
        FrameworkState::evictSharedDbConnection($group);
    }

    /** Extract the version from a migration filename. */
    protected function extractMigrationVersion(string $basename): string
    {
        if (preg_match('/\A(\d{4}[_-]?\d{2}[_-]?\d{2}[_-]?\d{6})_(\w+)\z/', $basename, $m) === 1) {
            return $m[1];
        }

        return $basename;
    }

    protected function extractClassName(string $filePath): ?string
    {
        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/^\s*class\s+(\w+)\s/m', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
