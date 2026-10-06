<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Config\Tenantable;
use nuelcyoung\tenantable\Exceptions\TenantInactiveException;
use nuelcyoung\tenantable\Exceptions\TenantNotReadyException;
use nuelcyoung\tenantable\Jobs\ProvisionTenantJob;
use nuelcyoung\tenantable\Models\TenantModel;
use nuelcyoung\tenantable\Services\TenantManager;
use nuelcyoung\tenantable\Services\TenantableQueue;

/**
 * Async provisioning has one job: a tenant row must not be servable before
 * the storage behind it exists.
 *
 * @covers \nuelcyoung\tenantable\Services\TenantManager
 * @covers \nuelcyoung\tenantable\Exceptions\TenantNotReadyException
 * @covers \nuelcyoung\tenantable\Jobs\ProvisionTenantJob
 */
class AsyncProvisioningTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function tenantRow(array $overrides = []): array
    {
        return $overrides + [
            'id'        => 5,
            'subdomain' => 'acme',
            'name'      => 'Acme',
            'is_active' => 1,
        ];
    }

    public function testAReadyTenantResolves(): void
    {
        TenantManager::getInstance()->setTenant($this->tenantRow(['status' => TenantModel::STATUS_READY]));

        $this->assertSame(5, TenantManager::getInstance()->getTenantId());
    }

    public function testARowWithNoStatusColumnResolves(): void
    {
        // Installations that never ran the status migration must be unaffected.
        TenantManager::getInstance()->setTenant($this->tenantRow());

        $this->assertSame(5, TenantManager::getInstance()->getTenantId());
    }

    public function testAProvisioningTenantIsRejected(): void
    {
        $this->expectException(TenantNotReadyException::class);
        $this->expectExceptionMessageMatches('/still being provisioned/');

        TenantManager::getInstance()->setTenant(
            $this->tenantRow(['status' => TenantModel::STATUS_PROVISIONING])
        );
    }

    public function testAFailedTenantIsRejectedWithADifferentMessage(): void
    {
        $this->expectException(TenantNotReadyException::class);
        $this->expectExceptionMessageMatches('/failed to provision/');

        TenantManager::getInstance()->setTenant(
            $this->tenantRow(['status' => TenantModel::STATUS_FAILED])
        );
    }

    public function testARejectedTenantLeavesNoContextBehind(): void
    {
        try {
            TenantManager::getInstance()->setTenant(
                $this->tenantRow(['status' => TenantModel::STATUS_PROVISIONING])
            );
        } catch (TenantNotReadyException $e) {
            // expected
        }

        $this->assertNull(TenantManager::getInstance()->getTenantId());
        $this->assertFalse(TenantManager::getInstance()->hasTenant());
    }

    public function testNotReadyIsCaughtByExistingInactiveHandlers(): void
    {
        // Apps already render a page for TenantInactiveException; extending it
        // keeps them working without a code change.
        $this->assertInstanceOf(
            TenantInactiveException::class,
            TenantNotReadyException::forStatus(5, TenantModel::STATUS_PROVISIONING)
        );
    }

    public function testInactiveStillWinsOverStatus(): void
    {
        $this->expectException(TenantInactiveException::class);
        $this->expectExceptionMessageMatches('/inactive/');

        TenantManager::getInstance()->setTenant(
            $this->tenantRow(['is_active' => 0, 'status' => TenantModel::STATUS_READY])
        );
    }

    public function testAsyncProvisioningIsOptIn(): void
    {
        $this->assertFalse((new Tenantable())->provisionAsync);
    }

    public function testTheProvisioningJobIsPushedCentrally(): void
    {
        // Booting the tenant first would mean connecting to the database this
        // job exists to create, so the job must carry no tenant stamp.
        $payload = ['tenant_id' => 5];

        $this->assertNull(TenantableQueue::tenantId(TenantableQueue::strip($payload)));
        $this->assertSame('tenantable', ProvisionTenantJob::QUEUE);
        $this->assertSame('tenantable:provision', ProvisionTenantJob::NAME);
    }

    public function testTheJobRejectsAPayloadWithoutATenant(): void
    {
        $job = new ProvisionTenantJob([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tenant_id/');

        $job->process();
    }

    public function testMarkStatusRejectsAnUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown tenant status/');

        (new TenantModel())->markStatus(1, 'halfway');
    }
}
