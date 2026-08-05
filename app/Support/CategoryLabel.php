<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns a stored heartbeat category (lowercase, as WakaTime sends it) into a
 * display label for category breakdowns — notably "coding" → "Human coding" to
 * contrast with "AI coding". Empty values pass through unchanged so callers can
 * apply their own "Uncategorised" label. Driven by `config/categories.php`.
 */
class CategoryLabel
{
    public static function format(?string $category): string
    {
        $category = is_string($category) ? trim($category) : '';

        if ($category === '') {
            return '';
        }

        return config('categories.labels')[Str::lower($category)] ?? Str::ucfirst($category);
    }
}
