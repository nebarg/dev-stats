<?php

use App\Models\AiPrice;
use App\Support\AiPricing;

beforeEach(function () {
    AiPrice::factory()->create([
        'model_prefix' => 'opus',
        'input_price' => 15.0,
        'output_price' => 75.0,
        'effective_from' => '2025-01-01',
    ]);
    AiPrice::factory()->create([
        'model_prefix' => 'opus/4.1',
        'input_price' => 10.0,
        'output_price' => 50.0,
        'effective_from' => '2026-01-01',
    ]);
});

test('the longest matching prefix in effect wins', function () {
    $pricing = new AiPricing;

    // "opus/4.1-medium" matches both prefixes; the version-specific row
    // applies. "opus/4.8" only matches the family row.
    expect($pricing->costInCents('opus/4.1-medium', 1_000_000, 0, '2026-06-01'))->toBe(1000)
        ->and($pricing->costInCents('opus/4.8', 1_000_000, 0, '2026-06-01'))->toBe(1500);
});

test('a version prefix not yet in effect falls back to the family price', function () {
    // The opus/4.1 row only starts in 2026.
    expect((new AiPricing)->costInCents('opus/4.1-medium', 1_000_000, 0, '2025-06-01'))->toBe(1500);
});

test('a newer price supersedes from its effective date', function () {
    AiPrice::factory()->create([
        'model_prefix' => 'opus',
        'input_price' => 30.0,
        'output_price' => 75.0,
        'effective_from' => '2026-06-29',
    ]);

    $pricing = new AiPricing;

    expect($pricing->costInCents('opus/4.8', 1_000_000, 0, '2026-06-28'))->toBe(1500)
        ->and($pricing->costInCents('opus/4.8', 1_000_000, 0, '2026-06-29'))->toBe(3000);
});

test('input and output tokens are priced separately and rounded to cents', function () {
    // 100K in at $15/M is $1.50; 10K out at $75/M is $0.75.
    expect((new AiPricing)->costInCents('opus/4.8', 100_000, 10_000, '2026-06-01'))->toBe(225);
});

test('unknown models, missing models and days before any price have none', function () {
    $pricing = new AiPricing;

    expect($pricing->costInCents('mystery/1.0', 1_000_000, 1_000_000, '2026-06-01'))->toBeNull()
        ->and($pricing->costInCents(null, 1_000_000, 1_000_000, '2026-06-01'))->toBeNull()
        ->and($pricing->costInCents('opus/4.8', 1_000_000, 0, '2024-12-31'))->toBeNull();
});
