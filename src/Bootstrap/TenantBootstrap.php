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

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;
use nuelcyoung\tenantable\Support\SharedInfrastructure;
use nuelcyoung\tenantable\Support\TenantableConfig;

class TenantBootstrap
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /** @var array<string, TenantAwareInterface> */
    protected array $systems = [];

    protected ?int $lastTenantId = null;
    protected bool $initialized = false;

    /** @var array<string, string> */
    protected array $bootErrors = [];

    public function initialize(): self
    {
        if ($this->initialized) {
            return $this;
        }

        // Before any tenant state is touched: an unsafe multi-node deployment
        // must not serve traffic at all.
        SharedInfrastructure::assertSafe();

        $bootstrappers = $this->resolveBootstrappers();

        foreach ($bootstrappers as $name => $class) {
            if ($class instanceof TenantAwareInterface) {
                $this->registerSystem($name, $class);
                continue;
            }

            if (! is_string($class)) {
                log_message('error', "TenantBootstrap: bootstrapper '{$name}' is not a class name or TenantAwareInterface.");
                continue;
            }

            if (! class_exists($class)) {
                log_message('error', "TenantBootstrap: bootstrapper class '{$class}' (for '{$name}') does not exist.");
                continue;
            }

            if (! is_subclass_of($class, TenantAwareInterface::class)) {
                log_message('error', "TenantBootstrap: bootstrapper '{$class}' (for '{$name}') must implement TenantAwareInterface; skipping.");
                continue;
            }

            $this->registerSystem($name, new $class());
        }

        $this->initialized = true;

        return $this;
    }

    protected function resolveBootstrappers(): array
    {
        $defaults = [
            'database' => Systems\DatabaseSystem::class,
            'table'    => Systems\TableSystem::class,
            'cache'    => Systems\CacheSystem::class,
            'storage'  => Systems\StorageSystem::class,
            'session'  => Systems\SessionSystem::class,
            'logging'  => Systems\LoggingSystem::class,
            'config'   => Systems\ConfigSystem::class,
            'queue'    => Systems\QueueSystem::class,
            'redis'    => RedisSystem::class,
        ];

        try {
            $config = TenantableConfig::get();
            if (!empty($config->bootstrappers) && is_array($config->bootstrappers)) {
                return $config->bootstrappers;
            }
        } catch (\Throwable $e) {
        }

        return $defaults;
    }

    public function registerSystem(string $name, TenantAwareInterface $system): self
    {
        $this->systems[$name] = $system;
        return $this;
    }

    public function unregisterSystem(string $name): self
    {
        unset($this->systems[$name]);
        return $this;
    }

    public function getSystem(string $name): ?TenantAwareInterface
    {
        return $this->systems[$name] ?? null;
    }

    public function getSystems(): array
    {
        return $this->systems;
    }

    public function boot(): void
    {
        $tenantId = TenantManager::getInstance()->getTenantId();
        $tenant   = TenantManager::getInstance()->getTenant();

        if ($tenantId === $this->lastTenantId) {
            return;
        }

        $this->lastTenantId = $tenantId;
        $this->bootErrors   = [];

        foreach ($this->systems as $name => $system) {
            try {
                $system->boot($tenantId, $tenant);
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                $this->bootErrors[$name] = $errorMessage;
                log_message('error', "TenantBootstrap: System '{$name}' failed to boot: {$e->getMessage()}", [
                    'exception' => $e,
                    'tenant_id' => $tenantId,
                ]);

                // A partial switch is dangerous. Roll everything back.
                $this->shutdown();
                $this->bootErrors[$name] = $errorMessage;
                TenantManager::getInstance()->clear();

                throw new \RuntimeException(
                    "TenantBootstrap: unable to initialize tenant context for tenant " .
                    ($tenantId === null ? 'none' : (string) $tenantId) .
                    " because system '{$name}' failed.",
                    0,
                    $e,
                );
            }
        }
    }

    public function bootForTenant(int $tenantId): void
    {
        TenantManager::getInstance()->setTenantById($tenantId);
        $this->lastTenantId = null;
        $this->boot();
    }

    public function runCentral(callable $callback): mixed
    {
        $tenantManager    = TenantManager::getInstance();
        $previousTenantId = $tenantManager->getTenantId();

        if ($previousTenantId === null) {
            return $callback();
        }

        $tenantManager->clear();

        foreach ($this->systems as $name => $system) {
            try {
                $system->boot(null, null);
            } catch (\Throwable $e) {
                log_message('error', "TenantBootstrap::runCentral: '{$name}' failed: {$e->getMessage()}", [
                    'exception' => $e,
                ]);

                $this->shutdown();
                $this->restoreTenantAfterCentralFailure($previousTenantId);

                throw new \RuntimeException(
                    "TenantBootstrap::runCentral: unable to enter central context because system '{$name}' failed.",
                    0,
                    $e,
                );
            }
        }

        $this->lastTenantId = null;

        try {
            return $callback();
        } finally {
            try {
                $this->shutdown();
                $this->bootForTenant($previousTenantId);
            } catch (\Throwable $e) {
                $tenantManager->clear();

                throw new \RuntimeException(
                    "TenantBootstrap::runCentral: failed to restore tenant {$previousTenantId}.",
                    0,
                    $e,
                );
            }
        }
    }

    private function restoreTenantAfterCentralFailure(int $tenantId): void
    {
        try {
            $this->bootForTenant($tenantId);
        } catch (\Throwable $restoreError) {
            TenantManager::getInstance()->clear();

            throw new \RuntimeException(
                "TenantBootstrap::runCentral: central bootstrap failed and tenant {$tenantId} could not be restored.",
                0,
                $restoreError,
            );
        }
    }

    /** Shutdown all systems. Dispatch TenancyEnded BEFORE calling this. */
    public function shutdown(): void
    {
        foreach ($this->systems as $name => $system) {
            try {
                $system->shutdown();
            } catch (\Throwable $e) {
                log_message('error', "TenantBootstrap: System '{$name}' failed to shutdown: {$e->getMessage()}");
            }
        }

        $this->lastTenantId = null;
        $this->bootErrors   = [];
    }

    public function checkAndBoot(): void
    {
        $currentTenantId = TenantManager::getInstance()->getTenantId();

        if ($currentTenantId !== $this->lastTenantId) {
            $this->boot();
        }
    }

    public function wasSuccessful(): bool
    {
        return empty($this->bootErrors);
    }

    public function getBootErrors(): array
    {
        return $this->bootErrors;
    }
}
