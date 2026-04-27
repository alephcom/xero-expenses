@extends('layouts.marketing', ['centerMain' => true])

@section('meta_description', __('Connect Xero, sync spend and bills, and review expense lines with your team—sign in with Xero, no separate passwords.'))

@section('content')
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
@endsection
