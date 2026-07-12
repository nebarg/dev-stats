<?php

namespace App\Support;

/**
 * Estimates AI agent spend from stored token counts and the owner-maintained
 * price map in config/ai-pricing.php. The CLI never sends costs; this is the
 * API-equivalent price of the tokens, keyed by the model token parsed from
 * the heartbeat User-Agent. Unpriced or unattributed models yield null so
 * they render as unknown rather than free.
 */
class AiPricing
{
    private const int TOKENS_PER_PRICE_UNIT = 1_000_000;

    public static function costInCents(?string $model, int $inputTokens, int $outputTokens): ?int
    {
        $price = self::priceFor($model);

        if ($price === null) {
            return null;
        }

        $dollars = ($inputTokens * $price['input'] + $outputTokens * $price['output']) / self::TOKENS_PER_PRICE_UNIT;

        return (int) round($dollars * 100);
    }

    /**
     * The longest configured prefix matching the model token wins, so a
     * version-specific entry ("opus/4.1") beats a family one ("opus").
     *
     * @return array{input: float, output: float}|null
     */
    private static function priceFor(?string $model): ?array
    {
        if ($model === null) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach (config('ai-pricing.models', []) as $prefix => $price) {
            if (str_starts_with($model, (string) $prefix) && strlen((string) $prefix) > $bestLength) {
                $best = $price;
                $bestLength = strlen((string) $prefix);
            }
        }

        return $best;
    }
}
