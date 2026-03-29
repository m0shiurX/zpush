<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import axios from 'axios';
import { CheckCircle, XCircle, RefreshCw, CloudOff, Filter, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { triggerSync } from '@/actions/App/Http/Controllers/SyncController';
import StatusBadge from '@/components/StatusBadge.vue';
import SyncProgress from '@/components/SyncProgress.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';

interface CloudServerData {
    id: number;
    name: string | null;
    api_base_url: string;
    is_active: boolean;
    is_connected: boolean;
}

interface SyncStats {
    pending_attendance: number;
    synced_attendance: number;
    queue_pending: number;
    queue_failed: number;
}

interface SyncLogEntry {
    id: number;
    cloud_server_id: number | null;
    device_id: number | null;
    direction: string;
    entity_type: string;
    records_affected: number;
    status: string;
    error_message: string | null;
    duration_ms: number | null;
    started_at: string;
    completed_at: string | null;
    cloud_server: { id: number; name: string } | null;
    device: { id: number; name: string } | null;
}

interface Filters {
    direction?: string;
    entity_type?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
}

const props = defineProps<{
    cloudServer: CloudServerData | null;
    stats: SyncStats;
    recentLogs: SyncLogEntry[];
    filters?: Filters;
}>();

const syncing = ref(false);
const syncResult = ref<{ success: boolean; message: string } | null>(null);
const showFilters = ref(hasActiveFilters());

// Auto-refresh sync stats and logs every 10 seconds
usePoll(10000, { only: ['stats', 'recentLogs'] });

const filterDirection = ref(props.filters?.direction ?? '');
const filterEntityType = ref(props.filters?.entity_type ?? '');
const filterStatus = ref(props.filters?.status ?? '');
const filterDateFrom = ref(props.filters?.date_from ?? '');
const filterDateTo = ref(props.filters?.date_to ?? '');

function hasActiveFilters(): boolean {
    if (!props.filters) return false;
    return Object.values(props.filters).some(v => v && v !== '');
}

function applyFilters() {
    const params: Record<string, string> = {};
    if (filterDirection.value) params.direction = filterDirection.value;
    if (filterEntityType.value) params.entity_type = filterEntityType.value;
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterDateFrom.value) params.date_from = filterDateFrom.value;
    if (filterDateTo.value) params.date_to = filterDateTo.value;

    router.get(window.location.pathname, params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    filterDirection.value = '';
    filterEntityType.value = '';
    filterStatus.value = '';
    filterDateFrom.value = '';
    filterDateTo.value = '';
    router.get(window.location.pathname, {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

const handleTriggerSync = async () => {
    syncing.value = true;
    syncResult.value = null;

    try {
        const { data } = await axios.post(triggerSync.url(), {}, { timeout: 120000 });
        syncResult.value = {
            success: data.success,
            message: data.message,
        };
        // Reload the page data after sync completes
        router.reload({ only: ['stats', 'recentLogs'] });
    } catch (error: any) {
        syncResult.value = {
            success: false,
            message: error.code === 'ECONNABORTED'
                ? 'Sync timed out — it may still be running in the background.'
                : error.response?.data?.message ?? 'Failed to trigger sync.',
        };
    } finally {
        syncing.value = false;
    }
};

function formatDirection(direction: string): string {
    const labels: Record<string, string> = {
        cloud_up: 'Local → Cloud',
        cloud_down: 'Cloud → Local',
        device_up: 'Local → Device',
        device_down: 'Device → Local',
    };
    return labels[direction] ?? direction;
}

function statusVariant(status: string): 'success' | 'warning' | 'error' | 'neutral' {
    const map: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
        completed: 'success',
        processing: 'warning',
        pending: 'neutral',
        failed: 'error',
    };
    return map[status] ?? 'neutral';
}

function formatDuration(ms: number | null): string {
    if (ms === null) return '—';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
}

function formatTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
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
    <Head title="Sync Monitor" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Sync Monitor</h1>
                <Button
                    :disabled="syncing || !cloudServer"
                    @click="handleTriggerSync"
                >
                    <Spinner v-if="syncing" class="mr-2" />
                    <RefreshCw v-else class="mr-2 h-4 w-4" />
                    {{ syncing ? 'Syncing...' : 'Sync Now' }}
                </Button>
            </div>

            <!-- No cloud server -->
            <div v-if="!cloudServer" class="flex flex-col items-center justify-center py-16">
                <CloudOff class="h-12 w-12 text-muted-foreground/50 mb-4" />
                <p class="text-muted-foreground text-center">
                    No cloud server configured. Set up a cloud server first to use sync.
                </p>
            </div>

            <template v-else>
                <!-- Sync result feedback -->
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

                <!-- Stat Cards -->
                <div class="grid auto-rows-min gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle class="text-sm font-medium text-muted-foreground">
                                Pending Attendance
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">{{ stats.pending_attendance }}</div>
                            <p class="text-xs text-muted-foreground">
                                Records awaiting cloud sync
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle class="text-sm font-medium text-muted-foreground">
                                Synced Attendance
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">{{ stats.synced_attendance }}</div>
                            <p class="text-xs text-muted-foreground">
                                Records uploaded to cloud
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle class="text-sm font-medium text-muted-foreground">
                                Queue Pending
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">{{ stats.queue_pending }}</div>
                            <p class="text-xs text-muted-foreground">
                                Jobs waiting to process
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle class="text-sm font-medium text-muted-foreground">
                                Queue Failed
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                                {{ stats.queue_failed }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Jobs that need attention
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <!-- Sync Progress -->
                <SyncProgress
                    :synced="stats.synced_attendance"
                    :pending="stats.pending_attendance"
                />

                <!-- Recent Sync Logs -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between">
                        <CardTitle class="text-base">Recent Sync Activity</CardTitle>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="showFilters = !showFilters"
                        >
                            <Filter class="mr-1 h-4 w-4" />
                            {{ showFilters ? 'Hide Filters' : 'Filters' }}
                        </Button>
                    </CardHeader>

                    <!-- Filters -->
                    <CardContent v-if="showFilters" class="border-b pb-4">
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-[140px]">
                                <label class="text-sm text-muted-foreground mb-1 block">Direction</label>
                                <Select v-model="filterDirection" @update:model-value="applyFilters">
                                    <SelectTrigger>
                                        <SelectValue placeholder="All" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="cloud_up">Local → Cloud</SelectItem>
                                        <SelectItem value="cloud_down">Cloud → Local</SelectItem>
                                        <SelectItem value="device_up">Local → Device</SelectItem>
                                        <SelectItem value="device_down">Device → Local</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="min-w-[130px]">
                                <label class="text-sm text-muted-foreground mb-1 block">Type</label>
                                <Select v-model="filterEntityType" @update:model-value="applyFilters">
                                    <SelectTrigger>
                                        <SelectValue placeholder="All" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="attendance">Attendance</SelectItem>
                                        <SelectItem value="employee">Employee</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="min-w-[130px]">
                                <label class="text-sm text-muted-foreground mb-1 block">Status</label>
                                <Select v-model="filterStatus" @update:model-value="applyFilters">
                                    <SelectTrigger>
                                        <SelectValue placeholder="All" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="completed">Completed</SelectItem>
                                        <SelectItem value="failed">Failed</SelectItem>
                                        <SelectItem value="processing">Processing</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="min-w-[140px]">
                                <label class="text-sm text-muted-foreground mb-1 block">From</label>
                                <Input v-model="filterDateFrom" type="date" @change="applyFilters" />
                            </div>
                            <div class="min-w-[140px]">
                                <label class="text-sm text-muted-foreground mb-1 block">To</label>
                                <Input v-model="filterDateTo" type="date" @change="applyFilters" />
                            </div>
                            <Button variant="outline" size="sm" @click="clearFilters">
                                <X class="mr-1 h-3 w-3" />
                                Clear
                            </Button>
                        </div>
                    </CardContent>

                    <CardContent>
                        <div v-if="recentLogs.length === 0" class="py-8 text-center text-muted-foreground text-sm">
                            No sync activity yet.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-muted-foreground">
                                        <th class="pb-2 pr-4 font-medium">Direction</th>
                                        <th class="pb-2 pr-4 font-medium">Type</th>
                                        <th class="pb-2 pr-4 font-medium">Source</th>
                                        <th class="pb-2 pr-4 font-medium">Records</th>
                                        <th class="pb-2 pr-4 font-medium">Status</th>
                                        <th class="pb-2 pr-4 font-medium">Duration</th>
                                        <th class="pb-2 font-medium">Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="log in recentLogs"
                                        :key="log.id"
                                        class="border-b last:border-0"
                                    >
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ formatDirection(log.direction) }}</td>
                                        <td class="py-2 pr-4 capitalize">{{ log.entity_type }}</td>
                                        <td class="py-2 pr-4 text-muted-foreground">
                                            {{ log.cloud_server?.name ?? log.device?.name ?? '—' }}
                                        </td>
                                        <td class="py-2 pr-4">{{ log.records_affected }}</td>
                                        <td class="py-2 pr-4">
                                            <StatusBadge
                                                :status="statusVariant(log.status)"
                                                :label="log.status"
                                            />
                                        </td>
                                        <td class="py-2 pr-4">{{ formatDuration(log.duration_ms) }}</td>
                                        <td class="py-2 whitespace-nowrap" :title="formatTime(log.started_at)">
                                            {{ timeAgo(log.started_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Error details for failed logs -->
                        <div
                            v-for="log in recentLogs.filter(l => l.status === 'failed' && l.error_message)"
                            :key="`error-${log.id}`"
                            class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
                        >
                            <span class="font-medium">Error ({{ formatTime(log.started_at) }}):</span>
                            {{ log.error_message }}
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </AppLayout>
</template>
