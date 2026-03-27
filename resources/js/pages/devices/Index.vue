<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { show } from '@/routes/devices';
import { test, poll, store, destroy, update } from '@/actions/App/Http/Controllers/DeviceController';
import { ref } from 'vue';
import axios from 'axios';

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

// Auto-refresh device data every 10 seconds to pick up listener-captured punches
usePoll(10000, { only: ['devices'] });

// Add device form
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

// Toggle active / inactive
const togglingDevice = ref<number | null>(null);

function handleToggleActive(device: Device) {
    togglingDevice.value = device.id;
    router.patch(update.url(device.id), { is_active: !device.is_active }, {
        preserveScroll: true,
        onFinish: () => {
            togglingDevice.value = null;
        },
    });
}

// Delete
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
                ? 'Device is connected — listener is active'
                : data.success
                    ? `Connected — ${data.device_name ?? 'Device'} (${data.serial_number ?? 'N/A'})`
                    : `Failed: ${data.error}`,
        };
    } catch (error: any) {
        testResult.value[deviceId] = {
            success: false,
            message: error.code === 'ECONNABORTED'
                ? 'Connection timed out — device may be busy or unreachable'
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
                    ? 'Attendance is being captured in real-time by the listener'
                    : `Synced: ${data.new} new, ${data.duplicates} duplicates, ${data.users_synced} users synced`,
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
                ? 'Sync timed out — device may be busy or unreachable'
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
</script>

<template>

    <Head title="Devices" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Devices</h1>
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

            <div v-if="devices.length === 0" class="flex flex-col items-center justify-center py-16">
                <p class="text-muted-foreground">No devices configured yet.</p>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="device in devices" :key="device.id" :class="{ 'opacity-60': !device.is_active }">
                    <CardHeader class="flex flex-row items-start justify-between space-y-0 pb-2">
                        <div>
                            <CardTitle class="text-base">
                                <Link :href="show.url({ device: device.id })" class="hover:underline">
                                    {{ device.name }}
                                </Link>
                                <span v-if="!device.is_active" class="ml-2 text-xs text-muted-foreground">(Inactive)</span>
                            </CardTitle>
                            <p class="text-sm text-muted-foreground mt-1">
                                {{ device.ip_address }}:{{ device.port }} ({{ device.protocol.toUpperCase() }}) &middot;
                                {{ device.poll_method === 'realtime' ? 'Real-time' : 'Bulk' }}
                            </p>
                        </div>
                        <StatusBadge
                            :status="device.is_listening ? 'success' : device.is_connected ? 'success' : device.connection_failures > 0 ? 'error' : 'neutral'"
                            :label="device.is_listening ? 'Listening' : device.is_connected ? 'Connected' : device.connection_failures > 0 ? 'Failed' : 'Unknown'" />
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span class="text-muted-foreground">Last Sync</span>
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
                            <div class="flex flex-wrap gap-2">
                                <Button size="sm" variant="outline" :disabled="testingDevice === device.id"
                                    @click="handleTest(device.id)">
                                    {{ testingDevice === device.id ? 'Testing...' : 'Test' }}
                                </Button>
                                <Button size="sm" :disabled="pollingDevice === device.id"
                                    @click="handlePoll(device.id)">
                                    {{ pollingDevice === device.id ? 'Syncing...' : 'Sync Now' }}
                                </Button>
                                <Button size="sm" variant="secondary"
                                    :disabled="togglingDevice === device.id"
                                    @click="handleToggleActive(device)">
                                    {{ device.is_active ? 'Deactivate' : 'Activate' }}
                                </Button>
                                <AlertDialog>
                                    <AlertDialogTrigger as-child>
                                        <Button size="sm" variant="destructive">Delete</Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Delete {{ device.name }}?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                This will permanently delete this device and all its local attendance records.
                                                This action cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                :disabled="deletingDevice === device.id"
                                                @click="handleDelete(device.id)"
                                            >
                                                {{ deletingDevice === device.id ? 'Deleting...' : 'Delete' }}
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
