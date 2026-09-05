<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Crosses Fortify's `array $input` boundary for
 * `Actions\Auth\UpdateUserProfile::update()`. Optional fields default to
 * `Optional` and never `?string`, since that action fills then checks
 * `isDirty('email')`, so a `null` for an unsubmitted field would clear it. It
 * also checks email uniqueness-except-self, which needs the user in scope.
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
