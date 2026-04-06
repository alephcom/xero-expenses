<?php

namespace Tests\Unit\Policies;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganisationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_member_denied_all_org_abilities(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        foreach (['view', 'sync', 'updateReviews', 'invite', 'manageXeroConnection'] as $ability) {
            $this->assertFalse($user->can($ability, $org), $ability);
        }
    }

    public function test_reviewer_member_can_view_sync_and_update_reviews_but_not_invite_or_manage_xero(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'reviewer']);

        $this->assertTrue($user->can('view', $org));
        $this->assertTrue($user->can('sync', $org));
        $this->assertTrue($user->can('updateReviews', $org));
        $this->assertFalse($user->can('invite', $org));
        $this->assertFalse($user->can('manageXeroConnection', $org));
    }

    public function test_admin_member_can_all_abilities(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        foreach (['view', 'sync', 'updateReviews', 'invite', 'manageXeroConnection'] as $ability) {
            $this->assertTrue($user->can($ability, $org), $ability);
        }
    }
}
