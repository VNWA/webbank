<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Vue3EasyDataTable from 'vue3-easy-data-table';
import AppButton from '@/components/AppButton.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import transferHistory from '@/routes/transfer-history';

type TableHeader = { text: string; value: string; sortable?: boolean; width?: number };
type TableItem = Record<string, unknown>;
type TableServerOptions = { page: number; rowsPerPage: number };

type Row = {
    id: number;
    device_id: number;
    device_name?: string | null;
    device_image_id?: string | null;
    channel: string;
    channel_label: string;
    bank_name: string | null;
    account_number: string;
    recipient_name: string | null;
    amount: number;
    transfer_note: string | null;
    requester_name?: string | null;
    created_at: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Lịch sử chuyển tiền', href: transferHistory.index() },
        ],
    },
});

const headers: TableHeader[] = [
    { text: 'Thời gian', value: 'created_at', width: 160 },
    { text: 'Kênh', value: 'channel_label', width: 110 },
    { text: 'Thiết bị', value: 'device_name', sortable: true },
    { text: 'Ngân hàng', value: 'bank_name' },
    { text: 'STK', value: 'account_number' },
    { text: 'Người nhận', value: 'recipient_name' },
    { text: 'Số tiền', value: 'amount' },
    { text: 'Nội dung', value: 'transfer_note' },
    { text: 'Thực hiện', value: 'requester_name' },
];

const tableItems = ref<TableItem[]>([]);
const loading = ref(false);
const serverItemsLength = ref(0);
const serverOptions = ref<TableServerOptions>({ page: 1, rowsPerPage: 15 });
const searchInput = ref('');
const searchDebounced = ref('');
const channelFilter = ref<'all' | 'pg' | 'baca'>('all');

watchDebounced(
    searchInput,
    (val) => {
        searchDebounced.value = val.trim();
        serverOptions.value = { ...serverOptions.value, page: 1 };
    },
    { debounce: 350 },
);

watch(channelFilter, () => {
    serverOptions.value = { ...serverOptions.value, page: 1 };
});

watch(
    [() => serverOptions.value.page, () => serverOptions.value.rowsPerPage, searchDebounced, channelFilter],
    () => {
        void loadRows();
    },
    { immediate: true },
);

function formatCurrency(n: number): string {
    return Number(n).toLocaleString('vi-VN') + ' VND';
}

function formatDt(raw: string | null | undefined): string {
    if (!raw) return '—';
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('vi-VN');
}

async function loadRows(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/transfer-histories', {
            params: {
                page: serverOptions.value.page,
                per_page: serverOptions.value.rowsPerPage,
                search: searchDebounced.value || undefined,
                channel: channelFilter.value === 'all' ? undefined : channelFilter.value,
            },
        });
        tableItems.value = data.data as TableItem[];
        serverItemsLength.value = Number(data.meta?.total ?? 0);
    } catch {
        toast.error('Không tải được lịch sử chuyển tiền.');
    } finally {
        loading.value = false;
    }
}

const channelOptions = computed(() => [
    { value: 'all' as const, label: 'Tất cả kênh' },
    { value: 'pg' as const, label: 'PG Bank' },
    { value: 'baca' as const, label: 'Bắc Á Bank' },
]);
</script>

<template>
    <Head title="Lịch sử chuyển tiền" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-lg font-semibold">Lịch sử chuyển tiền thành công</h1>
            <p class="text-sm text-muted-foreground">
                Chỉ ghi nhận các lần chuyển khoản PG / Bắc Á khi lệnh hoàn tất thành công.
            </p>
        </div>

        <Card>
            <CardHeader class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <CardTitle>Bộ lọc</CardTitle>
                    <CardDescription>Tìm theo tên thiết bị, image id, ngân hàng, STK, người nhận, người thực hiện.</CardDescription>
                </div>
                <AppButton :as="Link" variant="outline" :href="dashboard()">Về Dashboard</AppButton>
            </CardHeader>
            <CardContent class="flex flex-col gap-4 sm:flex-row sm:flex-wrap">
                <div class="max-w-sm flex-1 space-y-2">
                    <Label for="th-search">Tìm kiếm</Label>
                    <Input id="th-search" v-model="searchInput" placeholder="Tìm…" autocomplete="off" />
                </div>
                <div class="w-full max-w-xs space-y-2">
                    <Label for="th-channel">Kênh</Label>
                    <select
                        id="th-channel"
                        v-model="channelFilter"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    >
                        <option v-for="opt in channelOptions" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
            </CardContent>
        </Card>

        <div
            class="overflow-x-auto rounded-lg border border-sidebar-border/70 bg-card p-2 dark:border-sidebar-border"
        >
            <Vue3EasyDataTable
                v-model:server-options="serverOptions"
                :headers="headers"
                :items="tableItems"
                :loading="loading"
                :server-items-length="serverItemsLength"
                :rows-items="[15, 25, 50]"
                :rows-per-page="15"
                buttons-pagination
                border-cell
                theme-color="#6366f1"
            >
                <template #item-created_at="item">
                    {{ formatDt((item as Row).created_at) }}
                </template>
                <template #item-device_name="item">
                    <div class="text-xs">
                        <div class="font-medium">{{ (item as Row).device_name ?? '—' }}</div>
                        <div class="text-muted-foreground">{{ (item as Row).device_image_id ?? '' }}</div>
                    </div>
                </template>
                <template #item-bank_name="item">
                    {{ (item as Row).bank_name ?? '—' }}
                </template>
                <template #item-recipient_name="item">
                    {{ (item as Row).recipient_name ?? '—' }}
                </template>
                <template #item-amount="item">
                    <span class="font-medium tabular-nums">{{ formatCurrency((item as Row).amount) }}</span>
                </template>
                <template #item-transfer_note="item">
                    <span class="line-clamp-2 max-w-[14rem] text-xs">{{ (item as Row).transfer_note ?? '—' }}</span>
                </template>
                <template #item-requester_name="item">
                    {{ (item as Row).requester_name ?? '—' }}
                </template>
            </Vue3EasyDataTable>
        </div>
    </div>
</template>
