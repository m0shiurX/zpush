<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const props = defineProps<{
    synced: number;
    pending: number;
}>();

const total = computed(() => props.synced + props.pending);
const percentage = computed(() => (total.value > 0 ? Math.round((props.synced / total.value) * 100) : 0));
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <div class="flex items-center justify-between">
                <CardTitle class="text-base">Sync Progress</CardTitle>
                <span class="text-sm text-muted-foreground">
                    {{ synced.toLocaleString() }} / {{ total.toLocaleString() }} records
                </span>
            </div>
        </CardHeader>
        <CardContent>
            <div class="flex flex-col gap-2">
                <div class="h-3 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-primary transition-all duration-500"
                        :style="{ width: `${percentage}%` }"
                    />
                </div>
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>{{ percentage }}% synced</span>
                    <span v-if="pending > 0">{{ pending.toLocaleString() }} pending</span>
                    <span v-else class="text-green-600 dark:text-green-400">All synced</span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
