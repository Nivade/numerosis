@props(['label', 'value', 'trend'])

<x-numerosis::ui.card {{ $attributes->class(['aspect-video p-4']) }}>
    <div class="flex h-full flex-col justify-between">
        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
        <div class="text-2xl font-bold">{{ $value }}</div>
        @isset($trend)
            <div @class([
                'text-xs',
                'text-success-icon' => $trend > 0,
                'text-danger-icon' => $trend <= 0,
            ])>
                {{ $trend > 0 ? '+' : '' }}{{ $trend }}%
            </div>
        @endisset
    </div>
</x-numerosis::ui.card>
