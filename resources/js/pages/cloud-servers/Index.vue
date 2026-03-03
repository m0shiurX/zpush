<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    store,
    test,
    branches,
    syncAttendance,
    syncEmployees,
    syncToDevice,
    destroy,
} from '@/actions/App/Http/Controllers/CloudServerController';
import { CheckCircle, XCircle, Cloud, Building2, Trash2, Upload, Download, Monitor } from 'lucide-vue-next';
import axios from 'axios';

interface Branch {
    id: number;
    name: string;
    code: string;
    department_count: number;
    employee_count: number;
}

interface CloudServerData {
    id: number;
    name: string | null;
    api_base_url: string;
    api_key: string;
    branch_id: number | null;
    branch_name: string | null;
    is_active: boolean;
    is_connected: boolean;
    last_successful_sync: string | null;
    last_failed_sync: string | null;
    sync_failure_count: number;
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
}

const props = defineProps<{
    cloudServer: CloudServerData | null;
    recentSyncLogs: SyncLogEntry[];
}>();

const form = useForm({
    api_base_url: props.cloudServer?.api_base_url ?? '',
    api_key: props.cloudServer?.api_key ?? '',
    branch_id: props.cloudServer?.branch_id ?? null as number | null,
    branch_name: props.cloudServer?.branch_name ?? '' as string,
});

// Connection test state
const testing = ref(false);
const connectionResult = ref<{ success: boolean; message: string } | null>(null);

// Branch fetching state
const loadingBranches = ref(false);
const branchesList = ref<Branch[]>([]);
const branchesLoaded = ref(false);

const selectedBranchId = ref<string>(
    props.cloudServer?.branch_id ? String(props.cloudServer.branch_id) : '',
);

const selectedBranch = computed(() =>
    branchesList.value.find((b) => b.id === Number(selectedBranchId.value)),
);

const canFetchBranches = computed(
    () => connectionResult.value?.success && form.api_base_url && form.api_key,
);

// Sync action state
const syncingAction = ref<string | null>(null);
const syncResult = ref<{ success: boolean; message: string } | null>(null);

// Delete state
const deleting = ref(false);

const onBranchChange = (value: string) => {
    selectedBranchId.value = value;
    const branch = branchesList.value.find((b) => b.id === Number(value));
    if (branch) {
        form.branch_id = branch.id;
        form.branch_name = branch.name;
    }
};

const runTest = async () => {
    if (!props.cloudServer) return;

    testing.value = true;
    connectionResult.value = null;
    branchesList.value = [];
    branchesLoaded.value = false;

    try {
        const { data } = await axios.post(test.url(props.cloudServer.id));
        if (data.success) {
            connectionResult.value = {
                success: true,
                message: `Connected successfully. Server time: ${data.server_time}`,
            };
            await loadBranches();
        } else {
            connectionResult.value = {
                success: false,
                message: data.error ?? 'Connection failed.',
            };
        }
    } catch (error: any) {
        const message = error.response?.data?.message ?? 'Connection failed. Please check your settings.';
        connectionResult.value = {
            success: false,
            message: typeof message === 'string' ? message : 'Connection failed.',
        };
    } finally {
        testing.value = false;
    }
};

const loadBranches = async () => {
    if (!props.cloudServer) return;

    loadingBranches.value = true;
    try {
        const { data } = await axios.post(branches.url(props.cloudServer.id));
        if (data.success) {
            branchesList.value = data.branches;
            branchesLoaded.value = true;
        }
    } catch {
        // Silently fail — user can retry
    } finally {
        loadingBranches.value = false;
    }
};

const submit = () => {
    form.post(store.url(), {
        preserveScroll: true,
    });
};

const handleSync = async (action: 'attendance' | 'employees' | 'device') => {
    if (!props.cloudServer) return;

    syncingAction.value = action;
    syncResult.value = null;

    try {
        const urlMap = {
            attendance: syncAttendance.url(props.cloudServer.id),
            employees: syncEmployees.url(props.cloudServer.id),
            device: syncToDevice.url(props.cloudServer.id),
        };

        const { data } = await axios.post(urlMap[action]);
        syncResult.value = {
            success: data.success,
            message: data.message,
        };
    } catch (error: any) {
        syncResult.value = {
            success: false,
            message: error.response?.data?.message ?? 'Sync request failed.',
        };
    } finally {
        syncingAction.value = null;
    }
};

