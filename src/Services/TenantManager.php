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

namespace nuelcyoung\tenantable\Services;

use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;

class TenantManager
{
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

    protected ?int    $tenantId           = null;
    protected ?array  $tenant             = null;
    protected ?string $subdomain          = null;
    protected bool    $detectionAttempted = false;
    protected string  $baseDomain;
    protected array   $bypassRoutes       = [];

    private function __construct(?string $baseDomain = null)
    {
        $this->baseDomain = $baseDomain ?? $this->getDefaultBaseDomain();
    }

    /**
     * Detect tenant from request subdomain.
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
            return $this;
        }

        $this->subdomain = $subdomain;
        $this->resolveTenantBySubdomain($subdomain);

        return $this;
    }

    public function setTenantById(int $tenantId): self
    {
        $model  = new TenantModel();
        $tenant = $model->find($tenantId);

        if ($tenant === null) {
            throw new TenantNotFoundException("Tenant with ID {$tenantId} not found");
        }

        return $this->setTenant($tenant);
    }

    /**
     * Set the active tenant from a pre-fetched row (e.g. from the
     * resolver cache). Skips the DB round-trip that setTenantById does.
     *
     * @throws TenantInactiveException
     */
    public function setTenant(array $tenant): self
    {
        if (! isset($tenant['id'])) {
            throw new TenantNotFoundException('Tenant row missing id');
        }

        if (($tenant['is_active'] ?? false) !== true) {
            throw new TenantInactiveException("Tenant is inactive");
        }

        $this->tenantId  = (int) $tenant['id'];
        $this->tenant    = $tenant;
        $this->subdomain = $tenant['subdomain'] ?? null;

        return $this;
    }

    public function setTenantBySubdomain(string $subdomain): self
    {
        $this->resolveTenantBySubdomain($subdomain);
        return $this;
    }

    public static function initialize(?string $baseDomain = null): self
    {
        self::$instance = new self($baseDomain);
        return self::$instance;
    }

    protected function resolveTenantBySubdomain(string $subdomain): void
    {
        $entry = \nuelcyoung\tenantable\Services\TenantResolverCache::getInstance()
            ->resolveBySubdomain($subdomain);

        if ($entry === null) {
            throw new TenantNotFoundException("Tenant '{$subdomain}' not found");
        }

        // Prefer the full cached tenant row to skip a second DB call.
        $tenant = $entry['tenant'] ?? null;

        if (! is_array($tenant)) {
            $tenant = (new TenantModel())->find((int) $entry['tenant_id']);
            if ($tenant === null) {
                throw new TenantNotFoundException("Tenant '{$subdomain}' not found");
            }
        }

        if (($tenant['is_active'] ?? false) !== true) {
            throw new TenantInactiveException("Tenant '{$subdomain}' is inactive");
        }

        $this->tenantId = (int) $tenant['id'];
        $this->tenant   = $tenant;
    }

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

    public function clear(): self
    {
        $this->tenantId           = null;
        $this->tenant             = null;
        $this->subdomain          = null;
        $this->detectionAttempted = false;
        return $this;
    }

    protected function getHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return explode(':', $host)[0];
    }

    protected function getCurrentUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?? '/';
    }

    public function extractSubdomain(?string $host): ?string
    {
        if ($host === null || $host === '') {
            return null;
        }

        $host = strtolower(explode(':', $host)[0]);
        $base = strtolower($this->baseDomain);

        $suffix = '.' . $base;

        if (!str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        return $subdomain !== '' ? $subdomain : null;
    }

    /**
     * Whether a host header is allowed by config. Used by both the filter
     * (pre-identify) and EarlyTenantDetector (pre_system). Localhost is
     * always allowed so the dev bypass in IdentifyTenant stays reachable.
     */
    public function isHostAllowed(string $host): bool
    {
        if ($host === '') {
            return true; // Let downstream handle empty host
        }

        if ($this->isLocalhost($host)) {
            return true;
        }

        try {
            $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);
        } catch (\Throwable $e) {
            return true;
        }

        $patterns = $config->trustedHostPatterns ?? null;

        // Explicit opt-out
        if ($patterns === null) {
            return true;
        }

        // Empty array → fall back to derived defaults
        if ($patterns === []) {
            $baseDomain = $config->baseDomain ?? 'localhost';
            $patterns   = $baseDomain !== 'localhost'
                ? ['*.' . $baseDomain, $baseDomain]
                : ['localhost', '127.0.0.1', '::1'];
        }

        $host = strtolower($host);

        foreach ($patterns as $pattern) {
            if ($this->hostMatchesPattern($host, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    protected function hostMatchesPattern(string $host, string $pattern): bool
    {
        if ($pattern === $host) {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\\*', '[^.]+', preg_quote($pattern, '/')) . '$/';
            return (bool) preg_match($regex, $host);
        }

        return false;
    }

    public function isLocalhost(string $host): bool
    {
        $host = strtolower($host);
        $base = strtolower($this->baseDomain);

        $exact = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

        if (in_array($host, $exact, true)) {
            return true;
        }

        if (str_starts_with($host, 'localhost:')) {
            return true;
        }

        if (preg_match('/\.(test|local|example)$/', $host)) {
            if (!empty($base) && str_ends_with($host, $base)) {
                return false;
            }

            if ($host === $base) {
                return false;
            }

            return true;
        }

        if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $host)) {
            return true;
        }

        return false;
    }

    protected function getDefaultBaseDomain(): string
    {
        $envDomain = getenv('TENANT_BASE_DOMAIN');
        if ($envDomain !== false && !empty($envDomain)) {
            return $envDomain;
        }

        try {
            $config = config(\nuelcyoung\tenantable\Config\Tenantable::class);
            if (!empty($config->baseDomain) && $config->baseDomain !== 'localhost') {
                return $config->baseDomain;
            }
        } catch (\Throwable $e) {
        }

        return 'localhost';
    }
}
