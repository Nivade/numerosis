@props(['label', 'value', 'trend'])

<x-ui.card {{ $attributes->class(['aspect-video p-4']) }}>
    <div class="flex h-full flex-col justify-between">
        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
        <div class="text-2xl font-bold">{{ $value }}</div>
        @isset($trend)
            <div @class([
                'text-xs',
                'text-green-600' => $trend > 0,
                'text-red-600' => $trend <= 0,
            ])>
                {{ $trend > 0 ? '+' : '' }}{{ $trend }}%
            </div>
        @endisset
    </div>
</x-ui.card>
