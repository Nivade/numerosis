@props(['name'])

<div {{ $attributes }} x-data="{
    id: '{{ $name }}',
    get isOpen() { return this.active === this.id },
    toggle() { this.active = this.isOpen ? null : this.id }
}">
    {{ $slot }}
</div>
