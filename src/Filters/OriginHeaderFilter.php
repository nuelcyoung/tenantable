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
 * Identifies the tenant from the request's Origin header, for browser API
 * clients on a shared API domain, avoiding the CORS preflight a custom
 * header would trigger. Custom domains only; subdomains are not resolved.
 * Use via alias 'tenant_origin' or 'identify_tenant:strategy=origin'.
 */
class OriginHeaderFilter extends IdentifyTenant
{
    protected string $strategy = 'origin';
}
