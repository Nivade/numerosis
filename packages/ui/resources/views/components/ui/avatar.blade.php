@props([
    'initials' => null,
    'size' => 'md',
    'src' => null,
    'alt' => '',
])

@php
$sizes = [
    'xs' => 'h-6 w-6 text-xs',
    'sm' => 'h-8 w-8 text-xs',
    'md' => 'h-9 w-9 text-xs',
    'lg' => 'h-12 w-12 text-sm',
    'xl' => 'h-16 w-16 text-base',
];

$sizeClass = $sizes[$size] ?? $sizes['md'];
@endphp

<div {{ $attributes->merge(['class' => "flex shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700 text-zinc-700 dark:text-zinc-200 font-semibold select-none {$sizeClass}"]) }}>
    @if($src)
        <img src="{{ $src }}" alt="{{ $alt }}" class="h-full w-full rounded-full object-cover" />
    @else
        {{ $initials ?? $slot }}
    @endif
</div>
