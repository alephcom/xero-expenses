<?php

namespace App\Jobs\Middleware;

use Closure;

final class EnsureMinimumDuration
{
    public function __construct(
        private readonly int $seconds = 5,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $start = microtime(true);

        try {
            $next($job);
        } finally {
            $remaining = $this->seconds - (microtime(true) - $start);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }
    }
}
