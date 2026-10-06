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

use nuelcyoung\tenantable\Exceptions\QueueUnavailableException;

/**
 * Carries the active tenant across the queue boundary: push() stamps the
 * tenant into the payload, and TenantableJob reads it back on the worker.
 * Any handler exposing push()/later() works, so no hard dependency.
 */
class TenantableQueue
{
    /**
     * Payload key holding the tenant a job was pushed under. Namespaced so it
     * never collides with a job's own 'tenant_id' field.
     */
    public const TENANT_KEY = '_tenantable_tenant_id';

    private ?object $handler;

    public function __construct(?object $handler = null)
    {
        $this->handler = $handler;
    }

    /**
     * Push a job that runs under the active tenant. With no tenant active,
     * this pushes a central job, same as the queue service directly.
     *
     * @param array<string, mixed> $data
     */
    public function push(string $queue, string $job, array $data = []): bool
    {
        return (bool) $this->handler()->push($queue, $job, self::stamp($data, tenant_id()));
    }

    /**
     * Push a job with no tenant context, even when one is active. Any tenant
     * stamp in $data is dropped so "central" always means central.
     *
     * @param array<string, mixed> $data
     */
    public function pushCentral(string $queue, string $job, array $data = []): bool
    {
        return (bool) $this->handler()->push($queue, $job, self::strip($data));
    }

    /**
     * Push a job for a named tenant regardless of the active one; used by
     * central schedulers fanning out work. Null pushes a central job.
     *
     * @param array<string, mixed> $data
     */
    public function pushForTenant(?int $tenantId, string $queue, string $job, array $data = []): bool
    {
        return (bool) $this->handler()->push($queue, $job, self::stamp($data, $tenantId));
    }

    /**
     * Proxy anything else the queue handler offers (later(), size(), …) with
     * arguments untouched. Delayed/bulk enqueues must stamp themselves.
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $handler = $this->handler();

        if (! method_exists($handler, $method)) {
            throw QueueUnavailableException::forMissingMethod($method, $handler::class);
        }

        return $handler->{$method}(...$arguments);
    }

    /**
     * Add the tenant stamp to a payload. The active context always wins over
     * a caller-supplied stamp, which could otherwise name another tenant.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function stamp(array $data, ?int $tenantId): array
    {
        $supplied = $data[self::TENANT_KEY] ?? null;

        if ($supplied !== null && (int) $supplied !== $tenantId) {
            log_message(
                'warning',
                'Tenantable: queue payload carried ' . self::TENANT_KEY . '=' . (int) $supplied
                . ' but was pushed under tenant ' . ($tenantId === null ? 'none' : (string) $tenantId)
                . '. The pushing context wins; the supplied value was discarded.'
            );
        }

        if ($tenantId === null) {
            unset($data[self::TENANT_KEY]);

            return $data;
        }

        $data[self::TENANT_KEY] = $tenantId;

        return $data;
    }

    /**
     * The tenant a payload was pushed under, or null for a central job.
     *
     * @param array<string, mixed> $data
     */
    public static function tenantId(array $data): ?int
    {
        $stamp = $data[self::TENANT_KEY] ?? null;

        // Only an integer or digit string counts; payloads come back as
        // decoded JSON, so anything else here is corruption or tampering.
        if (is_int($stamp)) {
            $tenantId = $stamp;
        } elseif (is_string($stamp) && ctype_digit($stamp)) {
            $tenantId = (int) $stamp;
        } else {
            return null;
        }

        return $tenantId > 0 ? $tenantId : null;
    }

    /**
     * The payload as the job's own handler should see it, package key removed.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function strip(array $data): array
    {
        unset($data[self::TENANT_KEY]);

        return $data;
    }

    /**
     * The underlying queue handler, resolved lazily so constructing this
     * object never requires a queue to be installed.
     */
    public function handler(): object
    {
        if ($this->handler !== null) {
            return $this->handler;
        }

        $handler = function_exists('service') ? service('queue') : null;

        if (! is_object($handler)) {
            throw QueueUnavailableException::forMissingService();
        }

        return $this->handler = $handler;
    }
}
