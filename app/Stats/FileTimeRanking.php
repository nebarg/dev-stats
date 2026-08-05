<?php

namespace App\Stats;

use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Time per file over the period, live from heartbeats: the gap to each heartbeat,
 * when under the timeout and within the same day, is credited to the file of the
 * heartbeat that opened it (non-file heartbeats break the chain). Ranked by time
 * — the one authorship-independent file signal, so it works for pre-2026 years.
 */
class FileTimeRanking
{
    private const int TOP_LIMIT = 8;

    /**
     * @return array<int, array{key: string, seconds: int}>
     */
    public function forUser(User $user, CarbonImmutable $from, CarbonImmutable $dataEnd, string $timezone): array
    {
        $timeoutMs = (int) config('stats.heartbeat_timeout_sec') * 1000;

        $files = [];
        $previousEntity = null;
        $previousTimeMs = 0;
        $previousDay = null;

        $heartbeats = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $dataEnd)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->lazy();

        foreach ($heartbeats as $heartbeat) {
            $timeMs = (int) $heartbeat->recorded_at->getPreciseTimestamp(3);
            $day = $heartbeat->recorded_at->setTimezone($timezone)->toDateString();

            if ($previousEntity !== null && $previousDay === $day) {
                $gapMs = $timeMs - $previousTimeMs;

                if ($gapMs > 0 && $gapMs < $timeoutMs) {
                    $files[$previousEntity] = ($files[$previousEntity] ?? 0) + $gapMs;
                }
            }

            $previousEntity = $heartbeat->entity_type === 'file' ? $heartbeat->entity : null;
            $previousTimeMs = $timeMs;
            $previousDay = $day;
        }

        return collect($files)
            ->map(static fn (int $milliseconds, string $entity): array => [
                'key' => basename(str_replace('\\', '/', $entity)),
                'seconds' => (int) round($milliseconds / 1000),
            ])
            ->sortByDesc('seconds')
            ->take(self::TOP_LIMIT)
            ->values()
            ->all();
    }
}
