<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Privacy;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Signed by the mail, single-use here: the stamp is written before the
 * response streams, so a second click on the same link 404s even while the
 * first download is still running.
 */
class DownloadDataExportController extends Controller
{
    public function __invoke(DataExportRequest $export): StreamedResponse
    {
        $user = Auth::guard(Context::Central->guard())->user();

        abort_unless($user instanceof CentralUser && $user->global_id === $export->global_user_id, 403);

        abort_unless($export->isDownloadable(), 404);

        $disk = Storage::disk($export->disk);
        $path = (string) $export->path;

        abort_unless($disk->exists($path), 404);

        $export->update(['downloaded_at' => now()]);

        activity()
            ->causedBy($user)
            ->withProperties(['request_ulid' => $export->ulid])
            ->log('Personal data export downloaded');

        return $disk->download($path, 'your-data.zip');
    }
}
