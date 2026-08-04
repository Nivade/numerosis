<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('partials.head')
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <livewire:layouts::header />
        {{ $slot }}

        <x-footer/>

        @fluxScripts
        @livewireScripts
    </body>
</html>
