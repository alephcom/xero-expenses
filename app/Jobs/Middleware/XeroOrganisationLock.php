<?php

namespace App\Jobs\Middleware;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One Xero job per organisation at a time, shared across job classes.
 *
 * A sync replaces every local linked-transaction row while a write-back replaces some of the
 * same rows, so running both for one organisation at once can collide on the unique index.
 * Waiting jobs are released and retried; the lock expires shortly after the job's timeout so
 * a killed worker cannot hold it forever.
 */
final class XeroOrganisationLock
{
    public const RELEASE_AFTER_SECONDS = 15;

    public static function for(int $organisationId, int $jobTimeoutSeconds): WithoutOverlapping
    {
        return (new WithoutOverlapping('xero-organisation:'.$organisationId))
            ->shared()
            ->releaseAfter(self::RELEASE_AFTER_SECONDS)
            ->expireAfter($jobTimeoutSeconds + 60);
    }
}
