<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import {
    wizard,
    storeDevice,
    testDevice,
} from '@/actions/App/Http/Controllers/SetupController';
import ConnectionTester from '@/components/ConnectionTester.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import SetupLayout from '@/layouts/SetupLayout.vue';

interface DeviceConfig {
    id?: number;
    name: string;
    ip_address: string;
    port: number;
    protocol: string;
}

const props = defineProps<{
    device?: DeviceConfig | null;
}>();

const form = useForm({
    name: props.device?.name ?? 'K40',
    ip_address: props.device?.ip_address ?? '',
    port: props.device?.port ?? 4370,
    protocol: props.device?.protocol ?? 'tcp',
});

const submit = () => {
    form.post(storeDevice.url());
};
</script>

<template>

    <Head title="Setup — Connect Device" />

    <SetupLayout :current-step="2" title="Connect Your Device"
        description="Enter the network details for your ZKTeco attendance device.">
        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="name">Device Name</Label>
                    <Input id="name" v-model="form.name" placeholder="e.g. Office K40" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="ip_address">IP Address</Label>
                        <Input id="ip_address" v-model="form.ip_address" placeholder="192.168.1.100" />
                        <InputError :message="form.errors.ip_address" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="port">Port</Label>
                        <Input id="port" type="number" v-model.number="form.port" placeholder="4370" />
                        <InputError :message="form.errors.port" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="protocol">Protocol</Label>
                    <div class="flex gap-4">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="radio" v-model="form.protocol" value="tcp"
                                class="text-primary focus:ring-primary" />
                            <span class="text-sm">TCP</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="radio" v-model="form.protocol" value="udp"
                                class="text-primary focus:ring-primary" />
                            <span class="text-sm">UDP</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.protocol" />
                </div>
            </div>

            <!-- Test connection -->
            <ConnectionTester :url="testDevice.url()"
                :payload="() => ({ ip_address: form.ip_address, port: form.port, protocol: form.protocol })" />

            <div class="flex items-center justify-between">
                <Link :href="wizard.url()">
                    <Button type="button" variant="ghost">Back</Button>
                </Link>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" class="mr-2" />
                    Save &amp; Continue
                </Button>
            </div>
        </form>
    </SetupLayout>
</template>
