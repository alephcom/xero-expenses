<?php

namespace Tests\Unit\Support;

use App\Support\ExpenseReviewDatePresets;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpenseReviewDatePresetsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_last_month_is_previous_calendar_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05 12:00:00', 'UTC'));

        [$from, $to] = ExpenseReviewDatePresets::rangeFor(ExpenseReviewDatePresets::LAST_MONTH);

        $this->assertSame('2026-03-01', $from);
        $this->assertSame('2026-03-31', $to);
    }

    public function test_last_year_is_previous_calendar_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05', 'UTC'));

        [$from, $to] = ExpenseReviewDatePresets::rangeFor(ExpenseReviewDatePresets::LAST_YEAR);

        $this->assertSame('2025-01-01', $from);
        $this->assertSame('2025-12-31', $to);
    }

    public function test_last_quarter_is_previous_calendar_quarter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05', 'UTC'));

        [$from, $to] = ExpenseReviewDatePresets::rangeFor(ExpenseReviewDatePresets::LAST_QUARTER);

        $this->assertSame('2026-01-01', $from);
        $this->assertSame('2026-03-31', $to);
    }

    public function test_last_week_uses_sub_week_boundaries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05 12:00:00', 'UTC'));

        [$from, $to] = ExpenseReviewDatePresets::rangeFor(ExpenseReviewDatePresets::LAST_WEEK);

        $this->assertSame('2026-03-23', $from);
        $this->assertSame('2026-03-29', $to);
    }

    #[DataProvider('unknownPresetProvider')]
    public function test_unknown_preset_falls_back_to_last_month(string $preset): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05', 'UTC'));

        [$from, $to] = ExpenseReviewDatePresets::rangeFor($preset);

        $this->assertSame('2026-03-01', $from);
        $this->assertSame('2026-03-31', $to);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unknownPresetProvider(): iterable
    {
        yield 'custom' => ['custom'];
        yield 'empty' => [''];
        yield 'bogus' => ['bogus'];
    }
}
