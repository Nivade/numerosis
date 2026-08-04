@props([
    'type' => 'info',
    'message' => null,
    'closable' => false,
    'session' => null
])

@php
    // Check for session flash message if session key is provided
    $displayMessage = $message;
    if ($session && session($session)) {
        $displayMessage = session($session);
    }

    // Auto-detect common session keys if no message provided
    if (!$displayMessage) {
        foreach (['success', 'error', 'warning', 'info', 'message'] as $key) {
            if (session($key)) {
                $displayMessage = session($key);
                $type = $key === 'message' ? 'info' : $key;
                break;
            }
        }
    }

    // Define styling based on type
    $styles = [
        'success' => [
            'bg' => 'bg-green-50 dark:bg-green-950/20',
            'border' => 'border-green-200 dark:border-green-800',
            'text' => 'text-green-800 dark:text-green-200',
            'icon' => 'text-green-500 dark:text-green-400',
            'button' => 'text-green-500 hover:text-green-600 dark:text-green-400 dark:hover:text-green-300'
        ],
        'error' => [
            'bg' => 'bg-red-50 dark:bg-red-950/20',
            'border' => 'border-red-200 dark:border-red-800',
            'text' => 'text-red-800 dark:text-red-200',
            'icon' => 'text-red-500 dark:text-red-400',
            'button' => 'text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300'
        ],
        'warning' => [
            'bg' => 'bg-yellow-50 dark:bg-yellow-950/20',
            'border' => 'border-yellow-200 dark:border-yellow-800',
            'text' => 'text-yellow-800 dark:text-yellow-200',
            'icon' => 'text-yellow-500 dark:text-yellow-400',
            'button' => 'text-yellow-500 hover:text-yellow-600 dark:text-yellow-400 dark:hover:text-yellow-300'
        ],
        'info' => [
            'bg' => 'bg-blue-50 dark:bg-blue-950/20',
            'border' => 'border-blue-200 dark:border-blue-800',
            'text' => 'text-blue-800 dark:text-blue-200',
            'icon' => 'text-blue-500 dark:text-blue-400',
            'button' => 'text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300'
        ]
    ];

    $currentStyle = $styles[$type] ?? $styles['info'];

    // Define icons for each type
    $icons = [
        'success' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'error' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
        'warning' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z',
        'info' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'
    ];

    $iconPath = $icons[$type] ?? $icons['info'];
@endphp

@if($displayMessage)
    <div
        x-data="{ show: true }"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        {{ $attributes->class([
            'rounded-lg border p-4',
            $currentStyle['bg'],
            $currentStyle['border']
        ]) }}
    >
        <div class="flex">
            <div class="shrink-0">
                <svg class="size-5 {{ $currentStyle['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconPath }}"></path>
                </svg>
            </div>
            <div class="ml-3 flex-1">
                <div class="text-sm {{ $currentStyle['text'] }}">
                    @if($slot->isEmpty())
                        {{ $displayMessage }}
                    @else
                        {{ $slot }}
                    @endif
                </div>
            </div>
            @if($closable)
                <div class="ml-auto pl-3">
                    <div class="-m-1.5">
                        <button
                            @click="show = false"
                            type="button"
                            class="inline-flex rounded-md p-1.5 {{ $currentStyle['button'] }} hover:bg-black/5 dark:hover:bg-white/5 focus:outline-none focus:ring-2 focus:ring-current focus:ring-offset-2 focus:ring-offset-transparent"
                        >
                            <span class="sr-only">Dismiss</span>
                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endif
