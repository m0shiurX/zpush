<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import StatusBadge from '@/components/StatusBadge.vue';
import SetupLayout from '@/layouts/SetupLayout.vue';
import { finalize } from '@/actions/App/Http/Controllers/SetupController';
import { CheckCircle } from 'lucide-vue-next';

interface DeviceConfig {
    id?: number;
    name: string;
    ip_address: string;
    port: number;
    protocol: string;
}

interface CloudServer {
    id?: number;
    api_base_url: string;
}

const props = defineProps<{
    device?: DeviceConfig | null;
    cloudServer?: CloudServer | null;
}>();

const form = useForm({});

const submit = () => {
    form.post(finalize.url());
};
</script>

<template>
    <Head title="Setup — Complete" />

    <SetupLayout
        :current-step="4"
        title="You're All Set!"
        description="Here's a summary of your configuration."
    >
        <div class="flex flex-col gap-6">
            <!-- Summary -->
            <div class="grid gap-4">
                <!-- Device summary -->
                <div class="flex items-start gap-3 rounded-lg border p-4">
                    <CheckCircle class="mt-0.5 h-5 w-5 shrink-0 text-green-600" />
                    <div class="flex-1">
                        <h3 class="text-sm font-medium">Device Connected</h3>
                        <div v-if="device" class="mt-1 text-sm text-muted-foreground">
                            <p>{{ device.name }} — {{ device.ip_address }}:{{ device.port }} ({{ device.protocol.toUpperCase() }})</p>
                        </div>
                        <StatusBadge v-if="device" status="success" label="Configured" class="mt-2" />
                        <StatusBadge v-else status="error" label="Not configured" class="mt-2" />
                    </div>
                </div>

                <!-- Cloud summary -->
                <div class="flex items-start gap-3 rounded-lg border p-4">
                    <CheckCircle
                        class="mt-0.5 h-5 w-5 shrink-0"
                        :class="cloudServer ? 'text-green-600' : 'text-gray-400'"
                    />
                    <div class="flex-1">
                        <h3 class="text-sm font-medium">Cloud Sync</h3>
                        <div v-if="cloudServer" class="mt-1 text-sm text-muted-foreground">
                            <p>{{ cloudServer.api_base_url }}</p>
                        </div>
                        <StatusBadge v-if="cloudServer" status="success" label="Configured" class="mt-2" />
                        <StatusBadge v-else status="neutral" label="Skipped" class="mt-2" />
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <Button size="lg" :disabled="form.processing" @click="submit">
                    <Spinner v-if="form.processing" class="mr-2" />
                    Open Dashboard
                </Button>
            </div>
        </div>
    </SetupLayout>
</template>
