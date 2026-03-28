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
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { show } from '@/routes/devices';
import { test, poll, store, destroy, update } from '@/actions/App/Http/Controllers/DeviceController';
import { ref } from 'vue';
import axios from 'axios';
import { MoreVertical, Wifi, WifiOff, RefreshCw, Zap, Activity, Clock, Database, Trash2 } from 'lucide-vue-next';

interface Device {
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
    attendance_logs_count: number;
}

defineProps<{
    devices: Device[];
}>();

usePoll(10000, { only: ['devices'] });

const showAddForm = ref(false);
const form = useForm({
    name: '',
    ip_address: '',
    port: 4370,
    protocol: 'tcp',
    poll_method: 'realtime',
});

function submitAddDevice() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showAddForm.value = false;
        },
    });
}

function handleToggleActive(device: Device) {
    router.patch(update.url(device.id), { is_active: !device.is_active }, {
        preserveScroll: true,
    });
}

const deletingDevice = ref<number | null>(null);

function handleDelete(deviceId: number) {
    deletingDevice.value = deviceId;
    router.delete(destroy.url(deviceId), {
        preserveScroll: true,
        onFinish: () => {
            deletingDevice.value = null;
        },
    });
}

const testingDevice = ref<number | null>(null);
const pollingDevice = ref<number | null>(null);
const testResult = ref<Record<number, { success: boolean; message: string }>>({});

async function handleTest(deviceId: number) {
    testingDevice.value = deviceId;
    testResult.value[deviceId] = { success: false, message: '' };

    try {
        const { data } = await axios.post(test.url({ device: deviceId }), {}, { timeout: 15000 });
        testResult.value[deviceId] = {
            success: data.success,
            message: data.listening
                ? 'Listener active'
                : data.success
                    ? `OK — ${data.device_name ?? 'Device'} (${data.serial_number ?? 'N/A'})`
                    : `Failed: ${data.error}`,
        };
    } catch (error: any) {
        testResult.value[deviceId] = {
            success: false,
            message: error.code === 'ECONNABORTED'
                ? 'Connection timed out'
                : error.response?.data?.error ?? 'Network error',
        };
    } finally {
        testingDevice.value = null;
    }
}

