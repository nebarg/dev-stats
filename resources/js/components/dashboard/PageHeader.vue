<script setup lang="ts">
import RangeSelector from '@/components/dashboard/RangeSelector.vue';

defineProps<{
    title: string;
    ranges: string[];
    current: string;
    url: (range: string) => string;
    labels?: Record<string, string>;
}>();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
        <RangeSelector
            :ranges="ranges"
            :current="current"
            :url="url"
            :labels="labels"
        />
    </div>
</template>
