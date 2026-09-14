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

// `display` is the page-hero treatment, an opt-in size rather than a change
// to what `level` maps to, so existing headings keep their scale.
$sizeClasses = [
    'display' => 'text-4xl sm:text-5xl font-extrabold tracking-tight',
    '3xl' => 'text-3xl',
    '2xl' => 'text-2xl',
    'xl' => 'text-xl',
    'lg' => 'text-lg',
    'base' => 'text-base',
];

$sizeClass = $sizeClasses[$size] ?? $sizeClasses['2xl'];
$tag = "h{$level}";
@endphp

{{-- gray-900 was the last gray in the heading path; the pages are zinc. --}}
<{{ $tag }} {{ $attributes->merge(['class' => "{$sizeClass} font-bold text-zinc-900 dark:text-white"]) }}>
    {{ $slot }}
</{{ $tag }}>
