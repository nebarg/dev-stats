<?php

namespace App\Support;

use App\Models\AiPrice;

/**
 * Estimates AI agent spend from stored token counts and the owner-maintained
 * `ai_prices` table. The CLI never sends costs; this is the API-equivalent
 * price of the tokens, keyed by the model token parsed from the heartbeat
 * User-Agent and by the day the tokens were recorded, so historical spend
 * keeps its historical price. Unpriced or unattributed models yield null so
 * they render as unknown rather than free.
 *
 * Loads the price table once on construction — build one instance per
 * request and reuse it across buckets.
 */
class AiPricing
{
    private const int TOKENS_PER_PRICE_UNIT = 1_000_000;

    /**
     * Prices grouped by model prefix, longest prefix first, each group
     * sorted latest effective_from first — resolution is the first match.
     *
     * @var array<string, list<AiPrice>>
     */
    private array $prices;

    public function __construct()
    {
        $this->prices = AiPrice::query()
            ->orderByRaw('LENGTH(model_prefix) DESC')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('model_prefix')
            ->map(static fn ($group) => $group->values()->all())
            ->all();
    }

    /**
     * @param  string  $day  Y-m-d date the tokens were recorded on
     */
    public function costInCents(?string $model, int $inputTokens, int $outputTokens, string $day): ?int
    {
        $price = $this->priceFor($model, $day);

        if ($price === null) {
            return null;
        }

        $dollars = ($inputTokens * $price->input_price + $outputTokens * $price->output_price)
            / self::TOKENS_PER_PRICE_UNIT;

        return (int) round($dollars * 100);
    }

    /**
     * The longest matching prefix with a price in effect on the day wins, so
     * a version-specific row ("opus/4.1") beats a family one ("opus"), and a
     * family row still applies while a newer version has no dated price yet.
     * Days before a prefix's earliest price are unpriced.
     */
    private function priceFor(?string $model, string $day): ?AiPrice
    {
        if ($model === null) {
            return null;
        }

        foreach ($this->prices as $prefix => $rows) {
            if (! str_starts_with($model, (string) $prefix)) {
                continue;
            }

            foreach ($rows as $price) {
                if ($price->effective_from->toDateString() <= $day) {
                    return $price;
                }
            }
        }

        return null;
    }
}
