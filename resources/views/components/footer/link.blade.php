@props([
    'href',
    'external' => false,
])

{{--
    An external link states "opens in a new tab" for a screen reader; an
    internal one needs no `aria-label` at all, since its visible text already
    names it.
--}}
<li>
    <a
        href="{{ $href }}"
        {{ $attributes->class('hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring') }}
        @if ($external)
            target="_blank"
            rel="noreferrer"
            title="Opens in a new tab"
            aria-label="{{ trim($slot) }} (opens in a new tab)"
        @else
            wire:navigate
        @endif
    >{{ $slot }}</a>
</li>
