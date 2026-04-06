<?php

namespace Tests\Feature\Xero;

use App\Models\User;
use App\Services\Xero\XeroOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class XeroCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_callback_branch_requires_authentication(): void
    {
        $state = Str::random(40);

        $this->withSession(['xero_oauth_state' => $state, 'xero_oauth_user_id' => 1])
            ->get(route('xero.callback', ['code' => 'c', 'state' => $state]))
            ->assertForbidden();
    }

    public function test_oauth_callback_branch_dispatches_to_connect_flow(): void
    {
        $user = User::factory()->create();
        $state = Str::random(40);

        $mock = Mockery::mock(XeroOAuthService::class);
        $mock->shouldReceive('exchangeCode')
            ->once()
            ->with('auth-code')
            ->andReturn([
                'access_token' => 'at',
                'refresh_token' => 'rt',
                'expires_in' => 1800,
                'scope' => 'offline_access',
            ]);
        $mock->shouldReceive('fetchConnections')
            ->once()
            ->with('at')
            ->andReturn([]);
        $this->app->instance(XeroOAuthService::class, $mock);

        $this->actingAs($user)
            ->withSession(['xero_oauth_state' => $state, 'xero_oauth_user_id' => $user->id])
            ->get(route('xero.callback', ['code' => 'auth-code', 'state' => $state]))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_oauth_callback_rejects_when_session_user_binding_mismatches(): void
    {
        $initiator = User::factory()->create();
        $other = User::factory()->create();
        $state = Str::random(40);

        $this->actingAs($other)
            ->withSession(['xero_oauth_state' => $state, 'xero_oauth_user_id' => $initiator->id])
            ->get(route('xero.callback', ['code' => 'auth-code', 'state' => $state]))
            ->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
