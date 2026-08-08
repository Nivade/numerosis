@props([
    'icon' => null,
    'title' => null,
    'description' => null,
    'action' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center p-10']) }}>
    @if($icon)
        <flux:icon :name="$icon" class="w-10 h-10 text-zinc-400 dark:text-zinc-500 mb-3" />
    @endif

    {{-- Was gray-700/gray-600 against a zinc-400 icon — two neutral ramps in
         one component. zinc throughout, matching the pages. --}}
    @if($title)
        <p class="text-zinc-900 dark:text-white font-medium">{{ $title }}</p>
    @endif

    @if($description)
        <p class="text-sm text-zinc-600 dark:text-zinc-300 mt-1 max-w-md">{{ $description }}</p>
    @endif

    @if($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endif

    {{ $slot }}
</div>
