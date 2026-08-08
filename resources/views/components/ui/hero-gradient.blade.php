@props([
    'intensity' => 'bold',
])

@php
    // The radial wash behind every hero. Two intensities: marketing pages
    // carry it at full strength, legal pages at two-thirds so long-form text
    // stays the focus.
    //
    // Written with color-mix() rather than the `var(--color-blue-500)/15`
    // shorthand welcome.blade.php used: a bare color followed by `/15` is not
    // a valid gradient stop, so the browser dropped the whole declaration and
    // that page's wash never painted at all.
    //
    // Both variants are spelled out as complete literal class strings on
    // purpose. Tailwind scans source text, so a class assembled from
    // interpolated parts is a class it never generates.
    $variants = [
        'bold' => 'bg-[radial-gradient(50%_50%_at_50%_0%,color-mix(in_oklab,var(--color-blue-500)_15%,transparent)_0%,transparent_60%)] dark:bg-[radial-gradient(50%_50%_at_50%_0%,color-mix(in_oklab,var(--color-blue-500)_20%,transparent)_0%,transparent_60%)]',
        'soft' => 'bg-[radial-gradient(50%_50%_at_50%_0%,color-mix(in_oklab,var(--color-blue-500)_10%,transparent)_0%,transparent_60%)] dark:bg-[radial-gradient(50%_50%_at_50%_0%,color-mix(in_oklab,var(--color-blue-500)_15%,transparent)_0%,transparent_60%)]',
    ];
@endphp

<div
    aria-hidden="true"
    {{ $attributes->class([
        'absolute inset-0 pointer-events-none',
        $variants[$intensity] ?? $variants['bold'],
    ]) }}
></div>
