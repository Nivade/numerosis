@props([
    'divided' => true,
])

@php
$dividerClass = $divided ? 'divide-y divide-zinc-200 dark:divide-zinc-700' : '';
@endphp

<ul {{ $attributes->merge(['class' => $dividerClass]) }}>
    {{ $slot }}
</ul>
