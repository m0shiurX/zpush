<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import SetupLayout from '@/layouts/SetupLayout.vue';
import { device } from '@/actions/App/Http/Controllers/SetupController';
import { Fingerprint, Cloud, Zap } from 'lucide-vue-next';
</script>

<template>
    <Head title="Setup — Welcome" />

    <SetupLayout
        :current-step="1"
        title="Welcome to ZPush"
        description="Let's get your attendance system up and running in a few simple steps."
    >
        <div class="flex flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="flex flex-col items-center gap-2 rounded-lg border p-4 text-center">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                        <Fingerprint class="h-5 w-5 text-primary" />
                    </div>
                    <h3 class="text-sm font-medium">Connect Device</h3>
                    <p class="text-xs text-muted-foreground">
                        Link your ZKTeco K40 biometric device
                    </p>
                </div>
                <div class="flex flex-col items-center gap-2 rounded-lg border p-4 text-center">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                        <Cloud class="h-5 w-5 text-primary" />
                    </div>
                    <h3 class="text-sm font-medium">Cloud Sync</h3>
                    <p class="text-xs text-muted-foreground">
                        Optionally sync attendance to the cloud
                    </p>
                </div>
                <div class="flex flex-col items-center gap-2 rounded-lg border p-4 text-center">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                        <Zap class="h-5 w-5 text-primary" />
                    </div>
                    <h3 class="text-sm font-medium">Go Live</h3>
                    <p class="text-xs text-muted-foreground">
                        Start polling attendance automatically
                    </p>
                </div>
            </div>

            <div class="flex justify-end">
                <Link :href="device.url()">
                    <Button size="lg">
                        Get Started
                    </Button>
                </Link>
            </div>
        </div>
    </SetupLayout>
</template>
