<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
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
import SetupLayout from '@/layouts/SetupLayout.vue';
import {
    device,
    storeCloud,
    skipCloud,
    testCloud,
    fetchBranches,
} from '@/actions/App/Http/Controllers/SetupController';
import { CheckCircle, XCircle, Cloud, Building2 } from 'lucide-vue-next';
import axios from 'axios';

interface Branch {
    id: number;
    name: string;
    code: string;
    department_count: number;
    employee_count: number;
}

interface CloudServer {
    id?: number;
    api_base_url: string;
    api_key: string;
    branch_id?: number | null;
    branch_name?: string | null;
}

interface DeviceConfig {
    id?: number;
    name: string;
    ip_address: string;
}

const props = defineProps<{
    cloudServer?: CloudServer | null;
    device?: DeviceConfig | null;
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
const branches = ref<Branch[]>([]);
const branchesLoaded = ref(false);

const selectedBranchId = ref<string>(
    props.cloudServer?.branch_id ? String(props.cloudServer.branch_id) : '',
);

const selectedBranch = computed(() =>
    branches.value.find((b) => b.id === Number(selectedBranchId.value)),
);

const canFetchBranches = computed(
    () => connectionResult.value?.success && form.api_base_url && form.api_key,
);

const onBranchChange = (value: string) => {
    selectedBranchId.value = value;
    const branch = branches.value.find((b) => b.id === Number(value));
    if (branch) {
        form.branch_id = branch.id;
        form.branch_name = branch.name;
    }
};

const runTest = async () => {
    testing.value = true;
    connectionResult.value = null;
    branches.value = [];
    branchesLoaded.value = false;
    selectedBranchId.value = '';
    form.branch_id = null;
    form.branch_name = '';

    try {
        const response = await axios.post(testCloud.url(), {
            api_base_url: form.api_base_url,
            api_key: form.api_key,
        });

        if (response.data.success) {
            connectionResult.value = {
                success: true,
                message: `Connected successfully. Server time: ${response.data.server_time}`,
            };
            // Automatically fetch branches after successful connection
            await loadBranches();
        } else {
            connectionResult.value = {
                success: false,
                message: response.data.error ?? 'Connection failed.',
            };
        }
    } catch (error: any) {
        const message =
            error.response?.data?.message ??
            'Connection failed. Please check your settings.';
        connectionResult.value = {
            success: false,
            message: typeof message === 'string' ? message : 'Connection failed.',
        };
    } finally {
        testing.value = false;
    }
};

const loadBranches = async () => {
    loadingBranches.value = true;

    try {
        const response = await axios.post(fetchBranches.url(), {
            api_base_url: form.api_base_url,
            api_key: form.api_key,
        });

        if (response.data.success) {
            branches.value = response.data.branches;
            branchesLoaded.value = true;
        }
    } catch {
        // Silently fail — user can retry
    } finally {
        loadingBranches.value = false;
    }
};

const submit = () => {
    form.post(storeCloud.url());
};

const skip = () => {
    form.post(skipCloud.url(), {
        data: {},
        preserveState: false,
    });
};
</script>

<template>
    <Head title="Setup — Cloud Configuration" />

    <SetupLayout
        :current-step="3"
        title="Cloud Sync (Optional)"
        description="Configure your cloud server to sync attendance data. You can skip this and set it up later."
    >
        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-4">
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
                <Button
                    type="button"
                    variant="outline"
                    :disabled="testing || !form.api_base_url || !form.api_key"
                    @click="runTest"
                >
                    <Spinner v-if="testing" class="mr-2" />
                    <Cloud v-else class="mr-2 h-4 w-4" />
                    {{ testing ? 'Testing...' : 'Test Connection' }}
                </Button>

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
                    Choose which branch this device belongs to. Employees and attendance will be synced for this branch.
                </p>

                <div v-if="loadingBranches" class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner class="h-4 w-4" />
                    Loading branches...
                </div>

                <template v-else-if="branches.length > 0">
                    <Select :model-value="selectedBranchId" @update:model-value="onBranchChange">
                        <SelectTrigger>
                            <SelectValue placeholder="Select a branch..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="branch in branches"
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
                    <AlertDescription>No branches found. Please create branches in the cloud system first.</AlertDescription>
                </Alert>

                <InputError :message="form.errors.branch_id" />
            </div>

            <div class="flex items-center justify-between">
                <Link :href="device.url()">
                    <Button type="button" variant="ghost">Back</Button>
                </Link>
                <div class="flex gap-2">
                    <Button type="button" variant="outline" @click="skip">
                        Skip for now
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" class="mr-2" />
                        Save &amp; Continue
                    </Button>
                </div>
            </div>
        </form>
    </SetupLayout>
</template>
