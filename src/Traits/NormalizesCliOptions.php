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

namespace nuelcyoung\tenantable\Traits;

use CodeIgniter\CLI\CLI;

/**
 * Repairs what CI4's CLI parser leaves broken: `--name=value` is never split,
 * so the flag lands in the option store as a literal 'name=value' key. The
 * commands read options through this trait instead, which accepts both
 * `--mode prefix` and `--mode=prefix`. A flag carries null, so presence is an
 * array_key_exists() question; use hasCliOption() for flags.
 */
trait NormalizesCliOptions
{
    /**
     * All options of this invocation, equals-style keys split into name and
     * value. Later occurrences win, matching CI4's last-one-wins store.
     *
     * @return array<string, string|null>
     */
    protected function cliOptions(): array
    {
        return self::normalizeOptions(CLI::getOptions());
    }

    /**
     * The value of one option, or null when it is absent or a bare flag.
     */
    protected function cliOption(string $name): ?string
    {
        $value = $this->cliOptions()[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Whether the option was given at all; the correct check for flags,
     * which carry a null value.
     */
    protected function hasCliOption(string $name): bool
    {
        return array_key_exists($name, $this->cliOptions());
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, string|null>
     */
    protected static function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $name => $value) {
            [$key, $inlineValue] = self::splitOption((string) $name);

            $normalized[$key] = $inlineValue ?? (is_string($value) ? $value : null);
        }

        return $normalized;
    }

    /**
     * Split an option key on the first '='.
     *
     * @return array{0: string, 1: string|null}
     */
    protected static function splitOption(string $key): array
    {
        $position = strpos($key, '=');

        if ($position === false) {
            return [$key, null];
        }

        return [substr($key, 0, $position), substr($key, $position + 1)];
    }
}
