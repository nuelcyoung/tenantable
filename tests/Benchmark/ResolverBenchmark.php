<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Services\TenantResolverCache;

/**
 * Benchmark for resolver cache performance.
 *
 * Run with PHPUnit directly from the vendor binary.
 */
class ResolverBenchmark extends TestCase
{
    public function testColdResolutionPerformance(): void
    {
        $this->markTestSkipped('Requires database connection for realistic benchmark');
    }

    public function testWarmResolutionPerformance(): void
    {
        $this->markTestSkipped('Requires database connection for realistic benchmark');
    }

    public function testBenchmarkResultsWritten(): void
    {
        $resultsPath = __DIR__ . '/RESULTS.md';
        $this->assertFileExists($resultsPath, 'Benchmark results should be committed to RESULTS.md');
    }
}
