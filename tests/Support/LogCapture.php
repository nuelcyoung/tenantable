<?php

declare(strict_types=1);

namespace nuelcyoung\tenantable\Tests\Support;

/**
 * Records log_message() calls so tests can assert on them.
 *
 * Several of this package's guarantees are "degrade, but say so loudly":
 * Redis database exhaustion, unsafe multi-node infrastructure, a tenant that
 * cannot be provisioned. A silent log stub lets those regressions pass
 * unnoticed, so the test bootstrap routes log_message() here instead.
 *
 * Capture is off by default: only tests that call start() pay for it.
 */
final class LogCapture
{
    private static bool $capturing = false;

    /** @var list<array{level: string, message: string}> */
    private static array $entries = [];

    public static function start(): void
    {
        self::$capturing = true;
        self::$entries   = [];
    }

    public static function stop(): void
    {
        self::$capturing = false;
        self::$entries   = [];
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function record(string $level, string $message, array $context = []): void
    {
        if (! self::$capturing) {
            return;
        }

        self::$entries[] = [
            'level'   => strtolower($level),
            'message' => self::interpolate($message, $context),
        ];
    }

    /**
     * Substitute {key} placeholders from the context, as CI4's Logger does,
     * so assertions see the message an operator would actually read.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $replace = [];

        foreach ($context as $key => $value) {
            if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @return list<array{level: string, message: string}>
     */
    public static function entries(?string $level = null): array
    {
        if ($level === null) {
            return self::$entries;
        }

        $level = strtolower($level);

        return array_values(array_filter(
            self::$entries,
            static fn (array $entry): bool => $entry['level'] === $level
        ));
    }

    /** True when any captured message at $level contains $needle. */
    public static function has(string $level, string $needle): bool
    {
        foreach (self::entries($level) as $entry) {
            if (str_contains($entry['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
