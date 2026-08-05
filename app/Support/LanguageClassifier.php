<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalises the "language" an editor reports for a heartbeat. Real languages
 * pass through unchanged; non-languages (file types like ".env file",
 * placeholders, and durations that carry no language) collapse into a single
 * "Other" bucket so language breakdowns stay meaningful. Driven by
 * `config/languages.php` rather than a hard-coded list of every language.
 */
class LanguageClassifier
{
    public static function classify(?string $language): string
    {
        $language = is_string($language) ? trim($language) : '';

        if ($language === '' || ! self::isLanguage($language)) {
            return config('languages.other_label');
        }

        return $language;
    }

    public static function isLanguage(string $language): bool
    {
        if (in_array(Str::lower($language), config('languages.non_languages'), true)) {
            return false;
        }

        foreach (config('languages.non_language_patterns') as $pattern) {
            if (preg_match($pattern, $language) === 1) {
                return false;
            }
        }

        return true;
    }
}
