@props([
    'width' => 'wide',
    'spacing' => 'default',
    'divided' => false,
])

@php
    // Page rhythm, extracted from the marketing and legal pages: every
    // section is `py-12 sm:py-20` around a centred container, and heroes take
    // the taller asymmetric `pt-16 pb-12 sm:pt-24 sm:pb-16`.
    $widths = [
        'wide' => 'max-w-7xl',   // marketing grids and split layouts
        'prose' => 'max-w-4xl',  // long-form: privacy, terms
    ];

    $spacings = [
        'default' => 'py-12 sm:py-20',
        'hero' => 'pt-16 pb-12 sm:pt-24 sm:pb-16',
        'none' => '',
    ];
@endphp

<section {{ $attributes->class([
    'relative',
    $spacings[$spacing] ?? $spacings['default'],
    'border-t border-zinc-100 dark:border-white/5' => $divided,
]) }}>
    <div class="mx-auto {{ $widths[$width] ?? $widths['wide'] }} px-4 sm:px-6 lg:px-8">
        {{ $slot }}
    </div>
</section>
