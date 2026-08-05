// Pin a locale so server-rendered and client-rendered strings match. With the
// runtime default (`undefined`), Node SSR resolves to en-US while the browser
// resolves to en-GB, which reorders dates ("Aug 3" vs "3 Aug") and triggers
// hydration mismatches. The app is British English throughout.
const LOCALE = 'en-GB';

/**
 * Picks the singular or plural noun for a count. The plural defaults to the
 * singular with an "s" appended: `pluralise(3, 'day')` → "days".
 */
export function pluralise(
    count: number,
    singular: string,
    plural = `${singular}s`,
): string {
    return count === 1 ? singular : plural;
}

/**
 * Human-readable "3 hrs 14 mins" from a duration in seconds.
 */
export function formatDuration(seconds: number): string {
    if (seconds <= 0) {
        return '0 mins';
    }

    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);

    const parts: string[] = [];

    if (hrs > 0) {
        parts.push(`${hrs} ${pluralise(hrs, 'hr')}`);
    }

    if (mins > 0) {
        parts.push(`${mins} ${pluralise(mins, 'min')}`);
    }

    return parts.length > 0 ? parts.join(' ') : '<1 min';
}

/**
 * "$47.65" from an amount in US cents (AI spend estimates are priced in USD).
 */
export function formatUsd(cents: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(cents / 100);
}

/**
 * Compact "4.8K" / "63.1M" rendering for large counts (lines, tokens).
 */
export function formatCompactNumber(value: number): string {
    return new Intl.NumberFormat(LOCALE, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

function toLocalDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
}

/**
 * "Sun, 28 Jun" style label for a Y-m-d date, in the viewer's locale.
 */
export function formatDayLabel(date: string): string {
    return new Intl.DateTimeFormat(LOCALE, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(toLocalDate(date));
}

/**
 * Short weekday ("Mon") for a Y-m-d date.
 */
export function formatWeekday(date: string): string {
    return new Intl.DateTimeFormat(LOCALE, { weekday: 'short' }).format(
        toLocalDate(date),
    );
}

/**
 * Compact "28 Jun" day-and-month label for a Y-m-d date.
 */
export function formatDayMonth(date: string): string {
    return new Intl.DateTimeFormat(LOCALE, {
        day: 'numeric',
        month: 'short',
    }).format(toLocalDate(date));
}
