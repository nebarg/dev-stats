<?php

use App\Support\AiPricing;

beforeEach(function () {
    config(['ai-pricing.models' => [
        'opus' => ['input' => 15.0, 'output' => 75.0],
        'opus/4.1' => ['input' => 10.0, 'output' => 50.0],
    ]]);
});

test('the longest matching prefix wins', function () {
    // "opus/4.1-medium" matches both entries; the version-specific one applies.
    expect(AiPricing::costInCents('opus/4.1-medium', 1_000_000, 0))->toBe(1000)
        ->and(AiPricing::costInCents('opus/4.8', 1_000_000, 0))->toBe(1500);
});

test('input and output tokens are priced separately and rounded to cents', function () {
    // 100K in at $15/M is $1.50; 10K out at $75/M is $0.75.
    expect(AiPricing::costInCents('opus/4.8', 100_000, 10_000))->toBe(225);
});

test('unknown or missing models have no price', function () {
    expect(AiPricing::costInCents('mystery/1.0', 1_000_000, 1_000_000))->toBeNull()
        ->and(AiPricing::costInCents(null, 1_000_000, 1_000_000))->toBeNull();
});
