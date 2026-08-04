# Extract Chat into an app-modules package (`nvade/chat`)

**Status: ✅ Executed.** `app-modules/chat` exists with the `nvade/chat`
composer package, `Nvade\Chat` namespace, own `src/`, `database/`,
`resources/`, `routes/`, `tests/` — commit `f08ee38`.

## Context

Chat currently lives scattered across core `app/` (Filament plugin, Livewire
components, actions, events, listener, enums, policies), core `database/`
(migrations, factories, seeder), and is force-registered in
`TenantAdminPanelProvider`. Every other optional feature (`tasks`, `notes`,
`announcements`, `branding`) already lives under `app-modules/` using
`internachi/modular`. This plan converts chat to the same shape:
`app-modules/chat` (composer package `nvade/chat`, namespace `Nvade\Chat`).

The app is **not live** — no existing tenant data, no backward-compat
migration path needed, no dual-write period. This is a pure code move: cut
files, paste under the new namespace, delete the old ones, fix the handful of
core call sites that reference chat.

Three product decisions already made (do not revisit):
- **Plugin stays unconditionally registered** — not added to
  `config/modules.php`'s `catalogue`/`plugins` (chat is free/core, not a
  billed module). `TenantAdminPanelProvider` keeps force-registering it, just
  from the new namespace.
- **`ChatSeeder` stays unconditionally called** from `TenantDatabaseSeeder`
  (not converted to the on-demand `tenants:seed-module` pattern the other
  modules use).
