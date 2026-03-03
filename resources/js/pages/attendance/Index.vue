<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

interface AttendanceEntry {
    id: number;
    employee_name: string;
    employee_code: string | null;
    device_name: string;
    timestamp: string;
    punch_type: number;
    punch_label: string;
    punch_color: string;
    cloud_synced: boolean;
}

interface Filters {
    search?: string;
    date_from?: string;
    date_to?: string;
    punch_type?: string;
    device_id?: string;
}

const props = defineProps<{
    logs: PaginatedData<AttendanceEntry>;
    filters: Filters;
}>();

const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

let searchTimeout: ReturnType<typeof setTimeout>;

watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
});

function applyFilters() {
    const params: Record<string, string> = {};
    if (search.value) params.search = search.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;

    router.get(window.location.pathname, params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    search.value = '';
    dateFrom.value = '';
    dateTo.value = '';
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

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Attendance" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Attendance Logs</h1>
                <span class="text-sm text-muted-foreground">{{ logs.total }} total records</span>
            </div>

            <!-- Filters -->
            <Card>
                <CardContent class="pt-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[200px]">
                            <label class="text-sm text-muted-foreground mb-1 block">Search</label>
                            <Input v-model="search" placeholder="Employee name or code..." />
                        </div>
                        <div class="min-w-[150px]">
                            <label class="text-sm text-muted-foreground mb-1 block">From</label>
                            <Input v-model="dateFrom" type="date" @change="applyFilters" />
                        </div>
                        <div class="min-w-[150px]">
                            <label class="text-sm text-muted-foreground mb-1 block">To</label>
                            <Input v-model="dateTo" type="date" @change="applyFilters" />
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
                    <div v-if="logs.data.length === 0" class="text-sm text-muted-foreground py-8 text-center">
                        No attendance records found.
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="pb-2 pr-4">Employee</th>
                                    <th class="pb-2 pr-4">Code</th>
                                    <th class="pb-2 pr-4">Device</th>
                                    <th class="pb-2 pr-4">Date</th>
                                    <th class="pb-2 pr-4">Time</th>
                                    <th class="pb-2 pr-4">Type</th>
                                    <th class="pb-2">Synced</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="log in logs.data"
                                    :key="log.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="py-2.5 pr-4 font-medium">{{ log.employee_name }}</td>
                                    <td class="py-2.5 pr-4 text-muted-foreground">{{ log.employee_code ?? '—' }}</td>
                                    <td class="py-2.5 pr-4 text-muted-foreground">{{ log.device_name }}</td>
                                    <td class="py-2.5 pr-4">{{ formatDate(log.timestamp) }}</td>
                                    <td class="py-2.5 pr-4">{{ formatTime(log.timestamp) }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="log.punch_color === 'success'
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                                : 'bg-gray-100 text-gray-800 dark:bg-gray-800/50 dark:text-gray-400'"
                                        >
                                            {{ log.punch_label }}
                                        </span>
                                    </td>
                                    <td class="py-2.5">
                                        <span
                                            class="inline-flex h-2 w-2 rounded-full"
                                            :class="log.cloud_synced ? 'bg-green-500' : 'bg-yellow-500'"
                                            :title="log.cloud_synced ? 'Synced' : 'Pending'"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="logs.last_page > 1" class="flex items-center justify-between pt-4 border-t mt-4">
                        <span class="text-sm text-muted-foreground">
                            Page {{ logs.current_page }} of {{ logs.last_page }}
                        </span>
                        <div class="flex gap-1">
                            <Button
                                v-for="link in logs.links"
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
