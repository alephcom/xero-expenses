<?php

namespace Tests\Feature\Org;

use App\Jobs\PushLineReviewToXeroJob;
use App\Jobs\SyncXeroOrganisationJob;
use App\Livewire\Org\ExpenseLineReview;
use App\Models\LineReview;
use App\Models\Organisation;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\ExpenseReviewDatePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseLineReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_member_cannot_mount_expense_line_review(): void
    {
        $stranger = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($stranger)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->assertForbidden();
    }

    public function test_non_member_cannot_access_org_expenses_route(): void
    {
        $stranger = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Other Org',
            'slug' => 'other-route-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $this->actingAs($stranger)
            ->get(route('orgs.expenses', $org))
            ->assertForbidden();
    }

    public function test_search_matches_selected_invoice_to_contact_name(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $contactId = (string) Str::uuid();
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('xero_contacts')->insert([
            'organisation_id' => $org->id,
            'xero_contact_id' => $contactId,
            'name' => 'ZetaUnique Corp Name',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 10,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'Office supplies',
            'parent_reference' => 'REF-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('line_reviews')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => false,
            'is_reviewed' => false,
            'selected_contact_id' => $contactId,
            'notes' => null,
            'user_id' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->set('search', 'ZetaUnique')
            ->assertSee('ZetaUnique Corp Name')
            ->assertSee('Office supplies');
    }

    public function test_search_matches_parent_transaction_supplier_or_payee_contact(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Payee Org',
            'slug' => 'payee-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentContactId = (string) Str::uuid();
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('xero_contacts')->insert([
            'organisation_id' => $org->id,
            'xero_contact_id' => $parentContactId,
            'name' => 'AcmeSupplierUnique42',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'bill',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 25,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'Widget purchase',
            'parent_reference' => 'INV-900',
            'parent_contact_id' => $parentContactId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->set('search', 'AcmeSupplierUnique')
            ->assertSee('AcmeSupplierUnique42')
            ->assertSee('Widget purchase');
    }

    public function test_sort_by_line_amount_changes_order(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Sort Org',
            'slug' => 'sort-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $day = Carbon::now()->subDay()->toDateString();

        $rows = [
            ['parent' => (string) Str::uuid(), 'line' => (string) Str::uuid(), 'amount' => 100.0, 'desc' => 'High line'],
            ['parent' => (string) Str::uuid(), 'line' => (string) Str::uuid(), 'amount' => 5.0, 'desc' => 'Low line'],
        ];

        foreach ($rows as $r) {
            DB::table('expense_lines')->insert([
                'organisation_id' => $org->id,
                'source_kind' => 'spend',
                'parent_transaction_id' => $r['parent'],
                'line_item_id' => $r['line'],
                'occurred_on' => $day,
                'line_amount' => $r['amount'],
                'currency_code' => 'USD',
                'account_id' => null,
                'account_code' => '4000',
                'description' => $r['desc'],
                'parent_reference' => 'R',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $component = Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day);

        $component->call('sort', 'line_amount');
        $this->assertSame('line_amount', $component->get('sortColumn'));
        $this->assertSame('desc', $component->get('sortDirection'));
        $component->assertSeeInOrder(['High line', 'Low line']);

        $component->call('sort', 'line_amount');
        $this->assertSame('asc', $component->get('sortDirection'));
        $component->assertSeeInOrder(['Low line', 'High line']);
    }

    public function test_mount_defaults_to_last_month_when_no_cookie(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05 12:00:00', 'UTC'));

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Default Org',
            'slug' => 'default-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->assertSet('datePreset', ExpenseReviewDatePresets::LAST_MONTH)
            ->assertSet('dateFrom', '2026-03-01')
            ->assertSet('dateTo', '2026-03-31');

        Carbon::setTestNow();
    }

    public function test_mount_hydrates_filters_from_cookie(): void
    {
        // Livewire tests run without EncryptCookies; the browser sends decrypted JSON via web middleware.
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Cookie Org',
            'slug' => 'cookie-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $name = 'expense_line_review_filters_'.$org->id;
        $value = json_encode([
            'date_preset' => ExpenseReviewDatePresets::CUSTOM,
            'date_from' => '2025-06-10',
            'date_to' => '2025-06-20',
            'has_link' => 'yes',
            'needs_invoicing' => 'no',
            'reviewed' => 'yes',
            'search' => 'needle',
            'sort_column' => 'description',
            'sort_direction' => 'asc',
        ]);

        Livewire::actingAs($user)
            ->withCookie($name, $value)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->assertSet('datePreset', ExpenseReviewDatePresets::CUSTOM)
            ->assertSet('dateFrom', '2025-06-10')
            ->assertSet('dateTo', '2025-06-20')
            ->assertSet('hasLink', 'yes')
            ->assertSet('needsInvoicingFilter', 'no')
            ->assertSet('reviewedFilter', 'yes')
            ->assertSet('search', 'needle')
            ->assertSet('sortColumn', 'description')
            ->assertSet('sortDirection', 'asc');
    }

    public function test_changing_filter_queues_cookie(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Queue Org',
            'slug' => 'queue-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('reviewedFilter', 'no');

        $cookieName = 'expense_line_review_filters_'.$org->id;
        $this->assertTrue(
            Cookie::hasQueued($cookieName),
            'Expected filter cookie to be queued after updating a filter.'
        );
    }

    public function test_request_sync_creates_queued_run_and_dispatches_job_with_sync_run_id(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Sync Org',
            'slug' => 'sync-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $from = '2026-01-01';
        $to = '2026-01-31';

        $component = Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $from)
            ->set('dateTo', $to)
            ->call('requestSync');

        $activeId = $component->get('activeSyncRunId');
        $this->assertIsInt($activeId);
        $this->assertGreaterThan(0, $activeId);

        $this->assertDatabaseHas('sync_runs', [
            'organisation_id' => $org->id,
            'status' => SyncRun::STATUS_QUEUED,
        ]);

        $run = SyncRun::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($run);
        $this->assertSame($from, $run->date_from->toDateString());
        $this->assertSame($to, $run->date_to->toDateString());
        $this->assertSame('queued', $run->meta['step'] ?? null);

        Queue::assertPushed(SyncXeroOrganisationJob::class, function (SyncXeroOrganisationJob $job) use ($org, $from, $to, $run, $user): bool {
            return $job->organisationId === $org->id
                && $job->dateFrom === $from
                && $job->dateTo === $to
                && $job->initiatedByUserId === $user->id
                && $job->syncRunId === $run->id;
        });
    }

    public function test_request_sync_does_not_start_second_sync_when_one_is_in_progress(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Busy Org',
            'slug' => 'busy-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        SyncRun::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'status' => SyncRun::STATUS_QUEUED,
            'started_at' => now(),
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'meta' => ['step' => 'queued'],
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('requestSync')
            ->assertSee(__('A sync is already in progress for this organisation.'), false);

        Queue::assertNothingPushed();
        $this->assertSame(1, SyncRun::withoutGlobalScopes()->where('organisation_id', $org->id)->count());
    }

    public function test_update_notes_rejects_payload_longer_than_max(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Notes Org',
            'slug' => 'notes-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tooLong = str_repeat('a', ExpenseLineReview::MAX_NOTES_LENGTH + 1);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('updateNotes', 'spend', $parentId, $lineId, $tooLong)
            ->assertHasErrors(['notes']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());
    }

    public function test_update_notes_persists_at_max_length(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Notes Ok Org',
            'slug' => 'notes-ok-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $atMax = str_repeat('b', ExpenseLineReview::MAX_NOTES_LENGTH);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('updateNotes', 'spend', $parentId, $lineId, $atMax)
            ->assertHasNoErrors();

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertSame($atMax, $review->notes);
    }

    public function test_update_selected_contact_rejects_xero_contact_not_in_organisation(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Contact Org',
            'slug' => 'contact-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();
        $unknownContactId = (string) Str::uuid();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->call('updateSelectedContact', 'spend', $parentId, $lineId, $unknownContactId)
            ->assertHasErrors(['contact_id']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());
    }

    public function test_update_selected_contact_persists_when_xero_contact_exists(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Contact Ok Org',
            'slug' => 'contact-ok-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $contactId = (string) Str::uuid();
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('xero_contacts')->insert([
            'organisation_id' => $org->id,
            'xero_contact_id' => $contactId,
            'name' => 'Valid Contact',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->call('updateSelectedContact', 'spend', $parentId, $lineId, $contactId)
            ->assertHasNoErrors();

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertSame($contactId, $review->selected_contact_id);
    }

    public function test_update_selected_contact_clears_with_empty_string(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Clear Org',
            'slug' => 'clear-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $contactId = (string) Str::uuid();
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('xero_contacts')->insert([
            'organisation_id' => $org->id,
            'xero_contact_id' => $contactId,
            'name' => 'To Clear',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('line_reviews')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => false,
            'is_reviewed' => false,
            'selected_contact_id' => $contactId,
            'notes' => null,
            'user_id' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->call('updateSelectedContact', 'spend', $parentId, $lineId, '')
            ->assertHasNoErrors();

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertNull($review->selected_contact_id);
    }

    public function test_toggle_needs_invoicing_dispatches_push_line_review_to_xero_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Writeback Org',
            'slug' => 'writeback-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->call('toggleNeedsInvoicing', 'spend', $parentId, $lineId, true)
            ->assertHasNoErrors();

        Queue::assertPushed(PushLineReviewToXeroJob::class, function (PushLineReviewToXeroJob $job) use ($org, $parentId, $lineId): bool {
            return $job->organisationId === $org->id
                && $job->sourceKind === 'spend'
                && $job->parentTransactionId === $parentId
                && $job->lineItemId === $lineId;
        });
    }

    public function test_toggle_needs_invoicing_does_not_run_when_link_allocated_in_xero_mirror(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Locked Org',
            'slug' => 'locked-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $targetId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $org->id,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => $contactId,
            'target_transaction_id' => $targetId,
            'target_line_item_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LineReview::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => true,
            'is_reviewed' => false,
            'selected_contact_id' => $contactId,
            'notes' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->call('toggleNeedsInvoicing', 'spend', $parentId, $lineId, false)
            ->assertHasNoErrors();

        Queue::assertNotPushed(PushLineReviewToXeroJob::class);

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertTrue($review->needs_invoicing);
    }

    public function test_update_selected_contact_does_not_run_when_link_allocated_in_xero_mirror(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Locked Contact Org',
            'slug' => 'locked-contact-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactA = (string) Str::uuid();
        $contactB = (string) Str::uuid();
        $targetId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        foreach ([$contactA, $contactB] as $cid) {
            DB::table('xero_contacts')->insert([
                'organisation_id' => $org->id,
                'xero_contact_id' => $cid,
                'name' => 'Contact '.$cid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $org->id,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => $contactA,
            'target_transaction_id' => $targetId,
            'target_line_item_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LineReview::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => true,
            'is_reviewed' => false,
            'selected_contact_id' => $contactA,
            'notes' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->call('updateSelectedContact', 'spend', $parentId, $lineId, $contactB)
            ->assertHasNoErrors();

        Queue::assertNotPushed(PushLineReviewToXeroJob::class);

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertSame($contactA, $review->selected_contact_id);
    }

    public function test_line_review_mutations_reject_when_expense_line_missing(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Integrity Org',
            'slug' => 'integrity-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('toggleReviewed', 'spend', $parentId, $lineId, true)
            ->assertHasErrors(['line_item_id']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('toggleNeedsInvoicing', 'spend', $parentId, $lineId, true)
            ->assertHasErrors(['line_item_id']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('updateNotes', 'spend', $parentId, $lineId, 'note')
            ->assertHasErrors(['line_item_id']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());

        $contactId = (string) Str::uuid();
        DB::table('xero_contacts')->insert([
            'organisation_id' => $org->id,
            'xero_contact_id' => $contactId,
            'name' => 'C',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('updateSelectedContact', 'spend', $parentId, $lineId, $contactId)
            ->assertHasErrors(['line_item_id']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());
    }

    public function test_line_review_mutations_reject_expense_line_belonging_to_another_organisation(): void
    {
        $user = User::factory()->create();
        $orgA = Organisation::create([
            'name' => 'Org A',
            'slug' => 'org-a-line-scope',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $orgB = Organisation::create([
            'name' => 'Org B',
            'slug' => 'org-b-line-scope',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $orgA->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('expense_lines')->insert([
            'organisation_id' => $orgB->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'other org line',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $orgA])
            ->call('toggleReviewed', 'spend', $parentId, $lineId, true)
            ->assertHasErrors(['line_item_id']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $orgA->id)->count());
    }

    public function test_line_review_mutations_reject_invalid_source_kind(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Source Kind Org',
            'slug' => 'source-kind-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->call('toggleReviewed', 'not-a-kind', $parentId, $lineId, true)
            ->assertHasErrors(['source_kind']);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());
    }

    public function test_expanded_row_detail_survives_rerender_from_another_row(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Expand Org',
            'slug' => 'expand-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentIdA = (string) Str::uuid();
        $lineIdA = (string) Str::uuid();
        $parentIdB = (string) Str::uuid();
        $lineIdB = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        foreach ([
            [$parentIdA, $lineIdA, 'Line A'],
            [$parentIdB, $lineIdB, 'Line B'],
        ] as [$parentId, $lineId, $desc]) {
            DB::table('expense_lines')->insert([
                'organisation_id' => $org->id,
                'source_kind' => 'spend',
                'parent_transaction_id' => $parentId,
                'line_item_id' => $lineId,
                'occurred_on' => $day,
                'line_amount' => 1,
                'currency_code' => 'USD',
                'account_id' => null,
                'account_code' => '4000',
                'description' => $desc,
                'parent_reference' => 'R',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $expandKey = ExpenseLineReview::lineItemKey('spend', $parentIdA, $lineIdA);

        $component = Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->call('toggleExpandedLine', 'spend', $parentIdA, $lineIdA)
            ->assertSet('expandedLineKeys', [$expandKey])
            ->assertSee(__('Search contacts…'), false);

        $component
            ->call('toggleReviewed', 'spend', $parentIdB, $lineIdB, true)
            ->assertSet('expandedLineKeys', [$expandKey])
            ->assertSee(__('Search contacts…'), false);
    }

    public function test_same_parent_lines_share_data_parent_group_on_table_rows(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Group Org',
            'slug' => 'group-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $parentId = (string) Str::uuid();
        $lineId1 = (string) Str::uuid();
        $lineId2 = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        foreach ([[$lineId1, 'Alpha line'], [$lineId2, 'Beta line']] as [$lineId, $desc]) {
            DB::table('expense_lines')->insert([
                'organisation_id' => $org->id,
                'source_kind' => 'spend',
                'parent_transaction_id' => $parentId,
                'line_item_id' => $lineId,
                'occurred_on' => $day,
                'line_amount' => 1,
                'currency_code' => 'USD',
                'account_id' => null,
                'account_code' => '4000',
                'description' => $desc,
                'parent_reference' => 'REF-GROUP',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $expectedAttr = 'data-parent-group="spend|'.$parentId.'"';

        $html = Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->set('dateFrom', $day)
            ->set('dateTo', $day)
            ->html();

        $this->assertSame(2, substr_count($html, $expectedAttr));

        $row = (object) [
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId1,
        ];
        $this->assertSame('spend|'.$parentId, ExpenseLineReview::parentGroupKey($row));

        $meta = ExpenseLineReview::buildParentGroupLineMeta(collect([
            (object) ['source_kind' => 'spend', 'parent_transaction_id' => $parentId, 'line_item_id' => $lineId1],
            (object) ['source_kind' => 'spend', 'parent_transaction_id' => $parentId, 'line_item_id' => $lineId2],
        ]));
        $this->assertCount(2, $meta);
        $this->assertTrue($meta[0]['is_start']);
        $this->assertFalse($meta[1]['is_start']);
        $this->assertFalse($meta[0]['is_end']);
        $this->assertTrue($meta[1]['is_end']);
        $this->assertSame(0, $meta[0]['stripe']);
        $this->assertSame(0, $meta[1]['stripe']);
    }

    public function test_mount_sets_active_sync_run_when_queued_run_exists(): void
    {
        $user = User::factory()->create();
        $org = Organisation::create([
            'name' => 'Resume Org',
            'slug' => 'resume-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'admin']);

        $run = SyncRun::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'status' => SyncRun::STATUS_QUEUED,
            'started_at' => now(),
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
            'meta' => ['step' => 'queued'],
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseLineReview::class, ['organisation' => $org])
            ->assertSet('activeSyncRunId', $run->id);
    }
}
