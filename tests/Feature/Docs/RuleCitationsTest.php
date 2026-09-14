<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Docs;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Finder\Finder;

/**
 * A `path.php:NNN` citation in a rule file is wrong within a commit or two,
 * and a reader who seeks to that line lands on something unrelated rather than
 * on nothing — which is worse, because it reads as a real reference. Nine of
 * them had rotted at once by the time anyone checked. Name the enclosing
 * symbol instead; it survives a refactor.
 */
class RuleCitationsTest extends BaseTestCase
{
    public function test_no_rule_cites_a_line_number(): void
    {
        $files = iterator_to_array((new Finder)->files()->in(dirname(__DIR__, 3).'/.ai/rules')->name('*.md'), false);

        $this->assertNotEmpty($files, 'Scanned no rule files — the path above is wrong, so this guard is measuring nothing.');

        foreach ($files as $file) {
            preg_match_all('/[\w\/.-]+\.php:\d+/', $file->getContents(), $matches);

            $this->assertSame(
                [],
                $matches[0],
                '.ai/rules/'.$file->getFilename().' cites a line number: '.implode(', ', $matches[0]).'. Name the enclosing symbol instead.'
            );
        }
    }
}
