<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Illuminate\Validation\Rules\Password;
use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * Crosses Fortify's `array $input` boundary for
 * `Actions\Auth\ResetUserPassword::reset()`. `token`/`email` are validated by
 * `NewPasswordController` itself before the password broker calls back into
 * this action, so only `password` needs a rule here.
 */
class ResetPasswordData extends Data
{
    public function __construct(
        #[SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
