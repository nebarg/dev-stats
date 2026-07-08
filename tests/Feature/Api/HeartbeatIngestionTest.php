<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Models\Heartbeat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

const BULK_ENDPOINT = '/api/v1/users/current/heartbeats.bulk';
const HB_USER_AGENT = 'wakatime/v1.73.0 (darwin-arm64-go1.22) go1.22 vscode-wakatime/24.0.0';

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, string>
 */
function hbAuth(User $user, array $extra = []): array
{
    return array_merge([
        'Authorization' => 'Basic '.base64_encode($user->api_key),
        'User-Agent' => HB_USER_AGENT,
        'X-Machine-Name' => 'devbook',
    ], $extra);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function hbPayload(array $overrides = []): array
{
    return array_merge([
        'entity' => '/code/app/Http/Controllers/X.php',
        'type' => 'file',
        'category' => 'coding',
        'project' => 'dev-stats',
        'branch' => 'main',
        'language' => 'PHP',
        'is_write' => true,
        'lines' => 100,
        'lineno' => 42,
        'cursorpos' => 7,
        'time' => 1719580800.5,
    ], $overrides);
}

test('it ingests a bulk heartbeat payload and returns the bulk response shape', function () {
    $user = User::factory()->create();

    $response = $this->postJson(BULK_ENDPOINT, [hbPayload()], hbAuth($user));

    $response->assertCreated()
        ->assertJsonCount(1, 'responses')
        ->assertJsonPath('responses.0.1', 201)
        ->assertJsonPath('responses.0.0.data.entity', '/code/app/Http/Controllers/X.php')
        ->assertJsonPath('responses.0.0.data.type', 'file');

    expect($response->json('responses.0.0.data.id'))->toBeString();

    $heartbeat = Heartbeat::query()->firstOrFail();

    expect($heartbeat->user_id)->toBe($user->id)
        ->and($heartbeat->entity_type)->toBe('file')
        ->and($heartbeat->entity_class)->toBe('source')
        ->and($heartbeat->line_count)->toBe(100)
        ->and($heartbeat->line_number)->toBe(42)
        ->and($heartbeat->cursor_position)->toBe(7)
        ->and($heartbeat->is_write)->toBeTrue()
        ->and($heartbeat->editor)->toBe('vscode')
        ->and($heartbeat->operating_system)->toBe('macos')
        ->and($heartbeat->machine)->toBe('devbook');
});

test('it classifies agent files on ingest', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [hbPayload([
        'entity' => '/Users/dev/.claude/projects/x/memory/notes.md',
    ])], hbAuth($user))->assertCreated();

    expect(Heartbeat::query()->firstOrFail()->entity_class)->toBe('agent');
});

test('it stores AI coding fields including a negative net line change and subscription plan', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [hbPayload([
        'category' => 'ai coding',
        'ai_line_changes' => -12,
        'human_line_changes' => 3,
        'ai_session' => 'sess-1',
        'ai_subscription_plan' => 'claude-max',
        'ai_input_tokens' => 515458,
        'ai_output_tokens' => 329,
        'ai_prompt_length' => 104,
    ])], hbAuth($user))->assertCreated();

    $heartbeat = Heartbeat::query()->firstOrFail();

    expect($heartbeat->category)->toBe('ai coding')
        ->and($heartbeat->ai_line_changes)->toBe(-12)
        ->and($heartbeat->human_line_changes)->toBe(3)
        ->and($heartbeat->ai_session)->toBe('sess-1')
        ->and($heartbeat->ai_subscription_plan)->toBe('claude-max')
        ->and($heartbeat->ai_input_tokens)->toBe(515458)
        ->and($heartbeat->ai_output_tokens)->toBe(329)
        ->and($heartbeat->ai_prompt_length)->toBe(104);
});

test('it stores detected dependencies as an array', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [hbPayload([
        'dependencies' => ['laravel/framework', 'pestphp/pest', 42],
    ])], hbAuth($user))->assertCreated();

    $heartbeat = Heartbeat::query()->firstOrFail();

    expect($heartbeat->dependencies)->toBe(['laravel/framework', 'pestphp/pest']);
});

test('it rejects requests without a valid api key', function () {
    $this->postJson(BULK_ENDPOINT, [hbPayload()], ['User-Agent' => HB_USER_AGENT])
        ->assertUnauthorized();

    $this->postJson(BULK_ENDPOINT, [hbPayload()], [
        'Authorization' => 'Basic '.base64_encode('not-a-real-key'),
    ])->assertUnauthorized();

    expect(Heartbeat::query()->count())->toBe(0);
});

test('it accepts the api key as a query parameter', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT.'?api_key='.$user->api_key, [hbPayload()], ['User-Agent' => HB_USER_AGENT])
        ->assertCreated();

    expect(Heartbeat::query()->count())->toBe(1);
});

test('it strips the api_key query parameter once authenticated', function () {
    $user = User::factory()->create();

    $request = Request::create(BULK_ENDPOINT.'?api_key='.$user->api_key, 'POST');

    $reachedApp = false;

    app(AuthenticateApiKey::class)->handle($request, function (Request $request) use (&$reachedApp) {
        $reachedApp = true;

        expect($request->query('api_key'))->toBeNull()
            ->and($request->query())->not->toHaveKey('api_key');

        return response('ok');
    });

    expect($reachedApp)->toBeTrue();
});

