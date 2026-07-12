<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiPriceRequest;
use App\Models\AiPrice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiPricingController extends Controller
{
    /**
     * Show the AI pricing settings page.
     */
    public function edit(): Response
    {
        $prices = AiPrice::query()
            ->orderBy('model_prefix')
            ->orderByDesc('effective_from')
            ->get()
            ->map(static fn (AiPrice $price): array => [
                'id' => $price->id,
                'model_prefix' => $price->model_prefix,
                'input_price' => $price->input_price,
                'output_price' => $price->output_price,
                'effective_from' => $price->effective_from->toDateString(),
            ]);

        return Inertia::render('settings/AiPricing', [
            'prices' => $prices,
        ]);
    }

    /**
     * Add a price.
     */
    public function store(AiPriceRequest $request): RedirectResponse
    {
        AiPrice::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price added.')]);

        return to_route('ai-pricing.edit');
    }

    /**
     * Amend a price.
     */
    public function update(AiPriceRequest $request, AiPrice $price): RedirectResponse
    {
        $price->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price updated.')]);

        return to_route('ai-pricing.edit');
    }

    /**
     * Remove a price.
     */
    public function destroy(AiPrice $price): RedirectResponse
    {
        $price->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price removed.')]);

        return to_route('ai-pricing.edit');
    }
}