- **User's chat-specific relation methods are extracted into a module-owned
  trait** (`Nvade\Chat\Concerns\HasChatCapabilities`). The `casts()` method
  and the `#[Fillable]` attribute on `App\Models\Tenant\User` **cannot** be
  composed from a trait (PHP attributes aren't inherited from traits, and a
  trait-supplied `casts()` would collide with the class's own) — those two
  stay directly on `User`, just repointing the `DisplayStatus` import to the
  module.

## Target module layout

```
app-modules/chat/
  composer.json
  routes/
    chat-routes.php
  database/
    migrations/tenant/
      2026_01_19_065422_create_messages_table.php
      2026_05_01_000001_drop_and_rebuild_chat_tables.php
      2026_05_01_000002_add_chat_status_to_users_table.php
      2026_05_05_000001_add_is_bot_to_users_table.php
    factories/
      ChannelFactory.php
      MessageFactory.php
    seeders/
      ChatSeeder.php
  resources/
    views/
      filament/topbar-button.blade.php
      livewire/chat-panel.blade.php
      livewire/channel-view.blade.php
      livewire/status-picker.blade.php
  src/
    ChatPlugin.php
    Providers/ChatServiceProvider.php
    Models/Channel.php
    Models/ChannelMember.php
    Models/Message.php
    Enums/ChannelType.php
    Enums/DisplayStatus.php
    Enums/MessageType.php
    Actions/CreateChannel.php
    Actions/JoinChannel.php
    Actions/SendBotReply.php
    Actions/SendMessage.php
    Actions/UpdateUserStatus.php
    Events/MessageSent.php
    Events/UserStatusChanged.php
    Listeners/QueueBotReply.php
    Policies/ChannelPolicy.php
    Policies/MessagePolicy.php
    Concerns/HasChatAuth.php
    Concerns/HasChatCapabilities.php
    Livewire/ChatPanel.php
    Livewire/ChannelView.php
    Livewire/StatusPicker.php
    Support/ChatCache.php
  tests/
    Feature/ChannelTest.php
    Feature/SendMessageTest.php
    Feature/StatusTest.php
    Feature/Listeners/QueueBotReplyTest.php
    Feature/Livewire/ChannelViewTest.php
    Feature/Livewire/ChatPanelTest.php
```

Mirror `app-modules/notes/composer.json` exactly for shape; module name
`nvade/chat`, namespace `Nvade\Chat\`.

## Why migrations are safe to move as-is

`internachi/modular`'s auto-migration finder only registers
`app-modules/*/database/migrations` (depth 0), which does **not** match a
`tenant/` subdirectory — so moving these 4 files into
`app-modules/chat/database/migrations/tenant/` keeps them **out** of any
automatic `php artisan migrate` run (correct — they're tenant-only tables and
must never run against central). They run via the existing
`php artisan tenants:migrate-module chat` command
(`app/Console/Commands/MigrateTenantModule.php`), same as every other
module's tenant migrations. No service-provider wiring needed. Since the app
isn't live, there's no "already-migrated core path" to reconcile — just move
the files.

## Why the Livewire components need converting to real classes

`<livewire:chat.chat-panel />` currently resolves via Livewire 4's SFC/MFC
auto-discovery, which only scans the two hardcoded core directories in
`config('livewire.component_locations')` — it will **not** find a module's
own `resources/views` directory. This codebase already has a precedent for a
Livewire component living outside those directories:
`app/Providers/AppServiceProvider.php` boot() calls
`Livewire::addComponent(name: ..., viewPath: ..., class: ...)` for the
registration wizard steps. Mirror that pattern: split each `⚡`-folder's
anonymous `new class extends Component {...}` into a real named class under
`Nvade\Chat\Livewire\`, keep the `.blade.php` as a plain view file, and
register both via `addComponent` in `ChatServiceProvider::boot()`. Component
*names* stay identical (`chat.chat-panel`, `chat.channel-view`,
`chat.status-picker`) so no call site (`<livewire:chat.chat-panel />`,
`$this->dispatch(...)->to('chat.chat-panel')`, `getListeners()` etc.) needs
to change.

## Step-by-step

### 1. Scaffold the module

Create `app-modules/chat/composer.json`:

```json
{
	"name": "nvade/chat",
	"description": "",
	"type": "library",
	"version": "1.0",
	"license": "proprietary",
	"require": {
		"filament/filament": "^5.0"
	},
	"autoload": {
		"psr-4": {
			"Nvade\\Chat\\": "src/",
			"Nvade\\Chat\\Tests\\": "tests/",
			"Nvade\\Chat\\Database\\Factories\\": "database/factories/",
			"Nvade\\Chat\\Database\\Seeders\\": "database/seeders/"
		}
	},
	"minimum-stability": "stable",
	"extra": {
		"laravel": {
			"providers": [
				"Nvade\\Chat\\Providers\\ChatServiceProvider"
			]
		}
	}
}
```

Create `app-modules/chat/routes/chat-routes.php` (module route files are
`require`d directly by `RoutesPlugin`, no `Route::` wrapper needed — a plain
`Broadcast::channel()` call executes fine):

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Nvade\Chat\Support\ChatCache;

Broadcast::channel('chat.{tenantId}', function ($user, string $tenantId) {
    if (! tenancy()->initialized || tenant('id') !== $tenantId) {
        return false;
    }

    Cache::put(ChatCache::presence((int) $user->id), true, now()->addSeconds(90));

    return [
        'id' => $user->id,
        'name' => $user->name,
        'display_status' => $user->display_status?->value,
    ];
});

Broadcast::channel('channel.{channelId}', function ($user, int $channelId) {
    return $user->channels()->where('channels.id', $channelId)->exists();
});
```

### 2. Move + rewrite namespaces — mechanical files

For every file below: `git mv` old path to new path, change the top
`namespace` line, and update every `use` statement per the mapping table.
Content otherwise unchanged (method bodies, docblocks, logic — do not alter
behavior).

**Namespace mapping** (old → new):

| Old | New |
|---|---|
| `App\Models\Tenant\Channel` | `Nvade\Chat\Models\Channel` |
| `App\Models\Tenant\ChannelMember` | `Nvade\Chat\Models\ChannelMember` |
| `App\Models\Tenant\Message` | `Nvade\Chat\Models\Message` |
| `App\Enums\Chat\ChannelType` | `Nvade\Chat\Enums\ChannelType` |
| `App\Enums\Chat\DisplayStatus` | `Nvade\Chat\Enums\DisplayStatus` |
| `App\Enums\Chat\MessageType` | `Nvade\Chat\Enums\MessageType` |
| `App\Actions\Chat\*` | `Nvade\Chat\Actions\*` |
| `App\Events\Chat\*` | `Nvade\Chat\Events\*` |
| `App\Listeners\Chat\QueueBotReply` | `Nvade\Chat\Listeners\QueueBotReply` |
| `App\Policies\ChannelPolicy` | `Nvade\Chat\Policies\ChannelPolicy` |
| `App\Policies\MessagePolicy` | `Nvade\Chat\Policies\MessagePolicy` |
| `App\Livewire\Chat\Concerns\HasChatAuth` | `Nvade\Chat\Concerns\HasChatAuth` |
| `App\Filament\Chat\ChatPlugin` | `Nvade\Chat\ChatPlugin` |
| `App\Support\Cache\CacheKeys::chatPresence()` | `Nvade\Chat\Support\ChatCache::presence()` |
| `Database\Factories\Tenant\ChannelFactory` | `Nvade\Chat\Database\Factories\ChannelFactory` |
| `Database\Factories\Tenant\MessageFactory` | `Nvade\Chat\Database\Factories\MessageFactory` |
| `Database\Seeders\Tenant\ChatSeeder` | `Nvade\Chat\Database\Seeders\ChatSeeder` |

Files to move (source → destination), applying the mapping to their own
namespace + `use` statements. **Leave every `App\Models\Tenant\User`,
`App\Models\Central\Tenant`, `App\Models\User`, `App\Actions\Queries\...`,
`App\Models\Permission` reference untouched — those stay core.**

1. `app/Models/Tenant/Channel.php` → `app-modules/chat/src/Models/Channel.php`
2. `app/Models/Tenant/ChannelMember.php` → `app-modules/chat/src/Models/ChannelMember.php`
3. `app/Models/Tenant/Message.php` → `app-modules/chat/src/Models/Message.php`
4. `app/Enums/Chat/ChannelType.php` → `app-modules/chat/src/Enums/ChannelType.php`
5. `app/Enums/Chat/DisplayStatus.php` → `app-modules/chat/src/Enums/DisplayStatus.php`
6. `app/Enums/Chat/MessageType.php` → `app-modules/chat/src/Enums/MessageType.php`
7. `app/Actions/Chat/CreateChannel.php` → `app-modules/chat/src/Actions/CreateChannel.php`
8. `app/Actions/Chat/JoinChannel.php` → `app-modules/chat/src/Actions/JoinChannel.php`
9. `app/Actions/Chat/SendBotReply.php` → `app-modules/chat/src/Actions/SendBotReply.php`
10. `app/Actions/Chat/SendMessage.php` → `app-modules/chat/src/Actions/SendMessage.php`
11. `app/Actions/Chat/UpdateUserStatus.php` → `app-modules/chat/src/Actions/UpdateUserStatus.php` (keep its `use App\Models\Central\Tenant;` — that one does not move)
12. `app/Events/Chat/MessageSent.php` → `app-modules/chat/src/Events/MessageSent.php`
13. `app/Events/Chat/UserStatusChanged.php` → `app-modules/chat/src/Events/UserStatusChanged.php`
14. `app/Listeners/Chat/QueueBotReply.php` → `app-modules/chat/src/Listeners/QueueBotReply.php`
15. `app/Policies/ChannelPolicy.php` → `app-modules/chat/src/Policies/ChannelPolicy.php` (keep `use App\Models\User;`)
16. `app/Policies/MessagePolicy.php` → `app-modules/chat/src/Policies/MessagePolicy.php` (keep `use App\Models\User;`)
17. `app/Livewire/Chat/Concerns/HasChatAuth.php` → `app-modules/chat/src/Concerns/HasChatAuth.php` (keep `use App\Actions\Queries\GetAuthenticatedTenantUser;` and `use App\Models\Tenant\User;`)
18. `database/factories/Tenant/ChannelFactory.php` → `app-modules/chat/database/factories/ChannelFactory.php`
19. `database/factories/Tenant/MessageFactory.php` → `app-modules/chat/database/factories/MessageFactory.php`
20. `database/seeders/Tenant/ChatSeeder.php` → `app-modules/chat/database/seeders/ChatSeeder.php` (keep `use App\Actions\Chat\JoinChannel;` → becomes `use Nvade\Chat\Actions\JoinChannel;`; keep `use App\Models\Permission;` and `use App\Models\Tenant\User;` as core references)
21. `database/migrations/tenant/2026_01_19_065422_create_messages_table.php` → `app-modules/chat/database/migrations/tenant/2026_01_19_065422_create_messages_table.php` (no code changes — anonymous migration class, no namespace)
22. `database/migrations/tenant/2026_05_01_000001_drop_and_rebuild_chat_tables.php` → same dir, unchanged
23. `database/migrations/tenant/2026_05_01_000002_add_chat_status_to_users_table.php` → same dir, unchanged
24. `database/migrations/tenant/2026_05_05_000001_add_is_bot_to_users_table.php` → same dir, unchanged
25. Test files (update `use` statements the same way; update `namespace Tests\Feature\Chat` → `namespace Nvade\Chat\Tests\Feature`, etc., matching the composer.json `Nvade\Chat\Tests\` → `tests/` mapping):
    - `tests/Feature/Chat/ChannelTest.php` → `app-modules/chat/tests/Feature/ChannelTest.php`
    - `tests/Feature/Chat/SendMessageTest.php` → `app-modules/chat/tests/Feature/SendMessageTest.php`
    - `tests/Feature/Chat/StatusTest.php` → `app-modules/chat/tests/Feature/StatusTest.php`
    - `tests/Feature/Listeners/Chat/QueueBotReplyTest.php` → `app-modules/chat/tests/Feature/Listeners/QueueBotReplyTest.php`
    - `tests/Feature/Livewire/Chat/ChannelViewTest.php` → `app-modules/chat/tests/Feature/Livewire/ChannelViewTest.php`
    - `tests/Feature/Livewire/Chat/ChatPanelTest.php` → `app-modules/chat/tests/Feature/Livewire/ChatPanelTest.php`
    - Keep `use Tests\TestCase;`, `use App\Models\Central\Tenant;`, `use App\Models\Tenant\User as TenantUser;` as core references (`tests_base` in `config/app-modules.php` is already `Tests\TestCase`, matching every other module's tests).

Delete the now-empty old directories after moving:
`app/Filament/Chat/`, `app/Livewire/Chat/`, `app/Actions/Chat/`,
`app/Enums/Chat/`, `app/Events/Chat/`, `app/Listeners/Chat/`,
`tests/Feature/Chat/`, `tests/Feature/Listeners/Chat/`,
`tests/Feature/Livewire/Chat/`, `resources/views/components/chat/`,
`resources/views/filament/chat/`. (`app/Policies/ChannelPolicy.php` and
`app/Policies/MessagePolicy.php` are individual files directly under
`app/Policies/`, not a subfolder — delete just those two files, leave
`app/Policies/` itself and its `Concerns/` subfolder alone.)

### 3. New files that don't already exist verbatim

**`app-modules/chat/src/Support/ChatCache.php`** (mirrors
`app-modules/branding/src/Support/BrandingCache.php`):

```php
<?php

declare(strict_types=1);

namespace Nvade\Chat\Support;

final class ChatCache
{
    private function __construct() {}

    public static function presence(int $userId): string
    {
        return "chat:presence:{$userId}";
    }
}
```

**`app-modules/chat/src/Concerns/HasChatCapabilities.php`** — the three
chat-specific members extracted off `App\Models\Tenant\User`:

```php
<?php

declare(strict_types=1);

namespace Nvade\Chat\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Nvade\Chat\Models\Channel;
use Nvade\Chat\Models\ChannelMember;
use Nvade\Chat\Support\ChatCache;

trait HasChatCapabilities
{
    /**
     * @return BelongsToMany<Channel, $this, ChannelMember, 'pivot'>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_members')
            ->withPivot(['role', 'last_read_at', 'joined_at'])
            ->withTimestamps()
            ->using(ChannelMember::class);
    }

    /**
     * @return HasMany<ChannelMember, $this>
     */
    public function channelMemberships(): HasMany
    {
        return $this->hasMany(ChannelMember::class);
    }

    public function isOnlineInChat(): bool
    {
        if ($this->is_bot) {
            return true;
        }

        return Cache::has(ChatCache::presence($this->id));
    }
}
```

**`app-modules/chat/src/ChatPlugin.php`** — same behavior as today, moved
namespace, view calls repointed to the `chat::` namespace
(`InterNACHI\Modular\Plugins\ViewPlugin` auto-registers
`app-modules/chat/resources/views` under namespace `chat`), Livewire tag
unchanged since `addComponent` keeps the same registered name:

```php
<?php

