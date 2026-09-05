@props([
    'cols' => 1,
])

@php
$colsClass = match($cols) {
    2 => 'md:grid-cols-2',
    3 => 'md:grid-cols-3',
    4 => 'md:grid-cols-2 lg:grid-cols-4',
    default => ''
};
@endphp

<div {{ $attributes->class(['grid auto-rows-min gap-4', $colsClass]) }}>
    {{ $slot }}
</div>
