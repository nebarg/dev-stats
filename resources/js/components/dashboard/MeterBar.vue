<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        value: number;
        max: number;
        /** Floor so a small-but-nonzero value still shows a visible sliver. */
        minPercent?: number;
    }>(),
    { minPercent: 0 },
);

const width = computed(() => {
    const value = Math.max(0, props.value);

    if (value <= 0) {
        return '0%';
    }

    const percent = (value / Math.max(1, props.max)) * 100;

    return `${Math.max(props.minPercent, percent)}%`;
});
</script>

<template>
    <div class="h-1.5 overflow-hidden rounded-full bg-muted">
        <div class="h-full rounded-full bg-primary" :style="{ width }" />
    </div>
</template>
