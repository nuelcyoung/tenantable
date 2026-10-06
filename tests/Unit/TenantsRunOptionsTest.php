<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use CodeIgniter\CLI\CLI;
use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Commands\TenantsRun;

/**
 * The fan-out flags decide how many processes get spawned and where a rerun
 * starts, so bad input has to be rejected rather than coerced.
 *
 * @covers \nuelcyoung\tenantable\Commands\TenantsRun
 */
class TenantsRunOptionsTest extends TestCase
{
    private TenantsRun $command;

    protected function setUp(): void
    {
        parent::setUp();

        // CLI::error() writes through CLI::$io, which only exists after init().
        CLI::init();

        // Its errors go straight to STDERR, which output buffering cannot
        // reach; swap the writer so a passing suite stays quiet.
        $this->silenceCliOutput();

        // BaseCommand's constructor wants a logger and the command registry,
        // neither of which these option parsers touch.
        $this->command = (new \ReflectionClass(TenantsRun::class))->newInstanceWithoutConstructor();
    }

    private function silenceCliOutput(): void
    {
        $io = new class extends \CodeIgniter\CLI\InputOutput {
            public function fwrite($handle, string $string): void
            {
            }
        };

        $property = new \ReflectionProperty(CLI::class, 'io');
        $property->setAccessible(true);
        $property->setValue(null, $io);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(TenantsRun::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->command, ...$args);
    }

    public function testParallelDefaultsToOne(): void
    {
        $this->assertSame(1, $this->call('resolveParallel', []));
    }

    public function testParallelAcceptsAWholeNumber(): void
    {
        $this->assertSame(8, $this->call('resolveParallel', ['parallel' => '8']));
    }

    public function testParallelRejectsNonNumbers(): void
    {
        $this->assertNull($this->call('resolveParallel', ['parallel' => 'lots']), 'A bad value must abort, not default to 1.');
    }

    public function testParallelRejectsZeroAndNegatives(): void
    {
        $this->assertNull($this->call('resolveParallel', ['parallel' => '0']));
        $this->assertNull($this->call('resolveParallel', ['parallel' => '-4']));
    }

    public function testParallelIsCapped(): void
    {
        // Past the cap the database is the bottleneck, and an unbounded value
        // would fork a process per tenant.
        $this->assertNull($this->call('resolveParallel', ['parallel' => (string) (TenantsRun::MAX_PARALLEL + 1)]));

        $this->assertSame(TenantsRun::MAX_PARALLEL, $this->call('resolveParallel', ['parallel' => (string) TenantsRun::MAX_PARALLEL]));
    }

    public function testAValuelessParallelFlagMeansSequential(): void
    {
        $this->assertSame(1, $this->call('resolveParallel', ['parallel' => null]));
    }

    public function testResumeFromDefaultsToNull(): void
    {
        $this->assertNull($this->call('resolveResumeFrom', []));
    }

    public function testResumeFromParsesAnId(): void
    {
        $this->assertSame(42, $this->call('resolveResumeFrom', ['resume-from' => '42']));
    }

    public function testResumeFromNeverGoesNegative(): void
    {
        $this->assertSame(0, $this->call('resolveResumeFrom', ['resume-from' => '-5']));
    }

    /**
     * @dataProvider unsafeCommandNames
     */
    public function testCommandNameValidationIsUnchanged(string $command, bool $valid): void
    {
        $reflection = new \ReflectionMethod(TenantsRun::class, 'isValidCommandName');
        $reflection->setAccessible(true);

        $this->assertSame($valid, $reflection->invoke($this->command, $command));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function unsafeCommandNames(): array
    {
        return [
            'plain'          => ['migrate', true],
            'namespaced'     => ['db:seed', true],
            'dotted'         => ['cache.clear', true],
            'empty'          => ['', false],
            'semicolon'      => ['migrate; rm -rf /', false],
            'pipe'           => ['migrate|cat', false],
            'backtick'       => ['migrate`whoami`', false],
            'leading dash'   => ['-migrate', false],
            'space'          => ['migrate all', false],
        ];
    }

    /**
     * CI4 does not split `--name=value`: the whole token is the option key.
     * normalizeOptions() repairs that so both styles work.
     */
    public function testNormalizeOptionsSplitsEqualsStyleKeys(): void
    {
        $normalized = $this->call('normalizeOptions', [
            'tenants=1,2'  => null,
            'parallel=4'   => null,
            'in-process'   => null,
            'resume-from'  => '9',
        ]);

        $this->assertSame([
            'tenants'     => '1,2',
            'parallel'    => '4',
            'in-process'  => null,
            'resume-from' => '9',
        ], $normalized);
    }

    public function testNormalizeOptionsLetsTheLastOccurrenceWin(): void
    {
        $normalized = $this->call('normalizeOptions', [
            'tenants' => '1,2',
            'tenants' => '3',
        ]);

        $this->assertSame(['tenants' => '3'], $normalized);
    }

    /**
     * The regression this file guards: spark hands run() options as bare
     * name => value entries, so the old implode() dropped option names and
     * leaked tenants:run's own flag values as positional arguments.
     */
    public function testExtraArgsKeepOptionNames(): void
    {
        // What remains in $params after shifting 'tenants:run' and 'migrate'
        // for `php spark tenants:run migrate --all --tenants 1,2`:
        $params = ['all' => null, 'tenants' => '1,2'];

        $extraArgs = $this->call('buildExtraArgs', $params);

        $this->assertSame(escapeshellarg('--all'), $extraArgs);
    }

    public function testExtraArgsForwardNamedOptionsInSpaceStyle(): void
    {
        // CI4's parser understands `--name value` only, never `--name=value`,
        // so that is the shape the subprocess must receive.
        $params = ['g' => 'default', 'all' => null];

        $extraArgs = $this->call('buildExtraArgs', $params);

        $this->assertSame(
            escapeshellarg('--g') . ' ' . escapeshellarg('default') . ' ' . escapeshellarg('--all'),
            $extraArgs
        );
    }

    public function testExtraArgsSplitEqualsStyleKeysForTheTarget(): void
    {
        $params = ['namespace=App\Database\Migrations' => null];

        $extraArgs = $this->call('buildExtraArgs', $params);

        $this->assertSame(
            escapeshellarg('--namespace') . ' ' . escapeshellarg('App\Database\Migrations'),
            $extraArgs
        );
    }

    public function testExtraArgsDropThisCommandsOwnOptions(): void
    {
        $params = [
            'tenants'     => '1,2',
            'parallel=4'  => null,
            'in-process'  => null,
            'resume-from' => '9',
            'all'         => null,
        ];

        $extraArgs = $this->call('buildExtraArgs', $params);

        $this->assertSame(escapeshellarg('--all'), $extraArgs);
    }

    public function testExtraArgsKeepPositionalArguments(): void
    {
        $params = ['UserSeeder', 'tenants' => '5'];

        $extraArgs = $this->call('buildExtraArgs', $params);

        $this->assertSame(escapeshellarg('UserSeeder'), $extraArgs);
    }

    /**
     * End-to-end shape: what Console::run() actually builds for
     * `php spark tenants:run migrate --all --tenants=1,2` on CI4 >= 4.4.
     */
    public function testExtraArgsFromRealConsoleParams(): void
    {
        $params = array_merge(
            ['tenants:run', 'migrate'],
            ['all' => null, 'tenants=1,2' => null]
        );

        array_shift($params); // 'tenants:run'
        array_shift($params); // 'migrate'

        $extraArgs = $this->call('buildExtraArgs', $params);

        $this->assertSame(escapeshellarg('--all'), $extraArgs);
    }
}
