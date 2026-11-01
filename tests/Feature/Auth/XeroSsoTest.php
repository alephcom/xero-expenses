<?php

namespace Tests\Feature\Auth;

use App\Jobs\SyncXeroReviewerAccessJob;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class XeroSsoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sso_callback_rejects_invalid_state(): void
    {
        $this->withSession(['xero_sso_state' => 'expected'])
            ->get(route('xero.callback', ['code' => 'c', 'state' => 'wrong']))
            ->assertForbidden();
    }

    public function test_sso_callback_creates_user_and_logs_in(): void
    {
        $mock = Mockery::mock(XeroSsoService::class);
        $mock->shouldReceive('exchangeCode')
            ->once()
            ->with('auth-code')
            ->andReturn(['id_token' => 'jwt-here']);
        $mock->shouldReceive('verifyIdToken')
            ->once()
            ->with('jwt-here')
            ->andReturn([
                'sub' => 'xero-sub-1',
                'email' => 'new@example.com',
                'name' => 'New User',
            ]);
        $this->app->instance(XeroSsoService::class, $mock);

        $this->withSession(['xero_sso_state' => 'good-state'])
            ->get(route('xero.callback', ['code' => 'auth-code', 'state' => 'good-state']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'xero_sub' => 'xero-sub-1',
            'name' => 'New User',
        ]);
    }

    public function test_sso_callback_updates_existing_user_by_xero_sub(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'xero_sub' => 'same-sub',
            'name' => 'Old',
        ]);

        $mock = Mockery::mock(XeroSsoService::class);
        $mock->shouldReceive('exchangeCode')->andReturn(['id_token' => 't']);
        $mock->shouldReceive('verifyIdToken')->andReturn([
            'sub' => 'same-sub',
            'email' => 'new@example.com',
            'name' => 'Updated',
        ]);
        $this->app->instance(XeroSsoService::class, $mock);

        $this->withSession(['xero_sso_state' => 's'])
            ->get(route('xero.callback', ['code' => 'c', 'state' => 's']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertSame('Updated', $user->fresh()->name);
    }

    public function test_sso_callback_blocks_new_user_when_open_signup_disabled_and_users_exist(): void
    {
        config(['services.xero.allow_open_signup' => false]);

        User::factory()->create();

        $mock = Mockery::mock(XeroSsoService::class);
        $mock->shouldReceive('exchangeCode')
            ->once()
            ->with('auth-code')
            ->andReturn(['id_token' => 'jwt-here']);
        $mock->shouldReceive('verifyIdToken')
            ->once()
            ->andReturn([
                'sub' => 'brand-new-sub',
                'email' => 'stranger@example.com',
                'name' => 'Stranger',
            ]);
        $this->app->instance(XeroSsoService::class, $mock);

        $this->withSession(['xero_sso_state' => 'good-state'])
            ->get(route('xero.callback', ['code' => 'auth-code', 'state' => 'good-state']))
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_sso_callback_creates_first_user_when_open_signup_disabled(): void
    {
        config(['services.xero.allow_open_signup' => false]);

        $mock = Mockery::mock(XeroSsoService::class);
        $mock->shouldReceive('exchangeCode')
            ->once()
            ->andReturn(['id_token' => 'jwt-here']);
        $mock->shouldReceive('verifyIdToken')
            ->once()
            ->andReturn([
                'sub' => 'bootstrap-sub',
                'email' => 'first@example.com',
                'name' => 'First',
            ]);
        $this->app->instance(XeroSsoService::class, $mock);

        $this->withSession(['xero_sso_state' => 'st'])
            ->get(route('xero.callback', ['code' => 'c', 'state' => 'st']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'first@example.com',
            'xero_sub' => 'bootstrap-sub',
        ]);
    }

    public function test_sso_callback_allows_new_user_with_valid_invitation_when_open_signup_disabled(): void
    {
        config(['services.xero.allow_open_signup' => false]);

        $admin = User::factory()->create();
        $organisation = Organisation::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => 'tenant-invite-test',
        ]);

        $created = Invitation::createWithPlainToken([
            'email' => 'invited@example.com',
            'organisation_id' => $organisation->id,
            'role' => 'reviewer',
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);
        $plain = $created['plain_token'];

        $mock = Mockery::mock(XeroSsoService::class);
        $mock->shouldReceive('exchangeCode')->once()->andReturn(['id_token' => 'jwt']);
        $mock->shouldReceive('verifyIdToken')->once()->andReturn([
            'sub' => 'invited-xero-sub',
            'email' => 'invited@example.com',
            'name' => 'Invited User',
        ]);
        $this->app->instance(XeroSsoService::class, $mock);

        $this->withSession([
            'xero_sso_state' => 'ok',
            'post_sso_invitation_plain' => $plain,
        ])
            ->get(route('xero.callback', ['code' => 'c', 'state' => 'ok']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $newUser = User::query()->where('email', 'invited@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->organisations()->whereKey($organisation->id)->exists());
        $this->assertNotNull($created['invitation']->fresh()->accepted_at);
    }

    public function test_sso_callback_auto_attaches_reviewer_when_xero_users_lists_standard_user(): void
    {
        config(['services.xero.allow_open_signup' => true]);

        $organisation = Organisation::query()->create([
            'name' => 'Linked org',
            'slug' => 'linked-'.Str::lower(Str::random(8)),
            'xero_tenant_id' => 'tenant-sso-reviewer',
        ]);
        XeroConnection::query()->create([
            'organisation_id' => $organisation->id,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
            'scopes' => 'offline_access',
        ]);

        $api = Mockery::mock(XeroApiClient::class);
        $api->shouldReceive('get')
            ->once()
            ->with(Mockery::on(fn ($org) => $org->id === $organisation->id), 'Users')
            ->andReturn([
                'Users' => [
                    ['EmailAddress' => 'auto@example.com', 'OrganisationRole' => 'STANDARD'],
                ],
            ]);
        $this->app->instance(XeroApiClient::class, $api);

        $sso = Mockery::mock(XeroSsoService::class);
        $sso->shouldReceive('exchangeCode')
            ->once()
            ->with('auth-code')
            ->andReturn(['id_token' => 'jwt-here']);
        $sso->shouldReceive('verifyIdToken')
            ->once()
            ->with('jwt-here')
            ->andReturn([
                'sub' => 'xero-sub-auto',
                'email' => 'auto@example.com',
                'name' => 'Auto User',
            ]);
        $this->app->instance(XeroSsoService::class, $sso);

        $this->withSession(['xero_sso_state' => 'good-state'])
            ->get(route('xero.callback', ['code' => 'auth-code', 'state' => 'good-state']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $user = User::query()->where('email', 'auto@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->organisations()->whereKey($organisation->id)->exists());
        $this->assertSame('reviewer', $user->organisations()->whereKey($organisation->id)->first()->pivot->role);
    }

    public function test_sso_callback_does_not_create_account_when_invitation_is_for_another_email(): void
    {
        config(['services.xero.allow_open_signup' => false]);

        $admin = User::factory()->create();
        $organisation = Organisation::query()->create([
            'name' => 'Acme',
            'slug' => 'acme-mismatch',
            'xero_tenant_id' => 'tenant-invite-mismatch',
        ]);

        $created = Invitation::createWithPlainToken([
            'email' => 'invited@example.com',
            'organisation_id' => $organisation->id,
            'role' => 'reviewer',
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);

        $mock = Mockery::mock(XeroSsoService::class);
        $mock->shouldReceive('exchangeCode')->once()->andReturn(['id_token' => 'jwt']);
        $mock->shouldReceive('verifyIdToken')->once()->andReturn([
            'sub' => 'someone-else-sub',
            'email' => 'someone-else@example.com',
            'name' => 'Someone Else',
        ]);
        $this->app->instance(XeroSsoService::class, $mock);

        $this->withSession([
            'xero_sso_state' => 'ok',
            'post_sso_invitation_plain' => $created['plain_token'],
        ])
            ->get(route('xero.callback', ['code' => 'c', 'state' => 'ok']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertFalse(User::query()->where('email', 'someone-else@example.com')->exists());
        $this->assertNull($created['invitation']->fresh()->accepted_at);
    }

    public function test_sso_callback_refuses_sign_in_when_xero_email_belongs_to_another_account(): void
    {
        Queue::fake();

        $existing = User::factory()->create([
            'email' => 'old@example.com',
            'xero_sub' => 'xero-sub-changed',
        ]);
        User::factory()->create(['email' => 'taken@example.com']);

        $mock = Mockery::mock(XeroSsoService::class);
        $mock->shouldReceive('exchangeCode')->once()->andReturn(['id_token' => 'jwt']);
        $mock->shouldReceive('verifyIdToken')->once()->andReturn([
            'sub' => 'xero-sub-changed',
            'email' => 'Taken@example.com',
            'name' => 'Renamed',
        ]);
        $this->app->instance(XeroSsoService::class, $mock);

        $this->withSession(['xero_sso_state' => 'ok', 'post_sso_invitation_plain' => 'some-token'])
            ->get(route('xero.callback', ['code' => 'c', 'state' => 'ok']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('xero')
            ->assertSessionMissing('post_sso_invitation_plain');

        $this->assertGuest();
        $this->assertSame('old@example.com', $existing->fresh()->email);
        Queue::assertNotPushed(SyncXeroReviewerAccessJob::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
