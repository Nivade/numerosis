@props([
    'type' => 'info', // 'info', 'success', 'warning', 'danger'
    'title' => null,
])

{{--
    Kept as its own component because four call sites use this name and API,
    but it no longer carries a second copy of the callout palette — it was the
    third of three disagreeing semantic maps (this one said green/amber,
    ui/alert said green/yellow, ui/badge said green/yellow).

    ui/alert is the implementation. It accepts `danger` as an alias for
    `error`, so the `type` values this component documents still work.
--}}
<x-numerosis::ui.alert :type="$type" :title="$title" {{ $attributes }}>
    {{ $slot }}
</x-numerosis::ui.alert>
