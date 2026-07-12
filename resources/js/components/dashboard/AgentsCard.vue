<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCompactNumber, formatUsd, pluralise } from '@/lib/format';
import type { AgentStats } from '@/types';

const props = defineProps<{
    agents: AgentStats[];
}>();

const max = computed(() =>
    Math.max(1, ...props.agents.map((agent) => agent.lines)),
);

function width(lines: number): string {
    return `${Math.max(2, (Math.max(0, lines) / max.value) * 100)}%`;
}

function detail(agent: AgentStats): string {
    const tokens = agent.input_tokens + agent.output_tokens;
    const parts = [
        `${formatCompactNumber(tokens)} tokens`,
        `${agent.sessions} ${pluralise(agent.sessions, 'session')}`,
    ];

    if (agent.cost_cents !== null) {
        parts.push(`~${formatUsd(agent.cost_cents)}`);
    }

    return parts.join(' · ');
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>AI agents</CardTitle>
        </CardHeader>
        <CardContent>
            <p v-if="agents.length === 0" class="text-sm text-muted-foreground">
                No agent data in this range. Agents appear when AI heartbeats
                carry a model in their user agent.
            </p>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="agent in agents"
                    :key="agent.key"
                    class="flex flex-col gap-1.5"
                >
                    <div
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <span class="truncate">{{ agent.key }}</span>
                        <span
                            class="shrink-0 text-muted-foreground tabular-nums"
                        >
                            {{ formatCompactNumber(agent.lines) }}
                            {{ pluralise(agent.lines, 'line') }}
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-primary"
                            :style="{ width: width(agent.lines) }"
                        />
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ detail(agent) }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
