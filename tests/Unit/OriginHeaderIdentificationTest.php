<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\HTTP\RequestInterface;
use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Exceptions\TenantNotFoundException;
use nuelcyoung\tenantable\Filters\IdentifyTenant;
use nuelcyoung\tenantable\Services\TenantManager;

/**
 * Origin-header identification (strategy 'origin', alias tenant_origin).
 *
 * @covers \nuelcyoung\tenantable\Filters\IdentifyTenant::resolveByOrigin
 * @covers \nuelcyoung\tenantable\Filters\OriginHeaderFilter
 */
class OriginHeaderIdentificationTest extends TestCase
{
    private string|false $originalHost;

    protected function setUp(): void
    {
        parent::setUp();
        TenantManager::resetInstance();

        // identify() bails out when the request host is empty; the shared
        // API host exercises the real code path.
        $this->originalHost = $_SERVER['HTTP_HOST'] ?? false;
        $_SERVER['HTTP_HOST'] = 'api.example.com';
    }

    protected function tearDown(): void
    {
        if ($this->originalHost === false) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->originalHost;
        }

        TenantManager::resetInstance();
        parent::tearDown();
    }

    /** Requests with this Origin resolve to tenant 5. */
    private function filter(): TestableOriginFilter
    {
        $filter = new TestableOriginFilter();
        $filter->setStrategyForTest('origin');

        return $filter;
    }

    private function requestWithOrigin(string $origin): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getHeaderLine')->with('Origin')->willReturn($origin);

        return $request;
    }

    private function callIdentify(IdentifyTenant $filter, RequestInterface $request): void
    {
        $method = new \ReflectionMethod($filter, 'identify');
        $method->setAccessible(true);
        $method->invoke($filter, $request);
    }

    public function testOriginHostIsResolvedByDomain(): void
    {
        $filter  = $this->filter();
        $request = $this->requestWithOrigin('https://acme.example.com');

        $this->callIdentify($filter, $request);

        $this->assertSame('acme.example.com', $filter->resolvedHost);
        $this->assertTrue(TenantManager::getInstance()->hasTenant());
        $this->assertSame(5, TenantManager::getInstance()->getTenantId());
    }

    public function testOriginPortAndSchemeAreStripped(): void
    {
        $filter  = $this->filter();
        $request = $this->requestWithOrigin('http://acme.example.com:8080');

        $this->callIdentify($filter, $request);

        $this->assertSame('acme.example.com', $filter->resolvedHost);
    }

    public function testMissingOriginHeaderThrows(): void
    {
        $filter  = $this->filter();
        $request = $this->requestWithOrigin('');

        $this->expectException(TenantNotFoundException::class);

        $this->callIdentify($filter, $request);
    }

    public function testNullOriginHeaderThrows(): void
    {
        // Sandboxed iframes send "Origin: null".
        $filter  = $this->filter();
        $request = $this->requestWithOrigin('null');

        $this->expectException(TenantNotFoundException::class);

        $this->callIdentify($filter, $request);
    }

    public function testUnparseableOriginThrows(): void
    {
        $filter  = $this->filter();
        $request = $this->requestWithOrigin("https://\xE2\x80\xA5evil");

        $this->expectException(TenantNotFoundException::class);

        $this->callIdentify($filter, $request);
    }

    public function testUnknownOriginDomainThrows(): void
    {
        $filter  = $this->filter();
        $filter->existsByDomain = false;
        $request = $this->requestWithOrigin('https://unknown.example.net');

        $this->expectException(TenantNotFoundException::class);

        $this->callIdentify($filter, $request);
    }

    public function testOriginStrategyAcceptsKeyValueArgument(): void
    {
        $filter     = new IdentifyTenant();
        $normalize  = new \ReflectionMethod($filter, 'normalizeArguments');
        $normalize->setAccessible(true);
        $configure  = new \ReflectionMethod($filter, 'configure');
        $configure->setAccessible(true);

        $arguments = $normalize->invokeArgs($filter, [['strategy=origin']]);
        $configure->invokeArgs($filter, [$arguments]);

        $strategy = new \ReflectionProperty($filter, 'strategy');
        $strategy->setAccessible(true);

        $this->assertSame('origin', $strategy->getValue($filter));
    }

    public function testOriginHeaderFilterDefaultsToOriginStrategy(): void
    {
        $filter = new \nuelcyoung\tenantable\Filters\OriginHeaderFilter();

        $strategy = new \ReflectionProperty($filter, 'strategy');
        $strategy->setAccessible(true);

        $this->assertSame('origin', $strategy->getValue($filter));
    }

    public function testFilterAliasIsRegistered(): void
    {
        $config = new \nuelcyoung\tenantable\Config\Tenantable();

        $this->assertArrayHasKey('tenant_origin', $config->registerFilters());

        $registrar = new \ReflectionMethod(\nuelcyoung\tenantable\Config\Registrar::class, 'Filters');
        $registrar->setAccessible(true);
        $aliases = $registrar->invoke(null)['aliases'];

        $this->assertArrayHasKey('tenant_origin', $aliases);
        $this->assertSame(\nuelcyoung\tenantable\Filters\OriginHeaderFilter::class, $aliases['tenant_origin']);
    }
}

class TestableOriginFilter extends IdentifyTenant
{
    public ?string $resolvedHost = null;
    public bool $existsByDomain = true;

    public function setStrategyForTest(string $strategy): void
    {
        $this->strategy = $strategy;
    }

    protected function resolveByDomain(string $host, TenantManager $manager): bool
    {
        $this->resolvedHost = $host;

        if (! $this->existsByDomain) {
            return false;
        }

        $manager->setTenant([
            'id'        => 5,
            'subdomain' => 'acme',
            'is_active' => 1,
        ]);

        return true;
    }
}
