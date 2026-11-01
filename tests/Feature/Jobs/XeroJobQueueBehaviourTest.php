<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrganisationRole;
use App\Jobs\PushLineReviewToXeroJob;
use App\Jobs\SyncXeroOrganisationJob;
use App\Jobs\SyncXeroReviewerAccessJob;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Xero\XeroLineReviewWritebackService;
use App\Services\Xero\XeroSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class XeroJobQueueBehaviourTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_timeouts_stay_below_queue_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan((new SyncXeroOrganisationJob(1, '2026-01-01', '2026-01-31', 1))->timeout, $retryAfter);
        $this->assertGreaterThan((new PushLineReviewToXeroJob(1, 'spend', 'p', 'l'))->timeout, $retryAfter);
        $this->assertGreaterThan((new SyncXeroReviewerAccessJob(1))->timeout, $retryAfter);
    }

    public function test_reviewer_access_job_outlasts_rate_limit_waits_and_unique_lock_outlasts_it(): void
    {
        $job = new SyncXeroReviewerAccessJob(1);

        // XeroApiClient may wait up to 3 x 60s on a single 429; the worker default of 60s would kill it.
        $this->assertGreaterThanOrEqual(180, $job->timeout);
        $this->assertGreaterThanOrEqual($job->timeout, $job->uniqueFor);
    }

    public function test_sync_and_push_share_one_lock_per_organisation(): void
    {
        $sync = (new SyncXeroOrganisationJob(7, '2026-01-01', '2026-01-31', 1))->middleware()[0];
        $push = (new PushLineReviewToXeroJob(7, 'spend', 'p', 'l'))->middleware()[0];
        $otherOrg = (new PushLineReviewToXeroJob(8, 'spend', 'p', 'l'))->middleware()[0];

        $syncJob = new SyncXeroOrganisationJob(7, '2026-01-01', '2026-01-31', 1);
        $pushJob = new PushLineReviewToXeroJob(7, 'spend', 'p', 'l');

        $this->assertSame($sync->getLockKey($syncJob), $push->getLockKey($pushJob));
        $this->assertNotSame($push->getLockKey($pushJob), $otherOrg->getLockKey($pushJob));
    }

    public function test_push_does_not_run_while_a_sync_holds_the_organisation_lock(): void
    {
        $org = Organisation::create([
            'name' => 'Locked Org',
            'slug' => 'locked-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $writeback = Mockery::mock(XeroLineReviewWritebackService::class);
        $writeback->shouldNotReceive('push');
        $this->app->instance(XeroLineReviewWritebackService::class, $writeback);

        $lock = Cache::lock('laravel-queue-overlap:xero-organisation:'.$org->id, 60);
        $this->assertTrue($lock->get());

        try {
            PushLineReviewToXeroJob::dispatch($org->id, 'spend', (string) Str::uuid(), (string) Str::uuid());
        } finally {
            $lock->release();
        }
    }

    public function test_sync_runs_when_the_organisation_lock_is_free(): void
    {
        $org = Organisation::create([
            'name' => 'Free Org',
            'slug' => 'free-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => OrganisationRole::Admin->value]);

        $sync = Mockery::mock(XeroSyncService::class);
        $sync->shouldReceive('sync')->once();
        $this->app->instance(XeroSyncService::class, $sync);

        SyncXeroOrganisationJob::dispatch($org->id, '2026-01-01', '2026-01-31', $user->id);

        $this->assertTrue(
            Cache::lock('laravel-queue-overlap:xero-organisation:'.$org->id, 60)->get(),
            'The organisation lock should be released once the sync finishes.',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
