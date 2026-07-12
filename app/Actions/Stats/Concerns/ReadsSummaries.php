<?php

namespace App\Actions\Stats\Concerns;

use App\Models\DailyMetric;
use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side access to the pre-aggregated summary tables. Whole past days up
 * to the user's generation marker come from `summary_items`/`daily_metrics`;
 * days beyond it (always including today) are computed live by the callers
 * and merged. A covered day without rows is a genuine zero-activity day.
 */
trait ReadsSummaries
{
    /**
     * The last day (as midnight in the user's timezone) whose stored
     * summaries may be used, capped at yesterday — today is never read from
     * storage. Null when nothing is stored.
     */
    private static function summariesCoveredUntil(User $user, CarbonImmutable $today): ?CarbonImmutable
    {
        $marker = $user->summaries_generated_until;

        if ($marker === null) {
            return null;
        }

        return CarbonImmutable::parse($marker->toDateString(), $user->timezone)->min($today->subDay());
    }

    /**
     * Stored headline seconds keyed by day (Y-m-d). Days with no activity are
     * absent, matching the live per-day aggregation.
     *
     * @return array<string, int>
     */
    private static function storedSecondsPerDay(User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return self::summaryDayTotals(
            SummaryItem::query()->where('type', SummaryItem::HEADLINE_TYPE),
            $user,
            $from,
            $until,
        );
    }

    /**
     * Stored seconds per day for one category key (e.g. `ai coding`).
     *
     * @return array<string, int>
     */
    private static function storedCategorySecondsPerDay(User $user, CarbonImmutable $from, CarbonImmutable $until, string $category): array
    {
        return self::summaryDayTotals(
            SummaryItem::query()->where('type', 'category')->where('key', $category),
            $user,
            $from,
            $until,
        );
    }

    /**
     * Stored seconds per bucket key ('' for null) for one summary type over
     * the covered window.
     *
     * @return array<string, int>
     */
    private static function storedBucketTotals(User $user, CarbonImmutable $from, CarbonImmutable $until, string $type): array
    {
        $rows = SummaryItem::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', $until->addDay()->toDateString())
            ->groupBy('key')
            ->select('key')
            ->selectRaw('SUM(total_seconds) AS total_seconds')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->key ?? ''] = (int) $row->total_seconds;
        }

        return $totals;
    }

    /**
     * Stored net AI/human line changes keyed by day (Y-m-d).
     *
     * @return array<string, array{ai_lines: int, human_lines: int}>
     */
    private static function storedLinesPerDay(User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = DailyMetric::query()
            ->where('user_id', $user->id)
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', $until->addDay()->toDateString())
            ->groupBy('day')
            ->orderBy('day')
            ->select('day')
            ->selectRaw('SUM(ai_lines) AS ai_lines, SUM(human_lines) AS human_lines')
            ->get();

        $perDay = [];

        foreach ($rows as $row) {
            $perDay[$row->day->toDateString()] = [
                'ai_lines' => (int) $row->ai_lines,
                'human_lines' => (int) $row->human_lines,
            ];
        }

        return $perDay;
    }

    /**
     * Stored net AI/human line changes keyed by project ('' for null).
     *
     * @return array<string, array{ai_lines: int, human_lines: int}>
     */
    private static function storedProjectLineTotals(User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = DailyMetric::query()
            ->where('user_id', $user->id)
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', $until->addDay()->toDateString())
            ->groupBy('project')
            ->select('project')
            ->selectRaw('SUM(ai_lines) AS ai_lines, SUM(human_lines) AS human_lines')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->project ?? ''] = [
                'ai_lines' => (int) $row->ai_lines,
                'human_lines' => (int) $row->human_lines,
            ];
        }

        return $totals;
    }

    /**
     * Sum keyed integer maps (e.g. stored and live per-day seconds).
     *
     * @param  array<string, int>  ...$maps
     * @return array<string, int>
     */
    private static function mergeTotals(array ...$maps): array
    {
        $merged = [];

        foreach ($maps as $map) {
            foreach ($map as $key => $value) {
                $merged[$key] = ($merged[$key] ?? 0) + $value;
            }
        }

        return $merged;
    }

    /**
     * @param  Builder<SummaryItem>  $query
     * @return array<string, int>
     */
    private static function summaryDayTotals(Builder $query, User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = $query
            ->where('user_id', $user->id)
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', $until->addDay()->toDateString())
            ->groupBy('day')
            ->orderBy('day')
            ->select('day')
            ->selectRaw('SUM(total_seconds) AS total_seconds')
            ->get();

        $perDay = [];

        foreach ($rows as $row) {
            $perDay[$row->day->toDateString()] = (int) $row->total_seconds;
        }

        return $perDay;
    }
}
