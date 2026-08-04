<x-filament-panels::page>
    <form wire:submit="save" class="min-w-0">
        {{ $this->form }}
    </form>
    <x-filament-actions::modals/>
</x-filament-panels::page>
