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
use nuelcyoung\tenantable\Bootstrap\TenantBootstrap;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Config\Tenantable as TenantableConfig;
use nuelcyoung\tenantable\Services\TenantDatabaseManager;
use nuelcyoung\tenantable\Support\FanOutReport;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;

class TenantsRun extends BaseCommand
{
    use NormalizesCliOptions;

    protected $group       = 'Tenantable';
    protected $name        = 'tenants:run';
    protected $description = 'Run a Spark command for each (or specific) tenant(s).';
    protected $usage       = 'tenants:run <command> [args] [--tenants=1,2,3] [--parallel=N] [--in-process] [--resume-from=ID]';
    protected $arguments   = [
        'command' => 'The Spark command to run (e.g. migrate, db:seed).',
    ];
    protected $options = [
        '--tenants'     => 'Comma-separated list of tenant IDs (default: all active).',
        '--parallel'    => 'Run up to N tenants concurrently as separate processes (default 1).',
        '--in-process'  => 'Run an allow-listed command inside this process instead of spawning one per tenant.',
        '--resume-from' => 'Skip tenants with a lower ID. Use the value the last failure report suggests.',
    ];

    /**
     * Commands that may run inside this process under --in-process: only
     * commands that touch tenant storage and nothing global.
     */
    public const IN_PROCESS_ALLOWED = [
        'migrate',
        'migrate:status',
        'migrate:rollback',
        'migrate:refresh',
        'db:seed',
    ];

    /** Upper bound for --parallel. Past this, the database is the bottleneck. */
    public const MAX_PARALLEL = 32;

    /**
     * Options that belong to tenants:run itself. They must not be forwarded
     * to the target command as positional values.
     */
    private const OWN_OPTIONS = ['tenants', 'parallel', 'in-process', 'resume-from'];

    public function run(array $params): void
    {
        if (empty($params)) {
            CLI::error('Usage: php spark tenants:run <command> [args]');
            return;
        }

        $command = (string) array_shift($params);

        if (! $this->isValidCommandName($command)) {
            CLI::error(
                "Refusing to run '{$command}': a Spark command name may only contain " .
                'letters, digits and the characters : _ - . (no shell metacharacters).'
            );
            return;
        }

        // Normalized so both `--tenants 1,2` and `--tenants=1,2` work; CI4's
        // parser never splits the equals style itself.
        $options = $this->cliOptions();

        $parallel = $this->resolveParallel($options);

        if ($parallel === null) {
            return;
        }

        $inProcess = array_key_exists('in-process', $options);

        if ($inProcess && ! in_array($command, self::IN_PROCESS_ALLOWED, true)) {
            CLI::error(
                "Refusing to run '{$command}' in-process. Allowed: "
                . implode(', ', self::IN_PROCESS_ALLOWED) . '. Drop --in-process to run it per tenant.'
            );
            return;
        }

        if ($inProcess && $parallel > 1) {
            CLI::write('  --in-process runs in this single process; ignoring --parallel.', 'yellow');
            $parallel = 1;
        }

        $extraArgs = $this->buildExtraArgs($params);

        $model   = new TenantModel();
        $tenants = $this->resolveTenants($model, $options);

        if ($tenants === []) {
            CLI::write('No tenants found to run command for.', 'yellow');
            return;
        }

        $resumeFrom = $this->resolveResumeFrom($options);
        $skipped    = 0;

        if ($resumeFrom !== null) {
            $before  = count($tenants);
            $tenants = array_values(array_filter(
                $tenants,
                static fn (array $tenant): bool => (int) $tenant['id'] >= $resumeFrom
            ));
            $skipped = $before - count($tenants);
        }

        if ($tenants === []) {
            CLI::write("No tenants at or after ID {$resumeFrom}.", 'yellow');
            return;
        }

        $config = config(TenantableConfig::class);
        $mode   = $config->resolvedIsolationMode();

        // "migrate" uses the in-process migrator in database/prefix mode;
        // a subprocess knows nothing about tenant migration namespaces.
        $isForwardMigration = in_array($mode, ['database', 'prefix'], true) && $command === 'migrate';

        CLI::write('');
        CLI::write(CLI::color("  Running: php spark {$command} {$extraArgs}", 'cyan'));
        CLI::write(CLI::color("  Mode:    {$mode}", 'cyan'));
        CLI::write(CLI::color('  Tenants: ' . count($tenants) . ($skipped > 0 ? " ({$skipped} skipped)" : ''), 'cyan'));

        if ($parallel > 1) {
            CLI::write(CLI::color("  Workers: {$parallel}", 'cyan'));
        }

        if ($inProcess) {
            CLI::write(CLI::color('  Mode:    in-process (no subprocess per tenant)', 'cyan'));
        }

        CLI::write('');

        $report = new FanOutReport($command, $extraArgs, $mode, $parallel);

        if ($parallel > 1) {
            $this->runInParallel($tenants, $command, $extraArgs, $isForwardMigration, $parallel, $report);
        } else {
            $this->runSequentially($tenants, $command, $extraArgs, $isForwardMigration, $inProcess, $config, $mode, $report);
        }

        $this->summarize($report);
    }

