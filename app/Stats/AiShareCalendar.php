<?php

namespace App\Stats;

use App\Models\Heartbeat;
use App\Models\User;
use App\Stats\Support\StoredLiveWindow;
use Carbon\CarbonImmutable;

/**
 * Net AI and human line changes per day for the AI-share calendar: stored daily
 * metrics for covered days, heartbeats for the live tail. Days without line
 * data are present with zeros so the grid stays continuous.
 */
class AiShareCalendar
{
    public function __construct(private readonly SummaryReader $summaries) {}

    /**
     * @return array<int, array{date: string, ai_lines: int, human_lines: int}>
     */
    public function forUser(User $user, StoredLiveWindow $window, CarbonImmutable $to, string $timezone): array
    {
        $perDay = $window->hasStored() ? $this->summaries->linesPerDay($user, $window->from, $window->storedUntil) : [];

        $heartbeats = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($window->liveFrom(), $window->through)
            ->withLineChanges()
            ->select(['recorded_at', 'ai_line_changes', 'human_line_changes'])
            ->lazy();

        foreach ($heartbeats as $heartbeat) {
            $day = $heartbeat->recorded_at->setTimezone($timezone)->toDateString();
            $totals = $perDay[$day] ?? ['ai_lines' => 0, 'human_lines' => 0];
            $totals['ai_lines'] += $heartbeat->ai_line_changes ?? 0;
            $totals['human_lines'] += $heartbeat->human_line_changes ?? 0;
            $perDay[$day] = $totals;
        }

        $calendar = [];

        for ($day = $window->from; $day <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $calendar[] = [
                'date' => $date,
                'ai_lines' => $perDay[$date]['ai_lines'] ?? 0,
                'human_lines' => $perDay[$date]['human_lines'] ?? 0,
            ];
        }

        return $calendar;
    }
}
