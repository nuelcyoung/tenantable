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

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;

class TenantsMakeMigration extends BaseCommand
{
    use NormalizesCliOptions;

    protected $group       = 'Tenantable';
    protected $name        = 'tenants:make-migration';
    protected $description = 'Generate a tenant-scoped migration file.';
    protected $usage       = 'tenants:make-migration <name> [--table=tbl] [--namespace=NS] [--force]';
    protected $arguments   = [
        'name' => 'Migration class name (e.g. CreateUsersTable, AddStatusToOrders).',
    ];
    protected $options = [
        '--table'     => 'Table name to reference in the migration stub.',
        '--namespace' => 'Override the target namespace.',
        '--force'     => 'Overwrite the file if it already exists.',
    ];

    public function run(array $params): void
    {
        $name = $params[0] ?? CLI::prompt('Migration name', null, 'required');
        $name = trim((string) $name);

        if ($name === '') {
            CLI::error('Migration name is required.');
            return;
        }

        if (preg_match('/[^A-Za-z0-9_]/', $name) === 1) {
            CLI::error('Migration name may only contain letters, numbers, and underscores.');
            return;
        }

        $config    = config(TenantableConfig::class);
        $namespace = $this->cliOption('namespace');

        if ($namespace === null || $namespace === '') {
            $namespace = $config->tenantMigrationsNamespace;
        }

        if (empty($namespace)) {
            CLI::error(
                'No tenant migrations namespace configured.' . PHP_EOL .
                '  Set Config\Tenantable::$tenantMigrationsNamespace or pass --namespace=.'
            );
            return;
        }

        $namespace = trim($namespace, '\\');

        if (! $this->isValidNamespace($namespace)) {
            CLI::error("Invalid namespace '{$namespace}'. Use a valid PHP namespace, e.g. App\\Database\\Migrations\\Tenant.");
            return;
        }

        $force = $this->hasCliOption('force');
        $table = $this->cliOption('table') ?: $this->guessTable($name);

        if (! $this->isValidTableName($table)) {
            CLI::error("Invalid table name '{$table}'. Table names may only contain letters, numbers, and underscores.");
            return;
        }
        $timestamp = date('Y-m-d-His');
        $fileName  = "{$timestamp}_{$name}.php";

        $targetDir = $this->resolveNamespaceDir($namespace);
        if ($targetDir === null) {
            CLI::error("Could not resolve a directory for namespace '{$namespace}'.");
            CLI::write('  Add it to Config\Autoload::$psr4 (e.g. \'App\' => APPPATH).', 'yellow');
            return;
        }

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            CLI::error("Failed to create directory: {$targetDir}");
            return;
        }

        $filePath = $targetDir . $fileName;
        if (is_file($filePath) && ! $force) {
            CLI::error("File already exists: {$filePath}");
            CLI::write('  Pass --force to overwrite.', 'yellow');
            return;
        }

        $contents = $this->renderTemplate($namespace, $name, $table, $config);

        if (file_put_contents($filePath, $contents) === false) {
            CLI::error("Failed to write file: {$filePath}");
            return;
        }

