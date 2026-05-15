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

/**
 * TenantDomainChanged
 *
 * Fired when a tenant's domain mapping changes (insert, update, delete
 * in the tenant_domains table).
 */
class TenantDomainChanged
{
    public function __construct(
        public readonly int    $tenantId,
        public readonly string $domain,
        public readonly string $action, // 'created', 'updated', 'deleted'
        public readonly array  $data = []
    ) {}
}
