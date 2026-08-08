@props([
    'provider',
    'invitation' => null,
])

@php
    $params = ['driver' => $provider];

    if (tenancy()->initialized) {
        $params['tenant'] = tenant()?->id;
    }

    if ($invitation) {
        $params['invitation'] = $invitation->id;
    }
@endphp

<flux:button
    as="a"
    :href="route(config('auth.social.routes.redirect.name'), $params)"
    {{
        $attributes->class(
            'group w-full flex items-center justify-center gap-2
             transition-all duration-200
             hover:scale-[1.02] hover:shadow-md
             focus-ring
             active:scale-[0.99]
             text-zinc-900 dark:text-zinc-100'
        )
    }}
>
    {{ $slot }}
</flux:button>
