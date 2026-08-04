<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('partials.head')
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <div class="flex min-h-screen">
            <!-- Sidebar -->

            <!-- Main Content Area -->
            <div class="flex flex-1 flex-col">

                <!-- Main Content -->
                <main class="flex-1 overflow-hidden p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
