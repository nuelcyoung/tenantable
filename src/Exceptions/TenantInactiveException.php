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

class TenantInactiveException extends RuntimeException
{
    public static function forSubdomain(string $subdomain): self
    {
        return new self("Tenant '{$subdomain}' is currently inactive");
    }

    public static function forId(int $id): self
    {
        return new self("Tenant with ID {$id} is currently inactive");
    }
}
