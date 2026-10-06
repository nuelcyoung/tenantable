<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\CLI\CLI;
use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Traits\NormalizesCliOptions;

/**
 * CI4's CLI parser stores `--mode=prefix` as the literal key 'mode=prefix'
 * with a null value, invisible to CLI::getOption('mode'). The trait is what
 * makes the equals style, which every usage line advertises, work at all.
 *
 * @covers \nuelcyoung\tenantable\Traits\NormalizesCliOptions
 */
class NormalizesCliOptionsTest extends TestCase
{
    private object $host;

    protected function setUp(): void
    {
        parent::setUp();

        CLI::init();

        $this->host = new class {
            use NormalizesCliOptions;

            public function options(): array
            {
                return $this->cliOptions();
            }

            public function value(string $name): ?string
            {
                return $this->cliOption($name);
            }

            public function present(string $name): bool
            {
                return $this->hasCliOption($name);
            }
        };
    }

    protected function tearDown(): void
    {
        $this->setOptions([]);

        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function setOptions(array $options): void
    {
        $property = new \ReflectionProperty(CLI::class, 'options');
        $property->setAccessible(true);
        $property->setValue(null, $options);
    }

    public function testEqualsStyleValueIsVisible(): void
    {
        // What parseCommandLine() actually stores for `--mode=prefix`.
        $this->setOptions(['mode=prefix' => null]);

        $this->assertSame('prefix', $this->host->value('mode'));
        $this->assertTrue($this->host->present('mode'));
    }

    public function testSpaceStyleValueKeepsWorking(): void
    {
        $this->setOptions(['mode' => 'prefix']);

        $this->assertSame('prefix', $this->host->value('mode'));
    }

    public function testBareFlagIsPresentButValueless(): void
    {
        $this->setOptions(['force' => null]);

        $this->assertTrue($this->host->present('force'));
        $this->assertNull($this->host->value('force'));
    }

    public function testAbsentOptionIsNeitherPresentNorValued(): void
    {
        $this->setOptions([]);

        $this->assertFalse($this->host->present('mode'));
        $this->assertNull($this->host->value('mode'));
    }

    public function testEqualsStyleFlagCountsAsPresent(): void
    {
        // `--early-detection=1` must enable, not be mistaken for absence.
        $this->setOptions(['early-detection=1' => null]);

        $this->assertTrue($this->host->present('early-detection'));
        $this->assertSame('1', $this->host->value('early-detection'));
    }

    public function testMixedInvocationKeepsEveryShape(): void
    {
        $this->setOptions([
            'tenants=1,2' => null,
            'parallel'    => '4',
            'in-process'  => null,
        ]);

        $this->assertSame([
            'tenants'    => '1,2',
            'parallel'   => '4',
            'in-process' => null,
        ], $this->host->options());
    }

    public function testValueIsSplitOnTheFirstEqualsOnly(): void
    {
        // A filter expression like --where=a=b must survive intact.
        $this->setOptions(['where=a=b' => null]);

        $this->assertSame('a=b', $this->host->value('where'));
    }
}
