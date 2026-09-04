<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Illuminate\Validation\Rules\Password;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Numerosis;
use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * Crosses Fortify's `array $input` boundary for
 * `Actions\Auth\CreateRegisteredUser::create()`. Validation lives on the
 * action, not on a Form Request or on this class.
 */
class RegistrationData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $table = (new (Numerosis::model(CentralUser::class)))->getTable();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', "unique:{$table},email"],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
