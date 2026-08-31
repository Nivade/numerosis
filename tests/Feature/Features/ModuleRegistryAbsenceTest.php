<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * `internachi/modular` is `suggest`, and a `suggest` entry is a promise that
 * the package degrades cleanly without it. A Blade tag rendering as literal
 * text is the counter-example this repo already records
 * (`.claude/rules/testing.md`): "no error" is not the same as "clean
 * degradation", and a test that only ever runs with the package installed
 * proves nothing either way.
 *
 * So this drives a subprocess with Composer's autoloader wrapped to refuse
 * every `InterNACHI\*` class — the only way to simulate absence, since
 * `class_exists()` answers `true` for an already-declared class regardless of
 * what the autoloader would do. See tests/Support/module-registry-absence-probe.php.
 *
 * The other half of "unreachable rather than fatal" — that the marketplace,
 * the module detail page and the module resource all refuse access once
 * `ModuleSystemFeature::available()` is false — is asserted in-process by
 * tests/Feature/Actions/Modules/ModuleFeatureSwitchesTest.php.
 */
class ModuleRegistryAbsenceTest extends BaseTestCase
{
    /**
     * @return array{modular_installed: bool, module_system_available: bool, loaded: array<string, bool|string>}
     */
    private function probe(bool $withModular): array
    {
        $script = dirname(__DIR__, 2).'/Support/module-registry-absence-probe.php';

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);

        if (! $withModular) {
            $command .= ' --without-modular';
        }

        $output = shell_exec($command.' 2>&1');

        $this->assertIsString($output, 'The probe subprocess produced no output at all.');

        /** @var array{modular_installed: bool, module_system_available: bool, loaded: array<string, bool|string>}|null $decoded */
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, "The probe subprocess did not print JSON, which means it fataled:\n{$output}");

        return $decoded;
    }

    /**
     * The positive control. Without it the absence assertions below would pass
     * just as well against a probe that never resolved anything.
     */
    public function test_the_probe_sees_the_module_registry_when_it_is_installed(): void
    {
        $result = $this->probe(withModular: true);

        $this->assertTrue($result['modular_installed']);
        $this->assertTrue($result['module_system_available']);
    }

    public function test_the_module_system_reports_itself_unavailable_without_the_registry(): void
    {
        $result = $this->probe(withModular: false);

        $this->assertFalse($result['modular_installed'], 'The autoloader filter did not take effect, so this test measured nothing.');
        $this->assertFalse(
            $result['module_system_available'],
            'ModuleSystemFeature::available() answered true with internachi/modular unloadable — every guarded call site downstream is then a fatal.'
        );
    }

    /**
     * A bare `use` import is lazy; `extends` / `implements` / `use <Trait>`
     * and a class-constant fetch are not (`.claude/rules/optional-dependencies.md`,
     * `.claude/rules/package-split.md`). Nothing that names the registry may
     * cross that line, or the guard above never gets a chance to run.
     */
    public function test_every_class_that_names_the_registry_still_autoloads_without_it(): void
    {
        $result = $this->probe(withModular: false);

        $this->assertNotEmpty($result['loaded'], 'The probe checked no classes, so this guard is measuring nothing.');

        foreach ($result['loaded'] as $class => $outcome) {
            $this->assertTrue(
                $outcome,
                is_string($outcome)
                    ? "{$class} could not be autoloaded without internachi/modular: {$outcome}"
                    : "{$class} could not be autoloaded without internachi/modular."
            );
        }
    }
}
