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
 * `Actions\Auth\CreateRegisteredUser::create()`. See the "Cross the array
 * $input boundary with a Data object" note in
 * `.claude/plans/humming-nibbling-flame.md` (Phase 4b) for why validation
 * lives on the action rather than a Form Request here.
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
