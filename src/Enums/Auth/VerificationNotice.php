<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Auth;

/**
 * A magic value flashed under `status` by Fortify's own resend-verification
 * flow, not a `FlashKey` — it names *which* notice, not the flash slot.
 */
enum VerificationNotice: string
{
    case LinkSent = 'verification-link-sent';
}
