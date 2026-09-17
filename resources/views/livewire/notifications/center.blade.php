<div class="relative">
    <flux:button wire:click="toggle" variant="ghost" size="sm" class="relative" aria-label="{{ __('Notifications') }}">
        <flux:icon.bell class="size-5" />

        @if ($unread > 0)
            <span class="absolute -right-1 -top-1 min-w-4 rounded-full bg-danger-bg px-1 text-[10px] font-semibold leading-4 text-danger-text">
                {{ $unread > 9 ? '9+' : $unread }}
            </span>
        @endif
    </flux:button>

    @if ($open)
        <div class="absolute right-0 z-50 mt-2 w-80 rounded-xl border border-zinc-200 bg-white p-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between px-2 py-1">
                <x-numerosis::ui.text size="sm" class="font-medium">{{ __('Notifications') }}</x-numerosis::ui.text>

                <flux:button wire:click="markAllRead" size="xs" variant="ghost">
                    {{ __('Mark all read') }}
                </flux:button>
            </div>

            @if ($items->isEmpty())
                <x-numerosis::ui.text size="sm" variant="subtle" class="px-2 py-3">
                    {{ __('Nothing yet.') }}
                </x-numerosis::ui.text>
            @else
                <ul class="max-h-80 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                    @foreach ($items as $item)
                        <li class="px-2 py-2 {{ $item->unread ? 'bg-zinc-50 dark:bg-zinc-800/50' : '' }}">
                            <div class="text-sm font-medium">{{ $item->title }}</div>

                            @if ($item->body)
                                <div class="text-xs text-zinc-500">{{ $item->body }}</div>
                            @endif

                            <div class="mt-1 flex items-center gap-2 text-xs text-zinc-400">
                                <span>{{ $item->when }}</span>

                                @if ($item->tenant_name)
                                    <span>· {{ $item->tenant_name }}</span>
                                @endif

                                @if ($item->action_url)
                                    <a href="{{ $item->action_url }}" class="underline">{{ __('Open') }}</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
