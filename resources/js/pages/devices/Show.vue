<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { test, poll, syncTime, clearAttendance, clearLocalAttendance, clearDeviceUsers, update } from '@/actions/App/Http/Controllers/DeviceController';
import { index as devicesIndex } from '@/routes/devices';
import { ref } from 'vue';
import axios from 'axios';
import {
    Wifi, RefreshCw, Clock, Database, Activity, Settings, Trash2,
    AlertTriangle, UserX, MoreVertical, Pencil, WifiOff
} from 'lucide-vue-next';

interface DeviceDetail {
    id: number;
    name: string;
    ip_address: string;
    port: number;
    protocol: string;
    poll_method: string;
    is_active: boolean;
    is_connected: boolean;
    is_listening: boolean;
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

usePoll(10000, { only: ['device', 'recentLogs'] });

// Action states
const testing = ref(false);
const polling = ref(false);
const syncingTime = ref(false);
const resultMessage = ref('');
const resultSuccess = ref(false);

// Edit dialog
const showEditDialog = ref(false);
const editForm = useForm({
    name: props.device.name,
    ip_address: props.device.ip_address,
    port: props.device.port,
    protocol: props.device.protocol,
    poll_method: props.device.poll_method,
});

function openEditDialog() {
    editForm.name = props.device.name;
    editForm.ip_address = props.device.ip_address;
    editForm.port = props.device.port;
    editForm.protocol = props.device.protocol;
    editForm.poll_method = props.device.poll_method;
    showEditDialog.value = true;
}

function submitEdit() {
    editForm.patch(update.url(props.device.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditDialog.value = false;
        },
    });
}

// Danger action confirmations
const showDangerConfirm = ref<'device' | 'local' | 'users' | null>(null);
const clearingDevice = ref(false);
const clearingLocal = ref(false);
const clearingUsers = ref(false);

function handleToggleActive() {
    router.patch(update.url(props.device.id), { is_active: !props.device.is_active }, {
        preserveScroll: true,
    });
}

async function handleTest() {
    testing.value = true;
    resultMessage.value = '';

    try {
        const { data } = await axios.post(test.url({ device: props.device.id }), {}, { timeout: 15000 });
        resultSuccess.value = data.success;
        resultMessage.value = data.listening
            ? 'Device is connected — listener is active'
            : data.success
                ? `Connected — ${data.device_name ?? 'Device'} (SN: ${data.serial_number ?? 'N/A'}, FW: ${data.firmware ?? 'N/A'})`
                : `Failed: ${data.error}`;
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.code === 'ECONNABORTED'
            ? 'Connection timed out — device may be busy or unreachable'
            : error.response?.data?.error ?? 'Network error';
    } finally {
        testing.value = false;
    }
}

async function handlePoll() {
    polling.value = true;
    resultMessage.value = '';

    try {
        const { data } = await axios.post(poll.url({ device: props.device.id }), {}, { timeout: 30000 });
        resultSuccess.value = data.success;
        resultMessage.value = data.listening
            ? 'Attendance is being captured in real-time by the listener'
            : data.success
                ? `Synced: ${data.new} new, ${data.duplicates} duplicates, ${data.users_synced} users synced`
                : `Error: ${data.error}`;
        if (data.success && !data.listening) {
            router.reload({ only: ['recentLogs', 'device'] });
        }
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.code === 'ECONNABORTED'
            ? 'Sync timed out — device may be busy or unreachable'
            : error.response?.data?.error ?? 'Network error';
    } finally {
        polling.value = false;
    }
}

async function handleSyncTime() {
    syncingTime.value = true;
    resultMessage.value = '';

    try {
        const { data } = await axios.post(syncTime.url({ device: props.device.id }), {}, { timeout: 15000 });
        resultSuccess.value = data.success;
        resultMessage.value = data.success
            ? `Device time synced — now set to ${data.device_time}`
            : `Error: ${data.error}`;
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.code === 'ECONNABORTED'
            ? 'Timed out — device may be busy or unreachable'
            : error.response?.data?.error ?? 'Network error';
    } finally {
        syncingTime.value = false;
    }
}

async function handleClearDeviceAttendance() {
    clearingDevice.value = true;
    resultMessage.value = '';
    showDangerConfirm.value = null;

    try {
        const { data } = await axios.delete(clearAttendance.url({ device: props.device.id }));
        resultSuccess.value = data.success;
        resultMessage.value = data.success ? data.message : `Error: ${data.error}`;
        if (data.success) {
            router.reload({ only: ['recentLogs', 'device'] });
        }
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.response?.data?.error ?? 'Network error';
    } finally {
        clearingDevice.value = false;
    }
}

