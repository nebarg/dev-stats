<?php

namespace App\Support;

/**
 * Derives the editor and operating system from a wakatime-cli User-Agent.
 *
 * The CLI builds: "wakatime/<ver> (<os>-<arch>-<gover>) <gover> <plugin>",
 * e.g. "wakatime/v1.73.0 (darwin-arm64-go1.22) go1.22 vscode-wakatime/24.0.0".
 */
class UserAgentParser
{
    /** @var array<string, string> */
    private const OS_MAP = [
        'win' => 'windows',
        'windows' => 'windows',
        'darwin' => 'macos',
        'mac' => 'macos',
        'linux' => 'linux',
    ];

    /** @var array<string, string> */
    private const EDITOR_MAP = [
        'ktexteditor' => 'kate',
        'claude-code' => 'Claude',
    ];

    /**
     * @return array{editor: ?string, operating_system: ?string}
     */
    public static function parse(?string $userAgent): array
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return ['editor' => null, 'operating_system' => null];
        }

        return [
            'editor' => self::parseEditor($userAgent),
            'operating_system' => self::parseOperatingSystem($userAgent),
        ];
    }

    private static function parseOperatingSystem(string $userAgent): ?string
    {
        if (str_contains(strtolower($userAgent), 'wsl2')) {
            return 'wsl';
        }

        if (preg_match('/\(([^)]+)\)/', $userAgent, $matches) !== 1) {
            return null;
        }

        $os = strtolower(explode('-', $matches[1])[0]);

        return self::OS_MAP[$os] ?? $os;
    }

    private static function parseEditor(string $userAgent): ?string
    {
        $tokens = preg_split('/\s+/', trim($userAgent)) ?: [];
        $name = strtolower(explode('/', (string) end($tokens))[0]);
        $name = trim(str_replace(['-wakatime', 'wakatime-'], '', $name), '-');

        if (in_array($name, ['', 'wakatime', 'go'], true)) {
            return null;
        }

        return self::EDITOR_MAP[$name] ?? $name;
    }
}
