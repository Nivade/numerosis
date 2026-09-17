<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Numerosis;

/**
 * Deletes the artefacts only, leaving the request rows: the row is the
 * record that a subject access request was answered, and it holds no
 * personal data beyond a global id.
 */
#[Description('Delete subject access request artefacts older than the retention window')]
#[Signature('numerosis:prune-data-exports
                            {--days= : Retention window, defaulting to numerosis.privacy.keep_days}')]
class PruneDataExports extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? Config::integer('numerosis.privacy.keep_days', 7));

        if ($days < 1) {
            $this->components->error('The days option must be a positive integer.');

            return self::FAILURE;
        }

        $stale = Numerosis::model(DataExportRequest::class)::query()
            ->whereNotNull('path')
            ->where('created_at', '<', now()->subDays($days))
            ->get();

        $deleted = 0;

        foreach ($stale as $request) {
            Storage::disk($request->disk)->delete((string) $request->path);

            $request->update(['path' => null]);
            $deleted++;
        }

        $this->components->info("Deleted {$deleted} export artefact(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
