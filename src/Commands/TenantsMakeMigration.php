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

class TenantsMakeMigration extends BaseCommand
{
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
        $namespace = CLI::getOption('namespace');

        if (! is_string($namespace) || $namespace === '') {
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
        $force     = (bool) CLI::getOption('force');
        $table     = (string) (CLI::getOption('table') ?: $this->guessTable($name));
        $timestamp = date('Y-m-d-His');
        $fileName  = "{$timestamp}_{$name}.php";

        $targetDir = $this->resolveNamespaceDir($namespace);
        if ($targetDir === null) {
            CLI::error("Could not resolve a directory for namespace '{$namespace}'.");
            CLI::write('  Add it to Config\Autoload::$psr4 (e.g. \'App\' => APPPATH).', 'yellow');
            return;
        }

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0777, true) && ! is_dir($targetDir)) {
            CLI::error("Failed to create directory: {$targetDir}");
            return;
        }

        $filePath = $targetDir . $fileName;
        if (is_file($filePath) && ! $force) {
            CLI::error("File already exists: {$filePath}");
            CLI::write('  Pass --force to overwrite.', 'yellow');
            return;
        }

        $contents = $this->renderTemplate($namespace, $name, $table);

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

    private function renderTemplate(string $namespace, string $className, string $table): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use CodeIgniter\\Database\\Migration;

        class {$className} extends Migration
        {
            public function up(): void
            {
                \$this->forge->addField([
                    'id' => [
                        'type'           => 'INT',
                        'constraint'     => 11,
                        'unsigned'       => true,
                        'auto_increment' => true,
                    ],
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
                \$this->forge->createTable('{$table}', true);
            }

            public function down(): void
            {
                \$this->forge->dropTable('{$table}', true);
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
}
