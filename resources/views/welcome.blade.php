<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta
            name="description"
            content="{{ __('Connect Xero, sync spend and bills, and review expense lines with your team—sign in with Xero, no separate passwords.') }}"
        >

        <title>{{ config('app.name') }}</title>

        @include('partials.favicon')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link
            href="https://fonts.bunny.net/css?family=cormorant-garamond:400,500,600,700|plus-jakarta-sans:400,500,600&display=swap"
            rel="stylesheet"
        />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
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
                    <div class="font-display text-2xl font-medium tracking-wide text-champagne sm:text-3xl">
                        {{ config('app.name') }}
                    </div>
                    @if (Route::has('login'))
                        <div class="w-full sm:w-auto sm:flex-1 sm:flex sm:justify-end">
                            @include('livewire.welcome.navigation')
                        </div>
                    @endif
                </header>

                <main class="mt-14 flex flex-1 flex-col justify-center sm:mt-20 lg:mt-24">
                    <div
                        class="animate-luxury-reveal rounded-2xl border border-white/[0.08] bg-white/[0.04] p-8 shadow-[0_0_0_1px_rgba(196,165,116,0.12),0_25px_80px_-20px_rgba(0,0,0,0.65)] backdrop-blur-xl sm:p-10 lg:rounded-3xl lg:p-12 [animation-delay:80ms] [animation-fill-mode:both]"
                    >
                        <p
                            class="text-[0.65rem] font-semibold uppercase tracking-[0.35em] text-gold-bright/90"
                        >
                            {{ __('Built for Xero') }}
                        </p>
                        <h1
                            class="mt-4 font-display text-3xl font-medium leading-[1.15] tracking-tight text-champagne sm:text-4xl lg:text-5xl"
                        >
                            {{ __('Review expenses your whole team can trust') }}
                        </h1>
                        <p class="mt-6 max-w-2xl text-base leading-relaxed text-champagne/75">
                            {{ __('Pull bank spend and accounts-payable lines from Xero, keep reviews and notes in one place, and jump back to Xero when you need the source transaction. Admins connect organisations; everyone else joins by invitation.') }}
                        </p>

                        <div
                            class="mt-8 flex flex-wrap gap-2 text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-champagne/55"
                            aria-label="{{ __('Highlights') }}"
                        >
                            <span
                                class="rounded-full border border-white/10 bg-white/[0.03] px-3 py-1.5 text-champagne/70"
                            >{{ __('Sign in with Xero') }}</span>
                            <span
                                class="rounded-full border border-white/10 bg-white/[0.03] px-3 py-1.5 text-champagne/70"
                            >{{ __('Roles & invitations') }}</span>
                            <span
                                class="rounded-full border border-white/10 bg-white/[0.03] px-3 py-1.5 text-champagne/70"
                            >{{ __('Queued sync') }}</span>
                        </div>

                        <ul
                            class="mt-10 grid gap-8 text-sm leading-relaxed text-champagne/70 lg:grid-cols-3 lg:gap-0 lg:divide-x lg:divide-white/10"
                        >
                            <li class="lg:pr-8">
                                <span class="font-display text-3xl font-medium tabular-nums text-gold" aria-hidden="true">01</span>
                                <p class="mt-3">
                                    {{ __('Sign in with Xero—no app password. New teammates accept an email invite and use the same email in Xero.') }}
                                </p>
                            </li>
                            <li class="lg:px-8">
                                <span class="font-display text-3xl font-medium tabular-nums text-gold" aria-hidden="true">02</span>
                                <p class="mt-3">
                                    {{ __('An admin connects each organisation from the dashboard so the app can sync accounts, contacts, and expense lines.') }}
                                </p>
                            </li>
                            <li class="lg:pl-8">
                                <span class="font-display text-3xl font-medium tabular-nums text-gold" aria-hidden="true">03</span>
                                <p class="mt-3">
                                    {{ __('Open an organisation, filter and sort lines, update review state, and follow deep links into Xero when you need context.') }}
                                </p>
                            </li>
                        </ul>

                        <p class="mt-10 border-t border-white/10 pt-8 text-sm text-champagne/50">
                            {{ __('You will need Xero API credentials and a matching redirect URI in your environment before connecting tenants.') }}
                        </p>

                        <div class="mt-8 flex flex-wrap items-center gap-4">
                            @auth
                                <a
                                    href="{{ url('/dashboard') }}"
                                    class="inline-flex items-center justify-center rounded-full bg-gradient-to-b from-gold-bright to-gold px-8 py-3 text-sm font-semibold text-ink shadow-lg shadow-gold/20 transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-bright focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
                                >
                                    {{ __('Go to dashboard') }}
                                </a>
                            @else
                                <a
                                    href="{{ route('login') }}"
                                    class="inline-flex items-center justify-center rounded-full bg-gradient-to-b from-gold-bright to-gold px-8 py-3 text-sm font-semibold text-ink shadow-lg shadow-gold/20 transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-bright focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
                                >
                                    {{ __('Sign in with Xero') }}
                                </a>
                            @endauth
                        </div>
                    </div>
                </main>

                <footer class="mt-14 text-center text-xs tracking-wide text-champagne/40">
                    <span>{{ config('app.name') }} · {{ now()->year }}</span>
                    @if (config('app.github_url'))
                        <span class="mx-1.5" aria-hidden="true">·</span>
                        <a
                            href="{{ config('app.github_url') }}"
                            class="text-champagne/50 underline decoration-champagne/25 underline-offset-2 transition hover:text-champagne/75 hover:decoration-champagne/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ __('View on GitHub') }}
                        </a>
                    @endif
                </footer>
            </div>
        </div>
    </body>
</html>
