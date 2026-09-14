<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Assets;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Finder\Finder;

/**
 * `vite.config.js` builds one entry, `resources/js/numerosis.js`. A file
 * outside its import graph ships without ever being compiled, so it can name a
 * package `package.json` does not declare and nothing reports it until someone
 * adds it to an entry — `bootstrap.js` imported `axios` on those terms.
 */
class BuildGraphTest extends BaseTestCase
{
    public function test_every_javascript_source_is_reachable_from_the_build_entry(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js';

        $reachable = self::importsFrom($root.'/numerosis.js', $root);

        $files = iterator_to_array((new Finder)->files()->in($root)->name('*.js'), false);

        $this->assertNotEmpty($files, 'Scanned no javascript — the path above is wrong, so this guard is measuring nothing.');

        foreach ($files as $file) {
            $this->assertContains(
                $file->getPathname(),
                $reachable,
                'resources/js/'.$file->getFilename().' is in no entry\'s import graph, so it is never compiled.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function importsFrom(string $entry, string $root): array
    {
        $seen = [$entry];
        $queue = [$entry];

        while ($queue !== []) {
            $current = array_shift($queue);

            preg_match_all('/(?:from|import)\s+[\'"](\.[^\'"]+)[\'"]/', (string) file_get_contents($current), $matches);

            foreach ($matches[1] as $relative) {
                $path = realpath(dirname($current).'/'.$relative) ?: realpath($root.'/'.ltrim($relative, './').'.js');

                if (is_string($path) && ! in_array($path, $seen, true)) {
                    $seen[] = $path;
                    $queue[] = $path;
                }
            }
        }

        return $seen;
    }
}
