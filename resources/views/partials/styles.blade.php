@use(\Nvade\Numerosis\Numerosis)
@vite(['resources/css/app.css', 'resources/js/app.js'])
{!! Numerosis::assetTags() !!}
@fluxAppearance
@livewireStyles
