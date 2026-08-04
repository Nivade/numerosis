@props([
    'variant' => 'default',
    'size' => 'sm',
])

@php
$variants = [
    'default' => 'text-zinc-900 dark:text-white',
    'muted' => 'text-zinc-600 dark:text-zinc-400',
    'subtle' => 'text-zinc-500 dark:text-zinc-300',
    'primary' => 'text-blue-600 dark:text-blue-400',
];

$sizes = [
    'xs' => 'text-xs',
    'sm' => 'text-sm',
    'base' => 'text-base',
    'lg' => 'text-lg',
];

$variantClass = $variants[$variant] ?? $variants['default'];
$sizeClass = $sizes[$size] ?? $sizes['sm'];
@endphp

<div {{ $attributes->merge(['class' => trim("{$sizeClass} {$variantClass}")]) }}>
    {{ $slot }}
</div>
