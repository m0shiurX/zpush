<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { index as devicesIndex } from '@/routes/devices';
import { index as attendanceIndex } from '@/routes/attendance';
import { index as employeesIndex } from '@/routes/employees';
import { triggerSync } from '@/actions/App/Http/Controllers/SyncController';
import { Cloud, CheckCircle, XCircle } from 'lucide-vue-next';
import axios from 'axios';

interface DeviceSummary {
    id: number;
    name: string;
    ip_address: string;
    is_connected: boolean;
    last_poll_at: string | null;
    connection_failures: number;
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
    devices: DeviceSummary[];
    todayPunchCount: number;
    todayLogs: AttendanceEntry[];
    employeeCount: number;
    unsyncedCount: number;
    hasCloudServer: boolean;
    lastSyncAt: string | null;
}>();

const syncing = ref(false);
const syncResult = ref<{ success: boolean; message: string } | null>(null);

const handleSyncNow = async () => {
    syncing.value = true;
    syncResult.value = null;
    try {
        const { data } = await axios.post(triggerSync.url());
        syncResult.value = { success: data.success, message: data.message };
    } catch (error: any) {
        syncResult.value = {
            success: false,
            message: error.response?.data?.message ?? 'Failed to trigger sync.',
        };
    } finally {
        syncing.value = false;
    }
};

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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
    <Head title="Dashboard" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
            <!-- Stat Cards -->
            <div class="grid auto-rows-min gap-4 md:grid-cols-4">
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">
                            Devices
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ devices.length }}</div>
                        <p class="text-xs text-muted-foreground">
                            {{ devices.filter(d => d.is_connected).length }} connected
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">
                            Today's Punches
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ todayPunchCount }}</div>
                        <p class="text-xs text-muted-foreground">
                            Attendance records today
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">
                            Employees
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ employeeCount }}</div>
                        <p class="text-xs text-muted-foreground">
                            Active employees on device
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">
                            Unsynced
                        </CardTitle>
                        <Button
                            v-if="hasCloudServer"
                            variant="ghost"
                            size="sm"
                            :disabled="syncing"
                            @click="handleSyncNow"
                            class="h-7 px-2 text-xs"
                        >
                            <Spinner v-if="syncing" class="mr-1" />
                            <Cloud v-else class="mr-1 h-3 w-3" />
                            {{ syncing ? 'Syncing...' : 'Sync Now' }}
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ unsyncedCount }}</div>
                        <p class="text-xs text-muted-foreground">
                            {{ lastSyncAt ? `Last sync: ${timeAgo(lastSyncAt)}` : 'Records pending cloud sync' }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Sync feedback -->
            <Alert
                v-if="syncResult?.success"
                class="border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200"
            >
                <CheckCircle class="h-4 w-4 text-green-600 dark:text-green-400" />
                <AlertDescription>{{ syncResult.message }}</AlertDescription>
            </Alert>

            <Alert v-if="syncResult && !syncResult.success" variant="destructive">
                <XCircle class="h-4 w-4" />
                <AlertDescription>{{ syncResult.message }}</AlertDescription>
            </Alert>

            <!-- Device Status + Recent Punches -->
            <div class="grid gap-4 md:grid-cols-2">
                <!-- Device Status -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between">
                        <CardTitle class="text-base">Device Status</CardTitle>
                        <Link :href="devicesIndex.url()" class="text-sm text-primary hover:underline">
                            View all
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div v-if="devices.length === 0" class="text-sm text-muted-foreground py-4 text-center">
                            No devices configured.
                        </div>
                        <div v-else class="space-y-3">
                            <div
                                v-for="device in devices"
                                :key="device.id"
                                class="flex items-center justify-between rounded-lg border p-3"
                            >
                                <div>
                                    <p class="font-medium text-sm">{{ device.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ device.ip_address }}</p>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <StatusBadge
                                        :status="device.is_connected ? 'success' : 'error'"
                                        :label="device.is_connected ? 'Connected' : 'Offline'"
                                    />
                                    <span class="text-xs text-muted-foreground">
                                        {{ timeAgo(device.last_poll_at) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Recent Punches -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between">
                        <CardTitle class="text-base">Recent Punches</CardTitle>
                        <Link :href="attendanceIndex.url()" class="text-sm text-primary hover:underline">
                            View all
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div v-if="todayLogs.length === 0" class="text-sm text-muted-foreground py-4 text-center">
                            No attendance records today.
                        </div>
                        <div v-else class="space-y-2 max-h-[400px] overflow-y-auto">
                            <div
                                v-for="log in todayLogs"
                                :key="log.id"
                                class="flex items-center justify-between rounded-lg border px-3 py-2"
                            >
                                <div>
                                    <p class="font-medium text-sm">{{ log.employee_name }}</p>
                                    <p v-if="log.employee_code" class="text-xs text-muted-foreground">
                                        {{ log.employee_code }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="log.punch_color === 'success'
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-gray-100 text-gray-800 dark:bg-gray-800/50 dark:text-gray-400'"
                                    >
                                        {{ log.punch_label }}
                                    </span>
                                    <span class="text-xs text-muted-foreground whitespace-nowrap">
                                        {{ formatTime(log.timestamp) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
