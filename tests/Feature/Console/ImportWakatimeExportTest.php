<?php

use App\Models\Heartbeat;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeWakatimeMetadata(): void
{
    Http::fake([
        'api.wakatime.com/api/v1/users/current/user_agents*' => Http::response([
            'data' => [[
                'id' => 'ua-1',
                'value' => 'wakatime/v1.0 (darwin-arm64-go1.22) go1.22 phpstorm-wakatime/1.0',
                'editor' => 'PhpStorm',
                'os' => 'Mac',
            ]],
            'total_pages' => 1,
        ]),
        'api.wakatime.com/api/v1/users/current/machine_names*' => Http::response([
            'data' => [['id' => 'm-1', 'name' => 'Grants-MBP.local']],
            'total_pages' => 1,
        ]),
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $heartbeats
 */
function writeExport(array $heartbeats): string
{
    $path = tempnam(sys_get_temp_dir(), 'wt').'.json';
    file_put_contents($path, json_encode([
        'days' => [['date' => '2026-06-30', 'heartbeats' => $heartbeats]],
    ]));

    return $path;
}

test('it imports heartbeats, mapping fields the way live ingestion does', function () {
    fakeWakatimeMetadata();
    $user = User::factory()->create();

    $file = writeExport([
        [
            'entity' => '/app/Http/Kernel.php', 'type' => 'file', 'category' => 'Coding',
            'project' => 'app', 'branch' => 'main', 'language' => 'PHP', 'is_write' => true,
            'lines' => 120, 'time' => 1782300000.5, 'user_agent_id' => 'ua-1', 'machine_name_id' => 'm-1',
        ],
        [
            'entity' => '/app/Foo.php', 'type' => 'file', 'category' => 'AI Coding',
            'project' => 'app', 'language' => 'PHP', 'is_write' => false, 'ai_session' => 'sess-1',
            'ai_input_tokens' => 1000, 'ai_output_tokens' => 50, 'time' => 1782300060.0,
            'user_agent_id' => 'ua-1', 'machine_name_id' => 'm-1',
        ],
    ]);

    $this->artisan('wakatime:import', ['file' => $file, '--user' => $user->id, '--api-key' => 'test'])
        ->assertSuccessful();

    expect(Heartbeat::query()->count())->toBe(2);

    $first = Heartbeat::query()->where('entity', '/app/Http/Kernel.php')->sole();
    expect($first->editor)->toBe('phpstorm')
        ->and($first->operating_system)->toBe('macos')
        ->and($first->machine)->toBe('Grants-MBP.local')
        ->and($first->category)->toBe('coding')
        ->and($first->is_write)->toBeTrue()
        ->and($first->recorded_at->format('Y-m-d H:i:s.v'))->toBe('2026-06-24 11:20:00.500');

    $ai = Heartbeat::query()->where('ai_session', 'sess-1')->sole();
    expect($ai->category)->toBe('ai coding')
        ->and($ai->ai_input_tokens)->toBe(1000);

    unlink($file);
});

test('a second import of the same export inserts no duplicates', function () {
    fakeWakatimeMetadata();
    $user = User::factory()->create();

    $file = writeExport([[
        'entity' => '/app/Foo.php', 'type' => 'file', 'category' => 'Coding',
        'project' => 'app', 'language' => 'PHP', 'is_write' => true,
        'time' => 1782300000.0, 'user_agent_id' => 'ua-1', 'machine_name_id' => 'm-1',
    ]]);

    $this->artisan('wakatime:import', ['file' => $file, '--user' => $user->id, '--api-key' => 'test']);
    $this->artisan('wakatime:import', ['file' => $file, '--user' => $user->id, '--api-key' => 'test']);

    expect(Heartbeat::query()->count())->toBe(1);

    unlink($file);
});
