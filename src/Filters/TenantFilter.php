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

namespace nuelcyoung\tenantable\Filters;

/**
 * TenantFilter  (default identification filter — subdomain-based)
 *
 * Kept as the default 'tenant' filter alias for backwards compatibility.
 *
 * @deprecated Use IdentifyTenant with strategy='subdomain' instead.
 */
class TenantFilter extends IdentifyTenant
{
    protected string $strategy = 'subdomain';
}