        CLI::write('');
        CLI::write(CLI::color('  Migration created.', 'green'));
        CLI::write("  class:     {$namespace}\\{$name}");
        CLI::write("  file:      {$filePath}");
        CLI::write("  table:     {$table}");
        CLI::write("  namespace: {$namespace}");
        CLI::write('');
    }

    private function renderTemplate(
        string $namespace,
        string $className,
        string $table,
        TenantableConfig $config,
    ): string {
        // Row mode needs a NOT NULL, indexed, FK-constrained tenant column;
        // prefix/database modes isolate elsewhere and get none.
        $isRowMode = $config->resolvedIsolationMode() === 'row';

        // Prefix mode routes table names through TenantTableManager so they
        // resolve per tenant at run time.
        $isPrefixMode = $config->resolvedIsolationMode() === 'prefix';

        $tableManagerImport = '';
        $tableManagerInit   = '';
        $createTableCall    = "\$this->forge->createTable('{$table}', true);";
        $dropTableCall      = "\$this->forge->dropTable('{$table}', true);";

        if ($isPrefixMode) {
            $tableManagerImport = "\nuse nuelcyoung\\tenantable\\Services\\TenantTableManager;";
            $tableManagerInit   = "        \$tableManager = TenantTableManager::getInstance();\n\n";
            $createTableCall    = "\$this->forge->createTable(\$tableManager->getTable('{$table}'), true);";
            $dropTableCall      = "\$this->forge->dropTable(\$tableManager->getTable('{$table}'), true);";
        }

        $tenantField = '';
        $tenantKeys  = '';

        if ($isRowMode) {
            $tenantColumn = $config->tenantIdColumn;
            $tenantsTable = $config->tenantsTable;

            // Explicit indentation: interpolated as a unit at column 0.
            $tenantField = implode("\n", [
                "            '{$tenantColumn}' => [",
                "                'type'       => 'BIGINT',",
                "                'constraint' => 20,",
                "                'unsigned'   => true,",
                "                'null'       => false,",
                '            ],',
            ]);

            $tenantKeys = implode("\n", [
                "        \$this->forge->addKey('{$tenantColumn}');",
                "        \$this->forge->addForeignKey('{$tenantColumn}', '{$tenantsTable}', 'id', 'CASCADE', 'CASCADE');",
            ]);
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use CodeIgniter\\Database\\Migration;{$tableManagerImport}

        class {$className} extends Migration
        {
            public function up(): void
            {
        {$tableManagerInit}        \$this->forge->addField([
                    'id' => [
                        'type'           => 'BIGINT',
                        'constraint'     => 20,
                        'unsigned'       => true,
                        'auto_increment' => true,
                    ],
        {$tenantField}
                    'created_at' => [
                        'type' => 'DATETIME',
                        'null' => true,
                    ],
                    'updated_at' => [
                        'type' => 'DATETIME',
                        'null' => true,
                    ],
                ]);

                \$this->forge->addKey('id', true);
        {$tenantKeys}
                {$createTableCall}
            }

            public function down(): void
            {
        {$tableManagerInit}        {$dropTableCall}
            }
        }

        PHP;
    }

    private function guessTable(string $name): string
    {
        if (preg_match('/^Create(.+?)(?:Table)?$/i', $name, $m)) {
            return $this->snakeCase($m[1]);
        }

        if (preg_match('/(?:To|From)([A-Z][A-Za-z0-9]+)$/i', $name, $m)) {
            return $this->snakeCase($m[1]);
        }

        return $this->snakeCase($name);
    }

    private function resolveNamespaceDir(string $namespace): ?string
    {
        $autoload = config('Autoload');
        $psr4     = (array) ($autoload->psr4 ?? []);

        $psr4['App'] ??= rtrim(APPPATH, '/\\');

        $namespace = trim($namespace, '\\');

        $bestPrefix = '';
        $bestPath   = null;

        foreach ($psr4 as $prefix => $path) {
            $prefix = trim((string) $prefix, '\\');
            if ($prefix === '') {
                continue;
            }
            $matches = $namespace === $prefix || str_starts_with($namespace, $prefix . '\\');
            if ($matches && strlen($prefix) > strlen($bestPrefix)) {
                $bestPrefix = $prefix;
                $bestPath   = (string) $path;
            }
        }

        if ($bestPath === null) {
            return null;
        }

        $base      = rtrim($bestPath, '/\\') . DIRECTORY_SEPARATOR;
        $remainder = trim(substr($namespace, strlen($bestPrefix)), '\\');

        if ($remainder === '') {
            return $base;
        }

        return $base . str_replace('\\', DIRECTORY_SEPARATOR, $remainder) . DIRECTORY_SEPARATOR;
    }

    private function snakeCase(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;
        return strtolower($value);
    }

    private function isValidTableName(string $table): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1;
    }

    private function isValidNamespace(string $namespace): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace) === 1;
    }
}
