<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Tenancy;

use Illuminate\Support\Facades\Config;

/**
 * The only place this package (or a consuming test) should name one of the
 * 4 `tenancy.*` keys that moved between v3 and dev-master. A moved key is
 * invisible to autoloading and to PHPStan — it surfaces as `null` far from
 * the read — so routing every read *and* write through here is what keeps
 * a rename from silently reintroducing that gap. See
 * `.claude/rules/stancl-tenancy-v4.md` for the moved-key table.
 *
 * `tenancy.central_user_model`/`tenancy.tenant_user_model` are NOT here —
 * they are this package's own keys (`HostConfig.php`), present in no
 * stancl config stub on either version, and unaffected by any of this.
 */
final class TenancyConfigKeys
{
    /**
     * v3 leaf name (directly under `tenancy.`) => dev-master
     * [parent segment under `tenancy.`, leaf name under that parent].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const MOVED = [
        'central_domains' => ['identification', 'central_domains'],
        'tenant_model' => ['models', 'tenant'],
        'domain_model' => ['models', 'domain'],
        'id_generator' => ['models', 'id_generator'],
    ];

    /** The dotted key to read for the given v3 leaf name, on whichever version is installed. */
    public static function key(string $v3Leaf): string
    {
        if (! TenancyVersion::isDevMaster()) {
            return "tenancy.{$v3Leaf}";
        }

        [$parent, $leaf] = self::MOVED[$v3Leaf];

        return "tenancy.{$parent}.{$leaf}";
    }

    /**
     * Writes the given v3 leaf name's value on whichever version is
     * installed. On dev-master this is a read-modify-write of the whole
     * parent array (`tenancy.identification`/`tenancy.models`), never a
     * multi-segment dotted `Config::set()` — that segment is a sub-array
     * stancl's own `mergeConfigFrom()` also populates, and `Arr::set()`
     * auto-vivifies (replaces wholesale) a non-array intermediate segment,
     * which is exactly how `tenancy.database` lost its `prefix`/`suffix`/
     * `managers` once (`.claude/rules/package-host-bootstrap.md`).
     */
    public static function set(string $v3Leaf, mixed $value): void
    {
        if (! TenancyVersion::isDevMaster()) {
            Config::set("tenancy.{$v3Leaf}", $value);

            return;
        }

        [$parent, $leaf] = self::MOVED[$v3Leaf];
        $parentKey = "tenancy.{$parent}";

        $current = Config::array($parentKey, []);
        $current[$leaf] = $value;

        Config::set($parentKey, $current);
    }
}
