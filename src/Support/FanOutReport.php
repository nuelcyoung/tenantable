<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace nuelcyoung\tenantable\Support;

/**
 * The outcome of one `tenants:run` fan-out, written to disk: which tenants
 * failed, what they printed, and the ID to resume from.
 */
class FanOutReport
{
    /** Bytes of a failing tenant's output kept per entry. */
    public const OUTPUT_LIMIT = 4000;

    /** @var list<array{tenant_id: int, name: string, exit_code: int, output: string}> */
    private array $failures = [];

    private int $succeeded = 0;

    private string $startedAt;

    public function __construct(
        private string $command,
        private string $args,
        private string $mode,
        private int $parallel
    ) {
        $this->startedAt = date('c');
    }

    public function record(int $tenantId, string $name, int $exitCode, string $output = ''): void
    {
        if ($exitCode === 0) {
            $this->succeeded++;

            return;
        }

        $this->failures[] = [
            'tenant_id' => $tenantId,
            'name'      => $name,
            'exit_code' => $exitCode,
            // Truncated: full output per tenant would make the report unopenable.
            'output'    => mb_substr($output, 0, self::OUTPUT_LIMIT),
        ];
    }

    public function succeededCount(): int
    {
        return $this->succeeded;
    }

    public function failedCount(): int
    {
        return count($this->failures);
    }

    /** @return list<int> */
    public function failedIds(): array
    {
        return array_map(static fn (array $failure): int => $failure['tenant_id'], $this->failures);
    }

    /** The lowest failed tenant ID — what `--resume-from` should be given. */
    public function resumeFrom(): ?int
    {
        $ids = $this->failedIds();

        return $ids === [] ? null : min($ids);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'command'     => trim($this->command . ' ' . $this->args),
            'mode'        => $this->mode,
            'parallel'    => $this->parallel,
            'started_at'  => $this->startedAt,
            'finished_at' => date('c'),
            'succeeded'   => $this->succeeded,
            'failed'      => $this->failedCount(),
            'resume_from' => $this->resumeFrom(),
            'failures'    => $this->failures,
        ];
    }

    /**
     * Write the report, returning its path. Null means it could not be
     * written; a fan-out must not fail because the report could not be saved.
     */
    public function write(?string $path = null): ?string
    {
        $path ??= self::defaultPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return null;
        }

        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($path, $json) === false) {
            return null;
        }

        return $path;
    }

    public static function defaultPath(): string
    {
        return WRITEPATH . 'tenantable' . DIRECTORY_SEPARATOR . 'last_run.json';
    }
}
