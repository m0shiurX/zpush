<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { computed } from 'vue';

const props = defineProps<{
    currentStep: number;
    title: string;
    description?: string;
}>();

const totalSteps = 4;

const steps = [
    { number: 1, label: 'Welcome' },
    { number: 2, label: 'Device' },
    { number: 3, label: 'Cloud' },
    { number: 4, label: 'Complete' },
];

const progressPercent = computed(() => {
    return ((props.currentStep - 1) / (totalSteps - 1)) * 100;
});
</script>

<template>
    <div class="bg-muted flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <div class="flex w-full max-w-2xl flex-col gap-6">
            <!-- Logo -->
            <div class="flex items-center justify-center">
                <div class="flex h-10 w-10 items-center justify-center">
                    <AppLogoIcon class="size-10 fill-current text-primary" />
                </div>
            </div>

            <!-- Step indicator -->
            <nav class="flex items-center justify-center gap-2">
                <template v-for="step in steps" :key="step.number">
                    <div class="flex items-center gap-2">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                            :class="{
                                'bg-primary text-primary-foreground': step.number <= currentStep,
                                'bg-border text-muted-foreground': step.number > currentStep,
                            }"
                        >
                            {{ step.number }}
                        </div>
                        <span
                            class="hidden text-sm font-medium sm:inline"
                            :class="{
                                'text-foreground': step.number <= currentStep,
                                'text-muted-foreground': step.number > currentStep,
                            }"
                        >
                            {{ step.label }}
                        </span>
                    </div>
                    <div
                        v-if="step.number < totalSteps"
                        class="mx-1 h-px w-8 sm:w-12"
                        :class="{
                            'bg-primary': step.number < currentStep,
                            'bg-border': step.number >= currentStep,
                        }"
                    />
                </template>
            </nav>

            <!-- Content card -->
            <div class="rounded-xl border bg-card text-card-foreground shadow-sm">
                <div class="flex flex-col gap-1.5 px-8 pt-8 pb-0 text-center">
                    <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
                    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>
                <div class="px-8 py-8">
                    <slot />
                </div>
            </div>

            <!-- Footer -->
            <p class="text-center text-xs text-muted-foreground">
                Step {{ currentStep }} of {{ totalSteps }}
            </p>
        </div>
    </div>
</template>
