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
use CodeIgniter\Database\ConnectionInterface;
use Config\Database as DbConfig;

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

    public static function getCentralConnection(): ConnectionInterface
    {
        self::ensureCentralGroup();

        $config = self::$centralConfig ?? [];

        return DbConnectionFactory::connect($config, false);
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

    public function provisionTenant(array $tenant): bool
    {
        $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);

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

    public function createDatabase(string $databaseName): bool
    {
        try {
            $base = $this->getDefaultGroupConfig();
            $base['database'] = '';

            $admin   = \Config\Database::connect($base, false);
            $escaped = str_replace('`', '``', $databaseName);
            $admin->query("CREATE DATABASE IF NOT EXISTS `{$escaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            log_message('info', "Tenantable: database '{$databaseName}' created.");
            return true;
        } catch (\Throwable $e) {
            log_message('error', "Tenantable: CREATE DATABASE '{$databaseName}' failed: {$e->getMessage()}");
            return false;
        }
    }

    public function migrateTenant(array $tenant, string $namespace): bool
    {
        $dbName = \nuelcyoung\tenantable\Models\TenantModel::getDatabaseName($tenant);

        try {
            $config             = $this->getDefaultGroupConfig();
            $config['database'] = $dbName;

            $db    = DbConfig::connect($config, false);
            $forge = \Config\Database::forge($db);

            $this->ensureMigrationTable($db, $forge);

            $dir = $this->resolveNamespaceDirectory($namespace);
            if ($dir === null) {
                log_message('warning', "Tenantable: cannot resolve '{$namespace}' to a directory.");
                $db->close();
                return true;
            }

            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
            if (empty($files)) {
                log_message('info', "Tenantable: no migration files in '{$dir}'.");
                $db->close();
                return true;
            }

            sort($files);

            $applied = $this->getAppliedMigrations($db, $namespace);

            $ran = 0;
            foreach ($files as $file) {
                $basename = pathinfo($file, PATHINFO_FILENAME);

                if (in_array($basename, $applied, true)) {
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

                /** @var \CodeIgniter\Database\Migration $migration */
                $migration = new $fqcn($forge);
                $migration->up();

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

        $mode = $config->isolationMode;
        if ($mode === null) {
            $mode = $config->separateDatabasePerTenant ? 'database' : 'row';
        }

        return $mode === 'database';
    }

    public function getDefaultGroupConfig(): array
    {
        $dbConfig = config('Database');
        $group    = $dbConfig->defaultGroup ?? $this->defaultGroup;

        return (array) ($dbConfig->{$group} ?? []);
    }

    protected function buildTenantConfig(string $databaseName, array $base): array
    {
        $config             = $base;
        $config['database'] = $databaseName;

        return $config;
    }

    protected function evictCachedConnection(string $group): void
    {
        try {
            $existing = DbConfig::connect($group, true);
            if ($existing instanceof BaseConnection) {
                $existing->close();
            }
        } catch (\Throwable $e) {
        }

        try {
            $ref       = new \ReflectionClass(DbConfig::class);
            $prop      = $ref->getProperty('instances');
            $prop->setAccessible(true);
            $instances = (array) $prop->getValue();
            unset($instances[$group]);
            $prop->setValue(null, $instances);
        } catch (\Throwable $e) {
        }
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
