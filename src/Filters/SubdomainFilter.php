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
 * SubdomainFilter
 *
 * Identifies the tenant from the subdomain portion of the request host.
 *
 *   school.myapp.com  →  subdomain = 'school'
 *
 * @deprecated Use IdentifyTenant with strategy='subdomain' instead.
 */
class SubdomainFilter extends IdentifyTenant
{
    protected string $strategy = 'subdomain';
}
