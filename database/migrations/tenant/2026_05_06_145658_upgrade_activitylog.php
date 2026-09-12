<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activity_log', 'attribute_changes')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->json('attribute_changes')->nullable()->after('causer_id');
            });
        }

        if (Schema::hasColumn('activity_log', 'batch_uuid')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropColumn('batch_uuid');
            });
        }

        // Then migrate existing data: move 'attributes' and 'old' from properties to attribute_changes
        DB::table('activity_log')
            ->where(function ($query) {
                $query->whereNotNull('properties->attributes')
                    ->orWhereNotNull('properties->old');
            })
            ->eachById(function ($row) {
                $properties = json_decode((string) $row->properties, true);

                if (! is_array($properties)) {
                    return;
                }

                $changes = array_intersect_key($properties, array_flip(['attributes', 'old']));
                $remaining = array_diff_key($properties, array_flip(['attributes', 'old']));

                DB::table('activity_log')->where('id', $row->id)->update([
                    'attribute_changes' => $changes === [] ? null : json_encode($changes),
                    'properties' => $remaining === [] ? null : json_encode($remaining),
                ]);
            });
    }
};
