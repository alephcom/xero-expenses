<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Cloudflare Turnstile token server-side via the siteverify API.
 *
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
class Turnstile implements ValidationRule
{
    public function __construct(
        private readonly ?string $remoteIp = null,
    ) {}

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            $fail(__('Please complete the verification challenge.'));

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(config('services.turnstile.verify_url'), array_filter([
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $value,
                    'remoteip' => $this->remoteIp,
                ]));
        } catch (ConnectionException $e) {
            Log::warning('Turnstile verification request failed.', ['error' => $e->getMessage()]);
            $fail(__('We could not verify your submission. Please try again.'));

            return;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            Log::info('Turnstile verification rejected.', [
                'status' => $response->status(),
                'error_codes' => $response->json('error-codes'),
            ]);
            $fail(__('Verification failed. Please try again.'));
        }
    }
}
