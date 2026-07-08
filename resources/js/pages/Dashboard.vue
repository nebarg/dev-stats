<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ActivityChart from '@/components/dashboard/ActivityChart.vue';
import AgentsCard from '@/components/dashboard/AgentsCard.vue';
import AiCodingCard from '@/components/dashboard/AiCodingCard.vue';
import BreakdownCard from '@/components/dashboard/BreakdownCard.vue';
import EditingCard from '@/components/dashboard/EditingCard.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import { formatDayLabel, formatDuration, pluralise } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
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

const rangeLabels: Record<string, string> = {
    '7d': '7 days',
    '30d': '30 days',
    all: 'All time',
};

const dailyAverageHint = computed(() => {
    const days = props.stats.active_days;

    return `over ${days} ${pluralise(days, 'active day')}`;
});

const mostActive = computed(() => props.stats.most_active_day);

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
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold tracking-tight">
                Coding activity
            </h1>

            <div class="flex items-center gap-1 rounded-lg border bg-card p-1">
                <Link
                    v-for="key in stats.ranges"
                    :key="key"
                    :href="dashboard.url({ query: { range: key } })"
                    :class="
                        cn(
                            'rounded-md px-3 py-1 text-sm font-medium transition-colors',
                            key === stats.range
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )
                    "
                >
                    {{ rangeLabels[key] ?? key }}
                </Link>
            </div>
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
