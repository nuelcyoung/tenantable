<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Bootstrap;

use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantTableManager;
use nuelcyoung\tenantable\Events\TenancyInitialized;
use nuelcyoung\tenantable\Events\TenancyEnded;
use CodeIgniter\Events\Events;

/**
 * TenantBootstrap
 *
 * Orchestrates all tenant-aware subsystems when the tenant context changes.
 * Modelled after stancl/tenancy's bootstrapper concept.
 *
 * Usage (automatic — wired into TenantFilter):
 *   TenantBootstrap::getInstance()->initialize()->boot();
 *
 * Manual usage (e.g., CLI commands):
 *   TenantBootstrap::getInstance()
 *       ->initialize()
 *       ->bootForTenant($tenantId);
 *
 * M-2 – Systems are now registered from Config\Tenantable::$bootstrappers,
 *        so developers can opt-out of individual systems.
 * M-3 – Added $bootErrors[] + wasSuccessful() so callers can detect failures.
 */
class TenantBootstrap
{
    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var array<string, TenantAwareInterface> */
    protected array $systems = [];

    /** Last tenant ID that was booted (to detect changes) */
    protected ?int $lastTenantId = null;

    /** Whether initialize() has run */
    protected bool $initialized = false;

    /**
     * M-3 – Errors collected during last boot() call.
     * @var array<string, string>  [systemName => errorMessage]
     */
    protected array $bootErrors = [];

    // -------------------------------------------------------------------------
    // Initialization
    // -------------------------------------------------------------------------

    /**
     * Register all configured systems and mark as initialized.
     *
     * M-2 – Reads bootstrappers from Config\Tenantable::$bootstrappers so the
     *        developer can remove systems they don't need (e.g., SessionSystem
     *        when using JWT, StorageSystem when using S3).
     */
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

    /**
     * Read the bootstrapper list from Config\Tenantable.
     * Falls back to the full default set if config is unavailable.
     *
     * @return array<string, class-string<TenantAwareInterface>|TenantAwareInterface>
     */
    protected function resolveBootstrappers(): array
    {
        $defaults = [
            'table'   => Systems\TableSystem::class,
            'cache'   => Systems\CacheSystem::class,
            'storage' => Systems\StorageSystem::class,
            'session' => Systems\SessionSystem::class,
            'logging' => Systems\LoggingSystem::class,
            'config'  => Systems\ConfigSystem::class,
            'redis'   => RedisSystem::class,
        ];

        try {
            $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);
            if (!empty($config->bootstrappers) && is_array($config->bootstrappers)) {
                return $config->bootstrappers;
            }
        } catch (\Throwable $e) {
            // Config unavailable – use defaults
        }

        return $defaults;
    }

    // -------------------------------------------------------------------------
    // System registry
    // -------------------------------------------------------------------------

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

    /** @return array<string, TenantAwareInterface> */
    public function getSystems(): array
    {
        return $this->systems;
    }

    // -------------------------------------------------------------------------
    // Boot / Shutdown
    // -------------------------------------------------------------------------

    /**
     * Boot all registered systems for the current tenant.
     * Skips if the tenant hasn't changed since the last boot.
     */
    public function boot(): void
    {
        $tenantId = TenantManager::getInstance()->getTenantId();
        $tenant   = TenantManager::getInstance()->getTenant();

        if ($tenantId === $this->lastTenantId) {
            return; // Tenant unchanged – nothing to do
        }

        $this->lastTenantId = $tenantId;
        $this->bootErrors   = [];

        foreach ($this->systems as $name => $system) {
            try {
                $system->boot($tenantId, $tenant);
            } catch (\Throwable $e) {
                // M-3 – Collect errors; log them; do not silently discard
                $this->bootErrors[$name] = $e->getMessage();
                log_message('error', "TenantBootstrap: System '{$name}' failed to boot: {$e->getMessage()}", [
                    'exception' => $e,
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        // Dispatch TenancyInitialized after all systems have booted
        if ($tenantId !== null) {
            Events::trigger('tenancyInitialized', new TenancyInitialized($tenantId, $tenant ?? []));
        }
    }

    /**
     * Boot all systems for a specific tenant ID (useful in CLI/queue context).
     */
    public function bootForTenant(int $tenantId): void
    {
        TenantManager::getInstance()->setTenantById($tenantId);
        $this->lastTenantId = null; // Force re-boot even if same ID
        $this->boot();
    }

    /**
     * Shutdown all systems and clear tenant context.
     */
    public function shutdown(): void
    {
        // Dispatch TenancyEnded BEFORE systems shut down
        Events::trigger('tenancyEnded', new TenancyEnded(
            $this->lastTenantId,
            TenantManager::getInstance()->getTenant()
        ));

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

    /**
     * Check current tenant ID; re-boot if it has changed.
     * Useful as a guard in long-running processes.
     */
    public function checkAndBoot(): void
    {
        $currentTenantId = TenantManager::getInstance()->getTenantId();

        if ($currentTenantId !== $this->lastTenantId) {
            $this->boot();
        }
    }

    // -------------------------------------------------------------------------
    // M-3 – Boot result inspection
    // -------------------------------------------------------------------------

    /**
     * Whether all systems booted without errors on the last boot() call.
     */
    public function wasSuccessful(): bool
    {
        return empty($this->bootErrors);
    }

    /**
     * Get errors from the last boot() call.
     *
     * @return array<string, string>  [systemName => errorMessage]
     */
    public function getBootErrors(): array
    {
        return $this->bootErrors;
    }
}
