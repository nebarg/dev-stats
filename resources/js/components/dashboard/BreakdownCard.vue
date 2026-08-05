<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MeterBar from '@/components/dashboard/MeterBar.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDuration } from '@/lib/format';
import type { BreakdownItem } from '@/types';

const props = defineProps<{
    title: string;
    items: BreakdownItem[];
    itemUrl?: (key: string) => string;
}>();

const max = computed(() =>
    Math.max(1, ...props.items.map((item) => item.seconds)),
);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <p v-if="items.length === 0" class="text-sm text-muted-foreground">
                No activity yet.
            </p>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="item in items"
                    :key="item.key"
                    class="flex flex-col gap-1.5"
                >
                    <div
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <Link
                            v-if="itemUrl"
                            :href="itemUrl(item.key)"
                            class="truncate hover:underline"
                        >
                            {{ item.key }}
                        </Link>
                        <span v-else class="truncate">{{ item.key }}</span>
                        <span
                            class="shrink-0 text-muted-foreground tabular-nums"
                        >
                            {{ formatDuration(item.seconds) }}
                        </span>
                    </div>
                    <MeterBar
                        :value="item.seconds"
                        :max="max"
                        :min-percent="2"
                    />
                </div>
            </div>
        </CardContent>
    </Card>
</template>
