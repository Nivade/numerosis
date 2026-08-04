@props([
    'size' => 'sm',
])

@php
$sizeClasses = [
    'xs' => 'text-xs',
    'sm' => 'text-sm',
    'base' => 'text-base',
];

$sizeClass = $sizeClasses[$size] ?? $sizeClasses['sm'];
@endphp

<p {{ $attributes->merge(['class' => "{$sizeClass} text-gray-600 dark:text-gray-400"]) }}>
    {{ $slot }}
</p>
