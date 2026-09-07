<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Illuminate\Validation\Rules\Password;
use Nvade\Numerosis\Models\User;
use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * Crosses Fortify's `array $input` boundary for
 * `Actions\Auth\UpdateUserPassword::update()`.
 */
class UpdatePasswordData extends Data
{
    public function __construct(
        #[SensitiveParameter]
        public string $password,
        #[SensitiveParameter]
        public ?string $current_password = null,
    ) {}

    /**
     * The one rule set for a password change, for both Fortify's controller
     * and the Livewire settings screen. `rulesFor()` and not spatie's static
     * `rules()` because `current_password` only applies to a user who has one:
     * a social-login-only account sets its first password here.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(User $user): array
    {
        return [
            ...(filled($user->password) ? [
                'current_password' => ['required', 'string', 'current_password'],
            ] : []),
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
