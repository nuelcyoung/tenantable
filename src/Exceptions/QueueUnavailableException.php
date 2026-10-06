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
 * Thrown when tenant-aware queueing is used without a queue behind it.
 *
 * The queue is a suggestion, not a dependency, so this is the first point at
 * which its absence can be reported, and it must be reported rather than
 * swallowed: a silently dropped job is a tenant's work that never happens.
 */
class QueueUnavailableException extends RuntimeException
{
    public static function forMissingService(): self
    {
        return new self(
            "Tenantable: no 'queue' service is registered. Tenant-aware queueing needs a queue "
            . 'implementation — install one with `composer require codeigniter4/queue` and publish '
            . 'its config, or pass your own handler to new TenantableQueue($handler).'
        );
    }

    public static function forMissingMethod(string $method, string $handlerClass): self
    {
        return new self(
            "Tenantable: the queue handler {$handlerClass} has no {$method}() method. "
            . 'TenantableQueue proxies whatever the handler provides; check the method name '
            . 'against your queue package.'
        );
    }
}
