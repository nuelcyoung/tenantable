<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\CLI\CLI;
use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Commands\TenantsDoctor;

/**
 * The command exists to fail a pipeline, so its exit code is the contract.
 *
 * @covers \nuelcyoung\tenantable\Commands\TenantsDoctor
 */
class TenantsDoctorTest extends TestCase
{
    private TenantsDoctor $command;

    protected function setUp(): void
    {
        parent::setUp();

        CLI::init();
        $this->silenceCliOutput();

        $this->command = (new \ReflectionClass(TenantsDoctor::class))->newInstanceWithoutConstructor();
        $this->setOptions([]);
    }

    protected function tearDown(): void
    {
        $this->setOptions([]);

        parent::tearDown();
    }

    private function silenceCliOutput(): void
    {
        $io = new class extends \CodeIgniter\CLI\InputOutput {
            public string $written = '';

            public function fwrite($handle, string $string): void
            {
                $this->written .= $string;
            }
        };

        $property = new \ReflectionProperty(CLI::class, 'io');
        $property->setAccessible(true);
        $property->setValue(null, $io);
    }

    private function written(): string
    {
        $property = new \ReflectionProperty(CLI::class, 'io');
        $property->setAccessible(true);

        return $property->getValue()->written ?? '';
    }

    /** @param array<string, mixed> $options */
    private function setOptions(array $options): void
    {
        $property = new \ReflectionProperty(CLI::class, 'options');
        $property->setAccessible(true);
        $property->setValue(null, $options);
    }

    public function testItExitsNonZeroOnCriticalFindings(): void
    {
        // The harness has no session driver configured, i.e. CI4's file
        // handler: a critical finding, and what a fresh app ships with.
        $this->assertSame(1, $this->command->run([]));
    }

    public function testTheReportNamesTheFindingsAndTheirFixes(): void
    {
        $this->command->run([]);
        $output = $this->written();

        $this->assertStringContainsString('Tenantable Doctor', $output);
        $this->assertStringContainsString('Sessions are stored on local disk', $output);
        $this->assertStringContainsString('Fix:', $output);
    }

    public function testJsonOutputIsMachineReadable(): void
    {
        $this->setOptions(['json' => true]);
        $this->command->run([]);

        $decoded = json_decode(trim($this->written()), true);

        $this->assertIsArray($decoded, 'The --json report must parse.');
        $this->assertArrayHasKey('critical', $decoded);
        $this->assertArrayHasKey('warning', $decoded);
        $this->assertNotSame([], $decoded['findings']);
        $this->assertArrayHasKey('severity', $decoded['findings'][0]);
    }
}