declare(strict_types=1);

namespace Nvade\Chat;

use App\Models\Central\Tenant;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\View\Compilers\BladeCompiler;

class ChatPlugin implements Plugin
{
    public static function make(): static
    {
        return resolve(static::class);
    }

    public function getId(): string
    {
        return 'chat';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => resolve(BladeCompiler::class)->render('<livewire:chat.chat-panel />')
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('chat::filament.topbar-button')->render()
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                function (): string {
                    $currentTenant = tenant();
                    $tenantId = $currentTenant instanceof Tenant ? $currentTenant->id : '';

                    return '<meta name="tenant-id" content="'.e($tenantId).'">';
                }
            );
    }

    public function boot(Panel $panel): void {}
}
```

Note: it keeps implementing the plain Filament `Plugin` contract (not
`App\Contracts\Tenancy\ModulePlugin`) — that interface exists specifically
for the `config('modules.plugins')`-driven conditional registration path,
which chat deliberately does not use (decision above).

**`app-modules/chat/src/Providers/ChatServiceProvider.php`**:

```php
<?php

declare(strict_types=1);

namespace Nvade\Chat\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nvade\Chat\Livewire\ChannelView;
use Nvade\Chat\Livewire\ChatPanel;
use Nvade\Chat\Livewire\StatusPicker;

class ChatServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Livewire::addComponent(
            name: 'chat.chat-panel',
            viewPath: __DIR__.'/../../resources/views/livewire/chat-panel.blade.php',
            class: ChatPanel::class,
        );
        Livewire::addComponent(
            name: 'chat.channel-view',
            viewPath: __DIR__.'/../../resources/views/livewire/channel-view.blade.php',
            class: ChannelView::class,
        );
        Livewire::addComponent(
            name: 'chat.status-picker',
            viewPath: __DIR__.'/../../resources/views/livewire/status-picker.blade.php',
            class: StatusPicker::class,
        );
    }
}
```

### 4. Convert the three Livewire SFCs into real classes + plain views

For each of the three `⚡`-folder components:

- Take the `.php` file's `new class extends Component { ... }` body and turn
  it into a real class `Nvade\Chat\Livewire\{ChatPanel,ChannelView,StatusPicker}`
  extending `Livewire\Component`, with the same properties/methods verbatim.
  Rewrite its `use` statements per the namespace mapping table (e.g.
  `App\Models\Tenant\Channel` → `Nvade\Chat\Models\Channel`,
  `App\Livewire\Chat\Concerns\HasChatAuth` → `Nvade\Chat\Concerns\HasChatAuth`,
  `App\Support\Cache\CacheKeys` → `Nvade\Chat\Support\ChatCache` with the
  `Cache::put(CacheKeys::chatPresence(...))` call in `chat-panel.php`'s
  `heartbeat()` becoming `Cache::put(ChatCache::presence(...))`). Keep
  `App\Models\Tenant\User` as-is (core).
- Move the paired `.blade.php` file as-is into
  `app-modules/chat/resources/views/livewire/{chat-panel,channel-view,status-picker}.blade.php`,
  **except** `status-picker.blade.php`, which has two fully-qualified
  `\App\Enums\Chat\DisplayStatus::` references (lines 12 and 46) that must
  become `\Nvade\Chat\Enums\DisplayStatus::`.
- Move `resources/views/filament/chat/topbar-button.blade.php` →
  `app-modules/chat/resources/views/filament/topbar-button.blade.php`
  unchanged (no `App\` references in it).

Concretely:
- `resources/views/components/chat/⚡chat-panel/chat-panel.php` → class body → `app-modules/chat/src/Livewire/ChatPanel.php`
- `resources/views/components/chat/⚡chat-panel/chat-panel.blade.php` → `app-modules/chat/resources/views/livewire/chat-panel.blade.php`
- `resources/views/components/chat/⚡channel-view/channel-view.php` → class body → `app-modules/chat/src/Livewire/ChannelView.php`
- `resources/views/components/chat/⚡channel-view/channel-view.blade.php` → `app-modules/chat/resources/views/livewire/channel-view.blade.php`
- `resources/views/components/chat/⚡status-picker/status-picker.php` → class body → `app-modules/chat/src/Livewire/StatusPicker.php`
- `resources/views/components/chat/⚡status-picker/status-picker.blade.php` → `app-modules/chat/resources/views/livewire/status-picker.blade.php` (with the two `DisplayStatus` FQCN fixes)

### 5. Core files to edit (not move)

**`app/Models/Tenant/User.php`**:
- Change `use App\Enums\Chat\DisplayStatus;` → `use Nvade\Chat\Enums\DisplayStatus;`
- Add `use Nvade\Chat\Concerns\HasChatCapabilities;`
- Add `use HasChatCapabilities;` to the trait-use block (alongside
  `use LogsActivity; use ResourceSyncing;`)
- Delete the `channels()`, `channelMemberships()`, and `isOnlineInChat()`
  method bodies from `User` (now supplied by the trait)
- Remove `use App\Support\Cache\CacheKeys;` and
  `use Illuminate\Support\Facades\Cache;` if nothing else in the file uses
  them after `isOnlineInChat()` is removed (check first — `Cache` facade may
  still be needed elsewhere in the file; based on the version read during
  planning, `isOnlineInChat()` was the only user of both, so both imports
  should be removed)
- Leave `casts()` and `#[Fillable]` exactly as they are (still list
  `display_status`, `custom_status_text`, `is_bot` — see Context, these
  can't move to a trait)

