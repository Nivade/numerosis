@props([
    'initials',
    'title',
])

{{--
    One row of the workspace list. The three states it renders — a reserved
    domain, a tenant still provisioning, and a ready one — differ only in
    their subtitle and their trailing controls, so those are slots.
--}}
<x-numerosis::ui.list.item {{ $attributes->class('flex flex-row gap-3 justify-between') }}>
    <div class="min-w-0 flex items-start gap-3">
        <x-numerosis::ui.avatar class="hidden sm:flex" :initials="$initials" />

        <div class="min-w-0">
            <x-numerosis::ui.text variant="default" size="sm" class="font-medium truncate">
                {{ $title }}
            </x-numerosis::ui.text>
            <x-numerosis::ui.text variant="subtle" size="xs" class="mt-0.5 truncate">
                {{ $subtitle }}
            </x-numerosis::ui.text>
            {{ $slot }}
        </div>
    </div>
    <div class="flex items-center gap-2 shrink-0 justify-end">
        {{ $actions ?? '' }}
    </div>
</x-numerosis::ui.list.item>
