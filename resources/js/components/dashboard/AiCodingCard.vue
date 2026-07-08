<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCompactNumber, pluralise } from '@/lib/format';
import type { AiStats } from '@/types';

const props = defineProps<{
    ai: AiStats;
}>();

const hasActivity = computed(() => {
    const { ai_lines, human_lines, input_tokens, output_tokens, sessions } =
        props.ai;

    return [ai_lines, human_lines, input_tokens, output_tokens, sessions].some(
        (value) => value !== 0,
    );
});

// Line changes are signed nets (deletions can push them negative), so clamp
// to zero for the share calculation only — displayed counts stay raw.
const aiShare = computed(() => {
    const ai = Math.max(0, props.ai.ai_lines);
    const human = Math.max(0, props.ai.human_lines);
    const total = ai + human;

    return total > 0 ? ai / total : null;
});

type Tile = { label: string; value: string; hint?: string };

const tiles = computed<Tile[]>(() => {
    const ai = props.ai;
    const promptsPerSession =
        ai.sessions > 0 ? Math.round(ai.prompts / ai.sessions) : 0;

    return [
        {
            label: 'AI-driven',
            value:
                aiShare.value !== null
                    ? `${Math.round(aiShare.value * 100)}%`
                    : '—',
            hint: 'of line changes',
        },
        {
            label: 'AI lines',
            value: formatCompactNumber(ai.ai_lines),
        },
        {
            label: 'Human lines',
            value: formatCompactNumber(ai.human_lines),
        },
        {
            label: 'Tokens',
            value: formatCompactNumber(ai.input_tokens + ai.output_tokens),
            hint: `${formatCompactNumber(ai.input_tokens)} in / ${formatCompactNumber(ai.output_tokens)} out`,
        },
        {
            label: 'AI sessions',
            value: formatCompactNumber(ai.sessions),
            hint:
                promptsPerSession > 0
                    ? `~${promptsPerSession} ${pluralise(promptsPerSession, 'prompt')} each`
                    : undefined,
        },
        {
            label: 'Prompts',
            value: formatCompactNumber(ai.prompts),
            hint:
                ai.avg_prompt_length > 0
                    ? `~${formatCompactNumber(ai.avg_prompt_length)} chars avg`
                    : undefined,
        },
    ];
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>AI coding</CardTitle>
        </CardHeader>
        <CardContent>
            <p v-if="!hasActivity" class="text-sm text-muted-foreground">
                No AI activity in this range.
            </p>
            <div v-else class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="tile in tiles"
                        :key="tile.label"
                        class="flex flex-col gap-1"
                    >
                        <span class="text-sm text-muted-foreground">
                            {{ tile.label }}
                        </span>
                        <span
                            class="text-2xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ tile.value }}
                        </span>
                        <span
                            v-if="tile.hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{ tile.hint }}
                        </span>
                    </div>
                </div>
                <div
                    v-if="aiShare !== null"
                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full bg-primary"
                        :style="{ width: `${aiShare * 100}%` }"
                    />
                </div>
            </div>
        </CardContent>
    </Card>
</template>