**`app/Support/Cache/CacheKeys.php`**:
- Delete the `chatPresence()` method (lines ~66–73 including its docblock)
- The trailing comment block about "module-owned keys deliberately do not
  live here" already documents why — no edit needed there, it's now
  accurate instead of aspirational

**`app/Providers/Filament/TenantAdminPanelProvider.php`**:
- Change `use App\Filament\Chat\ChatPlugin;` → `use Nvade\Chat\ChatPlugin;`
- No other change — `ChatPlugin::make()` call site stays as-is

**`database/seeders/TenantDatabaseSeeder.php`**:
- Change `use Database\Seeders\Tenant\ChatSeeder;` → `use Nvade\Chat\Database\Seeders\ChatSeeder;`
- No other change — still called unconditionally in the same position

**`app/Contracts/Tenancy/ModulePlugin.php`**:
- Its docblock cites `App\Filament\Chat\ChatPlugin` as the example of an
  ungated plugin. Update that reference to `Nvade\Chat\ChatPlugin` (or the
  module name generically) so the comment doesn't point at a deleted class.

**`routes/channels.php`**:
- Delete the `chat.{tenantId}` and `channel.{channelId}` broadcast-channel
  definitions (they moved to `app-modules/chat/routes/chat-routes.php` in
  step 1) — keep the `online` and `user.{userId}` channels, which are not
  chat-specific
