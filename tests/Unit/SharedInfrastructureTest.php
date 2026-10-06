<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Config\Tenantable;
use nuelcyoung\tenantable\Exceptions\UnsafeInfrastructureException;
use nuelcyoung\tenantable\Support\SharedInfrastructure;

/**
 * @covers \nuelcyoung\tenantable\Support\SharedInfrastructure
 * @covers \nuelcyoung\tenantable\Exceptions\UnsafeInfrastructureException
 */
class SharedInfrastructureTest extends TestCase
{
    private function sessionConfig(?string $driver): object
    {
        return new class ($driver) {
            public function __construct(public ?string $driver) {}
        };
    }

    private function cacheConfig(string $handler): object
    {
        return new class ($handler) {
            public function __construct(public string $handler) {}
        };
    }

    private function shared(): object
    {
        return $this->sessionConfig(\CodeIgniter\Session\Handlers\DatabaseHandler::class);
    }

    /** @param list<array{id: string, severity: string, title: string, detail: string, remedy: string}> $findings */
    private function ids(array $findings): array
    {
        return array_column($findings, 'id');
    }

    public function testFileSessionsAreCritical(): void
    {
        $findings = SharedInfrastructure::inspect(
            $this->sessionConfig(\CodeIgniter\Session\Handlers\FileHandler::class),
            $this->cacheConfig('redis'),
            new Tenantable()
        );

        $this->assertSame(['session-file-handler'], $this->ids($findings));
        $this->assertSame(SharedInfrastructure::CRITICAL, $findings[0]['severity']);
    }

    public function testAnUnsetSessionDriverCountsAsFileStorage(): void
    {
        // CI4's default handler is the file handler, so "not configured" is
        // just as unsafe as configuring it explicitly.
        $findings = SharedInfrastructure::inspect(
            $this->sessionConfig(null),
            $this->cacheConfig('redis'),
            new Tenantable()
        );

        $this->assertSame(['session-file-handler'], $this->ids($findings));
    }

    public function testFileCacheIsCritical(): void
    {
        $findings = SharedInfrastructure::inspect(
            $this->shared(),
            $this->cacheConfig('file'),
            new Tenantable()
        );

        $this->assertSame(['cache-file-handler'], $this->ids($findings));
        $this->assertSame(SharedInfrastructure::CRITICAL, $findings[0]['severity']);
    }

    public function testFileCacheFindingNamesTheResolverTtl(): void
    {
        $config                   = new Tenantable();
        $config->resolverCacheTtl = 900;

        $findings = SharedInfrastructure::inspect($this->shared(), $this->cacheConfig('file'), $config);

        $this->assertStringContainsString('900s', $findings[0]['detail']);
    }

    public function testDummyCacheIsOnlyAWarning(): void
    {
        $findings = SharedInfrastructure::inspect(
            $this->shared(),
            $this->cacheConfig('dummy'),
            new Tenantable()
        );

        $this->assertSame(['cache-dummy-handler'], $this->ids($findings));
        $this->assertSame(SharedInfrastructure::WARNING, $findings[0]['severity']);
        $this->assertSame([], SharedInfrastructure::critical($findings));
    }

    public function testTheProductionTrioReportsNothing(): void
    {
        $findings = SharedInfrastructure::inspect(
            $this->shared(),
            $this->cacheConfig('redis'),
            new Tenantable()
        );

        $this->assertSame([], $findings);
    }

    public function testCriticalsSortAheadOfWarnings(): void
    {
        $findings = SharedInfrastructure::inspect(
            $this->sessionConfig(\CodeIgniter\Session\Handlers\FileHandler::class),
            $this->cacheConfig('dummy'),
            new Tenantable()
        );

        $this->assertSame(
            [SharedInfrastructure::CRITICAL, SharedInfrastructure::WARNING],
            array_column($findings, 'severity')
        );
    }

    public function testAssertSafeIsANoOpWhileTheFlagIsOff(): void
    {
        $config = new Tenantable();
        $this->assertFalse($config->requireSharedInfrastructure, 'The flag must stay opt-in.');

        SharedInfrastructure::assertSafe($config);

        $this->addToAssertionCount(1);
    }

    public function testAssertSafeDoesNotFireOutsideProduction(): void
    {
        // The suite runs with ENVIRONMENT = 'testing' and a file-handler
        // session config, i.e. the unsafe combination, yet a developer's
        // machine must still boot.
        $this->assertFalse(SharedInfrastructure::isProduction());

        $config                              = new Tenantable();
        $config->requireSharedInfrastructure = true;

        SharedInfrastructure::assertSafe($config);

        $this->addToAssertionCount(1);
    }

    public function testAssertSafeThrowsInProductionWhenTheFlagIsOn(): void
    {
        // The harness session config has no driver, i.e. CI4's file handler.
        $config                              = new Tenantable();
        $config->requireSharedInfrastructure = true;

        $this->expectException(UnsafeInfrastructureException::class);
        $this->expectExceptionMessageMatches('/session-file-handler/');

        SharedInfrastructure::assertSafe($config, true);
    }

    public function testAssertSafeStaysQuietInProductionWhileTheFlagIsOff(): void
    {
        SharedInfrastructure::assertSafe(new Tenantable(), true);

        $this->addToAssertionCount(1);
    }

    public function testTheExceptionNamesEveryFindingAndItsFix(): void
    {
        $findings = SharedInfrastructure::inspect(
            $this->sessionConfig(\CodeIgniter\Session\Handlers\FileHandler::class),
            $this->cacheConfig('file'),
            new Tenantable()
        );

        $exception = UnsafeInfrastructureException::forFindings($findings);
        $message   = $exception->getMessage();

        $this->assertStringContainsString('session-file-handler', $message);
        $this->assertStringContainsString('cache-file-handler', $message);
        $this->assertStringContainsString('Fix:', $message);
        $this->assertStringContainsString('requireSharedInfrastructure', $message);
    }
}
