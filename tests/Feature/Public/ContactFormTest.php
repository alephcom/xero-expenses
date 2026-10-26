<?php

namespace Tests\Feature\Public;

use App\Mail\ContactFormSubmitted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    public function test_contact_page_ok(): void
    {
        $this->get(route('contact'))
            ->assertOk();
    }

    public function test_contact_post_validation_errors(): void
    {
        $response = $this->from(route('contact'))->post(route('contact.store'), []);

        $response->assertSessionHasErrors(['name', 'email', 'message']);
    }

    public function test_contact_post_returns_503_when_not_configured(): void
    {
        config(['app.contact_email' => null]);

        $this->post(route('contact.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'message' => 'Hello',
        ])->assertStatus(503);
    }

    public function test_contact_post_sends_mail_and_redirects_with_flash(): void
    {
        Mail::fake();

        config(['app.contact_email' => 'inbox@example.com']);

        $response = $this->from(route('contact'))->post(route('contact.store'), [
            'name' => 'A User',
            'email' => 'user@example.com',
            'message' => 'Body text here.',
        ]);

        $response->assertRedirect(route('contact'));
        $response->assertSessionHas('status');

        Mail::assertSent(ContactFormSubmitted::class, function (ContactFormSubmitted $mail) {
            return $mail->hasTo('inbox@example.com')
                && $mail->contactName === 'A User'
                && $mail->contactEmail === 'user@example.com'
                && $mail->messageBody === 'Body text here.';
        });
    }

    public function test_contact_page_renders_turnstile_widget_when_configured(): void
    {
        $this->enableTurnstile();

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('data-sitekey="test-site-key"', false);
    }

    public function test_contact_page_omits_turnstile_widget_when_not_configured(): void
    {
        $this->get(route('contact'))
            ->assertOk()
            ->assertDontSee('cf-turnstile', false);
    }

    public function test_contact_post_requires_turnstile_token_when_configured(): void
    {
        Mail::fake();
        Http::fake();
        $this->enableTurnstile();

        $response = $this->from(route('contact'))->post(route('contact.store'), $this->validPayload());

        $response->assertSessionHasErrors(['cf-turnstile-response']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_contact_post_rejected_when_turnstile_verification_fails(): void
    {
        Mail::fake();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);
        $this->enableTurnstile();

        $response = $this->from(route('contact'))->post(route('contact.store'), $this->validPayload([
            'cf-turnstile-response' => 'bad-token',
        ]));

        $response->assertSessionHasErrors(['cf-turnstile-response']);
        Mail::assertNothingSent();
    }

    public function test_contact_post_rejected_when_turnstile_unreachable(): void
    {
        Mail::fake();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->enableTurnstile();

        $response = $this->from(route('contact'))->post(route('contact.store'), $this->validPayload([
            'cf-turnstile-response' => 'some-token',
        ]));

        $response->assertSessionHasErrors(['cf-turnstile-response']);
        Mail::assertNothingSent();
    }

    public function test_contact_post_sends_mail_when_turnstile_verification_passes(): void
    {
        Mail::fake();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);
        $this->enableTurnstile();

        $response = $this->from(route('contact'))->post(route('contact.store'), $this->validPayload([
            'cf-turnstile-response' => 'good-token',
        ]));

        $response->assertRedirect(route('contact'));
        $response->assertSessionHasNoErrors();
        Mail::assertSent(ContactFormSubmitted::class);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'test-secret-key'
            && $request['response'] === 'good-token');
    }

    private function enableTurnstile(): void
    {
        config([
            'app.contact_email' => 'inbox@example.com',
            'services.turnstile.site_key' => 'test-site-key',
            'services.turnstile.secret_key' => 'test-secret-key',
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'A User',
            'email' => 'user@example.com',
            'message' => 'Body text here.',
        ], $overrides);
    }
}
