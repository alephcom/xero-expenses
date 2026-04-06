<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardConnectVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_does_not_see_connect_xero_when_they_have_no_admin_org(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'reviewer']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee(route('xero.redirect', absolute: false), false);
    }

    public function test_user_with_no_orgs_sees_connect_xero(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('xero.redirect', absolute: false), false);
    }
}