const handleDelete = () => {
    if (!props.cloudServer) return;

    deleting.value = true;
    router.delete(destroy.url(props.cloudServer.id), {
        onFinish: () => {
            deleting.value = false;
        },
    });
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
    <Head title="Cloud Servers" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Cloud Server</h1>
                <StatusBadge
                    v-if="cloudServer"
                    :status="cloudServer.is_connected ? 'success' : cloudServer.sync_failure_count > 0 ? 'error' : 'neutral'"
                    :label="cloudServer.is_connected ? 'Connected' : cloudServer.sync_failure_count > 0 ? 'Failed' : 'Not tested'"
                />
            </div>

            <!-- Configuration Form -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Server Configuration</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="flex flex-col gap-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="api_base_url">API Base URL</Label>
                                <Input
                                    id="api_base_url"
                                    v-model="form.api_base_url"
                                    type="url"
                                    placeholder="https://api.example.com"
                                />
                                <InputError :message="form.errors.api_base_url" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="api_key">API Key</Label>
                                <Input
                                    id="api_key"
                                    v-model="form.api_key"
                                    type="password"
                                    placeholder="Your API key"
                                />
                                <InputError :message="form.errors.api_key" />
                            </div>
                        </div>

                        <!-- Test Connection -->
                        <div class="flex flex-col gap-3">
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="testing || !form.api_base_url || !form.api_key || !cloudServer"
                                    @click="runTest"
                                >
                                    <Spinner v-if="testing" class="mr-2" />
                                    <Cloud v-else class="mr-2 h-4 w-4" />
                                    {{ testing ? 'Testing...' : 'Test Connection' }}
                                </Button>
                            </div>

                            <Alert
                                v-if="connectionResult?.success"
                                class="border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200"
                            >
                                <CheckCircle class="h-4 w-4 text-green-600 dark:text-green-400" />
                                <AlertTitle>Connected</AlertTitle>
                                <AlertDescription>{{ connectionResult.message }}</AlertDescription>
                            </Alert>

                            <Alert v-if="connectionResult && !connectionResult.success" variant="destructive">
                                <XCircle class="h-4 w-4" />
                                <AlertTitle>Connection Failed</AlertTitle>
                                <AlertDescription>{{ connectionResult.message }}</AlertDescription>
                            </Alert>
                        </div>

                        <!-- Branch Selection -->
                        <div v-if="branchesLoaded" class="grid gap-3">
                            <div class="flex items-center gap-2">
                                <Building2 class="h-4 w-4 text-muted-foreground" />
                                <Label>Select Branch</Label>
                            </div>

                            <p class="text-muted-foreground text-sm">
                                Choose which branch this zpush instance belongs to.
                            </p>

                            <div v-if="loadingBranches" class="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner class="h-4 w-4" />
                                Loading branches...
                            </div>

                            <template v-else-if="branchesList.length > 0">
                                <Select :model-value="selectedBranchId" @update:model-value="onBranchChange">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a branch..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="branch in branchesList"
                                            :key="branch.id"
                                            :value="String(branch.id)"
                                        >
                                            {{ branch.name }} ({{ branch.code }})
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <div
                                    v-if="selectedBranch"
                                    class="rounded-md border bg-muted/50 p-3 text-sm"
                                >
                                    <div class="font-medium">{{ selectedBranch.name }}</div>
                                    <div class="text-muted-foreground mt-1">
                                        {{ selectedBranch.department_count }} departments &middot;
                                        {{ selectedBranch.employee_count }} employees
                                    </div>
                                </div>
                            </template>

                            <Alert v-else>
                                <AlertDescription>No branches found. Create branches in the cloud system first.</AlertDescription>
                            </Alert>

                            <InputError :message="form.errors.branch_id" />
                        </div>

                        <!-- Current branch info -->
                        <div
                            v-if="cloudServer?.branch_name && !branchesLoaded"
                            class="rounded-md border bg-muted/50 p-3 text-sm"
                        >
                            <div class="flex items-center gap-2">
                                <Building2 class="h-4 w-4 text-muted-foreground" />
                                <span class="font-medium">{{ cloudServer.branch_name }}</span>
                            </div>
                            <div class="text-muted-foreground mt-1">
                                Branch ID: {{ cloudServer.branch_id }}
                            </div>
                        </div>

                        <!-- Submit / Delete -->
                        <div class="flex items-center justify-between">
                            <Button
                                v-if="cloudServer"
                                type="button"
                                variant="destructive"
                                size="sm"
                                :disabled="deleting"
                                @click="handleDelete"
                            >
                                <Trash2 class="mr-1 h-4 w-4" />
                                {{ deleting ? 'Removing...' : 'Remove Server' }}
                            </Button>
                            <div v-else />

                            <Button type="submit" :disabled="form.processing">
                                <Spinner v-if="form.processing" class="mr-2" />
                                {{ cloudServer ? 'Update Configuration' : 'Save Configuration' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Sync Actions -->
            <Card v-if="cloudServer">
                <CardHeader>
                    <CardTitle class="text-base">Sync Actions</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <Button
                            variant="outline"
                            :disabled="syncingAction !== null"
                            @click="handleSync('attendance')"
                        >
                            <Spinner v-if="syncingAction === 'attendance'" class="mr-2" />
                            <Upload v-else class="mr-2 h-4 w-4" />
                            {{ syncingAction === 'attendance' ? 'Syncing...' : 'Sync Attendance' }}
                        </Button>

                        <Button
                            variant="outline"
                            :disabled="syncingAction !== null"
                            @click="handleSync('employees')"
                        >
                            <Spinner v-if="syncingAction === 'employees'" class="mr-2" />
                            <Download v-else class="mr-2 h-4 w-4" />
                            {{ syncingAction === 'employees' ? 'Syncing...' : 'Sync Employees' }}
                        </Button>

                        <Button
                            variant="outline"
                            :disabled="syncingAction !== null"
                            @click="handleSync('device')"
                        >
                            <Spinner v-if="syncingAction === 'device'" class="mr-2" />
                            <Monitor v-else class="mr-2 h-4 w-4" />
                            {{ syncingAction === 'device' ? 'Syncing...' : 'Push to Device' }}
                        </Button>
                    </div>

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

                    <!-- Last sync info -->
                    <div class="grid grid-cols-2 gap-4 text-sm text-muted-foreground">
                        <div>
                            <span>Last successful sync:</span>
                            <span class="ml-1 font-medium text-foreground">
                                {{ timeAgo(cloudServer.last_successful_sync) }}
                            </span>
                        </div>
                        <div>
                            <span>Last failed sync:</span>
                            <span class="ml-1 font-medium text-foreground">
                                {{ timeAgo(cloudServer.last_failed_sync) }}
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent Sync Logs -->
            <Card v-if="recentSyncLogs.length > 0">
                <CardHeader>
                    <CardTitle class="text-base">Recent Sync Logs</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="pb-2 pr-4 font-medium">Direction</th>
                                    <th class="pb-2 pr-4 font-medium">Type</th>
                                    <th class="pb-2 pr-4 font-medium">Records</th>
                                    <th class="pb-2 pr-4 font-medium">Status</th>
                                    <th class="pb-2 pr-4 font-medium">Duration</th>
                                    <th class="pb-2 font-medium">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="log in recentSyncLogs"
                                    :key="log.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-2 pr-4">{{ formatDirection(log.direction) }}</td>
                                    <td class="py-2 pr-4 capitalize">{{ log.entity_type }}</td>
                                    <td class="py-2 pr-4">{{ log.records_affected }}</td>
                                    <td class="py-2 pr-4">
                                        <StatusBadge
                                            :status="statusVariant(log.status)"
                                            :label="log.status"
                                        />
                                    </td>
                                    <td class="py-2 pr-4">{{ formatDuration(log.duration_ms) }}</td>
                                    <td class="py-2">{{ timeAgo(log.started_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Empty state -->
            <div v-if="!cloudServer" class="flex flex-col items-center justify-center py-12">
                <Cloud class="h-12 w-12 text-muted-foreground/50 mb-4" />
                <p class="text-muted-foreground text-center">
                    No cloud server configured. Enter your API settings above to get started.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
