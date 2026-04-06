<?php

namespace App\Support;

use App\Jobs\PushLineReviewToXeroJob;
use App\Jobs\SyncXeroOrganisationJob;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QueueJobLog
{
    /**
     * Register queue lifecycle listeners (queued, processing, processed, failed).
     */
    public static function register(): void
    {
        if (! config('queue.log_jobs', true)) {
            return;
        }

        Event::listen(JobQueued::class, [self::class, 'onQueued']);
        Event::listen(JobProcessing::class, [self::class, 'onProcessing']);
        Event::listen(JobProcessed::class, [self::class, 'onProcessed']);
        Event::listen(JobFailed::class, [self::class, 'onFailed']);
    }

    public static function onQueued(JobQueued $event): void
    {
        $context = [
            'event' => 'queued',
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'id' => $event->id,
            'delay' => $event->delay,
        ];

        if (is_object($event->job)) {
            $context['name'] = $event->job::class;
            $data = self::publicJobData($event->job);
            if ($data !== null) {
                $context['job'] = $data;
            }
        } elseif (is_string($event->job)) {
            $context['name'] = $event->job;
        }

        try {
            $decoded = $event->payload();
            $context['display_name'] = $decoded['displayName'] ?? null;
            $context['uuid'] = $decoded['uuid'] ?? null;
        } catch (Throwable) {
            // Payload may be invalid for some drivers; keep minimal context.
        }

        Log::info('queue.job', array_filter($context, fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    public static function onProcessing(JobProcessing $event): void
    {
        Log::info('queue.job', self::contextFromWorkerJob($event->connectionName, 'processing', $event->job));
    }

    public static function onProcessed(JobProcessed $event): void
    {
        Log::info('queue.job', self::contextFromWorkerJob($event->connectionName, 'processed', $event->job));
    }

    public static function onFailed(JobFailed $event): void
    {
        $context = self::contextFromWorkerJob($event->connectionName, 'failed', $event->job);
        $context['exception'] = $event->exception::class;
        $context['message'] = $event->exception->getMessage();

        Log::error('queue.job', $context);
    }

    /**
     * @return array<string, mixed>
     */
    private static function contextFromWorkerJob(string $connection, string $phase, QueueJobContract $job): array
    {
        $payload = $job->payload();

        $context = [
            'event' => $phase,
            'connection' => $connection,
            'uuid' => $job->uuid(),
            'job_id' => $job->getJobId(),
            'name' => $job->resolveName(),
            'class' => $job->resolveQueuedJobClass(),
            'queue' => $job->getQueue(),
            'attempts' => $job->attempts(),
            'max_tries' => $job->maxTries(),
        ];

        $data = self::tryUnserializeJobPayload($payload);
        if ($data !== null) {
            $context['job'] = $data;
        }

        return array_filter($context, fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function tryUnserializeJobPayload(array $payload): ?array
    {
        $serialized = $payload['data']['command'] ?? null;
        if (! is_string($serialized)) {
            return null;
        }

        try {
            $command = unserialize($serialized, [
                'allowed_classes' => [
                    PushLineReviewToXeroJob::class,
                    SyncXeroOrganisationJob::class,
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! is_object($command)) {
            return null;
        }

        return self::publicJobData($command);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function publicJobData(object $job): ?array
    {
        return match (true) {
            $job instanceof SyncXeroOrganisationJob => [
                'organisation_id' => $job->organisationId,
                'date_from' => $job->dateFrom,
                'date_to' => $job->dateTo,
                'initiated_by_user_id' => $job->initiatedByUserId,
                'sync_run_id' => $job->syncRunId,
            ],
            $job instanceof PushLineReviewToXeroJob => [
                'organisation_id' => $job->organisationId,
                'source_kind' => $job->sourceKind,
                'parent_transaction_id' => $job->parentTransactionId,
                'line_item_id' => $job->lineItemId,
            ],
            default => null,
        };
    }
}
