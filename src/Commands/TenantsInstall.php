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
use nuelcyoung\tenantable\Support\InstallPatcher;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;
use nuelcyoung\tenantable\Traits\ReportsInfrastructure;

/** `tenants:install` — first-run setup. Safe to re-run. */
class TenantsInstall extends BaseCommand
{
    use NormalizesCliOptions;
    use ReportsInfrastructure;

    protected $group       = 'Tenantable';
    protected $name        = 'tenants:install';
    protected $description = 'Install Tenantable: publish config, wire filters/events, migrate central tables.';
    protected $usage       = 'tenants:install [options]';
    protected $options     = [
        '--base-domain'     => 'Base domain (e.g. example.com). Skips the prompt.',
        '--mode'            => 'Isolation mode: row|prefix|database. Skips the prompt.',
        '--strategy'        => 'Identification strategy: subdomain|domain|domain_or_subdomain|path|request_data.',
        '--early-detection' => 'Enable EarlyTenantDetector (pre_system). Accepts yes/no; skips the prompt.',
        '--sessions-table'  => 'Ship the packaged ci_sessions migration into each tenant DB (database mode only). Accepts yes/no; skips the prompt.',
        '--no-migrate'      => 'Skip running tenants:setup at the end.',
        '--no-sample'       => 'Skip creating the sample tenant migration file.',
        '--no-events'       => 'Skip patching app/Config/Events.php.',
        '--no-filters'      => 'Skip patching app/Config/Filters.php.',
        '--force'           => 'Overwrite app/Config/Tenantable.php if it already exists.',
        '--yes'             => 'Non-interactive: use defaults / flag values, never prompt.',
    ];

    private const STRATEGIES = ['subdomain', 'domain', 'domain_or_subdomain', 'path', 'request_data'];
    private const MODES      = ['row', 'prefix', 'database'];

    public function run(array $params): void
    {
        $this->banner();

        $yes      = $this->hasCliOption('yes');
        $baseDom  = $this->resolveBaseDomain($yes);
        $mode     = $this->resolveMode($yes);
        $strategy = $this->resolveStrategy($yes);
        $early    = $this->resolveEarlyDetection($yes, $strategy);
        $sessions = $this->resolveSessionsTable($yes, $mode);

        CLI::write('');
        CLI::write(CLI::color('  Plan', 'yellow'));
        CLI::write('  base domain        : ' . CLI::color($baseDom, 'cyan'));
        CLI::write('  isolation mode     : ' . CLI::color($mode, 'cyan'));
        CLI::write('  identify strategy  : ' . CLI::color($strategy, 'cyan'));
        CLI::write('  early detection    : ' . CLI::color($early ? 'on' : 'off', 'cyan'));
        if ($mode === 'database') {
            CLI::write('  sessions table     : ' . CLI::color($sessions ? 'on' : 'off', 'cyan'));
        }
        CLI::write('');

        $this->publishConfig($baseDom, $mode, $strategy, $early, $sessions);

        if (! $this->hasCliOption('no-filters')) {
            $this->patchFilters($strategy);
        }

        if (! $this->hasCliOption('no-events')) {
            $this->patchEvents();
        }

        $this->scaffoldTenantMigrations();

        if (! $this->hasCliOption('no-migrate')) {
            $this->runSetup($mode);
        }

        $this->reportInfrastructure();

        $this->summary($mode);
    }

    private function resolveBaseDomain(bool $yes): string
    {
        $opt = $this->cliOption('base-domain');
        if (is_string($opt) && $opt !== '') {
            return $opt;
        }
        if ($yes) {
            return 'localhost';
        }
        $val = CLI::prompt('Base domain (e.g. example.com)', 'localhost');
        return $val !== '' ? $val : 'localhost';
    }

    private function resolveMode(bool $yes): string
    {
        $opt = $this->cliOption('mode');
        if (is_string($opt) && $opt !== '') {
            if (! in_array($opt, self::MODES, true)) {
                CLI::error("Invalid --mode '{$opt}'. Allowed: " . implode(', ', self::MODES));
                exit(1);
            }
            return $opt;
        }
        if ($yes) {
            return 'row';
        }
        return (string) CLI::prompt('Isolation mode', self::MODES, 'required');
    }

