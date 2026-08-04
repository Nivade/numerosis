@props([
    'level' => 1,
    'size' => null,
])

@php
// Auto-determine size from level if not explicitly set
$size = $size ?? match($level) {
    1 => '2xl',
    2 => 'xl',
    3 => 'lg',
    4 => 'base',
    default => 'base',
};

$sizeClasses = [
    '3xl' => 'text-3xl',
    '2xl' => 'text-2xl',
    'xl' => 'text-xl',
    'lg' => 'text-lg',
    'base' => 'text-base',
];

$sizeClass = $sizeClasses[$size] ?? $sizeClasses['2xl'];
$tag = "h{$level}";
@endphp

<{{ $tag }} {{ $attributes->merge(['class' => "{$sizeClass} font-bold text-gray-900 dark:text-white"]) }}>
    {{ $slot }}
</{{ $tag }}>
