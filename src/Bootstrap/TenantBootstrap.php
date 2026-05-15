<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;

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

        $bootstrappers = $this->resolveBootstrappers();

        foreach ($bootstrappers as $name => $class) {
            if (is_string($class) && class_exists($class)) {
                $this->registerSystem($name, new $class());
            } elseif ($class instanceof TenantAwareInterface) {
                $this->registerSystem($name, $class);
            }
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
            'redis'    => RedisSystem::class,
        ];

        try {
            $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);
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
                $this->bootErrors[$name] = $e->getMessage();
                log_message('error', "TenantBootstrap: System '{$name}' failed to boot: {$e->getMessage()}", [
                    'exception' => $e,
                    'tenant_id' => $tenantId,
                ]);
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
            }
        }

        $this->lastTenantId = null;

        try {
            return $callback();
        } finally {
            $this->bootForTenant($previousTenantId);
        }
    }

    /**
     * Shutdown all systems.
     *
     * Note: The TenancyEnded event should be dispatched BEFORE calling
     * this method so listeners can access tenant state before systems
     * are torn down.
     */
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