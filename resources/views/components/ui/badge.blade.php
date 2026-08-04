@props([
    'variant' => 'default',
    'icon' => false,
])

@php
$variants = [
    'success' => 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    'warning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-300',
    'error' => 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    'info' => 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    'default' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium', $variants[$variant]]) }}>
    @if($icon)
        <flux:icon name="{{ $icon }}" class="size-3" />
    @endif
    {{ $slot }}
</span>
