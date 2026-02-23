<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Services;

use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

/**
 * TenantManager Service
 *
 * Core service for managing tenant context across the application.
 * Handles subdomain detection, tenant resolution, and context management.
 *
 * FIX 1.2 – Singleton backed by a real static property.
 * FIX 3.8 – getDefaultBaseDomain() reads Config\Tenantable::$baseDomain (restored).
 * FIX 3.9 – extractSubdomain() no longer has the ambiguous "first segment"
 *            fallback that could misidentify hosts when baseDomain is misconfigured.
 */
class TenantManager
{
    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    private static ?self $instance = null;

    public static function getInstance(?string $baseDomain = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($baseDomain);
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    protected ?int    $tenantId           = null;
    protected ?array  $tenant             = null;
    protected ?string $subdomain          = null;
    protected bool    $detectionAttempted = false;
    protected string  $baseDomain;
    protected array   $bypassRoutes       = [];

    // -------------------------------------------------------------------------
    // Constructor (private — use getInstance())
    // -------------------------------------------------------------------------

    private function __construct(?string $baseDomain = null)
    {
        $this->baseDomain = $baseDomain ?? $this->getDefaultBaseDomain();
    }

    // -------------------------------------------------------------------------
    // Tenant detection
    // -------------------------------------------------------------------------

    /**
     * Detect the current tenant from the request's subdomain.
     *
     * @throws TenantNotFoundException  when subdomain exists but maps to no tenant
     * @throws TenantInactiveException  when the resolved tenant is inactive
     */
    public function detectFromSubdomain(): self
    {
        $this->detectionAttempted = true;

        $host = $this->getHost();

        if ($host === '' || $this->isLocalhost($host)) {
            return $this;
        }

        $subdomain = $this->extractSubdomain($host);

        if ($subdomain === null) {
            // Host is the bare base domain (no subdomain) — main domain request
            return $this;
        }

        $this->subdomain = $subdomain;
        $this->resolveTenantBySubdomain($subdomain);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Manual tenant setting (CLI, queues, tests)
    // -------------------------------------------------------------------------

    /**
     * Set the active tenant by ID.
     *
     * @throws TenantNotFoundException
     * @throws TenantInactiveException
     */
    public function setTenantById(int $tenantId): self
    {
        $model  = new TenantModel();
        $tenant = $model->find($tenantId);

        if ($tenant === null) {
            throw new TenantNotFoundException("Tenant with ID {$tenantId} not found");
        }

        if (($tenant['is_active'] ?? true) === false) {
            throw new TenantInactiveException("Tenant is inactive");
        }

        $this->tenantId  = $tenantId;
        $this->tenant    = $tenant;
        $this->subdomain = $tenant['subdomain'] ?? null;

        return $this;
    }

    /**
     * Set the active tenant by subdomain string.
     *
     * @throws TenantNotFoundException
     * @throws TenantInactiveException
     */
    public function setTenantBySubdomain(string $subdomain): self
    {
        $this->resolveTenantBySubdomain($subdomain);
        return $this;
    }

    /**
     * Initialise (or reinitialise) the singleton, optionally with a different base domain.
     * Useful in tests or when the domain changes between requests.
     */
    public static function initialize(?string $baseDomain = null): self
    {
        self::$instance = new self($baseDomain);
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Internal resolution
    // -------------------------------------------------------------------------

    /**
     * @throws TenantNotFoundException
     * @throws TenantInactiveException
     */
    protected function resolveTenantBySubdomain(string $subdomain): void
    {
        $model  = new TenantModel();
        $tenant = $model->where('subdomain', $subdomain)->first();

        if ($tenant === null) {
            throw new TenantNotFoundException("Tenant '{$subdomain}' not found");
        }

        if (($tenant['is_active'] ?? true) === false) {
            throw new TenantInactiveException("Tenant '{$subdomain}' is inactive");
        }

        $this->tenantId = (int) $tenant['id'];
        $this->tenant   = $tenant;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getTenantId(): ?int     { return $this->tenantId; }
    public function getTenant(): ?array     { return $this->tenant; }
    public function getSubdomain(): ?string { return $this->subdomain; }
    public function getBaseDomain(): string { return $this->baseDomain; }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function wasDetectionAttempted(): bool
    {
        return $this->detectionAttempted;
    }

    public function isCliRequest(): bool
    {
        return \CodeIgniter\CLI\CLI::isCli() || PHP_SAPI === 'cli';
    }

    // -------------------------------------------------------------------------
    // Setters / chain helpers
    // -------------------------------------------------------------------------

    public function setBaseDomain(string $domain): self
    {
        $this->baseDomain = $domain;
        return $this;
    }

    public function addBypassRoute(string $pattern): self
    {
        $this->bypassRoutes[] = $pattern;
        return $this;
    }

    public function shouldBypassDetection(): bool
    {
        $uri = $this->getCurrentUri();

        foreach ($this->bypassRoutes as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear all tenant context (useful between requests in long-running processes).
     */
    public function clear(): self
    {
        $this->tenantId           = null;
        $this->tenant             = null;
        $this->subdomain          = null;
        $this->detectionAttempted = false;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Host / URI helpers
    // -------------------------------------------------------------------------

    protected function getHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // Strip port (host:port → host)
        return explode(':', $host)[0];
    }

    protected function getCurrentUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?? '/';
    }

    /**
     * Extract the subdomain portion from a host string.
     *
     * FIX 3.9 – Removed the ambiguous fallback that returned $parts[0] whenever
     *            the host had more than two segments but didn't match baseDomain.
     *            That silently "found a subdomain" when baseDomain was misconfigured,
     *            causing wrong tenants to be resolved.
     *
     *            Now: if the host doesn't end with our baseDomain, we return null
     *            (no subdomain extractable) and let the caller decide what to do.
     *
     * @param string|null $host  Bare hostname (no port)
     * @return string|null       Subdomain, or null if the host has none
     */
    protected function extractSubdomain(?string $host): ?string
    {
        if ($host === null || $host === '') {
            return null;
        }

        // Strip port — belt-and-suspenders since getHost() already strips it
        $host = explode(':', $host)[0];

        if (!str_ends_with($host, $this->baseDomain)) {
            // FIX 3.9 – Host is not under our base domain; do not guess
            return null;
        }

        $subdomain = rtrim(str_replace($this->baseDomain, '', $host), '.');

        return $subdomain ?: null;
    }

    protected function isLocalhost(string $host): bool
    {
        $exact = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

        if (in_array($host, $exact, true)) {
            return true;
        }

        // localhost with port (localhost:8080)
        if (str_starts_with($host, 'localhost:')) {
            return true;
        }

        // Dev TLDs
        if (preg_match('/\.(test|local|example)$/', $host)) {
            return true;
        }

        // Private IP ranges (broad check)
        if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $host)) {
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Default base domain resolution
    // -------------------------------------------------------------------------

    /**
     * Determine the base domain from available sources.
     *
     * FIX 3.8 – Config\Tenantable::$baseDomain is now consulted (it was
     *            completely ignored before this fix).
     *
     * Priority (highest → lowest):
     *   1. TENANT_BASE_DOMAIN environment variable  (deploy-time override)
     *   2. Config\Tenantable::$baseDomain           (package config)
     *   3. 'localhost'                               (safe default)
     */
    protected function getDefaultBaseDomain(): string
    {
        // 1. Environment variable
        $envDomain = getenv('TENANT_BASE_DOMAIN');
        if ($envDomain !== false && !empty($envDomain)) {
            return $envDomain;
        }

        // 2. FIX 3.8 – Package config
        try {
            $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);
            if (!empty($config->baseDomain) && $config->baseDomain !== 'localhost') {
                return $config->baseDomain;
            }
        } catch (\Throwable $e) {
            // Config not available — fall through
        }

        // 3. Safe default
        return 'localhost';
    }
}
