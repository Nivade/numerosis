<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Illuminate\Validation\Rules\Password;
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
        public string $current_password,
        #[SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
