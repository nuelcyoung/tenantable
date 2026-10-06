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

namespace nuelcyoung\tenantable\Support;

use CodeIgniter\CodeIgniter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Config as DatabaseConfig;
use nuelcyoung\tenantable\Exceptions\FrameworkCompatibilityException;

/**
 * Single choke point for framework-internal state. CI4 has no public eviction
 * for shared DB connections, so the Reflection fallback lives here and fails
 * loudly with FrameworkCompatibilityException instead of leaking a tenant.
 */
final class FrameworkState
{
    /** Oldest CI4 line whose internals this class is tested against. */
    public const MIN_CI_VERSION = '4.4.0';

    /** Newest CI4 line whose internals this class is tested against. */
    public const MAX_CI_VERSION_TESTED = '4.7.99';

    public static function ciVersion(): string
    {
        return defined(CodeIgniter::class . '::CI_VERSION')
            ? CodeIgniter::CI_VERSION
            : '0.0.0';
    }

    /**
     * True inside the framework version window the internals above are tested
     * against. Outside it the package still works (diagnostics signal only).
     */
    public static function isTestedCiVersion(): bool
    {
        $version = self::ciVersion();

        return version_compare($version, self::MIN_CI_VERSION, '>=')
            && version_compare($version, self::MAX_CI_VERSION_TESTED, '<=');
    }

    /**
     * Prove that every internal structure this package relies on is where
     * this framework build keeps it; throws on the first mismatch.
     */
    public static function assertInternalsSupported(): void
    {
        self::readStaticStore(
            DatabaseConfig::class,
            'instances',
            'evict shared database connections'
        );

        if (! class_exists('Config\\Services')) {
            throw FrameworkCompatibilityException::forMissingClass(
                'Config\\Services',
                'reset shared services'
            );
        }

        if (! method_exists('Config\\Services', 'resetSingle')) {
            self::readStaticStore('Config\\Services', 'instances', 'reset shared services');
        }
    }

    /**
     * Drop the shared connection instance for a group so the next
     * Database::connect($group) rebuilds it from the current config.
     */
    public static function evictSharedDbConnection(string $group): void
    {
        $connections = DatabaseConfig::getConnections();

        if (! array_key_exists($group, $connections)) {
            return;
        }

        $existing = $connections[$group];

        if ($existing instanceof BaseConnection) {
            $existing->close();
        }

        $instances = self::readStaticStore(
            DatabaseConfig::class,
            'instances',
            'evict shared database connections'
        );

        unset($instances[$group]);

        self::writeStaticStore(
            DatabaseConfig::class,
            'instances',
            $instances,
            'evict shared database connections'
        );
    }

    /**
     * Drop a shared service instance (e.g. 'cache') so the next
     * Services::{$name}() call rebuilds it from the current config.
     */
    public static function resetSharedService(string $name): void
    {
        $servicesClass = 'Config\\Services';

        if (! class_exists($servicesClass)) {
            throw FrameworkCompatibilityException::forMissingClass(
                $servicesClass,
                "reset the shared '{$name}' service"
            );
        }

        if (method_exists($servicesClass, 'resetSingle')) {
            $servicesClass::resetSingle($name);

            return;
        }

        $instances = self::readStaticStore(
            $servicesClass,
            'instances',
            "reset the shared '{$name}' service"
        );

        unset($instances[strtolower($name)]);

        self::writeStaticStore(
            $servicesClass,
            'instances',
            $instances,
            "reset the shared '{$name}' service"
        );
    }

    /**
     * Read a static instance store. Loud when the framework moved it.
     *
     * @return array<string, mixed>
     */
    private static function readStaticStore(string $class, string $property, string $purpose): array
    {
        $store = self::reflectionProperty($class, $property, $purpose);

        return (array) $store->getValue();
    }

    /**
     * Write a static instance store. Loud when the framework moved it.
     *
     * @param array<string, mixed> $value
     */
    private static function writeStaticStore(string $class, string $property, array $value, string $purpose): void
    {
        $store = self::reflectionProperty($class, $property, $purpose);

        // The explicit null object is required: passing a single argument for
        // a static property is deprecated as of PHP 8.3.
        $store->setValue(null, $value);
    }

    private static function reflectionProperty(string $class, string $property, string $purpose): \ReflectionProperty
    {
        if (! class_exists($class)) {
            throw FrameworkCompatibilityException::forMissingClass($class, $purpose);
        }

        if (! property_exists($class, $property)) {
            throw FrameworkCompatibilityException::forMissingProperty($class, $property, $purpose);
        }

        $store = new \ReflectionProperty($class, $property);

        // A store that stopped being static is as much a structural change as
        // one that moved; reading it would throw a raw ReflectionException.
        if (! $store->isStatic()) {
            throw FrameworkCompatibilityException::forMissingProperty($class, $property, $purpose);
        }

        return $store;
    }
}
