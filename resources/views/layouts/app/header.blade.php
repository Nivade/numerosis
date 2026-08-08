<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('numerosis::partials.head')
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @include('numerosis::partials.toasts')

        <livewire:layouts::header />
        {{ $slot }}

        <x-numerosis::footer/>

        @fluxScripts
        @livewireScripts
    </body>
</html>
