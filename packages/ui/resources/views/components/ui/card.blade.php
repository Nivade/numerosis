@props([
    'variant' => 'default',
    'padding' => true,
    'placeholder' => false,
])

@php
// Surfaces sit *below* the page, which is `bg-white dark:bg-zinc-800` in
// every layout. `default` used to be `dark:bg-zinc-800` — the same value as
// the body — so in dark mode a card had no surface at all and only its
// border separated it from the page. The marketing pages already resolved
// this by recessing cards to zinc-900; that model wins.
//
// Recessing is also what lets the border be a translucent hairline
// (`white/10`, as the pages use) instead of solid zinc-700: there is a real
// tonal step behind it now.
//
// `muted` matches `default` in dark on purpose — the pages draw both
// white-on-light and zinc-50-on-light surfaces at the same dark value.
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
