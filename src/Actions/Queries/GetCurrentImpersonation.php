<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Features\Admin\ImpersonationFeature;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Numerosis;

/**
 * Deliberately not memoized in a static. Callers run on different requests of
 * one worker process, and a remembered answer hands the next request somebody
 * else's session.
 *
 * @method static ?ImpersonationSession run()
 */
class GetCurrentImpersonation
{
    use AsAction;

    public function handle(): ?ImpersonationSession
    {
        if (! ImpersonationFeature::available()) {
            return null;
        }

        // Not guarded on `isStarted()`: a queue worker's store answers `null`
        // for every key anyway, and the guard made this read false for a
        // caller outside the request lifecycle that had the marker.
        $id = Session::get(SessionKey::ImpersonationSession->value);

        if (! is_int($id)) {
            return null;
        }

        $session = Numerosis::model(ImpersonationSession::class)::query()->whereKey($id)->whereNull('ended_at')->first();

        return $session instanceof ImpersonationSession ? $session : null;
    }
}
