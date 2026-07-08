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
 * Compact "4.8K" / "63.1M" rendering for large counts (lines, tokens).
 */
export function formatCompactNumber(value: number): string {
    return new Intl.NumberFormat(undefined, {
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
    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(toLocalDate(date));
}

/**
 * Short weekday ("Mon") for a Y-m-d date.
 */
export function formatWeekday(date: string): string {
    return new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(
        toLocalDate(date),
    );
}
