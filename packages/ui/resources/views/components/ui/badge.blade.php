@use(\Nvade\NumerosisUi\Enums\Severity)

@props([
    'variant' => 'default',
    'icon' => false,
])

@php
    // Same semantic tokens as ui/alert, through `Severity::classes()` — one
    // definition, two consumers. `default` is not a severity and keeps its
    // own branch.
    $classes = Severity::fromAlias($variant)?->classes()
        ?? 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300';
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium', $classes]) }}>
    @if($icon)
        <flux:icon name="{{ $icon }}" class="size-3" />
    @endif
    {{ $slot }}
</span>
