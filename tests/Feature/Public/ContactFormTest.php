<?php

namespace Tests\Feature\Public;

use App\Mail\ContactFormSubmitted;
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
}
