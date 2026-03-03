<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/devices';
import { test, poll } from '@/actions/App/Http/Controllers/DeviceController';
import { ref } from 'vue';
import axios from 'axios';

interface Device {
    id: number;
    name: string;
    ip_address: string;
    port: number;
    protocol: string;
    is_active: boolean;
    is_connected: boolean;
    last_connected_at: string | null;
    last_poll_at: string | null;
    connection_failures: number;
    attendance_logs_count: number;
}

defineProps<{
    devices: Device[];
}>();

const testingDevice = ref<number | null>(null);
const pollingDevice = ref<number | null>(null);
const testResult = ref<Record<number, { success: boolean; message: string }>>({});

async function handleTest(deviceId: number) {
    testingDevice.value = deviceId;
    testResult.value[deviceId] = { success: false, message: '' };

    try {
        const { data } = await axios.post(test.url({ device: deviceId }));
        testResult.value[deviceId] = {
            success: data.success,
            message: data.success
                ? `Connected — ${data.device_name ?? 'Device'} (${data.serial_number ?? 'N/A'})`
                : `Failed: ${data.error}`,
        };
    } catch (error: any) {
        testResult.value[deviceId] = {
            success: false,
            message: error.response?.data?.error ?? 'Network error',
        };
    } finally {
        testingDevice.value = null;
    }
}

async function handlePoll(deviceId: number) {
    pollingDevice.value = deviceId;
    try {
        const { data } = await axios.post(poll.url({ device: deviceId }));
        if (data.success) {
            testResult.value[deviceId] = {
                success: true,
                message: `Polled: ${data.new} new, ${data.duplicates} duplicates, ${data.users_synced} users synced`,
            };
            router.reload({ only: ['devices'] });
        } else {
            testResult.value[deviceId] = { success: false, message: data.error };
        }
    } catch (error: any) {
        testResult.value[deviceId] = {
            success: false,
            message: error.response?.data?.error ?? 'Network error',
        };
    } finally {
        pollingDevice.value = null;
    }
}

function timeAgo(iso: string | null): string {
    if (!iso) return 'Never';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}
</script>

<template>

    <Head title="Devices" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Devices</h1>
            </div>

            <div v-if="devices.length === 0" class="flex flex-col items-center justify-center py-16">
                <p class="text-muted-foreground">No devices configured yet.</p>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="device in devices" :key="device.id">
                    <CardHeader class="flex flex-row items-start justify-between space-y-0 pb-2">
                        <div>
                            <CardTitle class="text-base">
                                <Link :href="show.url({ device: device.id })" class="hover:underline">
                                    {{ device.name }}
                                </Link>
                            </CardTitle>
                            <p class="text-sm text-muted-foreground mt-1">
                                {{ device.ip_address }}:{{ device.port }} ({{ device.protocol.toUpperCase() }})
                            </p>
                        </div>
                        <StatusBadge
                            :status="device.is_connected ? 'success' : device.connection_failures > 0 ? 'error' : 'neutral'"
                            :label="device.is_connected ? 'Connected' : device.connection_failures > 0 ? 'Failed' : 'Unknown'" />
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span class="text-muted-foreground">Last Poll</span>
                                    <p class="font-medium">{{ timeAgo(device.last_poll_at) }}</p>
                                </div>
                                <div>
                                    <span class="text-muted-foreground">Records</span>
                                    <p class="font-medium">{{ device.attendance_logs_count.toLocaleString() }}</p>
                                </div>
                            </div>

                            <!-- Test result message -->
                            <div v-if="testResult[device.id]" class="rounded-md px-3 py-2 text-xs" :class="testResult[device.id].success
                                ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400'
                                : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400'">
                                {{ testResult[device.id].message }}
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-2">
                                <Button size="sm" variant="outline" :disabled="testingDevice === device.id"
                                    @click="handleTest(device.id)">
                                    {{ testingDevice === device.id ? 'Testing...' : 'Test' }}
                                </Button>
                                <Button size="sm" :disabled="pollingDevice === device.id"
                                    @click="handlePoll(device.id)">
                                    {{ pollingDevice === device.id ? 'Polling...' : 'Poll Now' }}
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
