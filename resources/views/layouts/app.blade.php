<x-numerosis-layouts::app.header :title="$title ?? null">
    <flux:main>
        {{ $slot }}
    </flux:main>
</x-numerosis-layouts::app.header>