- Remove the now-unused `use Illuminate\Support\Facades\Cache;`-adjacent
  inline `Illuminate\Support\Facades\Cache::put(...)` call along with the
  deleted closure (it was fully-qualified inline, not a top-level `use`, so
  check nothing else in the file needs it)

**`resources/js/tenant.js`**: no change needed — it only references the
`chat.{tenantId}` channel name and `tenant-id` meta tag by string, neither of
which changes.

### 6. Root composer.json wiring

Add `"nvade/chat": "*"` to the root `composer.json`'s `"require"` block, in
alphabetical position among the existing `nvade/*` entries (before
`nvade/notes`, since packages are sorted — `sort-packages: true` — and
`chat` < `notes` alphabetically; actual position: after `nvade/branding`,
before `nvade/notes`). The `"repositories"` path entry (`app-modules/*`) is
already generic and covers this module with no change.

### 7. Commands to run after all files are moved and edited

```bash
vendor/bin/sail composer update nvade/chat --no-interaction
vendor/bin/sail artisan modules:sync
vendor/bin/sail bin pint --dirty --format agent
```

Do **not** run `php artisan migrate` for the moved migrations against any
existing database — since the app isn't live, there's no tenant to migrate
yet in this task; `tenants:migrate-module chat` is how a real tenant would
pick these up, verified in the next section by running the test suite
(which builds its own tenant schema via `Tests\Support\CloneTenantSchema`,
independent of this migration path — see `.claude/rules/testing.md`).

## Verification

1. `vendor/bin/sail exec -T laravel.test bash -lc "vendor/bin/phpstan analyse"`
   — confirm no new errors beyond the existing baseline (per
   `.claude/rules/static-analysis.md`, diff the count against `git stash` if
   anything shows up under `app/` or `app-modules/chat/`).
2. Run the moved test suite in place:
   `vendor/bin/sail artisan test app-modules/chat/tests --compact`
3. Run the touched core tests to confirm nothing broke on the core side:
   `vendor/bin/sail artisan test --compact --filter=TenantsMineTest` and any
   test touching `TenantAdminPanelProvider`/panel registration
   (`tests/Feature/Filament` tenant panel boot tests, if any match).
4. Confirm the chat panel still renders in a real tenant: load a tenant
   subdomain in the browser, verify the chat bubble/topbar button appear
   (render hooks fire), open a channel, send a message (confirms
   `Livewire::addComponent` wiring + broadcasting channel auth both work end
   to end). If Reverb isn't running locally, at minimum confirm no 500/view
   errors on page load and that `sendMessage()` persists a row.
5. `grep -rn "App\\\\Filament\\\\Chat\|App\\\\Livewire\\\\Chat\|App\\\\Actions\\\\Chat\|App\\\\Events\\\\Chat\|App\\\\Enums\\\\Chat\|App\\\\Listeners\\\\Chat" app/ resources/ database/ tests/ routes/ config/`
   — must return nothing, confirming no stale reference survived the move.