    /**
     * @param list<array<string, mixed>> $tenants
     */
    private function runSequentially(
        array $tenants,
        string $command,
        string $extraArgs,
        bool $isForwardMigration,
        bool $inProcess,
        TenantableConfig $config,
        string $mode,
        FanOutReport $report
    ): void {
        foreach ($tenants as $tenant) {
            $id = (int) $tenant['id'];

            CLI::write(CLI::color("  ► [{$id}] " . $this->label($tenant), 'yellow'));

            if ($isForwardMigration) {
                $exitCode = $this->runMigrations($tenant, $config, $mode);
            } elseif ($inProcess) {
                $exitCode = $this->runInProcess($id, $command, $extraArgs);
            } else {
                $exitCode = $this->runAsSubprocess($id, $command, $extraArgs);
            }

            $this->recordResult($report, $tenant, $exitCode, '');

            CLI::write('');
        }
    }

    /**
     * Run up to $parallel tenants at a time, each in its own process. Output
     * is buffered per tenant so failures stay attributable and readable.
     *
     * @param list<array<string, mixed>> $tenants
     */
    private function runInParallel(
        array $tenants,
        string $command,
        string $extraArgs,
        bool $isForwardMigration,
        int $parallel,
        FanOutReport $report
    ): void {
        $queue   = $tenants;
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $parallel) {
                $tenant = array_shift($queue);
                $worker = $this->startWorker((int) $tenant['id'], $command, $extraArgs, $isForwardMigration);

                if ($worker === null) {
                    $this->recordResult($report, $tenant, 1, 'Could not start a worker process.');
                    CLI::write(CLI::color("  ✘ [{$tenant['id']}] could not start a worker process.", 'red'));
                    continue;
                }

                $worker['tenant'] = $tenant;
                $running[]        = $worker;
            }

            if ($running === []) {
                continue;
            }

            $running = $this->reapFinishedWorkers($running, $report);

