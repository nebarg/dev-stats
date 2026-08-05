<?php

namespace App\Console\Commands;

use App\Actions\Durations\GenerateDurations;
use App\Actions\Summaries\GenerateSummaries;
use App\Models\Heartbeat;
use App\Models\User;
use App\Support\EntityClassifier;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Imports a WakaTime data export (Settings → Account → Export) into our
 * heartbeats table, mapping every field the same way live ingestion does so the
 * rows are indistinguishable from dual-sent ones. The export references
 * user-agents and machines by id, so their string values are resolved from the
 * WakaTime API (metadata endpoints, not subject to heartbeat retention limits).
 */
#[Signature('wakatime:import
    {file : Path to the WakaTime export JSON}
    {--user= : Target user id or email (defaults to the only user)}
    {--api-key= : WakaTime API key for user-agent/machine resolution (falls back to ~/.wakatime.cfg)}')]
#[Description('Import a WakaTime data export into the heartbeats table')]
class ImportWakatimeExport extends Command
{
    private const int CHUNK = 2000;

    public function handle(GenerateDurations $generateDurations, GenerateSummaries $generateSummaries): int
    {
        ini_set('memory_limit', '4G');

        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("Export file not found: {$file}");

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $apiKey = $this->resolveApiKey();

        if ($apiKey === null) {
            $this->error('No WakaTime API key given and none found in ~/.wakatime.cfg.');

            return self::FAILURE;
        }

        $this->info('Resolving user-agents and machines from WakaTime…');
        $userAgents = $this->fetchMap($apiKey, 'user_agents');
        $machines = $this->fetchMap($apiKey, 'machine_names');
        $this->line("  {$userAgents->count()} user-agents, {$machines->count()} machines");

        $this->info('Reading export…');
        /** @var array{days?: array<int, array{heartbeats?: array<int, array<string, mixed>>}>} $export */
        $export = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $days = $export['days'] ?? [];

        $total = array_sum(array_map(static fn (array $day): int => count($day['heartbeats'] ?? []), $days));
        $this->info("Importing {$total} heartbeats for {$user->email}…");

        $bar = $this->output->createProgressBar($total);
        $imported = 0;
        $buffer = [];

        foreach ($days as $day) {
            foreach ($day['heartbeats'] ?? [] as $raw) {
                $buffer[] = $this->row($user, $raw, $userAgents, $machines);

                if (count($buffer) >= self::CHUNK) {
                    $imported += $this->flush($buffer);
                    $bar->advance(count($buffer));
                    $buffer = [];
                }
            }
        }

        if ($buffer !== []) {
            $imported += $this->flush($buffer);
            $bar->advance(count($buffer));
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Inserted {$imported} new heartbeats (duplicates ignored).");

        $this->info('Regenerating durations…');
        $durations = $generateDurations->forUser($user);

        $this->info('Rolling up summaries…');
        $user->summaries_generated_until = null;
        $user->save();
        $generateSummaries->forUser($user);

        $this->info("Done. {$durations} durations generated.");

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $identifier = $this->option('user');

        if ($identifier !== null) {
            $user = User::query()
                ->when(is_numeric($identifier), fn ($query) => $query->whereKey($identifier))
                ->when(! is_numeric($identifier), fn ($query) => $query->where('email', $identifier))
                ->first();

            if ($user === null) {
                $this->error("No user matching: {$identifier}");
            }

            return $user;
        }

        if (User::query()->count() === 1) {
            return User::query()->first();
        }

        $this->error('Multiple users exist; pass --user=<id|email>.');

        return null;
    }

    private function resolveApiKey(): ?string
    {
        $key = $this->option('api-key');

        if (is_string($key) && $key !== '') {
            return $key;
        }

        $cfg = @file_get_contents((string) getenv('HOME').'/.wakatime.cfg');

        if ($cfg !== false && preg_match('/^\s*api_key\s*=\s*(\S+)/m', $cfg, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Resolve a WakaTime metadata resource (user_agents, machine_names) keyed by id.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function fetchMap(string $apiKey, string $resource): Collection
    {
        $auth = base64_encode($apiKey);
        $rows = collect();
        $page = 1;

        do {
            $response = Http::withHeaders(['Authorization' => "Basic {$auth}"])
                ->get("https://api.wakatime.com/api/v1/users/current/{$resource}", ['page' => $page])
                ->throw()
                ->json();

            foreach ($response['data'] ?? [] as $row) {
                $rows[$row['id']] = $row;
            }

            $pages = $response['total_pages'] ?? 1;
        } while ($page++ < $pages);

        return $rows;
    }

    /**
     * Map one export heartbeat onto our columns, mirroring StoreHeartbeats so
     * imported rows match live-ingested ones (including the dedup hash).
     *
     * @param  array<string, mixed>  $raw
     * @param  Collection<string, array<string, mixed>>  $userAgents
     * @param  Collection<string, array<string, mixed>>  $machines
     * @return array<string, mixed>
     */
    private function row(User $user, array $raw, Collection $userAgents, Collection $machines): array
    {
        $userAgent = $userAgents[$raw['user_agent_id'] ?? '']['value'] ?? null;
        ['editor' => $editor, 'operating_system' => $operatingSystem] = UserAgentParser::parse($userAgent);

        $type = $raw['type'] ?? 'file';
        $recordedAt = CarbonImmutable::createFromTimestampMs((int) round(((float) $raw['time']) * 1000), 'UTC');
        $category = is_string($raw['category'] ?? null) && $raw['category'] !== ''
            ? strtolower($raw['category'])
            : null;

        $attributes = [
            'user_id' => $user->id,
            'entity' => $raw['entity'] ?? '',
            'entity_type' => $type,
            'entity_class' => EntityClassifier::classify($raw['entity'] ?? null, $type),
            'category' => $category,
            'project' => $raw['project'] ?? null,
            'branch' => $raw['branch'] ?? null,
            'language' => $raw['language'] ?? null,
            'dependencies' => empty($raw['dependencies']) ? null : json_encode($raw['dependencies']),
            'is_write' => $raw['is_write'] ?? null,
            'line_count' => $raw['lines'] ?? null,
            'line_number' => $raw['lineno'] ?? null,
            'cursor_position' => $raw['cursorpos'] ?? null,
            'project_root_count' => $raw['project_root_count'] ?? null,
            'editor' => $editor,
            'operating_system' => $operatingSystem,
            'machine' => $machines[$raw['machine_name_id'] ?? '']['name'] ?? null,
            'user_agent' => $userAgent,
            'ai_line_changes' => $raw['ai_line_changes'] ?? null,
            'human_line_changes' => $raw['human_line_changes'] ?? null,
            'ai_session' => $raw['ai_session'] ?? null,
            'ai_subscription_plan' => $raw['ai_subscription_plan'] ?? null,
            'ai_input_tokens' => $raw['ai_input_tokens'] ?? null,
            'ai_output_tokens' => $raw['ai_output_tokens'] ?? null,
            'ai_prompt_length' => $raw['ai_prompt_length'] ?? null,
            'recorded_at' => $recordedAt->format('Y-m-d H:i:s.v'),
        ];

        $attributes['hash'] = $this->hash($attributes, $recordedAt);

        return $attributes;
    }

    /**
     * Mirrors HeartbeatMapper::hash so an imported heartbeat and a later
     * dual-sent copy of it collapse to one row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function hash(array $attributes, CarbonImmutable $recordedAt): string
    {
        $identity = [
            $attributes['user_id'],
            $attributes['entity'],
            $attributes['entity_type'],
            $attributes['category'],
            $attributes['project'],
            $attributes['branch'],
            $attributes['language'],
            match ($attributes['is_write']) {
                true => '1',
                false => '0',
                default => '',
            },
            $attributes['ai_session'],
            $recordedAt->format('Y-m-d H:i:s.v'),
        ];

        return hash('sha256', implode('|', $identity));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return int the number of rows newly inserted
     */
    private function flush(array $rows): int
    {
        return Heartbeat::query()->insertOrIgnore($rows);
    }
}
