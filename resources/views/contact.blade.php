@extends('layouts.marketing', ['centerMain' => false])

@section('title', __('Contact').' — '.config('app.name'))

@section('meta_description', __('Send a message to the team running Expense Review for Xero.'))

@section('content')
    <div
        class="animate-luxury-reveal w-full max-w-xl rounded-2xl border border-white/[0.08] bg-white/[0.04] p-8 shadow-[0_0_0_1px_rgba(196,165,116,0.12),0_25px_80px_-20px_rgba(0,0,0,0.65)] backdrop-blur-xl sm:p-10 lg:rounded-3xl [animation-delay:80ms] [animation-fill-mode:both]"
    >
        <p class="text-[0.65rem] font-semibold uppercase tracking-[0.35em] text-gold-bright/90">
            {{ __('Contact') }}
        </p>
        <h1 class="mt-4 font-display text-3xl font-medium leading-[1.15] tracking-tight text-champagne sm:text-4xl">
            {{ __('Send us a message') }}
        </h1>
        <p class="mt-3 text-sm leading-relaxed text-champagne/70">
            {{ __('We read every note. Replies go to the address you provide.') }}
        </p>

        @if (session('status'))
            <div
                class="mt-6 rounded-xl border border-gold/25 bg-gold/10 px-4 py-3 text-sm text-champagne"
                role="status"
            >
                {{ session('status') }}
            </div>
        @endif

        @if (! config('app.contact_email'))
            <p class="mt-6 rounded-xl border border-white/15 bg-white/[0.06] px-4 py-3 text-sm leading-relaxed text-champagne/90">
                {{ __('The contact form is not configured yet. Set APP_CONTACT_EMAIL in the server environment to enable submissions.') }}
            </p>
        @endif

        <form
            method="post"
            action="{{ route('contact.store') }}"
            class="mt-8 space-y-5"
        >
            @csrf

            <div>
                <label for="name" class="mb-1.5 block text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-champagne/80">
                    {{ __('Name') }}
                </label>
                <input
                    type="text"
                    name="name"
                    id="name"
                    value="{{ old('name') }}"
                    required
                    autocomplete="name"
                    class="luxury-form-field"
                />
                @error('name')
                    <p class="mt-1.5 text-sm text-red-300/90">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-champagne/80">
                    {{ __('Email') }}
                </label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    required
                    autocomplete="email"
                    class="luxury-form-field"
                />
                @error('email')
                    <p class="mt-1.5 text-sm text-red-300/90">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="message" class="mb-1.5 block text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-champagne/80">
                    {{ __('Message') }}
                </label>
                <textarea
                    name="message"
                    id="message"
                    rows="5"
                    required
                    class="luxury-form-field"
                >{{ old('message') }}</textarea>
                @error('message')
                    <p class="mt-1.5 text-sm text-red-300/90">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-1">
                <button
                    type="submit"
                    @disabled(! config('app.contact_email'))
                    class="inline-flex w-full items-center justify-center rounded-full bg-gradient-to-b from-gold-bright to-gold px-8 py-3 text-sm font-semibold text-ink shadow-lg shadow-gold/20 transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-bright focus-visible:ring-offset-2 focus-visible:ring-offset-ink disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {{ __('Send message') }}
                </button>
            </div>
        </form>

        <p class="mt-8 border-t border-white/10 pt-6 text-sm text-champagne/50">
            <a
                href="{{ url('/') }}"
                class="text-champagne/65 underline decoration-champagne/25 underline-offset-2 transition hover:text-champagne/85 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
            >
                {{ __('Back to home') }}
            </a>
        </p>
    </div>
@endsection
