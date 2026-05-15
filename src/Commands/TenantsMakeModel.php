<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;

class TenantsMakeModel extends BaseCommand
{
    protected $group       = 'Tenantable';
    protected $name        = 'tenants:make-model';
    protected $description = 'Generate a tenant-scoped or global model class.';
    protected $usage       = 'tenants:make-model <name> [--prefix] [--global] [--namespace=NS] [--table=tbl] [--force]';
    protected $arguments   = [
        'name' => 'Model class name (suffix "Model" is appended if missing).',
    ];
    protected $options = [
        '--namespace'  => 'Override the target namespace.',
        '--table'      => 'Database table name (default: snake_case of the un-suffixed name + "s").',
        '--prefix'     => 'Generate a prefix-mode model (uses TenantTablePrefixTrait).',
        '--global'     => 'Generate a global (non-tenant) model under the global namespace.',
        '--force'      => 'Overwrite the file if it already exists.',
        '--no-suffix'  => 'Do not append "Model" to the class name.',
    ];

    public function run(array $params): void
    {
        $rawName = $params[0] ?? CLI::prompt('Model name', null, 'required');
        $rawName = trim((string) $rawName);

        if ($rawName === '') {
            CLI::error('Model name is required.');
            return;
        }

        if (preg_match('/[^A-Za-z0-9_]/', $rawName) === 1) {
            CLI::error('Model name may only contain letters, numbers, and underscores.');
            return;
        }

        $global    = (bool) CLI::getOption('global');
        $isPrefix  = (bool) CLI::getOption('prefix');
        $force     = (bool) CLI::getOption('force');
        $noSuffix  = (bool) CLI::getOption('no-suffix');

        if ($global && $isPrefix) {
            CLI::error('Cannot combine --global with --prefix.');
            return;
        }

        $config = config(TenantableConfig::class);

        $namespace = CLI::getOption('namespace');
        if (! is_string($namespace) || $namespace === '') {
            $namespace = $global ? $config->globalModelsNamespace : $config->tenantModelsNamespace;
        }
        $namespace = trim($namespace, '\\');

        $className = $noSuffix || str_ends_with($rawName, 'Model') ? $rawName : $rawName . 'Model';
        $baseName  = preg_replace('/Model$/', '', $className) ?: $className;
        $table     = (string) (CLI::getOption('table') ?: ($this->snakeCase($baseName) . 's'));

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

        $filePath = $targetDir . $className . '.php';
        if (is_file($filePath) && ! $force) {
            CLI::error("File already exists: {$filePath}");
            CLI::write('  Pass --force to overwrite.', 'yellow');
            return;
        }

        $contents = $this->renderTemplate($namespace, $className, $table, $global, $isPrefix);

        if (file_put_contents($filePath, $contents) === false) {
            CLI::error("Failed to write file: {$filePath}");
            return;
        }

        CLI::write('');
        CLI::write(CLI::color('  Model created.', 'green'));
        CLI::write("  class: {$namespace}\\{$className}");
        CLI::write("  file:  {$filePath}");
        CLI::write("  table: {$table}");
        CLI::write('  type:  ' . ($global ? 'global' : ($isPrefix ? 'prefix' : 'tenantable')));
        CLI::write('');
    }

    private function renderTemplate(
        string $namespace,
        string $className,
        string $table,
        bool $global,
        bool $isPrefix,
    ): string {
        if ($global) {
            return $this->renderGlobal($namespace, $className, $table);
        }
        if ($isPrefix) {
            return $this->renderPrefix($namespace, $className, $table);
        }
        return $this->renderTenantable($namespace, $className, $table);
    }

    private function renderGlobal(string $namespace, string $className, string $table): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use nuelcyoung\\tenantable\\Models\\GlobalModel;

        class {$className} extends GlobalModel
        {
            protected \$table         = '{$table}';
            protected \$primaryKey    = 'id';
            protected \$returnType    = 'array';
            protected \$useTimestamps = true;
            protected \$allowedFields = [];
        }

        PHP;
    }

    private function renderTenantable(string $namespace, string $className, string $table): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use nuelcyoung\\tenantable\\Models\\TenantableModel;

        class {$className} extends TenantableModel
        {
            protected \$table         = '{$table}';
            protected \$primaryKey    = 'id';
            protected \$returnType    = 'array';
            protected \$useTimestamps = true;
            protected \$allowedFields = [];

            protected string \$tenantIdColumn = 'tenant_id';
        }

        PHP;
    }

    private function renderPrefix(string $namespace, string $className, string $table): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use CodeIgniter\\Model;
        use nuelcyoung\\tenantable\\Traits\\TenantTablePrefixTrait;

        class {$className} extends Model
        {
            use TenantTablePrefixTrait;

            protected \$table         = '{$table}';
            protected \$primaryKey    = 'id';
            protected \$returnType    = 'array';
            protected \$useTimestamps = true;
            protected \$allowedFields = [];
        }

        PHP;
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