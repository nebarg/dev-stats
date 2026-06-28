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
