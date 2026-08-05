<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCompactNumber } from '@/lib/format';
import type { LineTotals } from '@/types';

const props = defineProps<{
    title: string;
    items: LineTotals[];
    highlight: 'ai' | 'human';
    emptyMessage: string;
}>();

function positiveLines(item: LineTotals): { ai: number; human: number } {
    return {
        ai: Math.max(0, item.ai_lines),
        human: Math.max(0, item.human_lines),
    };
}

// The card ranks by one metric (AI or human lines), so the bar measures that
// same metric — otherwise an AI-heavy file dwarfs a fully-human one on a
// "human-edited" card.
function metricLines(item: LineTotals): number {
    const { ai, human } = positiveLines(item);

    return props.highlight === 'ai' ? ai : human;
}

const max = computed(() =>
    Math.max(1, ...props.items.map((item) => metricLines(item))),
);

function width(lines: number): string {
    return `${(lines / max.value) * 100}%`;
}

function headline(item: LineTotals): string {
    return `${formatCompactNumber(metricLines(item))} lines`;
}

// The card ranks by absolute line count, so a proportion ("0% human") reads as
// a contradiction next to it. Show the counterpart's count instead, so a
// top-human row that is mostly AI reads honestly: 31 human lines, 8.3K AI.
function counterpart(item: LineTotals): string {
    const { ai, human } = positiveLines(item);
    const other = props.highlight === 'ai' ? human : ai;
    const label = props.highlight === 'ai' ? 'human' : 'AI';

    return `${formatCompactNumber(other)} ${label} lines`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <p v-if="items.length === 0" class="text-sm text-muted-foreground">
                {{ emptyMessage }}
            </p>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="item in items"
                    :key="item.path ?? item.key"
                    class="flex flex-col gap-1.5"
                >
                    <div
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <span :title="item.path" class="truncate">
                            {{ item.key }}
                            <span
                                v-if="item.project"
                                class="text-xs text-muted-foreground"
                            >
                                {{ item.project }}
                            </span>
                        </span>
                        <span
                            class="shrink-0 text-muted-foreground tabular-nums"
                        >
                            {{ headline(item) }}
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            v-if="metricLines(item) > 0"
                            class="h-full rounded-full bg-primary"
                            :style="{ width: width(metricLines(item)) }"
                        />
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ counterpart(item) }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
