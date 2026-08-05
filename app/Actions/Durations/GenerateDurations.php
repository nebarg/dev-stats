<?php

namespace App\Actions\Durations;

use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a user's `durations` (coding sessions) from their raw heartbeats.
 *
 * Port of wakapi's programmatic sessionization (services/duration.go `getLive`):
 * heartbeats ordered by time are folded into sessions, where each within-timeout
 * gap extends the current session; a gap >= the timeout, a change of grouping
 * key, or a day boundary (in the user's timezone) starts a new session.
 *
 * `durations` is a regenerable cache, so this replaces the user's existing rows.
 */
class GenerateDurations
{
    /**
     * @return int the number of durations generated
     */
    public static function forUser(User $user): int
    {
        $sessions = self::foldHeartbeatsIntoSessions($user);

        return self::replaceDurations($user, $sessions);
    }

    /**
     * Fold the user's heartbeats, in time order, into coding sessions.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function foldHeartbeatsIntoSessions(User $user): array
    {
        $timeoutMs = self::timeoutSeconds() * 1000;
        $timezone = $user->timezone;

        $sessions = [];
        $current = null;

        $heartbeats = Heartbeat::query()
            ->forUser($user)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->lazy();

        foreach ($heartbeats as $heartbeat) {
            $group = self::groupHash($heartbeat);
            $timeMs = (int) $heartbeat->recorded_at->getPreciseTimestamp(3);

            if ($current === null) {
                $current = self::newSession($user, $heartbeat, $group, $timeMs);

                continue;
            }

            $isSameDay = $heartbeat->recorded_at->setTimezone($timezone)->isSameDay(
                Date::createFromTimestampMs($current['started_at_ms'], 'UTC')->setTimezone($timezone)
            );

            $gapMs = $isSameDay ? $timeMs - ($current['started_at_ms'] + $current['duration_ms']) : 0;

            // A within-timeout gap extends the running session up to this
            // heartbeat, even when the heartbeat then opens a new session.
            if ($gapMs > 0 && $gapMs < $timeoutMs) {
                $current['duration_ms'] += $gapMs;
            }

            $continuesSession = $isSameDay
                && $current['group_hash'] === $group
                && $gapMs < $timeoutMs;

            if ($continuesSession) {
                $current['heartbeat_count']++;

                continue;
            }

            $sessions[] = $current;
            $current = self::newSession($user, $heartbeat, $group, $timeMs);
        }

        if ($current !== null) {
            $sessions[] = $current;
        }

        return $sessions;
    }

    /**
     * Replace the user's durations with rows built from the given sessions.
     *
     * @param  array<int, array<string, mixed>>  $sessions
     * @return int the number of durations generated
     *
     * @throws \Throwable
     */
    private static function replaceDurations(User $user, array $sessions): int
    {
        return DB::transaction(static function () use ($user, $sessions): int {
            Duration::query()->forUser($user)->delete();

            $rows = array_map(self::toRow(...), $sessions);

            foreach (array_chunk($rows, 500) as $chunk) {
                Duration::insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function newSession(User $user, Heartbeat $heartbeat, string $group, int $timeMs): array
    {
        return [
            'user_id' => $user->id,
            'started_at_ms' => $timeMs,
            'duration_ms' => 0,
            'project' => $heartbeat->project,
            'language' => $heartbeat->language,
            'editor' => $heartbeat->editor,
            'operating_system' => $heartbeat->operating_system,
            'machine' => $heartbeat->machine,
            'branch' => $heartbeat->branch,
            'category' => $heartbeat->category,
            'heartbeat_count' => 1,
            'group_hash' => $group,
            'timeout_seconds' => self::timeoutSeconds(),
        ];
    }

    private static function timeoutSeconds(): int
    {
        return config('stats.heartbeat_timeout_sec');
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private static function toRow(array $session): array
    {
        $now = Date::now();

        return [
            'user_id' => $session['user_id'],
            'started_at' => Date::createFromTimestampMs($session['started_at_ms'], 'UTC')->format('Y-m-d H:i:s.v'),
            'duration_seconds' => (int) round($session['duration_ms'] / 1000),
            'project' => $session['project'],
            'language' => $session['language'],
            'editor' => $session['editor'],
            'operating_system' => $session['operating_system'],
            'machine' => $session['machine'],
            'branch' => $session['branch'],
            'category' => $session['category'],
            'heartbeat_count' => $session['heartbeat_count'],
            'group_hash' => $session['group_hash'],
            'timeout_seconds' => $session['timeout_seconds'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function groupHash(Heartbeat $heartbeat): string
    {
        return hash('sha256', implode('|', [
            $heartbeat->project ?? '',
            $heartbeat->language ?? '',
            $heartbeat->editor ?? '',
            $heartbeat->operating_system ?? '',
            $heartbeat->machine ?? '',
            $heartbeat->branch ?? '',
            $heartbeat->category ?? '',
        ]));
    }
}
