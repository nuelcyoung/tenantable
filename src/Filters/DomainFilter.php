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
 * DomainFilter
 *
 * Identifies the tenant from the full hostname (custom domain mapping).
 *
 * @deprecated Use IdentifyTenant with strategy='domain' instead.
 */
class DomainFilter extends IdentifyTenant
{
    protected string $strategy = 'domain';
}
