<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Config\Tenantable;
use nuelcyoung\tenantable\Support\Diagnostics;
use nuelcyoung\tenantable\Support\SharedInfrastructure;

/**
 * The doctor is meant to be a deploy gate, so what it calls critical decides
 * whether a pipeline stops.
 *
 * @covers \nuelcyoung\tenantable\Support\Diagnostics
 */
class DiagnosticsTest extends TestCase
{
    /**
     * @param list<array{id: string, severity: string, title: string, detail: string, remedy: string}> $findings
     *
     * @return list<string>
     */
    private function ids(array $findings, ?string $severity = null): array
    {
        if ($severity !== null) {
            $findings = array_values(array_filter(
                $findings,
                static fn (array $finding): bool => $finding['severity'] === $severity
            ));
        }

        return array_column($findings, 'id');
    }

    public function testTheHarnessesOwnFileSessionsAreReportedCritical(): void
    {
        // The suite runs with no session driver configured, i.e. CI4's file
        // handler: the same combination a fresh app ships with.
        $findings = Diagnostics::run(new Tenantable());

        $this->assertContains('session-file-handler', $this->ids($findings, SharedInfrastructure::CRITICAL));
        $this->assertTrue(Diagnostics::hasCritical($findings));
    }

    public function testEveryFindingCarriesADetailAndAnId(): void
    {
        foreach (Diagnostics::run(new Tenantable()) as $finding) {
            $this->assertNotSame('', $finding['id']);
            $this->assertNotSame('', $finding['title']);
            $this->assertNotSame('', $finding['detail']);

            if ($finding['severity'] !== Diagnostics::OK) {
                $this->assertNotSame('', $finding['remedy'], "{$finding['id']} has no fix to offer.");
            }
        }
    }

    public function testCriticalsAreListedBeforeWarningsBeforePasses(): void
    {
        $rank = [SharedInfrastructure::CRITICAL => 0, SharedInfrastructure::WARNING => 1, Diagnostics::OK => 2];

        $previous = -1;

        foreach (Diagnostics::run(new Tenantable()) as $finding) {
            $current = $rank[$finding['severity']];
            $this->assertGreaterThanOrEqual($previous, $current, 'Findings are out of severity order.');
            $previous = $current;
        }
    }

    public function testWritablePathsArePartOfTheReport(): void
    {
        $this->assertContains('writable-paths', $this->ids(Diagnostics::run(new Tenantable())));
    }

    public function testTheFrameworkVersionIsPartOfTheReport(): void
    {
        $this->assertContains('framework-version', $this->ids(Diagnostics::run(new Tenantable())));
    }

    public function testProvisioningChecksOnlyRunWhenAsyncIsOn(): void
    {
        $sync = new Tenantable();
        $this->assertFalse($sync->provisionAsync);

        $ids = $this->ids(Diagnostics::run($sync));

        $this->assertNotContains('async-provisioning-without-queue', $ids);
        $this->assertNotContains('provision-job-not-registered', $ids);
    }

    public function testAsyncProvisioningWithoutAQueueIsCritical(): void
    {
        // Silently falling back to inline provisioning is the exact slow
        // signup the setting was turned on to avoid.
        $config                 = new Tenantable();
        $config->provisionAsync = true;

        $this->assertContains(
            'async-provisioning-without-queue',
            $this->ids(Diagnostics::run($config), SharedInfrastructure::CRITICAL)
        );
    }

    public function testPrefixScaleIsOnlyCheckedInPrefixMode(): void
    {
        $row                = new Tenantable();
        $row->isolationMode = 'row';

        $this->assertNotContains('prefix-mode-tenant-count', $this->ids(Diagnostics::run($row)));
    }

    public function testTheThresholdIsConfigurable(): void
    {
        $this->assertSame(200, (new Tenantable())->prefixTenantWarningThreshold);
    }

    public function testHasCriticalIgnoresWarningsAndPasses(): void
    {
        $findings = [
            ['id' => 'a', 'severity' => SharedInfrastructure::WARNING, 'title' => 't', 'detail' => 'd', 'remedy' => 'r'],
            ['id' => 'b', 'severity' => Diagnostics::OK, 'title' => 't', 'detail' => 'd', 'remedy' => ''],
        ];

        $this->assertFalse(Diagnostics::hasCritical($findings));
    }
}
