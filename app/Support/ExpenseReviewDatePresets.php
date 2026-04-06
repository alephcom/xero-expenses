<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class ExpenseReviewDatePresets
{
    public const LAST_WEEK = 'last_week';

    public const LAST_MONTH = 'last_month';

    public const LAST_QUARTER = 'last_quarter';

    public const LAST_YEAR = 'last_year';

    public const CUSTOM = 'custom';

    /** @return list<string> */
    public static function presetKeys(): array
    {
        return [
            self::LAST_WEEK,
            self::LAST_MONTH,
            self::LAST_QUARTER,
            self::LAST_YEAR,
        ];
    }

    /**
     * @return array{0: string, 1: string} dateFrom, dateTo (Y-m-d)
     */
    public static function rangeFor(string $preset, ?CarbonInterface $now = null): array
    {
        $now = $now ?? now();

        return match ($preset) {
            self::LAST_WEEK => self::lastWeek($now),
            self::LAST_MONTH => self::lastMonth($now),
            self::LAST_QUARTER => self::lastQuarter($now),
            self::LAST_YEAR => self::lastYear($now),
            default => self::lastMonth($now),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function lastWeek(CarbonInterface $now): array
    {
        $w = $now->copy()->subWeek();

        return [
            $w->copy()->startOfWeek()->toDateString(),
            $w->copy()->endOfWeek()->toDateString(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function lastMonth(CarbonInterface $now): array
    {
        $ref = $now->copy()->startOfMonth()->subDay();

        return [
            $ref->copy()->startOfMonth()->toDateString(),
            $ref->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function lastQuarter(CarbonInterface $now): array
    {
        $ref = $now->copy()->startOfQuarter()->subDay();

        return [
            $ref->copy()->startOfQuarter()->toDateString(),
            $ref->copy()->endOfQuarter()->toDateString(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function lastYear(CarbonInterface $now): array
    {
        $year = $now->year - 1;

        return [
            sprintf('%d-01-01', $year),
            sprintf('%d-12-31', $year),
        ];
    }
}
