<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @hasSection('meta_description')
            <meta name="description" content="@yield('meta_description')">
        @endif

        <title>@yield('title', config('app.name'))</title>

        @include('partials.favicon')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link
            href="https://fonts.bunny.net/css?family=cormorant-garamond:400,500,600,700|plus-jakarta-sans:400,500,600&display=swap"
            rel="stylesheet"
        />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body class="min-h-screen antialiased font-luxury text-champagne selection:bg-gold/35 selection:text-ink">
        <div class="relative min-h-screen overflow-hidden bg-ink">
            <div
                class="pointer-events-none absolute inset-0 bg-gradient-to-b from-ink-soft/80 via-ink to-ink"
                aria-hidden="true"
            ></div>
            <div
                class="pointer-events-none absolute -top-40 left-1/2 h-[min(44rem,90vw)] w-[min(44rem,90vw)] -translate-x-1/2 rounded-full bg-gold/12 blur-[120px]"
                aria-hidden="true"
            ></div>
            <div
                class="pointer-events-none absolute -bottom-24 right-[-10%] h-80 w-80 rounded-full bg-gold-muted/25 blur-[100px]"
                aria-hidden="true"
            ></div>
            <div
                class="pointer-events-none absolute inset-0 bg-noise opacity-[0.06] mix-blend-overlay"
                aria-hidden="true"
            ></div>

            <div
                class="relative z-10 mx-auto flex min-h-screen max-w-5xl flex-col px-6 pb-16 pt-10 sm:px-10 lg:px-12"
            >
                <header
                    class="flex animate-luxury-reveal flex-col items-center justify-between gap-6 sm:flex-row sm:items-center"
                >
                    <a
                        href="{{ url('/') }}"
                        class="font-display text-2xl font-medium tracking-wide text-champagne transition hover:text-champagne/90 sm:text-3xl"
                    >
                        {{ config('app.name') }}
                    </a>
                    @if (Route::has('login'))
                        <div class="w-full sm:w-auto sm:flex-1 sm:flex sm:justify-end">
                            @include('livewire.welcome.navigation')
                        </div>
                    @endif
                </header>

                <main @class([
                    'mt-14 flex flex-1 flex-col sm:mt-20 lg:mt-24',
                    'justify-center' => $centerMain ?? true,
                ])>
                    @yield('content')
                </main>

                @include('partials.marketing-footer')
            </div>
        </div>
    </body>
</html>
