<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    status: 'success' | 'warning' | 'error' | 'neutral';
    label: string;
}>();

const dotColor = computed(() => {
    switch (props.status) {
        case 'success':
            return 'bg-green-500';
        case 'warning':
            return 'bg-yellow-500';
        case 'error':
            return 'bg-red-500';
        default:
            return 'bg-gray-400';
    }
});

const textColor = computed(() => {
    switch (props.status) {
        case 'success':
            return 'text-green-700 dark:text-green-400';
        case 'warning':
            return 'text-yellow-700 dark:text-yellow-400';
        case 'error':
            return 'text-red-700 dark:text-red-400';
        default:
            return 'text-muted-foreground';
    }
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-sm font-medium" :class="textColor">
        <span class="relative flex h-2 w-2">
            <span
                v-if="status === 'success'"
                class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"
            />
            <span class="relative inline-flex h-2 w-2 rounded-full" :class="dotColor" />
        </span>
        {{ label }}
    </span>
</template>