test('it deduplicates identical heartbeats across requests', function () {
    $user = User::factory()->create();

    $first = $this->postJson(BULK_ENDPOINT, [hbPayload()], hbAuth($user));
    $second = $this->postJson(BULK_ENDPOINT, [hbPayload()], hbAuth($user));

    expect(Heartbeat::query()->count())->toBe(1)
        ->and($first->json('responses.0.0.data.id'))->toBe($second->json('responses.0.0.data.id'));
});

test('it resolves last-value placeholders from the latest heartbeat', function () {
    $user = User::factory()->create();
    Heartbeat::factory()->forUser($user)->create([
        'project' => 'previous-project',
        'recorded_at' => now()->subMinutes(5),
    ]);

    $this->postJson(BULK_ENDPOINT, [hbPayload(['project' => '<<LAST_PROJECT>>'])], hbAuth($user))
        ->assertCreated();

    $stored = Heartbeat::query()->latest('id')->firstOrFail();

    expect($stored->project)->toBe('previous-project');
});

test('it clears placeholders and infers browsing category for browser heartbeats', function () {
    $user = User::factory()->create();
    Heartbeat::factory()->forUser($user)->create([
        'project' => 'previous-project',
        'recorded_at' => now()->subMinutes(5),
    ]);

    $this->postJson(BULK_ENDPOINT, [hbPayload([
        'type' => 'domain',
        'entity' => 'github.com',
        'project' => '<<LAST_PROJECT>>',
        'category' => null,
        'language' => null,
    ])], hbAuth($user))->assertCreated();

    $stored = Heartbeat::query()->where('entity_type', 'domain')->firstOrFail();

    expect($stored->project)->toBeNull()
        ->and($stored->category)->toBe('browsing');
});

test('it rejects heartbeats too far in the future per item', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [hbPayload(['time' => now()->addDay()->timestamp])], hbAuth($user))
        ->assertCreated()
        ->assertJsonPath('responses.0.1', 400);

    expect(Heartbeat::query()->count())->toBe(0);
});

test('the non-bulk alias accepts a single heartbeat object', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/users/current/heartbeats', hbPayload(), hbAuth($user))
        ->assertCreated()
        ->assertJsonCount(1, 'responses');

    expect(Heartbeat::query()->count())->toBe(1);
});

test('it attributes each heartbeat to the authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [hbPayload(['entity' => 'a.php'])], hbAuth($userA))->assertCreated();
    $this->postJson(BULK_ENDPOINT, [hbPayload(['entity' => 'b.php'])], hbAuth($userB))->assertCreated();

    $heartbeatA = Heartbeat::query()->where('user_id', $userA->id)->sole();
    $heartbeatB = Heartbeat::query()->where('user_id', $userB->id)->sole();

    expect($heartbeatA->entity)->toBe('a.php')
        ->and($heartbeatB->entity)->toBe('b.php');
});

test('it does not resolve last-value placeholders from another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Heartbeat::factory()->forUser($userB)->create([
        'project' => 'user-b-project',
        'recorded_at' => now()->subMinutes(5),
    ]);

    $this->postJson(BULK_ENDPOINT, [hbPayload(['project' => '<<LAST_PROJECT>>'])], hbAuth($userA))
        ->assertCreated();

    $stored = Heartbeat::query()->where('user_id', $userA->id)->sole();

    expect($stored->project)->toBeNull();
});

test('it rejects an invalid heartbeat per item without storing it', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [['type' => 'file']], hbAuth($user))
        ->assertCreated()
        ->assertJsonPath('responses.0.1', 400)
        ->assertJsonPath('responses.0.0.error', 'invalid heartbeat');

    expect(Heartbeat::query()->count())->toBe(0);
});

test('it returns per-item statuses for a mixed batch and stores only the valid items', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [
        hbPayload(['entity' => 'good.php']),
        ['type' => 'file'],
    ], hbAuth($user))
        ->assertCreated()
        ->assertJsonCount(2, 'responses')
        ->assertJsonPath('responses.0.1', 201)
        ->assertJsonPath('responses.1.1', 400);

    expect(Heartbeat::query()->sole()->entity)->toBe('good.php');
});

test('it stores heartbeats that differ only in a hashed field separately', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [hbPayload(['is_write' => true])], hbAuth($user))->assertCreated();
    $this->postJson(BULK_ENDPOINT, [hbPayload(['is_write' => false])], hbAuth($user))->assertCreated();

    expect(Heartbeat::query()->count())->toBe(2);
});

test('the content hash casts null fields to empty strings', function () {
    $user = User::factory()->create();

    $this->postJson(BULK_ENDPOINT, [[
        'entity' => 'main.go',
        'type' => 'file',
        'category' => null,
        'project' => null,
        'branch' => null,
        'language' => null,
        'is_write' => null,
        'time' => 1719580800.5,
    ]], hbAuth($user))->assertCreated();

    $recordedAt = Date::createFromTimestampMs((int) round(1719580800.5 * 1000), 'UTC')->format('Y-m-d H:i:s.v');

    // Recompute the hash with every null field written out as an explicit empty
    // string. Production relies on implode coercing null to ''; if a future PHP
    // changes that coercion the stored hash drifts from this expectation and the
    // test fails, flagging a dedup-breaking change before it ships.
    $expected = hash('sha256', implode('|', [
        (string) $user->id,
        'main.go',
        'file',
        '', // category
        '', // project
        '', // branch
        '', // language
        '', // is_write (null)
        '', // ai_session
        $recordedAt,
    ]));

    expect(Heartbeat::query()->sole()->hash)->toBe($expected);
});
