@props(['heading'])

<div>
    <h3 class="text-sm font-semibold mb-3">{{ $heading }}</h3>
    <ul class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
        {{ $slot }}
    </ul>
</div>
