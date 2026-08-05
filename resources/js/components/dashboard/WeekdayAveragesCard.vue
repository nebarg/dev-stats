<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDuration } from '@/lib/format';
import type { WeekdayAverage } from '@/types';

const props = defineProps<{
    weekdays: WeekdayAverage[];
}>();

const max = computed(() =>
    Math.max(1, ...props.weekdays.map((day) => day.average_seconds)),
);

function width(seconds: number): string {
    return `${(seconds / max.value) * 100}%`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <div class="flex items-center justify-between gap-2">
                <CardTitle>Weekday averages</CardTitle>
                <div
                    class="flex items-center gap-3 text-xs text-muted-foreground"
                >
                    <span class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-primary" />
                        AI coding
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-primary/30" />
                        Other
                    </span>
                </div>
            </div>
        </CardHeader>
        <CardContent>
            <div class="flex flex-col gap-2">
                <div
                    v-for="day in weekdays"
                    :key="day.label"
                    class="flex items-center gap-3"
                >
                    <span class="w-8 text-sm text-muted-foreground">
                        {{ day.label }}
                    </span>
                    <div class="relative h-3 flex-1 rounded-full bg-muted">
                        <div
                            v-if="day.average_seconds > 0"
                            class="absolute inset-y-0 left-0 rounded-full bg-primary/30"
                            :style="{ width: width(day.average_seconds) }"
                        />
                        <div
                            v-if="day.ai_average_seconds > 0"
                            class="absolute inset-y-0 left-0 rounded-full bg-primary"
                            :style="{ width: width(day.ai_average_seconds) }"
                        />
                    </div>
                    <span
                        class="w-16 text-right text-sm text-muted-foreground tabular-nums"
                    >
                        {{ formatDuration(day.average_seconds) }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
