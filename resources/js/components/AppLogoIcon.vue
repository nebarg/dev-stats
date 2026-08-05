<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <rect x="3" y="13" width="4.5" height="8" rx="1.5" fill="currentColor" />
        <rect
            x="9.75"
            y="8"
            width="4.5"
            height="13"
            rx="1.5"
            fill="currentColor"
        />
        <rect
            x="16.5"
            y="3"
            width="4.5"
            height="18"
            rx="1.5"
            fill="currentColor"
        />
    </svg>
</template>
