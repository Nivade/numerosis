@props([
    'hover' => true,
    'padding' => 'default',
])

@php
$paddingVariants = [
    'default' => 'py-4 px-4 md:px-6',
    'compact' => 'py-2 px-4',
    'spacious' => 'py-6 px-6',
];

$hoverClass = $hover ? 'hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors' : '';
$paddingClass = $paddingVariants[$padding] ?? $paddingVariants['default'];
@endphp

<li {{ $attributes->merge(['class' => trim($paddingClass . ' ' . $hoverClass)]) }}>
    {{ $slot }}
</li>
