<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import SetupLayout from '@/layouts/SetupLayout.vue';
import {
    device,
    storeCloud,
    skipCloud,
} from '@/actions/App/Http/Controllers/SetupController';

interface CloudServer {
    id?: number;
    api_base_url: string;
    api_key: string;
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
});

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
