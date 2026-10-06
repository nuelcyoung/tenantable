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

namespace nuelcyoung\tenantable\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when `Tenantable::$requireSharedInfrastructure` is on and
 * production is still relying on node-local session or cache storage.
 *
 * Refusing to boot is the point: the alternative is a deployment that appears
 * healthy and randomly drops sessions or serves a deactivated tenant,
 * depending on which node the load balancer picked.
 */
class UnsafeInfrastructureException extends RuntimeException
{
    /**
     * @param list<array{id: string, severity: string, title: string, detail: string, remedy: string}> $findings
     */
    public static function forFindings(array $findings): self
    {
        $lines = ['Tenantable: $requireSharedInfrastructure is enabled but this production environment is not multi-node safe.'];

        foreach ($findings as $finding) {
            $lines[] = '';
            $lines[] = '- ' . $finding['title'] . ' [' . $finding['id'] . ']';
            $lines[] = '  ' . $finding['detail'];
            $lines[] = '  Fix: ' . $finding['remedy'];
        }

        $lines[] = '';
        $lines[] = 'Set $requireSharedInfrastructure = false to boot anyway (single-node deployments).';

        return new self(implode("\n", $lines));
    }
}
