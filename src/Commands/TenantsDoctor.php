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
use nuelcyoung\tenantable\Support\Diagnostics;
use nuelcyoung\tenantable\Support\SharedInfrastructure;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;

/**
 * `tenants:doctor` checks the configuration a multi-tenant deployment needs
 * and exits non-zero on anything critical, so pipelines can gate on it.
 */
class TenantsDoctor extends BaseCommand
{
    use NormalizesCliOptions;

    protected $group       = 'Tenantable';
    protected $name        = 'tenants:doctor';
    protected $description = 'Check this deployment for tenancy misconfiguration. Exits non-zero on critical findings.';
    protected $usage       = 'tenants:doctor [--json] [--strict]';
    protected $options     = [
        '--json'   => 'Emit the findings as JSON instead of a report.',
        '--strict' => 'Also exit non-zero on warnings.',
    ];

    public function run(array $params): int
    {
        $findings = Diagnostics::run();

        $criticals = count(SharedInfrastructure::critical($findings));
        $warnings  = count(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['severity'] === SharedInfrastructure::WARNING
        ));

        if ($this->hasCliOption('json')) {
            CLI::write((string) json_encode([
                'critical' => $criticals,
                'warning'  => $warnings,
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->report($findings, $criticals, $warnings);
        }

        if ($criticals > 0 || ($warnings > 0 && $this->hasCliOption('strict'))) {
            return defined('EXIT_ERROR') ? EXIT_ERROR : 1;
        }

        return 0;
    }

    /**
     * @param list<array{id: string, severity: string, title: string, detail: string, remedy: string}> $findings
     */
    private function report(array $findings, int $criticals, int $warnings): void
    {
        CLI::write('');
        CLI::write(CLI::color('  Tenantable Doctor  ', 'white', 'blue'));
        CLI::write('');

        foreach ($findings as $finding) {
            [$marker, $colour] = match ($finding['severity']) {
                SharedInfrastructure::CRITICAL => ['✘ critical', 'red'],
                SharedInfrastructure::WARNING  => ['! warning ', 'yellow'],
                default                        => ['✔ ok      ', 'green'],
            };

            CLI::write(CLI::color("  {$marker}  " . $finding['title'], $colour));

            if ($finding['severity'] === Diagnostics::OK) {
                continue;
            }

            foreach (explode("\n", wordwrap($finding['detail'], 66, "\n", false)) as $line) {
                CLI::write('               ' . $line, 'light_gray');
            }

            if ($finding['remedy'] !== '') {
                CLI::write('               Fix: ' . $finding['remedy'], 'light_gray');
            }

            CLI::write('');
        }

        CLI::write('');

        if ($criticals === 0 && $warnings === 0) {
            CLI::write(CLI::color('  No problems found.', 'green'));
            CLI::write('');

            return;
        }

        CLI::write(
            "  {$criticals} critical, {$warnings} warning(s).",
            $criticals > 0 ? 'red' : 'yellow'
        );
        CLI::write('');
    }
}
