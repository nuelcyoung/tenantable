<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use nuelcyoung\tenantable\Commands\TenantsRun;
use nuelcyoung\tenantable\Support\FanOutReport;

/**
 * @covers \nuelcyoung\tenantable\Support\FanOutReport
 */
class FanOutReportTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = WRITEPATH . 'tenantable' . DIRECTORY_SEPARATOR . 'fanout_test.json';
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }

        parent::tearDown();
    }

    private function report(): FanOutReport
    {
        return new FanOutReport('migrate', '', 'database', 4);
    }

    public function testCountsSuccessesAndFailuresSeparately(): void
    {
        $report = $this->report();
        $report->record(1, 'One', 0);
        $report->record(2, 'Two', 1, 'boom');
        $report->record(3, 'Three', 0);

        $this->assertSame(2, $report->succeededCount());
        $this->assertSame(1, $report->failedCount());
    }

    public function testOneFailureDoesNotStopTheRestBeingRecorded(): void
    {
        // The point of the report: a failing tenant must not abort the fan-out.
        $report = $this->report();
        $report->record(1, 'One', 1, 'boom');
        $report->record(2, 'Two', 0);

        $this->assertSame([1], $report->failedIds());
        $this->assertSame(1, $report->succeededCount());
    }

    public function testResumeFromIsTheLowestFailedId(): void
    {
        $report = $this->report();
        $report->record(9, 'Nine', 1);
        $report->record(4, 'Four', 1);
        $report->record(7, 'Seven', 0);

        $this->assertSame(4, $report->resumeFrom());
    }

    public function testResumeFromIsNullWhenEverythingPassed(): void
    {
        $report = $this->report();
        $report->record(1, 'One', 0);

        $this->assertNull($report->resumeFrom());
    }

    public function testFailureOutputIsTruncated(): void
    {
        // A stack trace per tenant across a large failure would produce a
        // report nobody can open.
        $report = $this->report();
        $report->record(1, 'One', 1, str_repeat('x', FanOutReport::OUTPUT_LIMIT + 500));

        $written = $report->toArray()['failures'][0]['output'];

        $this->assertSame(FanOutReport::OUTPUT_LIMIT, strlen($written));
    }

    public function testTheWrittenFileNamesTheCommandAndEveryFailure(): void
    {
        $report = new FanOutReport('migrate', "'--all'", 'prefix', 8);
        $report->record(1, 'One', 0);
        $report->record(5, 'Five', 2, 'migration failed');

        $path = $report->write($this->path);

        $this->assertSame($this->path, $path);
        $this->assertFileExists($this->path);

        $decoded = json_decode((string) file_get_contents($this->path), true);

        $this->assertSame("migrate '--all'", $decoded['command']);
        $this->assertSame('prefix', $decoded['mode']);
        $this->assertSame(8, $decoded['parallel']);
        $this->assertSame(1, $decoded['succeeded']);
        $this->assertSame(1, $decoded['failed']);
        $this->assertSame(5, $decoded['resume_from']);
        $this->assertSame(5, $decoded['failures'][0]['tenant_id']);
        $this->assertSame('migration failed', $decoded['failures'][0]['output']);
    }

    public function testTheDefaultPathIsUnderWritable(): void
    {
        $this->assertStringStartsWith(WRITEPATH, FanOutReport::defaultPath());
        $this->assertStringEndsWith('last_run.json', FanOutReport::defaultPath());
    }

    public function testWritingToAnImpossiblePathReportsFailureInsteadOfThrowing(): void
    {
        // A fan-out must not be reported as failed because its report could
        // not be saved.
        $report = $this->report();
        $report->record(1, 'One', 0);

        // A regular file where the report's directory would have to be.
        $blocker = WRITEPATH . 'tenantable_not_a_directory';
        file_put_contents($blocker, 'x');

        try {
            $this->assertNull(@$report->write($blocker . DIRECTORY_SEPARATOR . 'last_run.json'));
        } finally {
            @unlink($blocker);
        }
    }

    public function testInProcessAllowListStaysNarrow(): void
    {
        // Commands that cache, write fixed-name files, or call exit() would
        // carry state between tenants, so the list is kept short.
        foreach (TenantsRun::IN_PROCESS_ALLOWED as $command) {
            $this->assertMatchesRegularExpression('/^(migrate|db:seed)/', $command);
        }

        $this->assertNotContains('cache:clear', TenantsRun::IN_PROCESS_ALLOWED);
    }
}
