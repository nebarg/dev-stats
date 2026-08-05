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

const max = computed(() =>
    Math.max(
        1,
        ...props.items.map((item) => {
            const { ai, human } = positiveLines(item);

            return ai + human;
        }),
    ),
);

function width(lines: number): string {
    return `${(lines / max.value) * 100}%`;
}

function headline(item: LineTotals): string {
    const count = props.highlight === 'ai' ? item.ai_lines : item.human_lines;

    return `${formatCompactNumber(count)} lines`;
}

function share(item: LineTotals): string | null {
    const { ai, human } = positiveLines(item);

    if (ai + human === 0) {
        return null;
    }

    return `${Math.round((ai / (ai + human)) * 100)}% AI`;
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
                    <div class="relative h-1.5 rounded-full bg-muted">
                        <div
                            v-if="
                                positiveLines(item).ai +
                                    positiveLines(item).human >
                                0
                            "
                            class="absolute inset-y-0 left-0 rounded-full bg-primary/30"
                            :style="{
                                width: width(
                                    positiveLines(item).ai +
                                        positiveLines(item).human,
                                ),
                            }"
                        />
                        <div
                            v-if="positiveLines(item).ai > 0"
                            class="absolute inset-y-0 left-0 rounded-full bg-primary"
                            :style="{ width: width(positiveLines(item).ai) }"
                        />
                    </div>
                    <span
                        v-if="share(item)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ share(item) }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
