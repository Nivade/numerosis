@props([
    'type' => 'info', // 'info', 'success', 'warning', 'danger'
    'title' => null,
])

@php
    $colors = match($type) {
        'success' => 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 icon-text:text-green-600 dark:icon-text:text-green-400',
        'warning' => 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 icon-text:text-amber-600 dark:icon-text:text-amber-400',
        'danger' => 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 icon-text:text-red-600 dark:icon-text:text-red-400',
        default => 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-200 icon-text:text-blue-600 dark:icon-text:text-blue-400',
    };

    $icon = match($type) {
        'success' => 'check-circle',
        'warning' => 'exclamation-triangle',
        'danger' => 'x-circle',
        default => 'information-circle',
    };
@endphp

<div {{ $attributes->merge(['class' => "p-4 border rounded-lg $colors"]) }}>
    <div class="flex items-start space-x-3">
        <flux:icon :name="$icon" class="h-5 w-5 mt-0.5 flex-shrink-0" />
        <div class="flex-1">
            @if($title)
                <p class="text-sm font-bold mb-1">
                    {{ $title }}
                </p>
            @endif
            <div class="text-sm">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
