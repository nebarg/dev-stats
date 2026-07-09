<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

defineProps<{
    ranges: string[];
    current: string;
    url: (range: string) => string;
}>();

const rangeLabels: Record<string, string> = {
    '7d': '7 days',
    '30d': '30 days',
    all: 'All time',
};
</script>

<template>
    <div class="flex items-center gap-1 rounded-lg border bg-card p-1">
        <Link
            v-for="key in ranges"
            :key="key"
            :href="url(key)"
            :class="
                cn(
                    'rounded-md px-3 py-1 text-sm font-medium transition-colors',
                    key === current
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
        >
            {{ rangeLabels[key] ?? key }}
        </Link>
    </div>
</template>
