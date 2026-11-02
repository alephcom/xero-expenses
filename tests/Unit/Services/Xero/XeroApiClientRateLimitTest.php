<?php

namespace Tests\Unit\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroApiClientRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->organisation = Organisation::create([
            'name' => 'Rate Org',
            'slug' => 'rate-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);
    }

    public function test_waits_for_retry_after_and_retries_a_rate_limited_request(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['Title' => 'Rate limit'], 429, ['Retry-After' => '2'])
                ->push(['Accounts' => [['AccountID' => 'a1']]]),
        ]);

        $json = app(XeroApiClient::class)->get($this->organisation->fresh(), 'Accounts');

        $this->assertSame('a1', $json['Accounts'][0]['AccountID']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function test_does_not_wait_out_a_long_retry_after(): void
    {
        Http::fake([
            '*' => Http::response(['Title' => 'Daily limit'], 429, ['Retry-After' => '3600']),
        ]);

        try {
            app(XeroApiClient::class)->get($this->organisation->fresh(), 'Accounts');
            $this->fail('Expected the 429 to be thrown.');
        } catch (RequestException $e) {
            $this->assertSame(429, $e->response->status());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_gives_up_after_three_retries(): void
    {
        Http::fake([
            '*' => Http::response(['Title' => 'Rate limit'], 429, ['Retry-After' => '1']),
        ]);

        $this->expectException(RequestException::class);

        try {
            app(XeroApiClient::class)->get($this->organisation->fresh(), 'Accounts');
        } finally {
            Http::assertSentCount(4);
            Sleep::assertSleptTimes(3);
        }
    }
}
