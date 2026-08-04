<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the tenant columns that {@see Stancl\VirtualColumn\VirtualColumn}
 * had been folding into `data`.
 *
 * `getCustomColumns()` defaults to `['id']`, so every other attribute was
 * written to the JSON column and the real columns stayed NULL. The model reads
 * them back off `data` transparently, which is why this went unnoticed — but
 * any query that filters or joins on them (`whereNotNull('provisioned_at')`,
 * Cashier resolving a customer by `stripe_id`) saw nothing at all.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = [
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'provisioned_at',
    ];

    /** @var list<string> */
    private const STALE_DATA_KEYS = ['created_at', 'updated_at'];

    private const DATES = ['trial_ends_at', 'provisioned_at'];

    public function up(): void
    {
        $connection = config('tenancy.database.central_connection', 'central');

        DB::connection($connection)->table('tenants')
            ->select('id', 'data')
            ->orderBy('id')
            ->each(function (object $row) use ($connection): void {
                /** @var array<string, mixed> $data */
                $data = json_decode((string) $row->data, true) ?: [];

                $updates = [];

                foreach (self::COLUMNS as $column) {
                    if (! array_key_exists($column, $data)) {
                        continue;
                    }

                    $value = $data[$column];

                    $updates[$column] = ($value !== null && in_array($column, self::DATES, true))
                        ? Carbon::parse((string) $value)
                        : $value;

                    unset($data[$column]);
                }

                foreach (self::STALE_DATA_KEYS as $key) {
                    unset($data[$key]);
                }

                if ($updates === []) {
                    return;
                }

                $updates['data'] = json_encode($data);

                DB::connection($connection)->table('tenants')
                    ->where('id', $row->id)
                    ->update($updates);
            });
    }

    /**
     * Deliberately irreversible: writing the values back into `data` would
     * recreate the bug this migration exists to undo.
     */
    public function down(): void {}
};
