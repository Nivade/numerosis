<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Notifications\SaveNotificationPreference;
use Nvade\Numerosis\Concerns\Auth\RequiresAuthenticatedUser;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\NotificationPreference;
use Nvade\Numerosis\Numerosis;

/**
 * The matrix of type against channel. Rows a user may not switch off are shown
 * and locked with the reason in words: a greyed checkbox with no explanation
 * reads as a bug.
 */
#[Layout('numerosis-layouts::app')]
class Notifications extends Component
{
    use RequiresAuthenticatedUser;

    /** @var array<string, array{mail: bool, database: bool}> */
    public array $preferences = [];

    public string $status = '';

    public function mount(): void
    {
        $this->preferences = $this->current();
    }

    public function save(): void
    {
        $user = $this->centralUser();

        foreach (NotificationType::cases() as $type) {
            $row = $this->preferences[$type->value] ?? null;

            if (! is_array($row)) {
                continue;
            }

            SaveNotificationPreference::run(
                $user->global_id,
                $type,
                mail: (bool) ($row['mail'] ?? $type->mailByDefault()),
                database: (bool) ($row['database'] ?? $type->databaseByDefault()),
            );
        }

        $this->preferences = $this->current();
        $this->status = __('Your notification preferences are saved.');
    }

    public function render(): View
    {
        return view('numerosis::livewire.settings.notifications', [
            'types' => NotificationType::cases(),
        ]);
    }

    /**
     * @return array<string, array{mail: bool, database: bool}>
     */
    private function current(): array
    {
        $user = $this->centralUser();

        $stored = Numerosis::model(NotificationPreference::class)::query()
            ->where('global_id', $user->global_id)
            ->get()
            ->keyBy(fn (NotificationPreference $preference): string => $preference->type->value);

        $rows = [];

        foreach (NotificationType::cases() as $type) {
            /** @var NotificationPreference|null $preference */
            $preference = $stored->get($type->value);

            $mail = $preference instanceof NotificationPreference ? $preference->mail : null;
            $database = $preference instanceof NotificationPreference ? $preference->database : null;

            $rows[$type->value] = [
                'mail' => $type->mayDisableMail() ? ($mail ?? $type->mailByDefault()) : true,
                'database' => $database ?? $type->databaseByDefault(),
            ];
        }

        return $rows;
    }

    private function centralUser(): CentralUser
    {
        $user = $this->authenticatedUser();

        abort_unless($user instanceof CentralUser, 403);

        return $user;
    }
}
