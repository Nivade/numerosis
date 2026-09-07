<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Illuminate\Validation\Rule;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Support\Numerosis;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Crosses Fortify's `array $input` boundary for
 * `Actions\Auth\UpdateUserProfile::update()`. Optional fields default to
 * `Optional` and never `?string`, since that action fills then checks
 * `isDirty('email')`, so a `null` for an unsubmitted field would clear it.
 */
class UpdateProfileData extends Data
{
    public function __construct(
        public string|Optional $name = new Optional,
        public string|Optional $email = new Optional,
    ) {}

    /**
     * The one rule set for a profile update, for both Fortify's controller and
     * the Livewire settings screen. `rulesFor()` and not spatie's static
     * `rules()` because uniqueness has to ignore the user being updated, and
     * it ignores by `global_id`: a tenant user's primary key belongs to the
     * tenant database, while the row uniqueness is checked against is central.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(User $user): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(Numerosis::model(CentralUser::class), 'email')
                    ->ignore($user->global_id, 'global_id'),
            ],
        ];
    }
}
