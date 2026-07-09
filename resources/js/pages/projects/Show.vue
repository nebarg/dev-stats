<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import ActivityChart from '@/components/dashboard/ActivityChart.vue';
import BreakdownCard from '@/components/dashboard/BreakdownCard.vue';
import FilesCard from '@/components/dashboard/FilesCard.vue';
import RangeSelector from '@/components/dashboard/RangeSelector.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import { formatDayLabel, formatDuration, pluralise } from '@/lib/format';
import { dashboard } from '@/routes';
import { show } from '@/routes/projects';
import type { ProjectStats } from '@/types';

const props = defineProps<{
    stats: ProjectStats;
}>();

setLayoutProps({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: props.stats.project,
            href: show(encodeURIComponent(props.stats.project)),
        },
    ],
});

function rangeUrl(range: string): string {
    return show.url(encodeURIComponent(props.stats.project), {
        query: { range },
    });
}

const dailyAverageHint = computed(() => {
    const days = props.stats.active_days;

    return `over ${days} ${pluralise(days, 'active day')}`;
});

const mostActive = computed(() => props.stats.most_active_day);
</script>

<template>
    <Head :title="stats.project" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ stats.project }}
            </h1>

            <RangeSelector
                :ranges="stats.ranges"
                :current="stats.range"
                :url="rangeUrl"
            />
        </div>

        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Total"
                :value="formatDuration(stats.total_seconds)"
            />
            <StatCard
                label="Today"
                :value="formatDuration(stats.today_seconds)"
            />
            <StatCard
                label="Daily average"
                :value="formatDuration(stats.daily_average_seconds)"
                :hint="dailyAverageHint"
            />
            <StatCard
                label="Most active day"
                :value="mostActive ? formatDuration(mostActive.seconds) : '—'"
                :hint="
                    mostActive
                        ? formatDayLabel(mostActive.date)
                        : 'No activity yet'
                "
            />
        </div>

        <ActivityChart :data="stats.activity" />

        <div class="grid gap-4 md:grid-cols-2">
            <FilesCard
                class="md:col-span-2"
                :files="stats.files"
                :file-count="stats.file_count"
            />
            <BreakdownCard
                title="Languages"
                :items="stats.breakdowns.languages"
            />
            <BreakdownCard
                title="Branches"
                :items="stats.breakdowns.branches"
            />
            <BreakdownCard title="Editors" :items="stats.breakdowns.editors" />
            <BreakdownCard
                title="Categories"
                :items="stats.breakdowns.categories"
            />
        </div>
    </div>
</template>
