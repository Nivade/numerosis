@props([
    'intensity' => 'bold',
])

@php
    // The radial wash behind every hero, at two intensities. color-mix(),
    // because a bare color followed by `/15` is not a valid gradient stop and
    // the browser drops the declaration.
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
