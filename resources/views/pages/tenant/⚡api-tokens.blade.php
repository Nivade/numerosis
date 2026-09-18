<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\Api\CreateApiToken;
use Nvade\Numerosis\Actions\Auth\Api\RevokeApiToken;
use Nvade\Numerosis\Actions\Queries\GetApiAbilities;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Models\Tenant\ApiToken;
use Nvade\Numerosis\Models\Tenant\User;

/**
 * API tokens for whoever is signed in, in this workspace only. The plaintext
 * value is shown once and never again, because only its hash is stored.
 */
new #[Layout('numerosis-layouts::app')]
class extends Component
{
    public string $name = '';

    /** @var list<string> */
    public array $abilities = [];

    public ?string $plaintext = null;

    public ?string $error = null;

    /** Scalars only: a hydrated tenant model re-queries whichever connection is open. */
    public array $tokens = [];

    public function mount(): void
    {
        $this->refreshTokens();
    }

    /**
     * @return list<string>
     */
    public function availableAbilities(): array
    {
        $user = $this->currentUser();

        return $user instanceof User ? GetApiAbilities::run($user) : [];
    }

    public function create(): void
    {
        $this->error = null;
        $this->plaintext = null;

        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        if (trim($this->name) === '') {
            $this->error = __('Give the token a name you will recognise later.');

            return;
        }

        if ($this->abilities === []) {
            $this->error = __('Pick at least one thing the token may read.');

            return;
        }

        $days = Config::get('numerosis.api.token_expiry_days');

        $token = CreateApiToken::run(
            $user,
            trim($this->name),
            $this->abilities,
            is_numeric($days) ? Carbon::now()->addDays((int) $days) : null,
        );

        $this->plaintext = $token->plainTextToken;
        $this->name = '';
        $this->abilities = [];

        $this->refreshTokens();
    }

    public function revoke(int|string $tokenId): void
    {
        $user = $this->currentUser();

        if ($user instanceof User) {
            RevokeApiToken::run($user, $tokenId);
        }

        $this->refreshTokens();
    }

    private function refreshTokens(): void
    {
        $user = $this->currentUser();

        $this->tokens = $user instanceof User
            ? $user->tokens()->latest()->get()
                ->map(fn (mixed $token): array => [
                    'id' => $token instanceof ApiToken ? $token->getKey() : null,
                    'name' => $token instanceof ApiToken ? (string) $token->getAttribute('name') : '',
                    'abilities' => $token instanceof ApiToken ? (array) $token->getAttribute('abilities') : [],
                    'last_used' => $token instanceof ApiToken ? $token->last_used_at?->diffForHumans() : null,
                    'expires' => $token instanceof ApiToken ? $token->expires_at?->toFormattedDayDateString() : null,
                ])
                ->values()
                ->all()
            : [];
    }

    private function currentUser(): ?User
    {
        $user = GetAuthenticatedUser::run('tenant');

        return $user instanceof User ? $user : null;
    }
};
?>
<section class="mx-auto max-w-prose w-full">
    <x-slot:title>{{ __('API tokens') }}</x-slot:title>

    <div class="flex w-full flex-col gap-4">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('API tokens') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">
                {{ __('Tokens read this workspace through the API. They are yours alone, and they stop working if you leave.') }}
            </x-numerosis::ui.subheading>
        </div>

        @if ($plaintext)
            <x-numerosis::ui.card class="flex flex-col gap-2">
                <x-numerosis::ui.text size="sm">
                    {{ __('Copy this now — it is not shown again.') }}
                </x-numerosis::ui.text>
                <code class="break-all rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">{{ $plaintext }}</code>
            </x-numerosis::ui.card>
        @endif

        <x-numerosis::ui.card class="flex flex-col gap-4">
            <flux:input wire:model="name" :label="__('Token name')" placeholder="Reporting script" />

            <flux:checkbox.group wire:model="abilities" :label="__('What it may read')">
                @foreach ($this->availableAbilities() as $ability)
                    <flux:checkbox :value="$ability" :label="$ability" />
                @endforeach
            </flux:checkbox.group>

            @if ($error)
                <x-numerosis::ui.text size="sm" class="text-danger-text">{{ $error }}</x-numerosis::ui.text>
            @endif

            <flux:button wire:click="create" variant="primary" class="self-start">
                {{ __('Create token') }}
            </flux:button>
        </x-numerosis::ui.card>

        @if ($tokens !== [])
            <x-numerosis::ui.card class="flex flex-col gap-3">
                @foreach ($tokens as $token)
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium">{{ $token['name'] }}</div>
                            <div class="text-xs text-zinc-500 break-all">{{ implode(', ', $token['abilities']) }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ $token['last_used'] ? __('Last used :when', ['when' => $token['last_used']]) : __('Never used') }}
                                @if ($token['expires'])
                                    · {{ __('Expires :date', ['date' => $token['expires']]) }}
                                @endif
                            </div>
                        </div>

                        <flux:button size="xs" variant="ghost" wire:click="revoke('{{ $token['id'] }}')">
                            {{ __('Revoke') }}
                        </flux:button>
                    </div>
                @endforeach
            </x-numerosis::ui.card>
        @endif
    </div>
</section>
