<?php

use App\Support\EntityClassifier;

test('it classifies agent directories and instruction files', function (string $entity) {
    expect(EntityClassifier::classify($entity, 'file'))->toBe('agent');
})->with([
    'claude project dir' => '/Users/dev/.claude/projects/x/memory/notes.md',
    'cursor dir' => '/Users/dev/code/app/.cursor/rules/style.mdc',
    'aider history' => '/Users/dev/code/app/.aider.chat.history.md',
    'copilot instructions' => '/Users/dev/code/app/.github/copilot-instructions.md',
    'CLAUDE.md' => '/Users/dev/code/app/CLAUDE.md',
    'lowercase claude.md' => '/Users/dev/code/app/claude.md',
    'AGENTS.md' => '/Users/dev/code/app/AGENTS.md',
    'cursorrules' => '/Users/dev/code/app/.cursorrules',
    'windows path' => 'C:\Users\dev\.claude\plans\plan.md',
]);

test('it classifies ordinary files as source', function (string $entity) {
    expect(EntityClassifier::classify($entity, 'file'))->toBe('source');
})->with([
    'php file' => '/Users/dev/code/app/app/Models/User.php',
    'file mentioning claude' => '/Users/dev/code/app/src/ClaudeClient.php',
    'markdown outside agent dirs' => '/Users/dev/code/app/README.md',
]);

test('it leaves non-file entities unclassified', function () {
    expect(EntityClassifier::classify('https://example.com', 'url'))->toBeNull()
        ->and(EntityClassifier::classify(null, 'file'))->toBeNull()
        ->and(EntityClassifier::classify('', 'file'))->toBeNull();
});
