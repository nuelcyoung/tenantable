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
 * DomainOrSubdomainFilter
 *
 * Tries full-domain identification first; falls back to subdomain.
 *
 * @deprecated Use IdentifyTenant with strategy='domain_or_subdomain' instead.
 */
class DomainOrSubdomainFilter extends IdentifyTenant
{
    protected string $strategy = 'domain_or_subdomain';
}
