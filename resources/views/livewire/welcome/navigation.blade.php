<nav class="flex flex-1 flex-wrap items-center justify-center gap-1 sm:justify-end">
    @auth
        <a
            href="{{ url('/dashboard') }}"
            class="rounded-full px-4 py-2 text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-champagne/80 transition hover:text-champagne focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-bright focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
        >
            Dashboard
        </a>
    @else
        <a
            href="{{ route('login') }}"
            class="rounded-full px-4 py-2 text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-champagne/80 transition hover:text-champagne focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-bright focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
        >
            {{ __('Sign in with Xero') }}
        </a>
    @endauth
</nav>
