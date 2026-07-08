<?php

use App\Support\UserAgentParser;

test('it parses editor and operating system from a cli user agent', function () {
    $result = UserAgentParser::parse('wakatime/v1.73.0 (darwin-arm64-go1.22) go1.22 vscode-wakatime/24.0.0');

    expect($result)->toBe(['editor' => 'vscode', 'operating_system' => 'macos']);
});

test('it maps operating systems to canonical names', function (string $userAgent, ?string $os) {
    expect(UserAgentParser::parse($userAgent)['operating_system'])->toBe($os);
})->with([
    'windows' => ['wakatime/v1.0 (win-amd64-go1.22) go1.22 vscode-wakatime/1.0', 'windows'],
    'linux' => ['wakatime/v1.0 (linux-amd64-go1.22) go1.22 vscode-wakatime/1.0', 'linux'],
    'wsl' => ['wakatime/v1.0 (linux-amd64-WSL2-go1.22) go1.22 vscode-wakatime/1.0', 'wsl'],
]);

test('it applies special-case editor names', function () {
    expect(UserAgentParser::parse('wakatime/v1.0 (darwin-arm64-go1.22) go1.22 claude-code/1.0')['editor'])
        ->toBe('Claude');
});

test('it returns nulls for an empty user agent', function () {
    expect(UserAgentParser::parse(null))->toBe(['editor' => null, 'operating_system' => null]);
});

test('it parses the ai model prepended to an ai heartbeat user agent', function () {
    expect(UserAgentParser::aiModel('opus/4.1-medium claude-code/2.1.45'))->toBe('opus/4.1-medium');
});

test('it finds no ai model in editor or malformed user agents', function (?string $userAgent) {
    expect(UserAgentParser::aiModel($userAgent))->toBeNull();
})->with([
    'cli' => 'wakatime/v1.73.0 (darwin-arm64-go1.22) go1.22 vscode-wakatime/24.0.0',
    'lone plugin token' => 'claude-code/2.1.45',
    'first token without version' => 'something else/1.0',
    'empty' => '',
    'null' => null,
]);
