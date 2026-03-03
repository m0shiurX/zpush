<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface AppStatus {
    setup_completed: boolean;
    device_count: number;
    connected_devices: number;
    unsynced_count: number;
    timezone: string;
}

const page = usePage<{ appStatus: AppStatus }>();

const isOffline = computed(() => {
    const status = page.props.appStatus;
    if (!status) return false;
    return status.device_count > 0 && status.connected_devices === 0;
});
</script>

<template>
    <div
        v-if="isOffline"
        class="flex items-center justify-center gap-2 bg-yellow-500 px-4 py-1.5 text-sm font-medium text-yellow-950"
    >
        <span class="relative flex h-2 w-2">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-yellow-800 opacity-75" />
            <span class="relative inline-flex h-2 w-2 rounded-full bg-yellow-800" />
        </span>
        All devices are offline — attendance polling paused
    </div>
</template>