    private function resolveStrategy(bool $yes): string
    {
        $opt = $this->cliOption('strategy');
        if (is_string($opt) && $opt !== '') {
            if (! in_array($opt, self::STRATEGIES, true)) {
                CLI::error("Invalid --strategy '{$opt}'. Allowed: " . implode(', ', self::STRATEGIES));
                exit(1);
            }
            return $opt;
        }
        if ($yes) {
            return 'domain_or_subdomain';
        }
        return (string) CLI::prompt('Identification strategy', self::STRATEGIES, 'required');
    }

    private function resolveEarlyDetection(bool $yes, string $strategy): bool
    {
        if (! in_array($strategy, ['subdomain', 'domain', 'domain_or_subdomain'], true)) {
            if ($this->hasCliOption('early-detection')) {
                CLI::write('  Early detection supports only domain and subdomain strategies; disabling it.', 'yellow');
            }

            return false;
        }

        if ($this->hasCliOption('early-detection')) {
            $opt = $this->cliOption('early-detection');
            if ($opt === null || $opt === '' || $opt === '1') {
                return true;
            }
            return ! in_array(strtolower($opt), ['0', 'false', 'no', 'off'], true);
        }
        if ($yes) {
            return false;
        }

        CLI::write('  Early detection runs in pre_system to isolate session/cache/storage', 'light_gray');
        CLI::write('  per tenant before the framework boots them. Recommended when sessions,', 'light_gray');
        CLI::write('  cache, or uploads must be tenant-scoped.', 'light_gray');

        $answer = strtolower((string) CLI::prompt('Enable early detection?', ['y', 'n'], 'required'));
        return $answer === 'y';
    }

    private function resolveSessionsTable(bool $yes, string $mode): bool
    {
        // Only meaningful in database isolation: the shipped migration runs
        // per tenant database and never on central.
        if ($mode !== 'database') {
            return false;
        }

        if ($this->hasCliOption('sessions-table')) {
            $opt = $this->cliOption('sessions-table');
            if ($opt === null || $opt === '' || $opt === '1') {
                return true;
            }
            return ! in_array(strtolower($opt), ['0', 'false', 'no', 'off'], true);
        }
        if ($yes) {
            return false;
        }

        CLI::write('  CodeIgniter never creates the session table itself (it only offers', 'light_gray');
        CLI::write('  the make:migration --session generator). With the database session', 'light_gray');
        CLI::write('  handler every tenant database needs its own ci_sessions table;', 'light_gray');
        CLI::write('  Tenantable can run its packaged migration on each tenant DB.', 'light_gray');

        $answer = strtolower((string) CLI::prompt('Ship the sessions table into tenant databases?', ['y', 'n'], 'required'));
        return $answer === 'y';
    }

    private function publishConfig(string $baseDomain, string $mode, string $strategy, bool $early, bool $sessions): void
    {
        CLI::write(CLI::color('• Publishing config', 'yellow'));

        $target = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Tenantable.php';
        $force  = $this->hasCliOption('force');

        if (is_file($target) && ! $force) {
            CLI::write('  app/Config/Tenantable.php already exists — skipped (pass --force to overwrite).', 'light_gray');
            return;
        }

        $stubPath = __DIR__ . '/../stubs/Tenantable.config.stub';
        if (! is_file($stubPath)) {
            CLI::error("  Stub missing: {$stubPath}");
            return;
        }

        $stub     = (string) file_get_contents($stubPath);
        $contents = InstallPatcher::renderConfig($stub, $baseDomain, $mode, $strategy, $early, $sessions);

        $targetDir = dirname($target);
        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            CLI::error("  Failed to create {$targetDir}");
            return;
        }

        if (file_put_contents($target, $contents) === false) {
            CLI::error("  Failed to write {$target}");
            return;
        }

