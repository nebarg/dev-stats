import { describe, expect, it } from 'vitest';
import {
    formatCompactNumber,
    formatDayLabel,
    formatDuration,
    formatWeekday,
    pluralise,
} from '@/lib/format';

describe('pluralise', () => {
    it('returns the singular for a count of exactly 1', () => {
        expect(pluralise(1, 'day')).toBe('day');
    });

    it('appends "s" by default for any other count', () => {
        expect(pluralise(0, 'day')).toBe('days');
        expect(pluralise(2, 'day')).toBe('days');
    });

    it('uses an explicit plural for irregular words', () => {
        expect(pluralise(1, 'entry', 'entries')).toBe('entry');
        expect(pluralise(3, 'entry', 'entries')).toBe('entries');
    });
});

describe('formatDuration', () => {
    it('returns "0 mins" for zero or negative input', () => {
        expect(formatDuration(0)).toBe('0 mins');
        expect(formatDuration(-30)).toBe('0 mins');
    });

    it('returns "<1 min" for durations under a minute', () => {
        expect(formatDuration(30)).toBe('<1 min');
    });

    it('uses singular units for exactly one hour or minute', () => {
        expect(formatDuration(3600)).toBe('1 hr');
        expect(formatDuration(60)).toBe('1 min');
        expect(formatDuration(3660)).toBe('1 hr 1 min');
    });

    it('pluralises hours and minutes', () => {
        expect(formatDuration(3 * 3600 + 14 * 60)).toBe('3 hrs 14 mins');
    });

    it('omits a unit that is zero', () => {
        expect(formatDuration(2 * 3600)).toBe('2 hrs');
    });
});

describe('formatCompactNumber', () => {
    it('leaves small counts unabbreviated', () => {
        expect(formatCompactNumber(0)).toBe('0');
        expect(formatCompactNumber(305)).toBe('305');
    });

    it('abbreviates thousands and millions to one decimal place', () => {
        // The locale picks the suffix ("4.8K" vs "4.8k" vs "4,8 Tsd."), so
        // assert the abbreviated digits only to stay locale-agnostic.
        expect(formatCompactNumber(4800)).toMatch(/^4[.,]8/);
        expect(formatCompactNumber(63100000)).toMatch(/^63[.,]1/);
    });

    it('keeps the sign of negative net counts', () => {
        expect(formatCompactNumber(-1200)).toMatch(/^-1[.,]2/);
    });
});

describe('date labels', () => {
    it('keeps the calendar day when parsing a Y-m-d string', () => {
        // Regression: naive `new Date('2026-06-28')` parses as UTC midnight and
        // can slip to the 27th in timezones behind UTC. toLocalDate avoids this.
        expect(formatDayLabel('2026-06-28')).toContain('28');
    });

    it('derives the weekday from the local calendar date', () => {
        // 2026-06-28 is a Sunday; compare against the same date built locally so
        // the assertion is locale-agnostic but still catches UTC drift.
        const expected = new Intl.DateTimeFormat(undefined, {
            weekday: 'short',
        }).format(new Date(2026, 5, 28));

        expect(formatWeekday('2026-06-28')).toBe(expected);
    });
});
