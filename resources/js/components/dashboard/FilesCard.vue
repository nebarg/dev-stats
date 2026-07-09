<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCompactNumber, formatDuration } from '@/lib/format';
import type { FileStats } from '@/types';

const props = defineProps<{
    files: FileStats[];
    fileCount: number;
}>();

const max = computed(() =>
    Math.max(1, ...props.files.map((file) => file.seconds)),
);

function width(seconds: number): string {
    return `${Math.max(2, (seconds / max.value) * 100)}%`;
}

function lines(file: FileStats): string | null {
    if (file.ai_lines === 0 && file.human_lines === 0) {
        return null;
    }

    return `${formatCompactNumber(file.ai_lines)} AI · ${formatCompactNumber(file.human_lines)} human lines`;
}

const overflow = computed(() => props.fileCount - props.files.length);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Files</CardTitle>
        </CardHeader>
        <CardContent>
            <p v-if="files.length === 0" class="text-sm text-muted-foreground">
                No file activity in this range.
            </p>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="file in files"
                    :key="file.path"
                    class="flex flex-col gap-1.5"
                >
                    <div
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <span :title="file.path" class="truncate font-mono">
                            {{ file.key }}
                        </span>
                        <span
                            class="shrink-0 text-muted-foreground tabular-nums"
                        >
                            {{ formatDuration(file.seconds) }}
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-primary"
                            :style="{ width: width(file.seconds) }"
                        />
                    </div>
                    <span
                        v-if="lines(file)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ lines(file) }}
                    </span>
                </div>
                <p v-if="overflow > 0" class="text-xs text-muted-foreground">
                    and {{ overflow }} more files
                </p>
            </div>
        </CardContent>
    </Card>
</template>
