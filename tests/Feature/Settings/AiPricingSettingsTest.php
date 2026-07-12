<?php

use App\Models\AiPrice;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('ai-pricing.edit'))->assertRedirect(route('login'));
});

test('the pricing page lists prices by model and newest effective date first', function () {
    $user = User::factory()->create();

    AiPrice::factory()->create([
        'model_prefix' => 'opus',
        'input_price' => 15.0,
        'output_price' => 75.0,
        'effective_from' => '2025-01-01',
    ]);
    AiPrice::factory()->create([
        'model_prefix' => 'opus',
        'input_price' => 30.0,
        'output_price' => 75.0,
        'effective_from' => '2026-06-01',
    ]);

    $this->actingAs($user)
        ->get(route('ai-pricing.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/AiPricing')
            ->has('prices', 2)
            ->where('prices.0.effective_from', '2026-06-01')
            ->where('prices.0.input_price', 30)
            ->where('prices.1.effective_from', '2025-01-01'));
});

test('a price can be added', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai-pricing.store'), [
            'model_prefix' => 'sonnet/5',
            'input_price' => 3,
            'output_price' => 15,
            'effective_from' => '2026-07-01',
        ])
        ->assertRedirect(route('ai-pricing.edit'));

    $price = AiPrice::sole();

    expect($price->model_prefix)->toBe('sonnet/5')
        ->and($price->input_price)->toBe(3.0)
        ->and($price->output_price)->toBe(15.0)
        ->and($price->effective_from->toDateString())->toBe('2026-07-01');
});

test('a duplicate model and effective date is rejected', function () {
    $user = User::factory()->create();

    AiPrice::factory()->create(['model_prefix' => 'opus', 'effective_from' => '2026-07-01']);

    $this->actingAs($user)
        ->from(route('ai-pricing.edit'))
        ->post(route('ai-pricing.store'), [
            'model_prefix' => 'opus',
            'input_price' => 15,
            'output_price' => 75,
            'effective_from' => '2026-07-01',
        ])
        ->assertRedirect(route('ai-pricing.edit'))
        ->assertSessionHasErrors('effective_from');

    // The same date under a different model is fine.
    $this->actingAs($user)
        ->post(route('ai-pricing.store'), [
            'model_prefix' => 'sonnet',
            'input_price' => 3,
            'output_price' => 15,
            'effective_from' => '2026-07-01',
        ])
        ->assertSessionHasNoErrors();
});

test('a price can be amended without tripping its own uniqueness', function () {
    $user = User::factory()->create();

    $price = AiPrice::factory()->create([
        'model_prefix' => 'opus',
        'input_price' => 15.0,
        'output_price' => 75.0,
        'effective_from' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch(route('ai-pricing.update', $price), [
            'model_prefix' => 'opus',
            'input_price' => 18,
            'output_price' => 90,
            'effective_from' => '2026-07-01',
        ])
        ->assertRedirect(route('ai-pricing.edit'))
        ->assertSessionHasNoErrors();

    expect($price->fresh()->input_price)->toBe(18.0);
});

test('amending a price onto another row of the same model and date is rejected', function () {
    $user = User::factory()->create();

    AiPrice::factory()->create(['model_prefix' => 'opus', 'effective_from' => '2026-06-01']);
    $price = AiPrice::factory()->create(['model_prefix' => 'opus', 'effective_from' => '2026-07-01']);

    $this->actingAs($user)
        ->from(route('ai-pricing.edit'))
        ->patch(route('ai-pricing.update', $price), [
            'model_prefix' => 'opus',
            'input_price' => 15,
            'output_price' => 75,
            'effective_from' => '2026-06-01',
        ])
        ->assertSessionHasErrors('effective_from');
});

test('a price can be removed', function () {
    $user = User::factory()->create();
    $price = AiPrice::factory()->create();

    $this->actingAs($user)
        ->delete(route('ai-pricing.destroy', $price))
        ->assertRedirect(route('ai-pricing.edit'));

    expect(AiPrice::count())->toBe(0);
});
