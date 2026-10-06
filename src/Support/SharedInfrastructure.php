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

namespace nuelcyoung\tenantable\Support;

use nuelcyoung\tenantable\Bootstrap\Systems\SessionSystem;
use nuelcyoung\tenantable\Exceptions\UnsafeInfrastructureException;

/**
 * Detects tenant state that only one node can reach: file sessions or a file
 * cache break behind multiple web servers. Shared by the install/setup
 * commands and the boot-time guard so all report the same findings.
 *
 * @phpstan-type Finding array{id: string, severity: string, title: string, detail: string, remedy: string}
 */
final class SharedInfrastructure
{
    public const CRITICAL = 'critical';
    public const WARNING  = 'warning';

    /**
     * Findings for the current runtime, criticals first.
     *
     * @return list<Finding>
     */
    public static function inspect(?object $session = null, ?object $cache = null, ?object $tenantable = null): array
    {
        $session ??= config('Session');
        $cache ??= config('Cache');
        $tenantable ??= TenantableConfig::get();

        $findings = array_merge(
            self::inspectSession($session),
            self::inspectCache($cache, $tenantable)
        );

        usort(
            $findings,
            static fn (array $a, array $b): int => (int) ($b['severity'] === self::CRITICAL)
                <=> (int) ($a['severity'] === self::CRITICAL)
        );

        return $findings;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public static function critical(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['severity'] === self::CRITICAL
        ));
    }

    /**
     * Throw when the deployment declares itself multi-node-safe but is not.
     * Opt-in via $requireSharedInfrastructure; production only.
     *
     * @param bool|null $production Null detects it from ENVIRONMENT.
     */
    public static function assertSafe(?object $tenantable = null, ?bool $production = null): void
    {
        $tenantable ??= TenantableConfig::get();

        if (! ($tenantable->requireSharedInfrastructure ?? false)) {
            return;
        }

        if (! ($production ?? self::isProduction())) {
            return;
        }

        $critical = self::critical(self::inspect(null, null, $tenantable));

        if ($critical === []) {
            return;
        }

        throw UnsafeInfrastructureException::forFindings($critical);
    }

    public static function isProduction(): bool
    {
        return defined('ENVIRONMENT') && ENVIRONMENT === 'production';
    }

    /**
     * @return list<Finding>
     */
    private static function inspectSession(?object $session): array
    {
        if ($session === null || ! SessionSystem::usesFileHandler($session)) {
            return [];
        }

        return [[
            'id'       => 'session-file-handler',
            'severity' => self::CRITICAL,
            'title'    => 'Sessions are stored on local disk',
            'detail'   => 'Config\Session uses the file handler. Each node keeps its own session '
                . 'directory, so a request routed to a second node finds no session and logs the '
                . 'user out. Tenantable gives every tenant its own save path, which multiplies the '
                . 'directories without making any of them reachable from another node.',
            'remedy'   => 'Switch $driver to DatabaseHandler, RedisHandler or MemcachedHandler. In '
                . 'database isolation mode set $shipTenantSessionsTable = true so each tenant '
                . 'database gets the sessions table.',
        ]];
    }

    /**
     * @return list<Finding>
     */
    private static function inspectCache(?object $cache, object $tenantable): array
    {
        $handler = is_string($cache->handler ?? null) ? strtolower($cache->handler) : '';

        if ($handler === 'file') {
            $ttl = (int) ($tenantable->resolverCacheTtl ?? 300);

            return [[
                'id'       => 'cache-file-handler',
                'severity' => self::CRITICAL,
                'title'    => 'The cache is stored on local disk',
                'detail'   => 'Config\Cache uses the file handler. Tenantable caches tenant rows and '
                    . 'host-to-tenant resolutions; on local disk every node caches independently, so '
                    . 'deactivating a tenant or moving its domain on one node leaves the others '
                    . "serving the previous answer for up to {$ttl}s.",
                'remedy'   => 'Use the redis, predis or memcached handler — one cache every node shares.',
            ]];
        }

        if ($handler === 'dummy') {
            return [[
                'id'       => 'cache-dummy-handler',
                'severity' => self::WARNING,
                'title'    => 'Caching is disabled',
                'detail'   => 'Config\Cache uses the dummy handler, so every request re-queries the '
                    . 'central database to resolve its tenant. Correct, but it puts the read traffic '
                    . 'of the whole deployment on one table.',
                'remedy'   => 'Use the redis, predis or memcached handler in production.',
            ]];
        }

        return [];
    }
}
