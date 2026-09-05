<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Auth;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\SocialAccount;

/**
 * `SerializesModels` keeps the row out of the queue payload, which matters
 * here beyond `LogSocialAccountLinked`'s `#[DeleteWhenMissingModels]`: a
 * whole-model serialization writes the `token`/`refresh_token` ciphertext
 * into the jobs table on every OAuth login.
 */
class SocialAccountLinked implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SocialAccount $socialAccount,
        public readonly string $globalUserId,
        public readonly string $provider,
    ) {}
}
