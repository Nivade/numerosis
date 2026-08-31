@props(['transition' => true])

<div
    x-show="isOpen"
    x-cloak
    @if($transition) x-collapse @endif
    {{ $attributes }}
>
    {{ $slot }}
</div>
