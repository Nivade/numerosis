<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Crosses Fortify's `array $input` boundary for
 * `Actions\Auth\UpdateUserProfile::update()`. Optional fields default to
 * `Optional`, not `?string` — `UpdateUserProfile` does `$user->fill($data)`
 * then checks `isDirty('email')`, and a `null` for a field the form simply
 * did not submit would clear it rather than leave it alone.
 *
 * Email uniqueness-except-self cannot be expressed here without the target
 * user in scope, so `UpdateUserProfile` checks it itself before saving.
 */
class UpdateProfileData extends Data
{
    public function __construct(
        public string|Optional $name = new Optional,
        public string|Optional $email = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'lowercase', 'email', 'max:255'],
        ];
    }
}
