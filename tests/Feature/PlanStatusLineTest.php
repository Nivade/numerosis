<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `.claude/plans/README.md` keeps a hand-copied "Live" table of which loose
 * plan files are still open, re-derived from each plan's own status text —
 * but a plan with no status text at all has nothing to re-derive from, so it
 * silently reads as still-live forever. `domain-events-expansion.md`,
 * `invitations-social-redesign.md`, `comment-destyle.md`,
 * `drifting-puzzling-flame.md` and `effervescent-questing-pumpkin.md` were
 * all fully executed and still listed live in the README until the
 * 2026-09-12 re-audit; `delightful-doodling-turing.md` had no status marker
 * at all and turned out to be a superseded draft.
 *
 * This does not replace the re-audit (a status line can itself go stale
 * without the code changing) — it only guarantees every loose plan carries
 * one to audit against.
 */
test('every loose plan file names its own status', function (): void {
    $plansDirectory = dirname(__DIR__, 2).'/.claude/plans';

    $files = (new Finder)
        ->files()
        ->in($plansDirectory)
        ->depth(0)
        ->name('*.md')
        ->notName('README.md');

    expect(iterator_count($files))->toBeGreaterThanOrEqual(0);

    foreach ($files as $file) {
        $firstLines = implode("\n", array_slice(explode("\n", $file->getContents()), 0, 15));

        expect($firstLines)->toMatch(
            '/status/i',
            "{$file->getRelativePathname()} has no status marker in its first 15 lines — ".
            'a live plan needs one for the README audit to catch, and an executed one belongs in archive/.',
        );
    }
});
