<footer class="mt-14 text-center text-xs tracking-wide text-champagne/40">
    <span>{{ config('app.name') }} · {{ now()->year }}</span>
    <span class="mx-1.5" aria-hidden="true">·</span>
    <a
        href="{{ route('contact') }}"
        class="text-champagne/50 underline decoration-champagne/25 underline-offset-2 transition hover:text-champagne/75 hover:decoration-champagne/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
    >
        {{ __('Contact') }}
    </a>
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
