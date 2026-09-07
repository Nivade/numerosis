<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Docs;

use Nvade\Numerosis\Console\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * docs/host-requirements.md is the real spec for what a host must own;
 * `numerosis:install` is the executable copy of it. Every session of the
 * extraction so far discovered host-owned keys the hard way and wrote them
 * into the doc without adding a check, so the two drifted — the same shape
 * auth-login.md records for the two login components.
 *
 * The doc's "Checked by" column is what makes the pairing testable. This
 * asserts it in both directions: no documented row without a check, and no
 * check without a documented row.
 */
class HostRequirementsTest extends TestCase
{
    public function test_every_documented_row_names_a_check_that_exists(): void
    {
        $unchecked = [];
        $missing = [];

        foreach ($this->documentedRows() as $row) {
            $checkedBy = $row['checked_by'];

            if (str_starts_with($checkedBy, '—')) {
                $this->assertNotSame(
                    '—',
                    trim($checkedBy),
                    "Row '{$row['key']}' opts out of verification without saying why. Write the reason after the dash."
                );

                continue;
            }

            if (preg_match_all('/`(\w+)\(\)`/', $checkedBy, $matches) !== 1) {
                $unchecked[] = $row['key'];

                continue;
            }

            foreach ($matches[1] as $method) {
                if (! in_array($method, $this->commandMethods(), true)) {
                    $missing[] = "{$row['key']} → {$method}()";
                }
            }
        }

        $this->assertSame([], $unchecked, 'Rows whose "Checked by" cell is neither a method nor a reasoned "—".');
        $this->assertSame([], $missing, 'Rows naming a method InstallNumerosisCommand does not have.');
    }

    public function test_every_check_the_command_runs_is_documented(): void
    {
        $documented = [];

        foreach ($this->documentedRows() as $row) {
            preg_match_all('/`(\w+)\(\)`/', $row['checked_by'], $matches);
            $documented = [...$documented, ...$matches[1]];
        }

        $undocumented = array_values(array_diff(
            array_filter($this->commandMethods(), fn (string $method): bool => str_starts_with($method, 'verify')),
            $documented,
        ));

        $this->assertSame(
            [],
            $undocumented,
            'InstallNumerosisCommand verifies something docs/host-requirements.md does not document. Add the row, with the failure signature a host would otherwise see.',
        );
    }

    /**
     * post-extraction-review.md Phase 4.2: a `verify*()` reading a mistyped
     * config key passes silently forever, and the two assertions above only
     * prove a method and a doc row exist together — neither runs the
     * method. This is what actually proves each one still fires: every
     * test in `InstallNumerosisCommandTest` carries an `@verifies <method>`
     * tag naming which `verify*()` it exercises, and this greps for them.
     */
    public function test_every_verify_method_has_a_failure_path_test(): void
    {
        $verifyMethods = array_values(array_filter(
            $this->commandMethods(),
            fn (string $method): bool => str_starts_with($method, 'verify'),
        ));

        $testFile = (string) file_get_contents(dirname(__DIR__, 2).'/Feature/Console/Commands/InstallNumerosisCommandTest.php');
        preg_match_all('/@verifies\s+(\w+)/', $testFile, $matches);
        $tested = $matches[1];

        $untested = array_values(array_diff($verifyMethods, $tested));

        $this->assertSame(
            [],
            $untested,
            'InstallNumerosisCommand has a verify*() method with no @verifies tag naming it in InstallNumerosisCommandTest — add a failure-path test.',
        );
    }

    /**
     * The other half of the same pairing. The assertions above pin
     * `InstallNumerosisCommand`'s checks to the doc, but nothing pinned
     * `HostConfig`'s normalizations to it — which is how `geoip.service`
     * came to be written on every boot while appearing nowhere in the
     * table, and stayed that way until someone re-read the doc by hand.
     *
     * Deliberately covers every dotted config key the file *names*, read or
     * written: a key `HostConfig` reasons about is a key a host can break
     * by setting it, so it belongs in the table either way.
     */
    public function test_every_config_key_host_config_touches_is_documented(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root.'/src/Support/HostConfig.php');
        $doc = (string) file_get_contents($root.'/docs/host-requirements.md');

        preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/', $source, $matches);

        $undocumented = [];

        foreach (array_unique($matches[1]) as $key) {
            // One row often documents a group of sibling keys under a shared
            // heading (`numerosis.domains.apex` / `.central` /
            // `.tenant_pattern`), so a key's own parent path counts — but
            // only when that parent is itself more than a namespace, or
            // every future `tenancy.*` key would pass on the strength of the
            // word "tenancy" appearing somewhere in the file.
            $parent = implode('.', array_slice(explode('.', $key), 0, -1));
            $parentCounts = str_contains($parent, '.') && str_contains($doc, $parent);

            if (str_contains($doc, $key) || $parentCounts) {
                continue;
            }

            $undocumented[] = $key;
        }

        $this->assertSame(
            [],
            $undocumented,
            'HostConfig names a config key docs/host-requirements.md never mentions. Add a §2 row saying what it is set to and how to override it.',
        );
    }

    /**
     * @return list<array{key: string, checked_by: string}>
     */
    private function documentedRows(): array
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/host-requirements.md');
        $rows = [];

        // The file carries tables that are not §1/§2 rows — §0's per-package
        // map, for one — so a table is opted *in* by its own header ending in
        // "Checked by" rather than by every `| ` line being assumed to be one.
        // Header row identified by its last cell, not its first: not every
        // table's first column is called "Key" (the seeder section's is
        // "Requirement"), and matching on the first cell made a
        // differently-named header parse as a data row whose "Checked by" was
        // the literal string "Checked by".
        $inCheckedTable = false;

        foreach (explode("\n", $doc) as $line) {
            if (! str_starts_with($line, '|')) {
                $inCheckedTable = false;

                continue;
            }
            if (str_starts_with($line, '|---')) {
                continue;
            }
            $cells = array_map(trim(...), explode('|', trim($line, "| \t")));

            if (end($cells) === 'Checked by') {
                $inCheckedTable = true;

                continue;
            }

            if (! $inCheckedTable) {
                continue;
            }

            // Key | Required value / shape | Why | Checked by
            $this->assertCount(4, $cells, "Row '{$cells[0]}' does not have a 'Checked by' cell.");

            $rows[] = ['key' => $cells[0], 'checked_by' => $cells[3]];
        }

        $this->assertNotSame([], $rows, 'Parsed no rows out of docs/host-requirements.md — the table format changed.');

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function commandMethods(): array
    {
        return array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            new ReflectionClass(InstallNumerosisCommand::class)->getMethods(),
        );
    }
}
