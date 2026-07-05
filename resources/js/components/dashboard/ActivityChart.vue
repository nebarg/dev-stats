<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDayLabel, formatDuration, formatWeekday } from '@/lib/format';
import type { ActivityDay } from '@/types';

const props = defineProps<{
    data: ActivityDay[];
}>();

const max = computed(() =>
    Math.max(1, ...props.data.map((day) => day.seconds)),
);
const showLabels = computed(() => props.data.length <= 14);

function height(seconds: number): string {
    if (seconds <= 0) {
        return '0%';
    }

    return `${Math.max(4, (seconds / max.value) * 100)}%`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Activity</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="flex h-44 items-end gap-1">
                <div
                    v-for="day in data"
                    :key="day.date"
                    class="group flex h-full flex-1 flex-col items-center justify-end gap-2"
                    :title="`${formatDayLabel(day.date)} — ${formatDuration(day.seconds)}`"
                >
                    <div class="flex w-full flex-1 items-end">
                        <div
                            class="w-full rounded-t-sm bg-primary/80 transition-colors group-hover:bg-primary"
                            :style="{ height: height(day.seconds) }"
                        />
                    </div>
                    <span
                        v-if="showLabels"
                        class="text-[10px] whitespace-nowrap text-muted-foreground"
                    >
                        {{ formatWeekday(day.date) }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
