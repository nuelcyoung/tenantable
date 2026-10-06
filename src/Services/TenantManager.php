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
use nuelcyoung\tenantable\Exceptions\TenantNotReadyException;
use nuelcyoung\tenantable\Support\TenantableConfig;

class TenantManager
{
    private static ?self $instance = null;

    public static function getInstance(string|array|null $baseDomain = null): self
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

    /** Primary central domain (first of $baseDomains). */
    protected string $baseDomain = 'localhost';

    /** All central domains: hosts on any of them (and their subdomains) are trusted. */
    protected array $baseDomains = [];

    protected array $bypassRoutes = [];

    private function __construct(string|array|null $baseDomain = null)
    {
        $this->setBaseDomain($baseDomain ?? $this->getDefaultBaseDomain());
    }

    /** Detect tenant from the request subdomain. */
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

    /** Set the active tenant from a row. */
    public function setTenant(array $tenant): self
    {
        if (! isset($tenant['id'])) {
            throw new TenantNotFoundException('Tenant row missing id');
        }

        // CI4 < 4.5 stores is_active as int.
        if (empty($tenant['is_active'])) {
            throw new TenantInactiveException("Tenant is inactive");
        }

        $this->assertProvisioned($tenant);

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

    public static function initialize(string|array|null $baseDomain = null): self
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

        // Use the cached row if available.
        $tenant = $entry['tenant'] ?? null;

        if (! is_array($tenant)) {
            $tenant = (new TenantModel())->find((int) $entry['tenant_id']);
            if ($tenant === null) {
                throw new TenantNotFoundException("Tenant '{$subdomain}' not found");
            }
        }

        // CI4 < 4.5 stores is_active as int.
        if (empty($tenant['is_active'])) {
            throw new TenantInactiveException("Tenant '{$subdomain}' is inactive");
        }

        $this->assertProvisioned($tenant);

        $this->tenantId  = (int) $tenant['id'];
        $this->tenant    = $tenant;
        // Keep subdomain in sync.
        $this->subdomain = $tenant['subdomain'] ?? $subdomain;
    }

    /**
     * Refuse a tenant whose storage is not there yet (async provisioning
     * creates the row first). Missing status column reads as ready.
     *
     * @param array<string, mixed> $tenant
     */
    private function assertProvisioned(array $tenant): void
    {
        $status = $tenant['status'] ?? TenantModel::STATUS_READY;

        if (! is_string($status) || $status === '' || $status === TenantModel::STATUS_READY) {
            return;
        }

        throw TenantNotReadyException::forStatus((int) $tenant['id'], $status);
    }

    public function getTenantId(): ?int     { return $this->tenantId; }
    public function getTenant(): ?array     { return $this->tenant; }
    public function getSubdomain(): ?string { return $this->subdomain; }

    /** The primary central domain (first configured). */
    public function getBaseDomain(): string { return $this->baseDomain; }

    /** Every configured central domain. */
    public function getBaseDomains(): array { return $this->baseDomains; }

