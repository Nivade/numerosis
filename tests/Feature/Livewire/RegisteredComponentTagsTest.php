<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire;

use Illuminate\View\FileViewFinder;
use Livewire\Livewire;
use Nvade\Numerosis\Tests\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Convention-registration audit (.claude/plans/package-extraction.md, step
 * 2): Livewire's default component discovery scans the *host* application's
 * `app/Livewire` namespace, which never has this package's classes. Every
 * `<livewire:name />` tag with no explicit `namespace::` prefix therefore
 * needs a matching `Livewire::addComponent()` call — `settings.delete-user-
 * form` shipped without one and silently resolved to a host class that
 * never existed. This test re-derives the tag list from the views on disk
 * so a future tag can't repeat the gap unnoticed.
 *
 * Namespaced tags (`layouts::header`) are skipped: those resolve through a
 * `livewire.component_namespaces`-style host config key, not through
 * `Livewire::addComponent()`, and are covered by
 * `docs/host-requirements.md` instead.
 */
class RegisteredComponentTagsTest extends TestCase
{
    public function test_every_unnamespaced_component_tag_is_registered(): void
    {
        // Every path registered under the `numerosis::` namespace, not just
        // this package's own — nvade/numerosis-ui serves the same namespace,
        // and a hardcoded path would quietly stop scanning what moved there.
        $finder = view()->getFinder();

        // ViewFinderInterface declares no getHints(); FileViewFinder is what
        // is bound, and is the only implementation that can answer this.
        throw_unless($finder instanceof FileViewFinder, 'Expected a FileViewFinder.');

        /** @var array<string, list<string>> $hints */
        $hints = $finder->getHints();
        $viewsPaths = array_values(array_filter($hints['numerosis'] ?? [], is_dir(...)));

        $names = [];

        foreach (Finder::create()->files()->in($viewsPaths)->name('*.blade.php') as $file) {
            if (preg_match_all('/<livewire:([a-zA-Z0-9_.:\-]+)/', $file->getContents(), $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $name) {
                if (! str_contains($name, '::')) {
                    $names[$name] = true;
                }
            }
        }

        $this->assertNotEmpty($names, 'expected to find at least one <livewire:…> tag under the numerosis:: view namespace');

        foreach (array_keys($names) as $name) {
            $this->assertTrue(
                Livewire::exists($name),
                "<livewire:{$name} /> has no matching Livewire::addComponent() registration — see NumerosisServiceProvider::packageBooted()."
            );
        }
    }
}
