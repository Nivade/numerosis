@vite(['resources/css/app.css', 'resources/js/app.js'])
@vite('resources/js/central.js')
@if(tenancy()->initialized)
    @vite('resources/js/tenant.js')
@endif
@fluxAppearance
@livewireStyles
