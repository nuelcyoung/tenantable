<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\HTTP\RequestInterface;
use nuelcyoung\tenantable\Config\Tenantable;
use nuelcyoung\tenantable\Exceptions\TenantAccessDeniedException;
use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Filters\IdentifyTenant;
use nuelcyoung\tenantable\Services\TenantManager;

class TestableIdentifyTenantFilter extends IdentifyTenant
{
    public Tenantable $config;

    public function setStrategyForTest(string $strategy): void
    {
        $this->strategy = $strategy;
    }

    public function authorizeForTest(RequestInterface $request, TenantManager $manager): void
    {
        $this->authorizeTenant($request, $manager);
    }

    protected function getTenantableConfig(): object
    {
        return $this->config;
    }
}

/**
 * Tests filter argument normalization for the documented `tenant:strategy=...` usage.
 *
 * @covers \nuelcyoung\tenantable\Filters\BaseTenantFilter
 * @covers \nuelcyoung\tenantable\Filters\IdentifyTenant
 */
class FilterArgumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantManager::resetInstance();
    }

    protected function tearDown(): void
    {
        TenantManager::resetInstance();
        parent::tearDown();
    }

    private function callProtected(object $object, string $method, array $args)
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $args);
    }

    private function readProtected(object $object, string $property)
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }

    public function testNormalizeExpandsKeyValueTokens(): void
    {
        $filter = new IdentifyTenant();

        $normalized = $this->callProtected($filter, 'normalizeArguments', [['strategy=domain', 'strict=true']]);

        $this->assertSame('domain', $normalized['strategy']);
        $this->assertSame('true', $normalized['strict']);
    }

    public function testNormalizeKeepsBareTokensAsPositional(): void
    {
        $filter = new IdentifyTenant();

        $normalized = $this->callProtected($filter, 'normalizeArguments', [['subdomain']]);

        $this->assertSame(['subdomain'], $normalized['_positional']);
    }

    public function testConfigureAppliesStrategyFromKeyValue(): void
    {
        $filter     = new IdentifyTenant();
        $normalized = $this->callProtected($filter, 'normalizeArguments', [['strategy=domain']]);
        $this->callProtected($filter, 'configure', [$normalized]);

        $this->assertSame('domain', $this->readProtected($filter, 'strategy'));
    }

    public function testConfigureAppliesStrategyFromBareShorthand(): void
    {
        $filter     = new IdentifyTenant();
        $normalized = $this->callProtected($filter, 'normalizeArguments', [['subdomain']]);
        $this->callProtected($filter, 'configure', [$normalized]);

        $this->assertSame('subdomain', $this->readProtected($filter, 'strategy'));
    }

    public function testConfigureParsesStrictBoolean(): void
    {
        $filter     = new IdentifyTenant();
        $normalized = $this->callProtected($filter, 'normalizeArguments', [['strategy=domain', 'strict=true']]);
        $this->callProtected($filter, 'configure', [$normalized]);

        $this->assertTrue($this->readProtected($filter, 'strict'));
    }

    public function testRequestSelectorFailsClosedWithoutAuthorizer(): void
    {
        $filter = new TestableIdentifyTenantFilter();
        $filter->config = new Tenantable();
        $filter->setStrategyForTest('request_data');

        $manager = TenantManager::getInstance();
        $this->setTenant($manager, 7, ['id' => 7, 'is_active' => 1]);

        $this->expectException(TenantAccessDeniedException::class);
        $filter->authorizeForTest($this->createMock(RequestInterface::class), $manager);
    }

    public function testRequestSelectorUsesApplicationAuthorizer(): void
    {
        $filter = new TestableIdentifyTenantFilter();
        $filter->config = new Tenantable();
        $filter->setStrategyForTest('path');

        $request = $this->createMock(RequestInterface::class);
        $called  = false;
        $filter->config->tenantAuthorizer = static function (RequestInterface $given, array $tenant) use ($request, &$called): bool {
            $called = $given === $request;
            return (int) $tenant['id'] === 7;
        };

        $manager = TenantManager::getInstance();
        $this->setTenant($manager, 7, ['id' => 7, 'is_active' => 1]);

        $filter->authorizeForTest($request, $manager);

        $this->assertTrue($called);
    }

    private function setTenant(TenantManager $manager, int $id, array $tenant): void
    {
        $reflection = new \ReflectionClass($manager);

        $tenantId = $reflection->getProperty('tenantId');
        $tenantId->setAccessible(true);
        $tenantId->setValue($manager, $id);

        $tenantProperty = $reflection->getProperty('tenant');
        $tenantProperty->setAccessible(true);
        $tenantProperty->setValue($manager, $tenant);
    }
}
