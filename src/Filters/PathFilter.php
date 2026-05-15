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
 * PathFilter
 *
 * Identifies the tenant from a URI path segment.
 *
 * @deprecated Use IdentifyTenant with strategy='path' instead.
 */
class PathFilter extends IdentifyTenant
{
    protected string $strategy = 'path';
}