        CLI::write(CLI::color('  app/Config/Tenantable.php written', 'green'));
    }

    private function patchFilters(string $strategy): void
    {
        CLI::write(CLI::color('• Wiring app/Config/Filters.php', 'yellow'));

        $path = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Filters.php';
        if (! is_file($path)) {
            CLI::write("  {$path} not found — skipped.", 'light_gray');
            return;
        }

        $source        = (string) file_get_contents($path);
        $identifyAlias = InstallPatcher::STRATEGY_FILTER[$strategy] ?? 'tenant_subdomain';
        $result        = InstallPatcher::patchFilters($source, $identifyAlias);

        if (! $result['located']) {
            $this->printFiltersSnippet($identifyAlias);
            CLI::write('  Could not locate $globals[before]/[after] — patch skipped.', 'red');
            return;
        }

        if (! $result['changed']) {
            CLI::write('  Tenant filters already wired — skipped.', 'light_gray');
            return;
        }

        if (file_put_contents($path, $result['source']) === false) {
            CLI::error("  Failed to write {$path}");
            return;
        }

        CLI::write(CLI::color('  added → ' . implode(', ', $result['added']), 'green'));
    }

    private function printFiltersSnippet(string $identifyAlias): void
    {
        CLI::write('');
        CLI::write('  Add this manually to app/Config/Filters.php:', 'yellow');
        CLI::write('');
        CLI::write("    public array \$globals = [", 'light_gray');
        CLI::write("        'before' => [", 'light_gray');
        CLI::write("            '{$identifyAlias}' => ['except' => ['health', '_health']],", 'light_gray');
        CLI::write("            'tenant_security' => ['except' => ['health', '_health']],", 'light_gray');
        CLI::write("        ],", 'light_gray');
        CLI::write("        'after' => [", 'light_gray');
        CLI::write("            'tenant_security' => ['except' => ['health', '_health']],", 'light_gray');
        CLI::write("        ],", 'light_gray');
        CLI::write("    ];", 'light_gray');
        CLI::write('');
    }

    private function patchEvents(): void
    {
        CLI::write(CLI::color('• Wiring app/Config/Events.php', 'yellow'));

        $path = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Events.php';
        if (! is_file($path)) {
            CLI::write("  {$path} not found — skipped.", 'light_gray');
            return;
        }

        $source = (string) file_get_contents($path);
        $result = InstallPatcher::patchEvents($source);

        if (! $result['changed']) {
            CLI::write('  Events already wired — skipped.', 'light_gray');
            return;
        }

        if (file_put_contents($path, $result['source']) === false) {
            CLI::error("  Failed to write {$path}");
            return;
        }

        CLI::write(CLI::color('  added → ' . implode(', ', $result['added']), 'green'));
    }

    private function scaffoldTenantMigrations(): void
    {
        CLI::write(CLI::color('• Scaffolding tenant migrations directory', 'yellow'));

        $dir = rtrim(APPPATH, '/\\')
            . DIRECTORY_SEPARATOR . 'Database'
            . DIRECTORY_SEPARATOR . 'Migrations'
            . DIRECTORY_SEPARATOR . 'Tenant'
            . DIRECTORY_SEPARATOR;

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            CLI::error("  Failed to create {$dir}");
            return;
        }

        CLI::write(CLI::color("  {$dir}", 'green'));

        if ($this->hasCliOption('no-sample')) {
            return;
        }

        $existing = glob($dir . '*.php') ?: [];
        if (! empty($existing)) {
            CLI::write('  Migrations already present — sample skipped.', 'light_gray');
            return;
        }

        $stubPath = __DIR__ . '/../stubs/CreateExampleTable.stub';
        if (! is_file($stubPath)) {
            CLI::write("  Stub missing: {$stubPath}", 'light_gray');
            return;
        }

        $stamp = date('Y-m-d-His');
        $file  = $dir . "{$stamp}_CreateExampleTable.php";
        if (file_put_contents($file, (string) file_get_contents($stubPath)) === false) {
            CLI::error("  Failed to write {$file}");
            return;
        }

        CLI::write(CLI::color("  sample migration → {$file}", 'green'));
    }

    private function runSetup(string $mode): void
    {
        CLI::write('');
        CLI::write(CLI::color('• Running tenants:setup', 'yellow'));
        CLI::write('');
        try {
            command("tenants:setup --mode={$mode}");
        } catch (\Throwable $e) {
            CLI::write('  tenants:setup failed: ' . $e->getMessage(), 'red');
            CLI::write("  Re-run manually after fixing the issue: php spark tenants:setup --mode={$mode}", 'light_gray');
        }
    }

    private function banner(): void
    {
        CLI::write('');
        CLI::write(CLI::color('  Tenantable Install  ', 'white', 'blue'));
        CLI::write('');
    }

    private function summary(string $mode): void
    {
        CLI::write('');
        CLI::write(CLI::color('  Install complete.', 'green'));
        CLI::write('');
        CLI::write('  Next:');
        CLI::write('    php spark tenants:create acme "Acme Corp"');
        CLI::write('    php spark tenants:make-model Student' . ($mode === 'prefix' ? ' --prefix --table=students' : ''));
        if ($mode !== 'row') {
            CLI::write('    edit app/Database/Migrations/Tenant/*_CreateExampleTable.php');
            CLI::write("    php spark tenants:setup --mode={$mode}" . ($mode === 'database' ? ' --create-db' : ''));
        }
        CLI::write('');
    }
}
