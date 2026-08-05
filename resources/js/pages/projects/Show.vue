<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import ActivityChart from '@/components/dashboard/ActivityChart.vue';
import BreakdownCard from '@/components/dashboard/BreakdownCard.vue';
import FilesCard from '@/components/dashboard/FilesCard.vue';
import PageHeader from '@/components/dashboard/PageHeader.vue';
import SummaryStats from '@/components/dashboard/SummaryStats.vue';
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
</script>

<template>
    <Head :title="stats.project" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <PageHeader
            :title="stats.project"
            :ranges="stats.ranges"
            :current="stats.range"
            :url="rangeUrl"
        />

        <SummaryStats
            :total-seconds="stats.total_seconds"
            :today-seconds="stats.today_seconds"
            :daily-average-seconds="stats.daily_average_seconds"
            :active-days="stats.active_days"
            :most-active-day="stats.most_active_day"
        />

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
