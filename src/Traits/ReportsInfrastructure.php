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

namespace nuelcyoung\tenantable\Traits;

use CodeIgniter\CLI\CLI;
use nuelcyoung\tenantable\Support\SharedInfrastructure;

/**
 * Prints SharedInfrastructure findings from a spark command. Shared by
 * tenants:install and tenants:setup so an operator sees the same wording
 * wherever the problem surfaces.
 */
trait ReportsInfrastructure
{
    /**
     * Write the multi-node readiness report.
     *
     * @return int The number of critical findings.
     */
    protected function reportInfrastructure(): int
    {
        $findings = SharedInfrastructure::inspect();

        CLI::write('');
        CLI::write(CLI::color('• Multi-node readiness', 'yellow'));

        if ($findings === []) {
            CLI::write(CLI::color('  Sessions and cache are on shared storage.', 'green'));

            return 0;
        }

        foreach ($findings as $finding) {
            $critical = $finding['severity'] === SharedInfrastructure::CRITICAL;
            $label    = $critical ? '  [critical] ' : '  [warning]  ';

            CLI::write(CLI::color($label . $finding['title'], $critical ? 'red' : 'yellow'));

            foreach (self::wrapDetail($finding['detail']) as $line) {
                CLI::write('              ' . $line, 'light_gray');
            }

            CLI::write('              Fix: ' . $finding['remedy'], 'light_gray');
            CLI::write('');
        }

        $criticalCount = count(SharedInfrastructure::critical($findings));

        if ($criticalCount > 0) {
            CLI::write(
                '  Single-node deployments can ignore this. Behind a load balancer it is a bug.',
                'light_gray'
            );
            CLI::write(
                '  Set $requireSharedInfrastructure = true to make production refuse to boot instead.',
                'light_gray'
            );
        }

        return $criticalCount;
    }

    /**
     * @return list<string>
     */
    private static function wrapDetail(string $detail): array
    {
        return explode("\n", wordwrap($detail, 66, "\n", false));
    }
}
