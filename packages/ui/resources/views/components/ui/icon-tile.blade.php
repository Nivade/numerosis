@props([
    'icon',
    'color' => 'blue',
    'size' => 'md',
])

@php
    // The tinted square behind a feature icon. Colours are a fixed allowlist,
    // not a free prop: every class has to appear literally in source for
    // Tailwind to generate it.
    $colors = [
        'blue' => 'bg-blue-500/10 text-blue-500',
        'emerald' => 'bg-emerald-500/10 text-emerald-500',
        'amber' => 'bg-amber-500/10 text-amber-500',
        'red' => 'bg-red-500/10 text-red-500',
        'purple' => 'bg-purple-500/10 text-purple-500',
    ];

    $sizes = [
        'sm' => 'size-10 rounded-lg',
        'md' => 'size-12 rounded-xl',
    ];
@endphp

<div {{ $attributes->class([
    'flex-none flex items-center justify-center',
    $colors[$color] ?? $colors['blue'],
    $sizes[$size] ?? $sizes['md'],
]) }}>
    <flux:icon :name="$icon" />
</div>
