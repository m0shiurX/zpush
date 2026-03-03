<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { update } from '@/actions/App/Http/Controllers/AppSettingsController';
import { CheckCircle, Settings, Clock, Globe, Database } from 'lucide-vue-next';

interface TimezoneOption {
    value: string;
    label: string;
}

const props = defineProps<{
    settings: {
        sync_interval: number;
        timezone: string;
        log_retention_days: number;
        auto_sync_enabled: boolean;
        poll_interval: number;
    };
    timezones: TimezoneOption[];
}>();

const page = usePage();
const flash = page.props.flash as { success?: string } | undefined;

const form = useForm({
    sync_interval: props.settings.sync_interval,
    timezone: props.settings.timezone,
    log_retention_days: props.settings.log_retention_days,
    auto_sync_enabled: props.settings.auto_sync_enabled,
    poll_interval: props.settings.poll_interval,
});

const handleSubmit = () => {
    form.put(update.url());
};
</script>

<template>
    <Head title="Application Settings" />

    <AppLayout>
        <div class="mx-auto max-w-3xl px-3 py-6 sm:px-6">
            <div class="mb-6">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                        <Settings class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 sm:text-2xl">
                            Application Settings
                        </h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400 sm:text-sm">
                            Configure sync intervals, timezone, and data retention
                        </p>
                    </div>
                </div>
            </div>

            <Alert
                v-if="flash?.success"
                class="mb-6 border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200"
            >
                <CheckCircle class="h-4 w-4 text-green-600 dark:text-green-400" />
                <AlertDescription>{{ flash.success }}</AlertDescription>
            </Alert>

            <form @submit.prevent="handleSubmit" class="space-y-6">
                <!-- Device Polling -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-2">
                            <Clock class="h-4 w-4 text-muted-foreground" />
                            <CardTitle class="text-base">Device Polling</CardTitle>
                        </div>
                        <CardDescription>
                            How often zpush fetches attendance data from connected devices.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-2">
                            <Label for="poll_interval">Poll Interval (minutes)</Label>
                            <Input
                                id="poll_interval"
                                v-model.number="form.poll_interval"
                                type="number"
                                min="1"
                                max="1440"
                                class="max-w-[200px]"
                            />
                            <p class="text-xs text-muted-foreground">
                                Recommended: 5 minutes for real-time tracking, 15-30 for low-traffic branches.
                            </p>
                            <p v-if="form.errors.poll_interval" class="text-sm text-red-600">
                                {{ form.errors.poll_interval }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Cloud Sync -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-2">
                            <Globe class="h-4 w-4 text-muted-foreground" />
                            <CardTitle class="text-base">Cloud Sync</CardTitle>
                        </div>
                        <CardDescription>
                            How attendance data is synchronized with the cloud server.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-center gap-3">
                            <Checkbox
                                id="auto_sync_enabled"
                                :model-value="form.auto_sync_enabled"
                                @update:model-value="form.auto_sync_enabled = $event as boolean"
                            />
                            <div>
                                <Label for="auto_sync_enabled" class="cursor-pointer">
                                    Enable automatic cloud sync
                                </Label>
                                <p class="text-xs text-muted-foreground">
                                    When enabled, attendance data is automatically pushed to the cloud on schedule.
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="sync_interval">Sync Interval (minutes)</Label>
                            <Input
                                id="sync_interval"
                                v-model.number="form.sync_interval"
                                type="number"
                                min="1"
                                max="1440"
                                class="max-w-[200px]"
                            />
                            <p class="text-xs text-muted-foreground">
                                How often to push attendance to cloud and pull employee updates.
                            </p>
                            <p v-if="form.errors.sync_interval" class="text-sm text-red-600">
                                {{ form.errors.sync_interval }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Timezone -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-2">
                            <Clock class="h-4 w-4 text-muted-foreground" />
                            <CardTitle class="text-base">Timezone</CardTitle>
                        </div>
                        <CardDescription>
                            The timezone used for displaying and processing attendance timestamps.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-2 max-w-[300px]">
                            <Label for="timezone">Timezone</Label>
                            <Select v-model="form.timezone">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select timezone" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="tz in timezones"
                                        :key="tz.value"
                                        :value="tz.value"
                                    >
                                        {{ tz.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.timezone" class="text-sm text-red-600">
                                {{ form.errors.timezone }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Log Retention -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-2">
                            <Database class="h-4 w-4 text-muted-foreground" />
                            <CardTitle class="text-base">Data Retention</CardTitle>
                        </div>
                        <CardDescription>
                            How long to keep sync logs and old attendance data locally.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-2">
                            <Label for="log_retention_days">Log Retention (days)</Label>
                            <Input
                                id="log_retention_days"
                                v-model.number="form.log_retention_days"
                                type="number"
                                min="1"
                                max="365"
                                class="max-w-[200px]"
                            />
                            <p class="text-xs text-muted-foreground">
                                Sync logs older than this will be automatically cleaned up. Attendance data is retained
                                regardless.
                            </p>
                            <p v-if="form.errors.log_retention_days" class="text-sm text-red-600">
                                {{ form.errors.log_retention_days }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Submit -->
                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving...' : 'Save Settings' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
