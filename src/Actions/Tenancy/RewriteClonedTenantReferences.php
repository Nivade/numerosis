<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\DatabaseDriver;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Replaces the source tenant's id wherever the restored rows still carry it:
 * stored URLs, file paths, settings blobs. Without this a clone keeps pointing
 * at the tenant it was copied from, which looks like a working staging tenant
 * until something follows one of those references.
 *
 * @method static int run(Tenant $into, string $sourceTenantId)
 */
class RewriteClonedTenantReferences
{
    use AsAction;

    /** @return int Columns that held at least one reference. */
    public function handle(Tenant $into, string $sourceTenantId): int
    {
        if ($sourceTenantId === $into->id) {
            return 0;
        }

        /** @var int $rewritten */
        $rewritten = $into->runHere(function () use ($sourceTenantId, $into): int {
            $connection = DB::connection();
            $rewritten = 0;

            foreach ($connection->getSchemaBuilder()->getTables(
                DatabaseDriver::from($connection->getDriverName())->schemaName($connection->getDatabaseName())
            ) as $table) {
                $name = (string) ($table['name'] ?? '');

                if ($name === '' || $name === 'migrations') {
                    continue;
                }

                foreach ($connection->getSchemaBuilder()->getColumns($name) as $column) {
                    if (! $this->holdsText($column)) {
                        continue;
                    }

                    $columnName = is_string($column['name'] ?? null) ? $column['name'] : '';

                    $wrappedTable = $connection->getQueryGrammar()->wrapTable($name);
                    $wrappedColumn = $connection->getQueryGrammar()->wrap($columnName);

                    // PostgreSQL has no `like` or `replace` for json, so the
                    // column is read as text and cast back on the way in.
                    $jsonCast = $this->postgresJsonType($connection->getDriverName(), $column);
                    $read = $jsonCast === null ? $wrappedColumn : $wrappedColumn.'::text';
                    $written = $jsonCast === null
                        ? "replace({$read}, ?, ?)"
                        : "replace({$read}, ?, ?)::{$jsonCast}";

                    // A raw statement instead of a query-builder update. The
                    // new value is the old one with a substring replaced, and
                    // that reads the column it writes.
                    $affected = $connection->update(
                        "update {$wrappedTable} set {$wrappedColumn} = {$written} where {$read} like ?",
                        [$sourceTenantId, $into->id, '%'.$sourceTenantId.'%'],
                    );

                    $rewritten += $affected > 0 ? 1 : 0;
                }
            }

            return $rewritten;
        });

        return $rewritten;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function postgresJsonType(string $driver, array $column): ?string
    {
        if (DatabaseDriver::tryFrom($driver) !== DatabaseDriver::Pgsql) {
            return null;
        }

        $declared = $column['type_name'] ?? $column['type'] ?? '';
        $type = strtolower(is_string($declared) ? $declared : '');

        return in_array($type, ['json', 'jsonb'], true) ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function holdsText(array $column): bool
    {
        $declared = $column['type_name'] ?? $column['type'] ?? '';
        $type = strtolower(is_string($declared) ? $declared : '');

        return array_any(['char', 'text', 'json', 'clob'], fn ($textual) => str_contains($type, $textual));
    }
}
