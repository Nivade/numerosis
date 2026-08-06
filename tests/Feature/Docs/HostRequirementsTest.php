<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Docs;

use Nvade\Numerosis\Commands\InstallNumerosisCommand;
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
     * @return list<array{key: string, checked_by: string}>
     */
    private function documentedRows(): array
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/host-requirements.md');
        $rows = [];

        foreach (explode("\n", $doc) as $line) {
            if (! str_starts_with($line, '| ') || str_starts_with($line, '|---')) {
                continue;
            }

            $cells = array_map(trim(...), explode('|', trim($line, "| \t")));

            // Key | Required value / shape | Why | Checked by
            $this->assertCount(4, $cells, "Row '{$cells[0]}' does not have a 'Checked by' cell.");

            // Header row, identified by its last cell rather than its first:
            // not every table's first column is called "Key" (the seeder
            // section's is "Requirement"), and matching on the first cell made
            // a differently-named header parse as a data row whose "Checked
            // by" was the literal string "Checked by".
            if ($cells[3] === 'Checked by') {
                continue;
            }

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
            (new ReflectionClass(InstallNumerosisCommand::class))->getMethods(),
        );
    }
}
