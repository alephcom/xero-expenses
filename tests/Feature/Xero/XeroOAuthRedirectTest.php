<?php

namespace Tests\Feature\Xero;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroOAuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_with_organisation_cannot_start_xero_oauth_redirect(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'reviewer']);

        $this->actingAs($user)
            ->get(route('xero.redirect'))
            ->assertForbidden();
    }

    public function test_user_with_no_organisations_can_start_xero_oauth_redirect(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('xero.redirect'))
            ->assertRedirect();
    }

    public function test_admin_of_an_organisation_can_start_xero_oauth_redirect(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('xero.redirect'))
            ->assertRedirect();
    }
}
