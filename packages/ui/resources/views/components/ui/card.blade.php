@props([
    'variant' => 'default',
    'padding' => true,
    'placeholder' => false,
])

@php
// Surfaces sit below the page, which is `bg-white dark:bg-zinc-800` in every
// layout, so a card recessed to zinc-900 is what gives dark mode a tonal step.
// `muted` matches `default` in dark deliberately.
$variants = [
    'default' => 'bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-white/10',
    'muted' => 'bg-zinc-50 dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-white/10',
    'ghost' => 'bg-transparent rounded-xl',
];

$paddingClass = $padding ? 'p-6' : '';
@endphp

<div {{ $attributes->class(['relative overflow-hidden', $variants[$variant], $paddingClass]) }}>
    @if($placeholder)
        <x-numerosis::placeholder-pattern class="absolute inset-0 size-full stroke-zinc-900/20 dark:stroke-zinc-100/20"/>
    @endif
    {{ $slot }}
</div>
