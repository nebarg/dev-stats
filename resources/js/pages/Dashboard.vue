<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import ActivityChart from '@/components/dashboard/ActivityChart.vue';
import AgentsCard from '@/components/dashboard/AgentsCard.vue';
import AiCodingCard from '@/components/dashboard/AiCodingCard.vue';
import BreakdownCard from '@/components/dashboard/BreakdownCard.vue';
import EditingCard from '@/components/dashboard/EditingCard.vue';
import PageHeader from '@/components/dashboard/PageHeader.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import SummaryStats from '@/components/dashboard/SummaryStats.vue';
import { formatDuration, pluralise } from '@/lib/format';
import { dashboard } from '@/routes';
import { show as showProject } from '@/routes/projects';
import type { DashboardStats } from '@/types';

const props = defineProps<{
    stats: DashboardStats;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const deepWorkHint = computed(() => {
    const blocks = props.stats.focus.deep_work_blocks;

    return `${blocks} ${pluralise(blocks, 'block')} of 25 min+`;
});

const streakValue = computed(() => {
    const days = props.stats.streak.current_days;

    return `${days} ${pluralise(days, 'day')}`;
});

const streakHint = computed(() => {
    const days = props.stats.streak.longest_days;

    return `longest ${days} ${pluralise(days, 'day')}`;
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <PageHeader
            title="Coding activity"
            :ranges="stats.ranges"
            :current="stats.range"
            :url="(range) => dashboard.url({ query: { range } })"
        />

        <SummaryStats
            :total-seconds="stats.total_seconds"
            :today-seconds="stats.today_seconds"
            :daily-average-seconds="stats.daily_average_seconds"
            :active-days="stats.active_days"
            :most-active-day="stats.most_active_day"
        />

        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Longest block"
                :value="formatDuration(stats.focus.longest_block_seconds)"
                hint="uninterrupted coding"
            />
            <StatCard
                label="Deep work"
                :value="formatDuration(stats.focus.deep_work_seconds)"
                :hint="deepWorkHint"
            />
            <StatCard
                label="Context switches"
                :value="String(stats.focus.context_switches)"
                hint="project hops mid-flow"
            />
            <StatCard label="Streak" :value="streakValue" :hint="streakHint" />
        </div>

        <ActivityChart :data="stats.activity" />

        <AiCodingCard :ai="stats.ai" />

        <EditingCard :editing="stats.editing" />

        <div class="grid gap-4 md:grid-cols-2">
            <BreakdownCard
                title="Projects"
                :items="stats.breakdowns.projects"
                :item-url="(key) => showProject.url(encodeURIComponent(key))"
            />
            <BreakdownCard
                title="Languages"
                :items="stats.breakdowns.languages"
            />
            <BreakdownCard title="Editors" :items="stats.breakdowns.editors" />
            <BreakdownCard
                title="Operating systems"
                :items="stats.breakdowns.operating_systems"
            />
            <BreakdownCard
                title="Categories"
                :items="stats.breakdowns.categories"
            />
            <AgentsCard :agents="stats.ai.agents" />
        </div>
    </div>
</template>
