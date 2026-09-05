@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-success-icon']) }}>
        {{ $status }}
    </div>
@endif
