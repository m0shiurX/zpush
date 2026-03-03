<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ref, watch } from 'vue';

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface EmployeeEntry {
    id: number;
    device_uid: number;
    name: string;
    employee_code: string | null;
    department: string | null;
    is_active: boolean;
    is_cloud_synced: boolean;
    attendance_logs_count: number;
    created_at: string;
}

interface Filters {
    search?: string;
    status?: string;
}

const props = defineProps<{
    employees: PaginatedData<EmployeeEntry>;
    filters: Filters;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

let searchTimeout: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
});

function applyFilters() {
    const params: Record<string, string> = {};
    if (search.value) params.search = search.value;
    if (status.value) params.status = status.value;

    router.get(window.location.pathname, params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    search.value = '';
    status.value = '';
    router.get(window.location.pathname, {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function goToPage(url: string | null) {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Employees" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Employees</h1>
                <span class="text-sm text-muted-foreground">{{ employees.total }} total</span>
            </div>

            <!-- Filters -->
            <Card>
                <CardContent class="pt-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[200px]">
                            <label class="text-sm text-muted-foreground mb-1 block">Search</label>
                            <Input v-model="search" placeholder="Name, code, or department..." />
                        </div>
                        <div class="min-w-[120px]">
                            <label class="text-sm text-muted-foreground mb-1 block">Status</label>
                            <select
                                v-model="status"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                @change="applyFilters"
                            >
                                <option value="">All</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <Button variant="outline" size="sm" @click="clearFilters">
                            Clear
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Table -->
            <Card>
                <CardContent class="pt-4">
                    <div v-if="employees.data.length === 0" class="text-sm text-muted-foreground py-8 text-center">
                        No employees found.
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="pb-2 pr-4">UID</th>
                                    <th class="pb-2 pr-4">Name</th>
                                    <th class="pb-2 pr-4">Code</th>
                                    <th class="pb-2 pr-4">Department</th>
                                    <th class="pb-2 pr-4">Records</th>
                                    <th class="pb-2 pr-4">Status</th>
                                    <th class="pb-2">Cloud</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="emp in employees.data"
                                    :key="emp.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-2.5 pr-4 text-muted-foreground">{{ emp.device_uid }}</td>
                                    <td class="py-2.5 pr-4 font-medium">{{ emp.name }}</td>
                                    <td class="py-2.5 pr-4 text-muted-foreground">{{ emp.employee_code ?? '—' }}</td>
                                    <td class="py-2.5 pr-4 text-muted-foreground">{{ emp.department ?? '—' }}</td>
                                    <td class="py-2.5 pr-4">{{ emp.attendance_logs_count }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="emp.is_active
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                                : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'"
                                        >
                                            {{ emp.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="py-2.5">
                                        <span
                                            class="inline-flex h-2 w-2 rounded-full"
                                            :class="emp.is_cloud_synced ? 'bg-green-500' : 'bg-yellow-500'"
                                            :title="emp.is_cloud_synced ? 'Synced' : 'Pending'"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="employees.last_page > 1" class="flex items-center justify-between pt-4 border-t mt-4">
                        <span class="text-sm text-muted-foreground">
                            Page {{ employees.current_page }} of {{ employees.last_page }}
                        </span>
                        <div class="flex gap-1">
                            <Button
                                v-for="link in employees.links"
                                :key="link.label"
                                size="sm"
                                :variant="link.active ? 'default' : 'outline'"
                                :disabled="!link.url"
                                @click="goToPage(link.url)"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
