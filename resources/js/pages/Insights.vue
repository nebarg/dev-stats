<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import BreakdownCard from '@/components/dashboard/BreakdownCard.vue';
import CalendarHeatmap from '@/components/dashboard/CalendarHeatmap.vue';
import RangeSelector from '@/components/dashboard/RangeSelector.vue';
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

const secondsByDate = computed(
    () => new Map(props.stats.calendar.map((day) => [day.date, day.seconds])),
);

const aiShareDays = computed<CalendarHeatmapDay[]>(() =>
    props.stats.ai_calendar.map((day) => {
        const ai = Math.max(0, day.ai_lines);
        const human = Math.max(0, day.human_lines);
        const total = ai + human;

        if (total === 0) {
            // Line-authorship data only exists from 2026. Before then (and any
            // other day with coding activity but no line data) we treat the day
            // as fully human; genuinely idle days stay blank.
            const isActive = (secondsByDate.value.get(day.date) ?? 0) > 0;

            return {
                date: day.date,
                level: isActive ? 1 : 0,
                title: isActive
                    ? `${formatDayLabel(day.date)} — 100% human (no AI line data)`
                    : `${formatDayLabel(day.date)} — no activity`,
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
            <RangeSelector
                :ranges="stats.ranges"
                :current="stats.range"
                :labels="{ '12m': 'Last 12 months' }"
                :url="(range) => insights.url({ query: { range } })"
            />
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
            <BreakdownCard title="Top projects" :items="stats.top_projects" />
            <BreakdownCard title="Top files" :items="stats.top_files" />
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <TopLinesCard
                title="Top AI-assisted projects"
                :items="stats.top_ai_projects"
                highlight="ai"
                empty-message="No AI line changes recorded for this period."
            />
            <TopLinesCard
                title="Top human-edited projects"
                :items="stats.top_human_projects"
                highlight="human"
                empty-message="No human line changes recorded for this period."
            />
            <TopLinesCard
                title="Top AI-assisted files"
                :items="stats.top_ai_files"
                highlight="ai"
                empty-message="No AI line changes recorded for this period."
            />
            <TopLinesCard
                title="Top human-edited files"
                :items="stats.top_human_files"
                highlight="human"
                empty-message="No human line changes recorded for this period."
            />
        </div>
    </div>
</template>
