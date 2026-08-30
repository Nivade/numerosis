@props([
    'variant' => 'default',
    'icon' => false,
])

@php
// Same semantic tokens as ui/alert (resources/css/tokens.css) — one map,
// two consumers. Badge used to tint at the 100 step, alert at the softer
// 50 step; the token layer settled on alert's depth as `-bg`, so this is
// visually lighter than the old badge and matches alert exactly now.
//
// `danger` mirrors ui/info-box's name for the red variant so the two
// components take the same vocabulary.
$variants = [
    'success' => 'bg-success-bg text-success-text',
    'warning' => 'bg-warning-bg text-warning-text',
    'error' => 'bg-danger-bg text-danger-text',
    'danger' => 'bg-danger-bg text-danger-text',
    'info' => 'bg-info-bg text-info-text',
    'default' => 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300',
];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium', $variants[$variant]]) }}>
    @if($icon)
        <flux:icon name="{{ $icon }}" class="size-3" />
    @endif
    {{ $slot }}
</span>
