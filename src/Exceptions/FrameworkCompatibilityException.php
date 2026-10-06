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

/**
 * Thrown when a CodeIgniter internals structure this package depends on is
 * not where the framework version guarantees it to be. Failing loudly beats
 * silently leaving stale shared connections behind: a partial tenant switch
 * risks a cross-tenant leak.
 */
class FrameworkCompatibilityException extends RuntimeException
{
    public static function forMissingClass(string $class, string $purpose): self
    {
        return new self(
            "Tenantable: cannot {$purpose} because class '{$class}' does not exist on this " .
            'CodeIgniter build. Check the installed framework version against the versions ' .
            'supported by this package.'
        );
    }

    public static function forMissingProperty(string $class, string $property, string $purpose): self
    {
        return new self(
            "Tenantable: cannot {$purpose} because '{$class}::\${$property}' is not available on " .
            'this CodeIgniter build. The framework moved its shared-instance store; upgrade ' .
            'the tenantable package to a version that supports this framework release.'
        );
    }
}
