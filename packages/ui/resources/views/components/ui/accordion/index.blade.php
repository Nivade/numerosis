@props(['transition' => true])

<div {{ $attributes }} x-data="{ active: null, transition: {{ $transition ? 'true' : 'false' }} }">
    {{ $slot }}
</div>