    /**
     * The central domain a host belongs to (exact match or direct subdomain);
     * the longest match wins.
     */
    public function getBaseDomainForHost(?string $host): ?string
    {
        $host = $host === null ? null : $this->normalizeHost($host);

        if ($host === null || $host === '') {
            return null;
        }

        $match    = null;
        $matchLen = 0;

        foreach ($this->baseDomains as $base) {
            if (($host === $base || str_ends_with($host, '.' . $base)) && strlen($base) > $matchLen) {
                $match    = $base;
                $matchLen = strlen($base);
            }
        }

        return $match;
    }

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
        return PHP_SAPI === 'cli' || (function_exists('is_cli') && is_cli());
    }

    /** Set the central domain(s). Accepts one domain or a list. */
    public function setBaseDomain(string|array $domain): self
    {
        $domains = [];

        foreach ((array) $domain as $entry) {
            $normalized = $this->normalizeHost((string) $entry) ?? strtolower(trim((string) $entry));

            if ($normalized !== '' && ! in_array($normalized, $domains, true)) {
                $domains[] = $normalized;
            }
        }

        if ($domains === []) {
            return $this;
        }

        $this->baseDomains = $domains;
        $this->baseDomain  = $domains[0];

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
        $normalizedUri = ltrim($uri, '/');

        foreach ($this->bypassRoutes as $pattern) {
            if (fnmatch($pattern, $uri) || fnmatch($pattern, $normalizedUri)) {
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

    /** Normalize and validate a host. Rejects malformed input before lookup. */
    public function normalizeHost(string $host): ?string
    {
        $host = trim($host);

        if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
            return null;
        }

        if (str_starts_with($host, '[')) {
            if (preg_match('/^\[([^\]]+)\](?::(\d{1,5}))?$/', $host, $matches) !== 1) {
                return null;
            }

            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }

            if (isset($matches[2]) && (int) $matches[2] > 65535) {
                return null;
            }

            return strtolower($matches[1]);
        }

        if (substr_count($host, ':') > 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? strtolower($host)
                : null;
        }

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);

            if ($port === '' || preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port > 65535) {
                return null;
            }
        }

        $host = rtrim(strtolower($host), '.');

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host === '' ? null : $host;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return null;
            }
        }

        return strlen($host) <= 253 ? $host : null;
    }

    protected function getHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        return $this->normalizeHost((string) $host) ?? '';
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

        $host = $this->normalizeHost($host);

        if ($host === null) {
            return null;
        }

        // The most specific central domain wins, so overlapping central
        // domains (example.com + app.example.com) resolve correctly.
        $base = $this->getBaseDomainForHost($host);

        if ($base === null) {
            return null;
        }

        $subdomain = rtrim(substr($host, 0, -strlen($base)), '.');

        return $subdomain !== '' ? $subdomain : null;
    }

    /** Check if a host is allowed. Fails closed when no patterns are set. */
    public function isHostAllowed(string $host): bool
    {
        $host = $this->normalizeHost($host);

        if ($host === null) {
            return false;
        }

        if ($this->isLoopback($host)) {
            try {
                return TenantableConfig::get()->allowLocalhost;
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            $config = TenantableConfig::get();
        } catch (\Throwable $e) {
            // No config: fall back to base domain only.
            return $this->matchesDerivedDefaults($host);
        }

        $patterns = $config->trustedHostPatterns ?? null;

        // No patterns: fall back to every configured central domain.
        if ($patterns === null || $patterns === []) {
            $patterns = $this->allDefaultHostPatterns();
        }

        foreach ($patterns as $pattern) {
            if ($this->hostMatchesPattern($host, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    /** Default patterns: base domain and its subdomains. */
    protected function deriveDefaultHostPatterns(string $baseDomain): array
    {
        $baseDomain = $this->normalizeHost($baseDomain) ?? strtolower(trim($baseDomain));

        if ($baseDomain === '' || $baseDomain === 'localhost') {
            return ['localhost', '127.0.0.1', '::1'];
        }

        return ['*.' . $baseDomain, $baseDomain];
    }

    /** Patterns covering every configured central domain. */
    protected function allDefaultHostPatterns(): array
    {
        $patterns = [];

        foreach ($this->baseDomains as $base) {
            foreach ($this->deriveDefaultHostPatterns($base) as $pattern) {
                if (! in_array($pattern, $patterns, true)) {
                    $patterns[] = $pattern;
                }
            }
        }

        if ($patterns === []) {
            return $this->deriveDefaultHostPatterns($this->baseDomain);
        }

        return $patterns;
    }

    protected function matchesDerivedDefaults(string $host): bool
    {
        $host = strtolower($host);

        foreach ($this->allDefaultHostPatterns() as $pattern) {
            if ($this->hostMatchesPattern($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function hostMatchesPattern(string $host, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if ($pattern === $host) {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\\*', '[^.]+', preg_quote($pattern, '/')) . '$/';
            return (bool) preg_match($regex, $host);
        }

        return false;
    }

    /** Loopback and dev hosts. Excludes private IPs. */
    public function isLoopback(string $host): bool
    {
        $host = $this->normalizeHost($host) ?? strtolower(trim($host));

        $exact = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

        if (in_array($host, $exact, true)) {
            return true;
        }

        if (str_starts_with($host, 'localhost:')) {
            return true;
        }

        if (preg_match('/\.(test|local|example)$/', $host)) {
            // Hosts on a configured central domain are never loopback.
            foreach ($this->baseDomains as $base) {
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** True for RFC1918 private IPv4. */
    public function isPrivateIp(string $host): bool
    {
        $host = preg_replace('/:\d+$/', '', strtolower($host));

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $long = ip2long($host);

        return ($long >= ip2long('10.0.0.0')    && $long <= ip2long('10.255.255.255'))
            || ($long >= ip2long('172.16.0.0')  && $long <= ip2long('172.31.255.255'))
            || ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255'));
    }

    /** Loopback, dev, and private IPs. Not used for host allowlist. */
    public function isLocalhost(string $host): bool
    {
        return $this->isLoopback($host) || $this->isPrivateIp($host);
    }

    /** Env override and config defaults. Returns one domain or a list. */
    protected function getDefaultBaseDomain(): string|array
    {
        $envDomain = getenv('TENANT_BASE_DOMAIN');
        if ($envDomain !== false && !empty($envDomain)) {
            // Comma-separated lists are supported: "example.com,example.org".
            return array_map('trim', explode(',', $envDomain));
        }

        try {
            $config = TenantableConfig::get();

            $domains = array_values(array_filter(
                $config->centralDomains(),
                static fn (string $domain): bool => $domain !== 'localhost'
            ));

            if ($domains !== []) {
                return $domains;
            }
        } catch (\Throwable $e) {
        }

        return 'localhost';
    }
}
