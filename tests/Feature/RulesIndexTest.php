<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `record-rule` rewrites `.ai/rules/index.md` from a hardcoded template whose
 * row is `| globs | file |`, so every note and the pointer to `overview.md`
 * are dropped on each call. It has happened three times. Restore the file
 * from git when this fails. See `.ai/rules/overview.md`.
 */
test('the rules index still carries its notes', function (): void {
    $rulesDirectory = dirname(__DIR__, 2).'/.ai/rules';
    $index = (string) file_get_contents($rulesDirectory.'/index.md');

    expect($index)->toContain('overview.md');

    $files = (new Finder)
        ->files()
        ->in($rulesDirectory)
        ->depth(0)
        ->name('*.md')
        ->notName('index.md')
        ->notName('overview.md');

    $rows = array_values(array_filter(
        explode("\n", $index),
        fn (string $line): bool => str_starts_with($line, '| ')
            && ! str_starts_with($line, '| Applies to')
            && ! str_starts_with($line, '| --- '),
    ));

    expect($rows)->toHaveCount(iterator_count($files));

    foreach ($rows as $row) {
        $cells = array_map(trim(...), explode('|', $row));

        expect($cells[2] ?? '')->toMatch(
            '/\]\([^)]+\.md\)\s+—\s+\S/',
            "A row in .ai/rules/index.md lost its note: {$row}",
        );
    }
});
