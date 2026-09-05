@props([
    'variant' => 'default',
    'size' => 'sm',
])

@php
// Dark values realigned to the pages: body copy is `zinc-600/zinc-300` and
// muted copy is `zinc-500/zinc-400` there. `subtle` had them crossed — it
// was lighter in dark (zinc-300) than `muted` (zinc-400) despite being the
// quieter of the two.
$variants = [
    'default' => 'text-zinc-900 dark:text-white',
    'muted' => 'text-zinc-600 dark:text-zinc-300',
    'subtle' => 'text-zinc-500 dark:text-zinc-400',
    'primary' => 'text-primary',
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
