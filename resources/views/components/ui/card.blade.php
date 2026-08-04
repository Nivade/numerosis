@props([
    'variant' => 'default',
    'padding' => true,
    'placeholder' => false,
])

@php
$variants = [
    'default' => 'bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700',
    'muted' => 'bg-zinc-50 dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700',
    'ghost' => 'bg-transparent rounded-lg',
];

$paddingClass = $padding ? 'p-6' : '';
@endphp

<div {{ $attributes->class(['relative overflow-hidden', $variants[$variant], $paddingClass]) }}>
    @if($placeholder)
        <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20"/>
    @endif
    {{ $slot }}
</div>
