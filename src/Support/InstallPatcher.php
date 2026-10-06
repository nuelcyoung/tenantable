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

/** Pure file-content patchers for the install command. No filesystem side-effects. */
final class InstallPatcher
{
    /** Strategy name → filter alias. */
    public const STRATEGY_FILTER = [
        'subdomain'           => 'tenant_subdomain',
        'domain'              => 'tenant_domain',
        'domain_or_subdomain' => 'tenant_domain_or_subdomain',
        'path'                => 'tenant_path',
        'request_data'        => 'tenant_request',
    ];

    /** Aliases implying "tenant resolved". */
    private const TENANT_IDENTIFIERS = [
        'tenant',
        'identify_tenant',
        'tenant_subdomain',
        'tenant_domain',
        'tenant_domain_or_subdomain',
        'tenant_path',
        'tenant_request',
    ];

    /** Insert filters into the globals before/after arrays. Skips existing entries. */
    public static function patchFilters(string $source, string $identifyAlias): array
    {
        $beforeBounds = self::locateSubArray($source, 'globals', 'before');
        $afterBounds  = self::locateSubArray($source, 'globals', 'after');

        if ($beforeBounds === null || $afterBounds === null) {
            return ['source' => $source, 'added' => [], 'changed' => false, 'located' => false];
        }

        [$beforeStart, $beforeEnd] = $beforeBounds;
        [$afterStart,  $afterEnd]  = $afterBounds;

        $patched = $source;
        $added   = [];

        $beforeBody     = substr($patched, $beforeStart, $beforeEnd - $beforeStart);
        $hasIdentifier  = false;
        foreach (self::TENANT_IDENTIFIERS as $alias) {
            if (self::aliasInBlock($beforeBody, $alias)) {
                $hasIdentifier = true;
                break;
            }
        }
        $hasSecurityBefore = self::aliasInBlock($beforeBody, 'tenant_security');

        $insertBefore = '';
        if (! $hasIdentifier) {
            $insertBefore .= "            '{$identifyAlias}' => ['except' => ['health', '_health']],\n";
            $added[]       = "before: {$identifyAlias}";
        }
        if (! $hasSecurityBefore) {
            $insertBefore .= "            'tenant_security' => ['except' => ['health', '_health']],\n";
            $added[]       = 'before: tenant_security';
        }

        if ($insertBefore !== '') {
            $patched     = substr_replace($patched, $insertBefore, $beforeStart, 0);
            $shift       = strlen($insertBefore);
            $afterStart += $shift;
            $afterEnd   += $shift;
        }

        $afterBody = substr($patched, $afterStart, $afterEnd - $afterStart);
        if (! self::aliasInBlock($afterBody, 'tenant_security')) {
            $insertAfter = "            'tenant_security' => ['except' => ['health', '_health']],\n";
            $patched     = substr_replace($patched, $insertAfter, $afterStart, 0);
            $added[]     = 'after: tenant_security';
        }

        return [
            'source'  => $patched,
            'added'   => $added,
            'changed' => $patched !== $source,
            'located' => true,
        ];
    }

    /** Add the package events registration to Events.php. Idempotent. */
    public static function patchEvents(string $source): array
    {
        $patched = $source;
        $added   = [];

        if (! str_contains($patched, 'PackageEvents::register()')) {
            $patched = self::ensureUse($patched, 'nuelcyoung\\tenantable\\Bootstrap\\PackageEvents');
            $patched = self::appendBlock(
                $patched,
                "// Tenantable: register package-owned lifecycle hooks.\nPackageEvents::register();\n",
            );
            $added[] = 'PackageEvents::register()';
        }

        return [
            'source'  => $patched,
            'added'   => $added,
            'changed' => $patched !== $source,
        ];
    }

    /** Render published config from stub. */
    public static function renderConfig(
        string $stub,
        string $baseDomain,
        string $mode,
        string $strategy,
        bool $early,
        bool $shipSessions = false,
    ): string {
        return strtr($stub, [
            '{{base_domain}}'           => var_export($baseDomain, true),
            '{{isolation_mode}}'        => "'{$mode}'",
            '{{separate_db}}'           => $mode === 'database' ? 'true' : 'false',
            '{{identification_method}}' => self::STRATEGY_FILTER[$strategy] ?? 'tenant_subdomain',
            '{{strategy}}'              => $strategy,
            '{{early_detection}}'       => $early && in_array($strategy, ['subdomain', 'domain', 'domain_or_subdomain'], true)
                ? $strategy
                : 'off',
            '{{ship_sessions}}'         => $shipSessions ? 'true' : 'false',
        ]);
    }

    /** Find body bounds of a keyed sub-array within a public array property. */
    private static function locateSubArray(string $source, string $prop, string $key): ?array
    {
        $propPattern = '~public\s+array\s+\$' . preg_quote($prop, '~') . '\s*=\s*\[~';
        if (preg_match($propPattern, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $propOpen = $m[0][1] + strlen($m[0][0]);
        $propEnd  = self::matchingBracket($source, $propOpen - 1);
        if ($propEnd === null) {
            return null;
        }

        $body       = substr($source, $propOpen, $propEnd - $propOpen);
        $keyPattern = "~'" . preg_quote($key, '~') . "'\s*=>\s*\[~";
        if (preg_match($keyPattern, $body, $km, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $keyOpen  = $propOpen + $km[0][1] + strlen($km[0][0]);
        $keyClose = self::matchingBracket($source, $keyOpen - 1);
        if ($keyClose === null) {
            return null;
        }

        return [$keyOpen, $keyClose];
    }

    private static function matchingBracket(string $s, int $openIdx): ?int
    {
        if (! isset($s[$openIdx]) || $s[$openIdx] !== '[') {
            return null;
        }
        $depth = 0;
        $len   = strlen($s);
        for ($i = $openIdx; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    private static function aliasInBlock(string $block, string $alias): bool
    {
        $escaped = preg_quote($alias, '~');
        return preg_match("~['\"]{$escaped}['\"]\\s*(=>|,|\\s*\\])~", $block) === 1;
    }

    private static function ensureUse(string $source, string $fqcn): string
    {
        $needle = "use {$fqcn};";
        if (str_contains($source, $needle)) {
            return $source;
        }

        if (preg_match_all('~^use\s+[^;]+;~m', $source, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[0]);
            $pos  = $last[1] + strlen($last[0]);
            return substr_replace($source, "\n{$needle}", $pos, 0);
        }

        if (preg_match('~^namespace\s+[^;]+;~m', $source, $m, PREG_OFFSET_CAPTURE) === 1) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr_replace($source, "\n\n{$needle}", $pos, 0);
        }

        return preg_replace('~<\?php~', "<?php\n\n{$needle}", $source, 1) ?? $source;
    }

    private static function appendBlock(string $source, string $block): string
    {
        return rtrim($source) . "\n\n" . $block;
    }
}