async function handlePoll(deviceId: number) {
    pollingDevice.value = deviceId;
    try {
        const { data } = await axios.post(poll.url({ device: deviceId }), {}, { timeout: 30000 });
        if (data.success) {
            testResult.value[deviceId] = {
                success: true,
                message: data.listening
                    ? 'Real-time capture active'
                    : `${data.new} new, ${data.duplicates} dup, ${data.users_synced} users`,
            };
            if (!data.listening) {
                router.reload({ only: ['devices'] });
            }
        } else {
            testResult.value[deviceId] = { success: false, message: data.error };
        }
    } catch (error: any) {
        testResult.value[deviceId] = {
            success: false,
            message: error.code === 'ECONNABORTED'
                ? 'Sync timed out'
                : error.response?.data?.error ?? 'Network error',
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

function statusIcon(device: Device) {
    if (device.is_listening) return 'listening';
    if (device.is_connected) return 'connected';
    if (device.connection_failures > 0) return 'failed';
    return 'unknown';
}
</script>

<template>

    <Head title="Devices" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Devices</h1>
                    <p class="text-sm text-muted-foreground">Manage your attendance devices</p>
                </div>
                <Button size="sm" @click="showAddForm = !showAddForm">
                    {{ showAddForm ? 'Cancel' : 'Add Device' }}
                </Button>
            </div>

            <!-- Add Device Form -->
            <Card v-if="showAddForm">
                <CardHeader>
                    <CardTitle class="text-base">Add New Device</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submitAddDevice" class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="name">Device Name</Label>
                            <Input id="name" v-model="form.name" placeholder="e.g. K40 Front Door" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="space-y-2">
                            <Label for="ip_address">IP Address</Label>
                            <Input id="ip_address" v-model="form.ip_address" placeholder="192.168.1.100" />
                            <InputError :message="form.errors.ip_address" />
                        </div>
                        <div class="space-y-2">
                            <Label for="port">Port</Label>
                            <Input id="port" v-model.number="form.port" type="number" />
                            <InputError :message="form.errors.port" />
                        </div>
                        <div class="space-y-2">
                            <Label for="protocol">Protocol</Label>
                            <select id="protocol" v-model="form.protocol"
                                class="border-input bg-background ring-offset-background flex h-9 w-full rounded-md border px-3 py-1 text-sm">
                                <option value="tcp">TCP</option>
                                <option value="udp">UDP</option>
                            </select>
                            <InputError :message="form.errors.protocol" />
                        </div>
                        <div class="space-y-2">
                            <Label for="poll_method">Poll Method</Label>
                            <select id="poll_method" v-model="form.poll_method"
                                class="border-input bg-background ring-offset-background flex h-9 w-full rounded-md border px-3 py-1 text-sm">
                                <option value="realtime">Real-time (Listener)</option>
                                <option value="bulk">Bulk (Polling)</option>
                            </select>
                            <InputError :message="form.errors.poll_method" />
                        </div>
                        <div class="sm:col-span-2 flex justify-end">
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Adding...' : 'Add Device' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Empty State -->
            <div v-if="devices.length === 0 && !showAddForm"
                class="flex flex-col items-center justify-center rounded-lg border border-dashed py-16">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted mb-4">
                    <Wifi class="h-6 w-6 text-muted-foreground" />
                </div>
                <p class="text-sm font-medium">No devices configured</p>
                <p class="text-sm text-muted-foreground mt-1">Add a device to start capturing attendance</p>
                <Button size="sm" class="mt-4" @click="showAddForm = true">Add Device</Button>
            </div>

            <!-- Device Grid -->
            <div v-else-if="devices.length > 0" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="device in devices" :key="device.id" class="relative transition-shadow hover:shadow-md"
                    :class="{ 'opacity-50': !device.is_active }">
                    <CardHeader class="pb-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <Link :href="show.url({ device: device.id })" class="group flex items-center gap-2">
                                    <CardTitle class="truncate text-base group-hover:underline">
                                        {{ device.name }}
                                    </CardTitle>
                                </Link>
                                <p class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground font-mono">
                                    {{ device.ip_address }}:{{ device.port }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <StatusBadge
                                    :status="device.is_listening ? 'success' : device.is_connected ? 'success' : device.connection_failures > 0 ? 'error' : 'neutral'"
                                    :label="device.is_listening ? 'Listening' : device.is_connected ? 'Connected' : device.connection_failures > 0 ? 'Failed' : 'Offline'" />

                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-48">
                                        <DropdownMenuItem @click="handleToggleActive(device)">
                                            <component :is="device.is_active ? WifiOff : Wifi" class="mr-2 h-4 w-4" />
                                            {{ device.is_active ? 'Deactivate' : 'Activate' }}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <AlertDialog>
                                            <AlertDialogTrigger as-child>
                                                <DropdownMenuItem class="text-destructive focus:text-destructive"
                                                    @select.prevent>
                                                    <Trash2 class="mr-2 h-4 w-4" />
                                                    Delete Device
                                                </DropdownMenuItem>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>Delete {{ device.name }}?</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        This will permanently delete this device and all its local
                                                        attendance records.
                                                        This action cannot be undone.
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                    <AlertDialogAction :disabled="deletingDevice === device.id"
                                                        @click="handleDelete(device.id)">
                                                        {{ deletingDevice === device.id ? 'Deleting...' : 'Delete' }}
                                                    </AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent class="space-y-3 pt-0">
                        <!-- Tags row -->
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                {{ device.protocol.toUpperCase() }}
                            </span>
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium" :class="device.poll_method === 'realtime'
                                ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400'
                                : 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400'">
                                {{ device.poll_method === 'realtime' ? 'Real-time' : 'Bulk' }}
                            </span>
                            <span v-if="!device.is_active"
                                class="inline-flex items-center rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/20 dark:text-red-400">
                                Inactive
                            </span>
                        </div>

                        <!-- Stats -->
                        <div class="grid grid-cols-3 gap-3">
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <div class="flex flex-col items-center rounded-md bg-muted/50 p-2">
                                            <Database class="mb-1 h-3.5 w-3.5 text-muted-foreground" />
                                            <span class="text-sm font-semibold">{{
                                                device.attendance_logs_count.toLocaleString() }}</span>
                                            <span class="text-[10px] text-muted-foreground">Records</span>
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent>Total attendance records</TooltipContent>
                                </Tooltip>
                            </TooltipProvider>

                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <div class="flex flex-col items-center rounded-md bg-muted/50 p-2">
                                            <Clock class="mb-1 h-3.5 w-3.5 text-muted-foreground" />
                                            <span class="text-sm font-semibold">{{ timeAgo(device.last_poll_at)
                                                }}</span>
                                            <span class="text-[10px] text-muted-foreground">Last Sync</span>
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent>Last synchronization time</TooltipContent>
                                </Tooltip>
                            </TooltipProvider>

                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <div class="flex flex-col items-center rounded-md bg-muted/50 p-2"
                                            :class="device.connection_failures > 0 ? 'bg-red-50 dark:bg-red-900/10' : ''">
                                            <Activity class="mb-1 h-3.5 w-3.5"
                                                :class="device.connection_failures > 0 ? 'text-red-500' : 'text-muted-foreground'" />
                                            <span class="text-sm font-semibold"
                                                :class="device.connection_failures > 0 ? 'text-red-600 dark:text-red-400' : ''">{{
                                                device.connection_failures }}</span>
                                            <span class="text-[10px] text-muted-foreground">Failures</span>
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent>Connection failures since last success</TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>

                        <!-- Result message -->
                        <div v-if="testResult[device.id]?.message" class="rounded-md px-3 py-2 text-xs" :class="testResult[device.id].success
                            ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400'
                            : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400'">
                            {{ testResult[device.id].message }}
                        </div>

                        <!-- Primary Actions -->
                        <Separator />
                        <div class="flex gap-2">
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button size="sm" variant="outline" class="flex-1"
                                            :disabled="testingDevice === device.id" @click="handleTest(device.id)">
                                            <Wifi class="mr-1.5 h-3.5 w-3.5" />
                                            {{ testingDevice === device.id ? 'Testing...' : 'Test' }}
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Test device connection</TooltipContent>
                                </Tooltip>
                            </TooltipProvider>

                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button size="sm" class="flex-1" :disabled="pollingDevice === device.id"
                                            @click="handlePoll(device.id)">
                                            <RefreshCw class="mr-1.5 h-3.5 w-3.5"
                                                :class="{ 'animate-spin': pollingDevice === device.id }" />
                                            {{ pollingDevice === device.id ? 'Syncing...' : 'Sync' }}
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Sync attendance from device</TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
