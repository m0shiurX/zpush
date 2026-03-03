<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { CheckCircle, XCircle, Wifi } from 'lucide-vue-next';
import axios from 'axios';

const props = defineProps<{
    url: string;
    payload: () => Record<string, unknown>;
    label?: string;
}>();

const testing = ref(false);
const result = ref<{ success: boolean; message: string; data?: Record<string, unknown> } | null>(null);

const runTest = async () => {
    testing.value = true;
    result.value = null;

    try {
        const response = await axios.post(props.url, props.payload());
        result.value = {
            success: response.data.success ?? true,
            message: response.data.message ?? 'Connection successful',
            data: response.data,
        };
    } catch (error: any) {
        const message = error.response?.data?.message
            ?? error.response?.data?.errors
                ? Object.values(error.response.data.errors).flat().join(', ')
                : 'Connection failed. Please check your settings.';

        result.value = {
            success: false,
            message: typeof message === 'string' ? message : 'Connection failed. Please check your settings.',
        };
    } finally {
        testing.value = false;
    }
};

defineExpose({ result, testing });
</script>

<template>
    <div class="flex flex-col gap-3">
        <Button
            type="button"
            variant="outline"
            :disabled="testing"
            @click="runTest"
        >
            <Spinner v-if="testing" class="mr-2" />
            <Wifi v-else class="mr-2 h-4 w-4" />
            {{ testing ? 'Testing...' : (label ?? 'Test Connection') }}
        </Button>

        <Alert v-if="result && result.success" class="border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            <CheckCircle class="h-4 w-4 text-green-600 dark:text-green-400" />
            <AlertTitle>Connected</AlertTitle>
            <AlertDescription>
                {{ result.message }}
                <div v-if="result.data?.serial_number" class="mt-2 text-xs">
                    <span class="font-medium">Serial:</span> {{ result.data.serial_number }}
                    <template v-if="result.data.device_name"> &middot; <span class="font-medium">Name:</span> {{ result.data.device_name }}</template>
                    <template v-if="result.data.user_count !== undefined"> &middot; <span class="font-medium">Users:</span> {{ result.data.user_count }}</template>
                </div>
            </AlertDescription>
        </Alert>

        <Alert v-if="result && !result.success" variant="destructive">
            <XCircle class="h-4 w-4" />
            <AlertTitle>Connection Failed</AlertTitle>
            <AlertDescription>{{ result.message }}</AlertDescription>
        </Alert>
    </div>
</template>