            if ($running !== []) {
                // Every worker is still busy: yield rather than spin the CPU
                // in a tight polling loop.
                usleep(20000);
            }
        }
    }

    /**
     * @param list<array{process: resource, pipes: array<int, resource>, output: string, tenant: array<string, mixed>}> $running
     *
     * @return list<array{process: resource, pipes: array<int, resource>, output: string, tenant: array<string, mixed>}>
     */
    private function reapFinishedWorkers(array $running, FanOutReport $report): array
    {
        $stillRunning = [];

        foreach ($running as $worker) {
            $worker['output'] .= $this->drain($worker['pipes']);
            $status = proc_get_status($worker['process']);

            if ($status['running']) {
                $stillRunning[] = $worker;
                continue;
            }

            // One last read: output written just before exit is still buffered.
            $worker['output'] .= $this->drain($worker['pipes']);

            foreach ($worker['pipes'] as $pipe) {
                fclose($pipe);
            }

            proc_close($worker['process']);

            $tenant = $worker['tenant'];
            CLI::write(CLI::color("  ► [{$tenant['id']}] " . $this->label($tenant), 'yellow'));

            $output = trim($worker['output']);

            if ($output !== '') {
                foreach (explode("\n", $output) as $line) {
                    CLI::write('    ' . rtrim($line), 'light_gray');
                }
            }

            $this->recordResult($report, $tenant, (int) $status['exitcode'], $output);
            CLI::write('');
        }

        return $stillRunning;
    }

    /**
     * Spawn one tenant's process. A forward migration re-enters this command
     * for a single tenant so the package's migrator does the work.
     *
     * @return array{process: resource, pipes: array<int, resource>, output: string}|null
     */
    private function startWorker(int $tenantId, string $command, string $extraArgs, bool $isForwardMigration): ?array
    {
        $php   = escapeshellarg(PHP_BINARY);
        $spark = escapeshellarg(ROOTPATH . 'spark');

        if ($isForwardMigration) {
            // Space-style options: CI4's parser does not split
            // `--tenants={id}`, so the worker would ignore it.
            $cmd = trim("{$php} {$spark} tenants:run {$command} {$extraArgs} --tenants {$tenantId} --parallel 1");
        } else {
            $cmd = trim("{$php} {$spark} {$command} {$extraArgs}");
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['TENANTABLE_TENANT_ID'] = (string) $tenantId;

        $pipes   = [];
        $process = @proc_open($cmd, $descriptors, $pipes, ROOTPATH, $env);

        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        unset($pipes[0]);

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return ['process' => $process, 'pipes' => $pipes, 'output' => ''];
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function drain(array $pipes): string
    {
        $output = '';

        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);

            if (is_string($chunk)) {
                $output .= $chunk;
            }
        }

        return $output;
    }

    /**
     * Run an allow-listed command in this process, scoped to one tenant.
     */
    protected function runInProcess(int $tenantId, string $command, string $extraArgs): int
    {
        try {
            TenantBootstrap::getInstance()->initialize()->bootForTenant($tenantId);

            command(trim("{$command} {$extraArgs}"));

            return 0;
        } catch (\Throwable $e) {
            CLI::write(CLI::color("    Error: {$e->getMessage()}", 'red'));

            return 1;
        } finally {
            // Never leave one tenant's connection, prefix or cache prefix in
            // place for the next iteration.
            TenantBootstrap::getInstance()->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function recordResult(FanOutReport $report, array $tenant, int $exitCode, string $output): void
    {
        $report->record((int) $tenant['id'], $this->label($tenant), $exitCode, $output);

        if ($exitCode === 0) {
            CLI::write(CLI::color('    ✔ Done', 'green'));

            return;
        }

        CLI::write(CLI::color("    ✘ Failed (exit {$exitCode})", 'red'));
    }

    private function summarize(FanOutReport $report): void
    {
        $failed = $report->failedCount();

        CLI::write("  Ran on {$report->succeededCount()} tenant(s) successfully." . ($failed > 0 ? " {$failed} failed." : ''));

        $path = $report->write();

        if ($path !== null) {
            CLI::write('  Report: ' . $path, 'light_gray');
        }

        if ($failed > 0) {
            $resume = $report->resumeFrom();

            CLI::write(CLI::color('  Failed tenants: ' . implode(', ', $report->failedIds()), 'red'));

            if ($resume !== null) {
                CLI::write("  Re-run from the first failure: php spark tenants:run ... --resume-from={$resume}", 'light_gray');
            }
        }

        CLI::write('');
    }

    /** @param array<string, mixed> $tenant */
    private function label(array $tenant): string
    {
        $label = $tenant['name'] ?? $tenant['subdomain'] ?? null;

        return is_string($label) && $label !== '' ? $label : 'tenant #' . (int) $tenant['id'];
    }

    /** Null means the option was invalid and an error has been reported. */
    private function resolveParallel(array $options): ?int
    {
        $option = $options['parallel'] ?? null;

        if ($option === null || $option === true || $option === '') {
            return 1;
        }

        if (! is_numeric($option) || (int) $option != $option) {
            CLI::error('--parallel must be a whole number.');

            return null;
        }

        $parallel = (int) $option;

        if ($parallel < 1 || $parallel > self::MAX_PARALLEL) {
            CLI::error('--parallel must be between 1 and ' . self::MAX_PARALLEL . '.');

            return null;
        }

        return $parallel;
    }

    private function resolveResumeFrom(array $options): ?int
    {
        $option = $options['resume-from'] ?? null;

        if ($option === null || $option === true || $option === '') {
            return null;
        }

        return max(0, (int) $option);
    }

    /**
     * Rebuild the arguments meant for the target command as `--name value`
     * (space style, which CI4's parser understands), dropping own flags.
     */
    private function buildExtraArgs(array $params): string
    {
        $tokens = [];

        foreach ($params as $key => $value) {
            if (is_int($key)) {
                $tokens[] = escapeshellarg((string) $value);

                continue;
            }

            [$name, $inlineValue] = self::splitOption((string) $key);

            if (in_array($name, self::OWN_OPTIONS, true)) {
                continue;
            }

            $optionValue = $inlineValue ?? $value;

            if ($optionValue === null || $optionValue === true) {
                $tokens[] = escapeshellarg('--' . $name);

                continue;
            }

            $tokens[] = escapeshellarg('--' . $name) . ' ' . escapeshellarg((string) $optionValue);
        }

        return implode(' ', $tokens);
    }

    protected function runMigrations(array $tenant, TenantableConfig $config, string $mode): int
    {
        try {
            $manager = new TenantDatabaseManager(
                $config->isDatabaseIsolation(),
                $config->defaultDatabaseGroup,
            );

            $namespaces = $config->tenantMigrationNamespaces();

            if (empty($namespaces)) {
                CLI::write('    No tenant migration namespaces configured.', 'yellow');
                return 0;
            }

            foreach ($namespaces as $ns) {
                if ($mode === 'prefix') {
                    $applied = $manager->migrateTenantTables($tenant, $ns);

                    if ($applied === null) {
                        CLI::write(CLI::color("    Failed: {$ns}", 'red'));
                        return 1;
                    }

                    CLI::write("    {$ns}: " . ($applied > 0 ? "{$applied} applied" : 'up to date'), 'light_gray');
                    continue;
                }

                CLI::write("    Migrating: {$ns}", 'light_gray');
                if (! $manager->migrateTenant($tenant, $ns)) {
                    return 1;
                }
            }

            return 0;
        } catch (\Throwable $e) {
            CLI::write(CLI::color("    Error: {$e->getMessage()}", 'red'));
            return 1;
        }
    }

    protected function runAsSubprocess(int $tenantId, string $command, string $extraArgs): int
    {
        $exitCode  = 0;
        $sparkPath = escapeshellarg(ROOTPATH . 'spark');
        $php       = escapeshellarg(PHP_BINARY);

        // The command is validated and extra args are escaped before shell execution.
        $cmd = trim("{$php} {$sparkPath} {$command} {$extraArgs}") . ' 2>&1';

        putenv("TENANTABLE_TENANT_ID={$tenantId}");
        passthru($cmd, $exitCode);
        putenv('TENANTABLE_TENANT_ID=');

        return $exitCode;
    }

    /** Validate a command name to prevent shell injection. */
    protected function isValidCommandName(string $command): bool
    {
        return $command !== '' && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9:_.\-]*$/', $command) === 1;
    }

    protected function resolveTenants(TenantModel $model, array $options): array
    {
        $idsOption = $options['tenants'] ?? null;

        if (!empty($idsOption)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $idsOption)));
            if (empty($ids)) {
                CLI::error('--tenants must be a comma-separated list of integer IDs.');
                return [];
            }

            return $model->whereIn('id', $ids)->orderBy('id', 'asc')->findAll();
        }

        // Ordered by ID so --resume-from means the same thing on every run.
        return $model->where('is_active', 1)->orderBy('id', 'asc')->findAll();
    }
}
