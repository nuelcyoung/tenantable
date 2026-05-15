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

namespace nuelcyoung\tenantable\Events;

class TenantUpdated
{
    public function __construct(
        public readonly int   $tenantId,
        public readonly array $tenant,
        public readonly array $changedData,
        public readonly array $before = []
    ) {}
}
