<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Assets;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `resources/views/partials/script-config.blade.php` is what makes
 * `resources/js/numerosis.js` prebuildable at all (see that file's own
 * docblock and `.claude/plans/archive/better-dx.md` Phase 3): the browser's Reverb
 * connection details and current tenant id have to come from PHP at
 * request time, not from `import.meta.env.VITE_REVERB_*` baked in at the
 * package maintainer's build time.
 */
class ScriptConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_reverb_config_and_a_null_tenant_id_centrally(): void
    {
        $data = $this->renderWindowNumerosis();

        $this->assertNull($data['reverb']['key']);
        $this->assertSame('localhost', $data['reverb']['host']);
        $this->assertSame(8080, $data['reverb']['port']);
        $this->assertSame('http', $data['reverb']['scheme']);
        $this->assertNull($data['tenantId']);
    }

    public function test_it_exposes_the_current_tenant_id_inside_tenant_context(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () use ($tenant) {
            $data = $this->renderWindowNumerosis();

            $this->assertSame((string) $tenant->getTenantKey(), $data['tenantId']);
        });
    }

    public function test_it_reflects_a_hosts_reverb_config_overrides(): void
    {
        Config::set('numerosis.broadcasting.reverb', [
            'key' => 'app-key',
            'host' => 'ws.example.test',
            'port' => 443,
            'scheme' => 'https',
        ]);

        $data = $this->renderWindowNumerosis();

        $this->assertSame('app-key', $data['reverb']['key']);
        $this->assertSame('ws.example.test', $data['reverb']['host']);
        $this->assertSame(443, $data['reverb']['port']);
        $this->assertSame('https', $data['reverb']['scheme']);
    }

    /**
     * `@js()` (Illuminate\Support\Js::from()) encodes as
     * `JSON.parse('{"key":...}')` — every character that could
     * end the JS string literal early (quotes, backslashes, `<`) is
     * `\uXXXX`-escaped, safe against `</script>` injection but not
     * something a literal `assertStringContainsString('"key":null', ...)`
     * can match against. Decoding twice — once as the JS string literal
     * (turns `"` back into `"`), once as the JSON it then contains —
     * is what `JSON.parse()` does in the browser at runtime, so this tests
     * the real contract rather than the wire encoding.
     *
     * @return array{reverb: array{key: string|null, host: string, port: int, scheme: string}, tenantId: string|null}
     */
    private function renderWindowNumerosis(): array
    {
        $html = view()->file($this->scriptConfigViewPath())->render();

        preg_match("/JSON\.parse\('(.+?)'\)/s", $html, $matches);

        $this->assertNotEmpty($matches, 'window.Numerosis assignment not found in rendered script-config partial.');

        $jsonString = json_decode('"'.$matches[1].'"', flags: JSON_THROW_ON_ERROR);

        $this->assertIsString($jsonString, 'Decoded JS string literal was not a string.');

        /** @var array{reverb: array{key: string|null, host: string, port: int, scheme: string}, tenantId: string|null} $data */
        $data = json_decode($jsonString, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * `view()->file($path)`, not `view('numerosis::partials.script-config')`:
     * Larastan's `view-string` type runs `View::exists($name)` against its
     * *own* bootstrap application to validate a namespaced view-name
     * literal, and that bootstrap does not resolve this package's
     * `numerosis::` namespace the same way the real Testbench application
     * under test does — even though the view genuinely exists and every
     * test above renders it successfully. `file()` takes a raw path, no
     * namespace resolution involved, so there is nothing for that check to
     * (incorrectly) reject.
     */
    private function scriptConfigViewPath(): string
    {
        return dirname(__DIR__, 3).'/resources/views/partials/script-config.blade.php';
    }
}
