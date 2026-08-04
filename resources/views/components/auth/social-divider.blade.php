@props([
    'label' => __('Or continue with email'),
])

<div {{ $attributes->merge(['class' => 'relative']) }}>
    <div class="absolute inset-0 flex items-center">
        <div class="w-full border-t border-gray-300 dark:border-zinc-700"></div>
    </div>
    <div class="relative flex justify-center text-sm">
        <span class="px-2 bg-white dark:bg-zinc-800 text-gray-500 dark:text-zinc-400">{{ $label }}</span>
    </div>
</div>
