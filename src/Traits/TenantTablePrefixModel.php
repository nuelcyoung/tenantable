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

namespace nuelcyoung\tenantable\Traits;

    /**
     * Base model for the table-prefix isolation strategy. All behaviour lives
     * in the prefix trait, so extending this class or using the trait on a
     * plain Model both work (implement TenantPrefixAware when using the trait).
     * Reads fail safe and writes throw without a tenant context.
     *
     * Own file so the PSR-4 autoloader can resolve it.
     */
abstract class TenantTablePrefixModel extends \CodeIgniter\Model implements \nuelcyoung\tenantable\Contracts\TenantPrefixAware
{
    use TenantTablePrefixTrait;
}
