<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCompactNumber } from '@/lib/format';
import type { EditingStats } from '@/types';

const props = defineProps<{
    editing: EditingStats;
}>();

const knownEvents = computed(
    () => props.editing.write_events + props.editing.read_events,
);

const writeShare = computed(() =>
    knownEvents.value > 0
        ? props.editing.write_events / knownEvents.value
        : null,
);

type Tile = { label: string; value: string; hint?: string };

const tiles = computed<Tile[]>(() => {
    const editing = props.editing;
    const agentShare =
        editing.write_events > 0
            ? Math.round(
                  (editing.agent_write_events / editing.write_events) * 100,
              )
            : 0;

    return [
        {
            label: 'Write share',
            value:
                writeShare.value !== null
                    ? `${Math.round(writeShare.value * 100)}%`
                    : '—',
            hint: 'of read/write events',
        },
        {
            label: 'Writes',
            value: formatCompactNumber(editing.write_events),
        },
        {
            label: 'Reads',
            value: formatCompactNumber(editing.read_events),
        },
        {
            label: 'Agent-file writes',
            value: formatCompactNumber(editing.agent_write_events),
            hint:
                editing.agent_write_events > 0
                    ? `${agentShare}% of writes`
                    : undefined,
        },
        {
            label: 'Agent-file lines',
            value: formatCompactNumber(editing.agent_lines),
            hint: 'plans, memory & rules',
        },
    ];
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Editing activity</CardTitle>
        </CardHeader>
        <CardContent>
            <p v-if="knownEvents === 0" class="text-sm text-muted-foreground">
                No read/write data in this range.
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
                    v-if="writeShare !== null"
                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full bg-primary"
                        :style="{ width: `${writeShare * 100}%` }"
                    />
                </div>
            </div>
        </CardContent>
    </Card>
</template>
