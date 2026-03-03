<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { test, poll } from '@/actions/App/Http/Controllers/DeviceController';
import { index as devicesIndex } from '@/routes/devices';
import { ref } from 'vue';
import axios from 'axios';

interface DeviceDetail {
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
    total_logs: number;
}

interface AttendanceEntry {
    id: number;
    employee_name: string;
    employee_code: string | null;
    timestamp: string;
    punch_type: number;
    punch_label: string;
    punch_color: string;
}

const props = defineProps<{
    device: DeviceDetail;
    recentLogs: AttendanceEntry[];
}>();

const testing = ref(false);
const polling = ref(false);
const resultMessage = ref('');
const resultSuccess = ref(false);

async function handleTest() {
    testing.value = true;
    resultMessage.value = '';

    try {
        const { data } = await axios.post(test.url({ device: props.device.id }));
        resultSuccess.value = data.success;
        resultMessage.value = data.success
            ? `Connected — ${data.device_name ?? 'Device'} (SN: ${data.serial_number ?? 'N/A'}, FW: ${data.firmware ?? 'N/A'})`
            : `Failed: ${data.error}`;
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.response?.data?.error ?? 'Network error';
    } finally {
        testing.value = false;
    }
}

async function handlePoll() {
    polling.value = true;
    resultMessage.value = '';

    try {
        const { data } = await axios.post(poll.url({ device: props.device.id }));
        resultSuccess.value = data.success;
        resultMessage.value = data.success
            ? `Polled: ${data.new} new, ${data.duplicates} duplicates, ${data.users_synced} users synced`
            : `Error: ${data.error}`;
        if (data.success) {
            router.reload({ only: ['recentLogs', 'device'] });
        }
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.response?.data?.error ?? 'Network error';
    } finally {
        polling.value = false;
    }
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
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

    <Head :title="device.name" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <Link :href="devicesIndex.url()" class="hover:text-foreground">Devices</Link>
                <span>/</span>
                <span class="text-foreground">{{ device.name }}</span>
            </div>

            <!-- Device Info Card -->
            <Card>
                <CardHeader class="flex flex-row items-start justify-between space-y-0">
                    <div>
                        <CardTitle class="text-xl">{{ device.name }}</CardTitle>
                        <p class="text-sm text-muted-foreground mt-1">
                            {{ device.ip_address }}:{{ device.port }} ({{ device.protocol.toUpperCase() }})
                        </p>
                    </div>
                    <StatusBadge
                        :status="device.is_connected ? 'success' : device.connection_failures > 0 ? 'error' : 'neutral'"
                        :label="device.is_connected ? 'Connected' : device.connection_failures > 0 ? 'Failed' : 'Unknown'" />
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4 text-sm">
                        <div>
                            <span class="text-muted-foreground block">Last Connected</span>
                            <span class="font-medium">{{ timeAgo(device.last_connected_at) }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Last Polled</span>
                            <span class="font-medium">{{ timeAgo(device.last_poll_at) }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Failures</span>
                            <span class="font-medium">{{ device.connection_failures }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Total Records</span>
                            <span class="font-medium">{{ device.total_logs.toLocaleString() }}</span>
                        </div>
                    </div>

                    <!-- Result message -->
                    <div v-if="resultMessage" class="rounded-md px-3 py-2 text-sm mb-4" :class="resultSuccess
                        ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400'
                        : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400'">
                        {{ resultMessage }}
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-2">
                        <Button variant="outline" :disabled="testing" @click="handleTest">
                            {{ testing ? 'Testing...' : 'Test Connection' }}
                        </Button>
                        <Button :disabled="polling" @click="handlePoll">
                            {{ polling ? 'Polling...' : 'Poll Attendance' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent Attendance Logs -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Recent Attendance (last 50)</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="recentLogs.length === 0" class="text-sm text-muted-foreground py-4 text-center">
                        No attendance records for this device.
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="pb-2 pr-4">Employee</th>
                                    <th class="pb-2 pr-4">Code</th>
                                    <th class="pb-2 pr-4">Date</th>
                                    <th class="pb-2 pr-4">Time</th>
                                    <th class="pb-2">Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in recentLogs" :key="log.id" class="border-b last:border-0">
                                    <td class="py-2 pr-4 font-medium">{{ log.employee_name }}</td>
                                    <td class="py-2 pr-4 text-muted-foreground">{{ log.employee_code ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ formatDate(log.timestamp) }}</td>
                                    <td class="py-2 pr-4">{{ formatTime(log.timestamp) }}</td>
                                    <td class="py-2">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="log.punch_color === 'success'
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                                : 'bg-gray-100 text-gray-800 dark:bg-gray-800/50 dark:text-gray-400'">
                                            {{ log.punch_label }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
