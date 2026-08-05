<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    formatDayLabel,
    formatDayMonth,
    formatDuration,
    formatWeekday,
} from '@/lib/format';
import type { ActivityDay } from '@/types';

const props = defineProps<{
    data: ActivityDay[];
}>();

const niceHourSteps: readonly number[] = [0.25, 0.5, 1, 2, 3, 4, 6, 8, 12, 24];

const maxSeconds = computed(() =>
    Math.max(1, ...props.data.map((day) => day.seconds)),
);

/**
 * A rounded axis maximum in whole hours (or half hours for short days) so the
 * gridlines land on tidy values rather than the raw peak.
 */
const axisMaxHours = computed(() => {
    const maxHours = maxSeconds.value / 3600;
    const roughStep = maxHours / 4;
    const step =
        niceHourSteps.find((candidate) => candidate >= roughStep) ??
        Math.ceil(maxHours);

    return Math.max(step, Math.ceil(maxHours / step) * step);
});

/**
 * Tick values from the axis maximum down to zero, used for both the y-axis
 * labels and the horizontal gridlines.
 */
const ticks = computed(() => {
    const max = axisMaxHours.value;
    const step =
        niceHourSteps.find((candidate) => candidate >= max / 4) ?? max / 4;
    const values: number[] = [];

    for (let value = max; value >= 0; value -= step) {
        values.push(Number(value.toFixed(2)));
    }

    return values;
});

const denseLabels = computed(() => props.data.length <= 14);

/**
 * How many bars to skip between x-axis date labels when the range is too wide
 * to label every day. Anchored from the most recent day so it stays labelled.
 */
const labelInterval = computed(() =>
    Math.max(1, Math.round(props.data.length / 6)),
);

function isLabelledDay(index: number): boolean {
    return (props.data.length - 1 - index) % labelInterval.value === 0;
}

function formatHours(hours: number): string {
    if (hours === 0) {
        return '0';
    }

    return `${hours % 1 === 0 ? hours : hours.toFixed(1)}h`;
}

function height(seconds: number): string {
    if (seconds <= 0) {
        return '0%';
    }

    return `${Math.max(2, (seconds / (axisMaxHours.value * 3600)) * 100)}%`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Activity</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="flex gap-2">
                <div
                    class="flex h-44 w-8 shrink-0 flex-col justify-between text-right text-[10px] leading-none text-muted-foreground"
                >
                    <span v-for="tick in ticks" :key="tick">
                        {{ formatHours(tick) }}
                    </span>
                </div>

                <div class="min-w-0 flex-1">
                    <div class="relative h-44">
                        <div
                            class="absolute inset-0 flex flex-col justify-between"
                        >
                            <div
                                v-for="tick in ticks"
                                :key="tick"
                                class="border-t border-border/60"
                            />
                        </div>

                        <div class="relative flex h-full items-end gap-1">
                            <div
                                v-for="day in data"
                                :key="day.date"
                                class="group flex h-full flex-1 items-end"
                                :title="`${formatDayLabel(day.date)} — ${formatDuration(day.seconds)}`"
                            >
                                <div
                                    class="w-full rounded-t-sm bg-primary/80 transition-colors group-hover:bg-primary"
                                    :style="{ height: height(day.seconds) }"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 flex gap-1">
                        <div
                            v-for="(day, index) in data"
                            :key="day.date"
                            class="min-w-0 flex-1 text-center text-[10px] whitespace-nowrap text-muted-foreground"
                        >
                            <span v-if="denseLabels">
                                {{ formatWeekday(day.date) }}
                            </span>
                            <span v-else-if="isLabelledDay(index)">
                                {{ formatDayMonth(day.date) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
