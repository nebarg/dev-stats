<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import CalendarHeatmap from '@/components/dashboard/CalendarHeatmap.vue';
import TopLinesCard from '@/components/dashboard/TopLinesCard.vue';
import WeekdayAveragesCard from '@/components/dashboard/WeekdayAveragesCard.vue';
import {
    formatCompactNumber,
    formatDayLabel,
    formatDuration,
} from '@/lib/format';
import { insights } from '@/routes';
import type { CalendarHeatmapDay, InsightsStats } from '@/types';

const props = defineProps<{
    stats: InsightsStats;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Insights',
                href: insights(),
            },
        ],
    },
});

const activityDays = computed<CalendarHeatmapDay[]>(() => {
    const max = Math.max(1, ...props.stats.calendar.map((day) => day.seconds));

    return props.stats.calendar.map((day) => ({
        date: day.date,
        level:
            day.seconds <= 0
                ? 0
                : Math.min(4, Math.max(1, Math.ceil((day.seconds / max) * 4))),
        title: `${formatDayLabel(day.date)} — ${formatDuration(day.seconds)}`,
    }));
});

const aiShareDays = computed<CalendarHeatmapDay[]>(() =>
    props.stats.ai_calendar.map((day) => {
        const ai = Math.max(0, day.ai_lines);
        const human = Math.max(0, day.human_lines);
        const total = ai + human;

        if (total === 0) {
            return {
                date: day.date,
                level: 0,
                title: `${formatDayLabel(day.date)} — no line data`,
            };
        }

        const share = ai / total;

        return {
            date: day.date,
            level: 1 + Math.round(share * 3),
            title:
                `${formatDayLabel(day.date)} — ${Math.round(share * 100)}% AI ` +
                `(${formatCompactNumber(day.ai_lines)} AI / ${formatCompactNumber(day.human_lines)} human lines)`,
        };
    }),
);
</script>

<template>
    <Head title="Insights" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold tracking-tight">Insights</h1>
            <span class="text-sm text-muted-foreground">Last 12 months</span>
        </div>

        <CalendarHeatmap
            title="Activity"
            :days="activityDays"
            legend="Less → more time"
        />

        <CalendarHeatmap
            title="AI share"
            :days="aiShareDays"
            legend="Human → AI lines"
        />

        <WeekdayAveragesCard :weekdays="stats.weekdays" />

        <div class="grid gap-4 md:grid-cols-2">
            <TopLinesCard
                title="Top AI-assisted projects"
                :items="stats.top_ai_projects"
                highlight="ai"
                empty-message="No AI line data in the last year."
            />
            <TopLinesCard
                title="Top human-edited projects"
                :items="stats.top_human_projects"
                highlight="human"
                empty-message="No human line data in the last year."
            />
            <TopLinesCard
                title="Top AI-assisted files"
                :items="stats.top_ai_files"
                highlight="ai"
                empty-message="No AI line data in the last year."
            />
            <TopLinesCard
                title="Top human-edited files"
                :items="stats.top_human_files"
                highlight="human"
                empty-message="No human line data in the last year."
            />
        </div>
    </div>
</template>
