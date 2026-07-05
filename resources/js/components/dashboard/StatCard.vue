<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

defineProps<{
    label: string;
    value: string;
    hint?: string;
}>();
</script>

<template>
    <Card>
        <CardContent class="flex flex-col gap-1">
            <span class="text-sm text-muted-foreground">{{ label }}</span>
            <span class="text-2xl font-semibold tracking-tight tabular-nums">
                {{ value }}
            </span>
            <span v-if="hint" class="text-xs text-muted-foreground">
                {{ hint }}
            </span>
        </CardContent>
    </Card>
</template>
