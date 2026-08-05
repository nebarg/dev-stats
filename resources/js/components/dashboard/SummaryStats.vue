<script setup lang="ts">
import { computed } from 'vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import { formatDayLabel, formatDuration, pluralise } from '@/lib/format';

const props = defineProps<{
    totalSeconds: number;
    todaySeconds: number;
    dailyAverageSeconds: number;
    activeDays: number;
    mostActiveDay: { date: string; seconds: number } | null;
}>();

const dailyAverageHint = computed(
    () =>
        `over ${props.activeDays} ${pluralise(props.activeDays, 'active day')}`,
);
</script>

<template>
    <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Total" :value="formatDuration(totalSeconds)" />
        <StatCard label="Today" :value="formatDuration(todaySeconds)" />
        <StatCard
            label="Daily average"
            :value="formatDuration(dailyAverageSeconds)"
            :hint="dailyAverageHint"
        />
        <StatCard
            label="Most active day"
            :value="mostActiveDay ? formatDuration(mostActiveDay.seconds) : '—'"
            :hint="
                mostActiveDay
                    ? formatDayLabel(mostActiveDay.date)
                    : 'No activity yet'
            "
        />
    </div>
</template>
