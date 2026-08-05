<?php

namespace App\Stats;

use App\Models\DailyMetric;
use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side access to the pre-aggregated summary tables. Whole past days up to
 * the user's generation marker come from `summary_items`/`daily_metrics`; days
 * beyond it (always including today) are computed live by the callers and
 * merged. A covered day without rows is a genuine zero-activity day.
 */
class SummaryReader
{
    /**
     * The last day (midnight in the user's timezone) whose stored summaries may
     * be used, capped at yesterday — today is never read from storage. Null
     * when nothing is stored.
     */
    public function coveredUntil(User $user, CarbonImmutable $today): ?CarbonImmutable
    {
        $marker = $user->summaries_generated_until;

        if ($marker === null) {
            return null;
        }

        return CarbonImmutable::parse($marker->toDateString(), $user->timezone)->min($today->subDay());
    }

    /**
     * Stored headline seconds keyed by day (Y-m-d).
     *
     * @return array<string, int>
     */
    public function secondsPerDay(User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->dayTotals(
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
    public function categorySecondsPerDay(User $user, CarbonImmutable $from, CarbonImmutable $until, string $category): array
    {
        return $this->dayTotals(
            SummaryItem::query()->where('type', 'category')->where('key', $category),
            $user,
            $from,
            $until,
        );
    }

    /**
     * Stored seconds per bucket key ('' for null) for one summary type over the
     * covered window.
     *
     * @return array<string, int>
     */
    public function bucketTotals(User $user, CarbonImmutable $from, CarbonImmutable $until, string $type): array
    {
        $rows = SummaryItem::query()
            ->forUser($user)
            ->where('type', $type)
            ->forDayRange($from, $until)
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
    public function linesPerDay(User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = DailyMetric::query()
            ->forUser($user)
            ->forDayRange($from, $until)
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
    public function projectLineTotals(User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = DailyMetric::query()
            ->forUser($user)
            ->forDayRange($from, $until)
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
     * @param  Builder<SummaryItem>  $query
     * @return array<string, int>
     */
    private function dayTotals(Builder $query, User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = $query
            ->forUser($user)
            ->forDayRange($from, $until)
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
