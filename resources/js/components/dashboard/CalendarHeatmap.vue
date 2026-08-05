<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CalendarHeatmapDay } from '@/types';

const props = defineProps<{
    title: string;
    days: CalendarHeatmapDay[];
    legend?: string;
}>();

const levelClasses = [
    'bg-muted',
    'bg-primary/25',
    'bg-primary/45',
    'bg-primary/70',
    'bg-primary',
];

// Pinned locale so SSR (Node) and client agree — see resources/js/lib/format.ts.
const monthFormat = new Intl.DateTimeFormat('en-GB', { month: 'short' });

function toDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
}

type Week = {
    days: (CalendarHeatmapDay | null)[];
    monthLabel: string | null;
};

const weeks = computed<Week[]>(() => {
    if (props.days.length === 0) {
        return [];
    }

    // Pad so the grid starts on a Monday row.
    const offset = (toDate(props.days[0].date).getDay() + 6) % 7;
    const slots: (CalendarHeatmapDay | null)[] = [
        ...Array(offset).fill(null),
        ...props.days,
    ];

    const result: Week[] = [];
    let previousMonth: number | null = null;

    for (let start = 0; start < slots.length; start += 7) {
        const days = slots.slice(start, start + 7);
        const firstDay = days.find((day) => day !== null);
        const month = firstDay ? toDate(firstDay.date).getMonth() : null;
        const isNewMonth = month !== null && month !== previousMonth;

        result.push({
            days: [...days, ...Array(7 - days.length).fill(null)],
            // Skip the label on the very first column so a clipped month
            // never renders half-visible at the grid's left edge.
            monthLabel:
                isNewMonth && firstDay && result.length > 0
                    ? monthFormat.format(toDate(firstDay.date))
                    : null,
        });
        previousMonth = month ?? previousMonth;
    }

    return result;
});

const weekdayLabels = ['Mon', '', 'Wed', '', 'Fri', '', ''];
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="flex gap-2">
                <div class="flex shrink-0 flex-col gap-1 pt-5">
                    <span
                        v-for="(label, index) in weekdayLabels"
                        :key="index"
                        class="h-3 text-[10px] leading-3 text-muted-foreground"
                    >
                        {{ label }}
                    </span>
                </div>

                <div class="overflow-x-auto pb-1">
                    <div class="flex h-5 gap-1">
                        <div
                            v-for="(week, index) in weeks"
                            :key="index"
                            class="relative w-3 shrink-0"
                        >
                            <span
                                v-if="week.monthLabel"
                                class="absolute top-0 left-0 text-[10px] leading-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ week.monthLabel }}
                            </span>
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <div
                            v-for="(week, index) in weeks"
                            :key="index"
                            class="flex shrink-0 flex-col gap-1"
                        >
                            <div
                                v-for="(day, dayIndex) in week.days"
                                :key="day?.date ?? `pad-${dayIndex}`"
                                class="h-3 w-3 rounded-[2px]"
                                :class="
                                    day
                                        ? levelClasses[day.level]
                                        : 'bg-transparent'
                                "
                                :title="day?.title"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="legend"
                class="mt-3 flex items-center justify-end gap-2 text-xs text-muted-foreground"
            >
                <span>{{ legend }}</span>
                <div class="flex items-center gap-1">
                    <div
                        v-for="(levelClass, index) in levelClasses"
                        :key="index"
                        class="h-3 w-3 rounded-[2px]"
                        :class="levelClass"
                    />
                </div>
            </div>
        </CardContent>
    </Card>
</template>