async function handleClearLocalAttendance() {
    clearingLocal.value = true;
    resultMessage.value = '';
    showDangerConfirm.value = null;

    try {
        const { data } = await axios.delete(clearLocalAttendance.url({ device: props.device.id }));
        resultSuccess.value = data.success;
        resultMessage.value = data.success ? data.message : `Error: ${data.error}`;
        if (data.success) {
            router.reload({ only: ['recentLogs', 'device'] });
        }
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.response?.data?.error ?? 'Network error';
    } finally {
        clearingLocal.value = false;
    }
}

async function handleClearDeviceUsers() {
    clearingUsers.value = true;
    resultMessage.value = '';
    showDangerConfirm.value = null;

    try {
        const { data } = await axios.delete(clearDeviceUsers.url({ device: props.device.id }));
        resultSuccess.value = data.success;
        resultMessage.value = data.success ? data.message : `Error: ${data.error}`;
    } catch (error: any) {
        resultSuccess.value = false;
        resultMessage.value = error.response?.data?.error ?? 'Network error';
    } finally {
        clearingUsers.value = false;
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

            <!-- Device Header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <Wifi class="h-6 w-6 text-muted-foreground" />
                    </div>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-xl font-semibold">{{ device.name }}</h1>
                            <StatusBadge
                                :status="device.is_listening ? 'success' : device.is_connected ? 'success' : device.connection_failures > 0 ? 'error' : 'neutral'"
                                :label="device.is_listening ? 'Listening' : device.is_connected ? 'Connected' : device.connection_failures > 0 ? 'Failed' : 'Offline'" />
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground font-mono">
                            {{ device.ip_address }}:{{ device.port }}
                            <span class="font-sans"> &middot; {{ device.protocol.toUpperCase() }} &middot; {{
                                device.poll_method === 'realtime' ? 'Real-time' : 'Bulk Polling' }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="openEditDialog">
                        <Pencil class="mr-1.5 h-3.5 w-3.5" />
                        Edit
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="icon" class="h-8 w-8">
                                <MoreVertical class="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-52">
                            <DropdownMenuItem @click="handleToggleActive">
                                <component :is="device.is_active ? WifiOff : Wifi" class="mr-2 h-4 w-4" />
                                {{ device.is_active ? 'Deactivate Device' : 'Activate Device' }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem class="text-destructive focus:text-destructive"
                                @click="showDangerConfirm = 'local'">
                                <Trash2 class="mr-2 h-4 w-4" />
                                Clear Local Records
                            </DropdownMenuItem>
                            <DropdownMenuItem class="text-destructive focus:text-destructive"
                                @click="showDangerConfirm = 'device'">
                                <Trash2 class="mr-2 h-4 w-4" />
                                Clear Device & Local
                            </DropdownMenuItem>
                            <DropdownMenuItem class="text-destructive focus:text-destructive"
                                @click="showDangerConfirm = 'users'">
                                <UserX class="mr-2 h-4 w-4" />
                                Clear Device Users
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Result message -->
            <div v-if="resultMessage" class="rounded-md px-4 py-3 text-sm" :class="resultSuccess
                ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400'
                : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400'">
                {{ resultMessage }}
            </div>

            <!-- Stats + Actions -->
            <Card>
                <CardContent class="p-4">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted">
                                <Clock class="h-4 w-4 text-muted-foreground" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">Last Connected</p>
                                <p class="truncate text-sm font-semibold">{{ timeAgo(device.last_connected_at) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted">
                                <RefreshCw class="h-4 w-4 text-muted-foreground" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">Last Synced</p>
                                <p class="truncate text-sm font-semibold">{{ timeAgo(device.last_poll_at) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md"
                                :class="device.connection_failures > 0 ? 'bg-red-50 dark:bg-red-900/10' : 'bg-muted'">
                                <Activity class="h-4 w-4"
                                    :class="device.connection_failures > 0 ? 'text-red-500' : 'text-muted-foreground'" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">Failures</p>
                                <p class="truncate text-sm font-semibold"
                                    :class="device.connection_failures > 0 ? 'text-red-600 dark:text-red-400' : ''">
                                    {{ device.connection_failures }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted">
                                <Database class="h-4 w-4 text-muted-foreground" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">Total Records</p>
                                <p class="truncate text-sm font-semibold">{{ device.total_logs.toLocaleString() }}</p>
                            </div>
                        </div>
                    </div>
                    <Separator class="my-4" />
                    <div class="flex flex-wrap items-center gap-2">
                        <Button size="sm" :disabled="polling" @click="handlePoll">
                            <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': polling }" />
                            {{ polling ? 'Syncing...' : 'Sync Device' }}
                        </Button>
                        <Button size="sm" variant="outline" :disabled="testing" @click="handleTest">
                            <Wifi class="mr-1.5 h-3.5 w-3.5" />
                            {{ testing ? 'Testing...' : 'Test Connection' }}
                        </Button>
                        <Button size="sm" variant="outline" :disabled="syncingTime" @click="handleSyncTime">
                            <Clock class="mr-1.5 h-3.5 w-3.5" />
                            {{ syncingTime ? 'Syncing...' : 'Sync Time' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Attendance Logs -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Recent Attendance (last 50)</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="recentLogs.length === 0" class="py-8 text-center">
                        <Database class="mx-auto h-8 w-8 text-muted-foreground/50" />
                        <p class="mt-2 text-sm text-muted-foreground">No attendance records for this device.</p>
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="pb-2 pr-4 font-medium">Employee</th>
                                    <th class="pb-2 pr-4 font-medium">Code</th>
                                    <th class="pb-2 pr-4 font-medium">Date</th>
                                    <th class="pb-2 pr-4 font-medium">Time</th>
                                    <th class="pb-2 font-medium">Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in recentLogs" :key="log.id"
                                    class="border-b last:border-0 hover:bg-muted/50">
                                    <td class="py-2.5 pr-4 font-medium">{{ log.employee_name }}</td>
                                    <td class="py-2.5 pr-4 text-muted-foreground font-mono text-xs">{{ log.employee_code
                                        ?? '—' }}</td>
                                    <td class="py-2.5 pr-4">{{ formatDate(log.timestamp) }}</td>
                                    <td class="py-2.5 pr-4 font-mono text-xs">{{ formatTime(log.timestamp) }}</td>
                                    <td class="py-2.5">
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

            <!-- Edit Device Dialog -->
            <Dialog v-model:open="showEditDialog">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit Device</DialogTitle>
                        <DialogDescription>Update the connection details for this device.</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="submitEdit" class="grid gap-4">
                        <div class="space-y-2">
                            <Label for="edit-name">Device Name</Label>
                            <Input id="edit-name" v-model="editForm.name" placeholder="e.g. K40 Front Door" />
                            <InputError :message="editForm.errors.name" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="edit-ip">IP Address</Label>
                                <Input id="edit-ip" v-model="editForm.ip_address" placeholder="192.168.1.100" />
                                <InputError :message="editForm.errors.ip_address" />
                            </div>
                            <div class="space-y-2">
                                <Label for="edit-port">Port</Label>
                                <Input id="edit-port" v-model.number="editForm.port" type="number" />
                                <InputError :message="editForm.errors.port" />
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="edit-protocol">Protocol</Label>
                                <select id="edit-protocol" v-model="editForm.protocol"
                                    class="border-input bg-background ring-offset-background flex h-9 w-full rounded-md border px-3 py-1 text-sm">
                                    <option value="tcp">TCP</option>
                                    <option value="udp">UDP</option>
                                </select>
                                <InputError :message="editForm.errors.protocol" />
                            </div>
                            <div class="space-y-2">
                                <Label for="edit-poll-method">Poll Method</Label>
                                <select id="edit-poll-method" v-model="editForm.poll_method"
                                    class="border-input bg-background ring-offset-background flex h-9 w-full rounded-md border px-3 py-1 text-sm">
                                    <option value="realtime">Real-time (Listener)</option>
                                    <option value="bulk">Bulk (Polling)</option>
                                </select>
                                <InputError :message="editForm.errors.poll_method" />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" @click="showEditDialog = false">Cancel</Button>
                            <Button type="submit" :disabled="editForm.processing">
                                {{ editForm.processing ? 'Saving...' : 'Save Changes' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Danger Action Confirmation Dialog -->
            <AlertDialog :open="!!showDangerConfirm"
                @update:open="(v: boolean) => { if (!v) showDangerConfirm = null }">
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle class="flex items-center gap-2">
                            <AlertTriangle class="h-5 w-5 text-destructive" />
                            {{ showDangerConfirm === 'device'
                                ? 'Clear Device & Local Attendance?'
                                : showDangerConfirm === 'users'
                                    ? 'Remove All Device Users?'
                                    : 'Clear Local Attendance?' }}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            <template v-if="showDangerConfirm === 'device'">
                                This will wipe all attendance logs from the physical device and remove all local
                                records. This cannot be undone.
                            </template>
                            <template v-else-if="showDangerConfirm === 'users'">
                                This removes all enrolled users and their fingerprints from the device. You will need to
                                re-push employees and re-enroll fingerprints.
                            </template>
                            <template v-else>
                                This only removes records from the local database. The device keeps its logs.
                            </template>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90"
                            :disabled="clearingDevice || clearingLocal || clearingUsers"
                            @click="showDangerConfirm === 'device' ? handleClearDeviceAttendance() : showDangerConfirm === 'users' ? handleClearDeviceUsers() : handleClearLocalAttendance()">
                            {{ (clearingDevice || clearingLocal || clearingUsers) ? 'Clearing...' : 'Yes, Clear' }}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    </AppLayout>
</template>
