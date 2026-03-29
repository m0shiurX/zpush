<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import { Form } from '@inertiajs/vue3';
import { Fingerprint, Cloud, Zap } from 'lucide-vue-next';
import { device } from '@/actions/App/Http/Controllers/SetupController';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import SetupLayout from '@/layouts/SetupLayout.vue';
import { login } from '@/routes';
import { register } from '@/routes';
import { store as loginStore } from '@/routes/login';
import { request } from '@/routes/password';
import { store as registerStore } from '@/routes/register';

defineProps<{
    status?: string;
    hasUsers: boolean;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <Head title="Setup — Welcome" />

    <SetupLayout
        :current-step="1"
        :title="hasUsers ? 'Log in to ZPush' : 'Register to ZPush'"
        :description="hasUsers
            ? 'Enter your credentials to access your attendance system.'
            : 'Let\'s get your attendance system up and running in a few simple steps.'"
    >
        <!-- ✅ No users: show feature highlights + Get Started -->
        <template v-if="!hasUsers">
            <div class="flex flex-col gap-6">
             

                <!-- Register Form -->
                <Form
                    v-bind="registerStore.form()"
                    :reset-on-success="['password', 'password_confirmation']"
                    :on-success="()=>router.visit(device.url())"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-6 px-10"
                >
                    <div class="grid gap-6">
                        <div class="grid gap-2">
                            <Label for="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                required
                                autofocus
                                :tabindex="1"
                                autocomplete="name"
                                name="name"
                                placeholder="Full name"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                :tabindex="2"
                                autocomplete="email"
                                name="email"
                                placeholder="email@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                required
                                :tabindex="3"
                                autocomplete="new-password"
                                name="password"
                                placeholder="Password"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password_confirmation">Confirm password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                required
                                :tabindex="4"
                                autocomplete="new-password"
                                name="password_confirmation"
                                placeholder="Confirm password"
                            />
                            <InputError :message="errors.password_confirmation" />
                        </div>

                        <Button
                            type="submit"
                            class="mt-2 w-full"
                            tabindex="5"
                            :disabled="processing"
                            data-test="register-user-button"
                        >
                            <Spinner v-if="processing" />
                            Create account
                        </Button>
                    </div>
                </Form>
            </div>
        </template>

        <!-- ✅ Users exist: show login form -->
        <template v-else>
            <div
                v-if="status"
                class="mb-4 text-center text-sm font-medium text-green-600"
            >
                {{ status }}
            </div>

            <Form
                v-bind="loginStore.form()"
                :reset-on-success="['password']"
                :on-success="()=>router.visit(device.url())"
                
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autofocus
                            :tabindex="1"
                            autocomplete="email"
                            placeholder="email@example.com"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="password">Password</Label>
                            <TextLink
                                v-if="canResetPassword"
                                :href="request()"
                                class="text-sm"
                                :tabindex="5"
                            >
                                Forgot password?
                            </TextLink>
                        </div>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            required
                            :tabindex="2"
                            autocomplete="current-password"
                            placeholder="Password"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="flex items-center justify-between">
                        <Label for="remember" class="flex items-center space-x-3">
                            <Checkbox id="remember" name="remember" :tabindex="3" />
                            <span>Remember me</span>
                        </Label>
                    </div>

                    <Button
                        type="submit"
                        class="mt-4 w-full"
                        :tabindex="4"
                        :disabled="processing"
                        data-test="login-button"
                    >
                        <Spinner v-if="processing" />
                        Log in
                    </Button>
                </div>

                <div class="text-muted-foreground text-center text-sm" v-if="canRegister">
                    Don't have an account?
                    <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
                </div>
            </Form>
        </template>
    </SetupLayout>
</template>