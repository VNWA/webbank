<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import axios from 'axios';
import { RefreshCw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Vue3EasyDataTable from 'vue3-easy-data-table';
import AppButton from '@/components/AppButton.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import echo from '@/echo';
import { dashboard } from '@/routes';
import managedDevices from '@/routes/api/managed-devices';
import deviceManagement from '@/routes/device-management';

type TableHeader = { text: string; value: string; sortable?: boolean; width?: number };
type TableItem = Record<string, unknown>;
type TableServerOptions = { page: number; rowsPerPage: number };
type OperationStatus = 'queued' | 'running' | 'success' | 'failed';
type Device = {
    id: number;
    user_id: number;
    user_name: string;
    status: string;
    duo_api_key: string;
    image_id: string;
    device_status: string | null;
    name: string;
    pg_pass: string;
    pg_pin: string;
    baca_pass: string;
    baca_pin: string;
    pg_video_id: string;
    baca_video_id: string;
    pg_balance: string | null;
    baca_balance: string | null;
    pg_balance_updated_at: string | null;
    baca_balance_updated_at: string | null;
    note: string | null;
};
type DeviceOperationLog = {
    id: number;
    level: string;
    stage: string;
    message: string;
    created_at: string | null;
};
type DeviceBalances = {
    pg_balance: string | null;
    baca_balance: string | null;
    pg_balance_updated_at: string | null;
    baca_balance_updated_at: string | null;
};
type DeviceOperation = {
    id: number;
    device_id: number;
    operation_type: 'pg_check_login' | 'baca_check_login' | 'pg_balance' | 'baca_balance';
    status: OperationStatus;
    result_message: string | null;
    requested_by_name: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    logs: DeviceOperationLog[];
    device_balances?: DeviceBalances | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Quản lý thiết bị', href: deviceManagement.index() },
        ],
    },
});

const headers: TableHeader[] = [
    { text: '', value: 'device_status', width: 10 },
    { text: 'Image ID', value: 'image_id', sortable: true },
    { text: 'Tên thiết bị', value: 'name', sortable: true },
    { text: 'PG Số dư', value: 'pg_balance' },
    { text: 'Bắc Á Số dư', value: 'baca_balance' },
    { text: 'Ghi chú', value: 'note' },
    { text: '', value: 'actions' },
];

function formatCurrency(raw: string | null | undefined): string {
    if (!raw) return '—';
    const numeric = Number(String(raw).replace(/,/g, ''));
    if (!Number.isFinite(numeric)) return String(raw);
    return numeric.toLocaleString('vi-VN') + ' VND';
}

function formatUpdatedAt(raw: string | null | undefined): string {
    if (!raw) return '—';
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('vi-VN');
}

/** Nhãn hiển thị trạng thái DWIN (tránh hiện raw `unknown`). `null` = chờ batch sau khi tải danh sách. */
function deviceStatusDisplayLabel(raw: string | null | undefined): string {
    const s = String(raw ?? '').trim();
    if (s === '' || s === 'null') {
        return 'Đang tải trạng thái…';
    }
    const labels: Record<string, string> = {
        unknown: 'Chưa xác định (DWIN)',
        not_configured: 'Chưa cấu hình',
        powering_on: 'Đang bật máy…',
        configuring: 'Đang cấu hình…',
        config_failed: 'Cấu hình thất bại',
        expired: 'Hết hạn',
        renewal_needed: 'Cần gia hạn',
    };
    return labels[s] ?? s;
}

const tableItems = ref<TableItem[]>([]);
const selectedItems = ref<TableItem[]>([]);
const loading = ref(false);
const serverItemsLength = ref(0);
const serverOptions = ref<TableServerOptions>({ page: 1, rowsPerPage: 10 });
const searchInput = ref('');
const searchDebounced = ref('');
const operationsByDevice = ref<Record<number, DeviceOperation[]>>({});
const loadingOperations = ref(false);

const noteDialogOpen = ref(false);
const noteDraft = ref('');
const noteDeviceId = ref<number | null>(null);
const savingNote = ref(false);

/** Chỉ dùng khi máy đang powering_on / configuring — làm mới nhẹ trạng thái DuoPlus. */
let deviceStatusPollTimer: ReturnType<typeof setInterval> | null = null;

const selectedIds = computed<number[]>(() =>
    selectedItems.value.map((item) => Number(item.id)).filter((id) => Number.isFinite(id)),
);

watchDebounced(
    searchInput,
    (val) => {
        searchDebounced.value = val.trim();
        serverOptions.value = { ...serverOptions.value, page: 1 };
    },
    { debounce: 350 },
);

watch(
    [() => serverOptions.value.page, () => serverOptions.value.rowsPerPage, searchDebounced],
    () => {
        void loadDevices();
    },
    { immediate: true },
);

function errorMessage(err: unknown): string {
    if (err instanceof Error && err.message) {
        return err.message;
    }

    return 'Đã xảy ra lỗi.';
}

function extractReceiptUrl(msg: string | null): string | null {
    if (!msg) return null;
    const m = msg.match(/https?:\/\/\S+\.(?:jpg|jpeg|png|webp)/i);
    return m ? m[0] : null;
}

function extractResultText(msg: string | null): string {
    if (!msg) return '';
    return msg.replace(/\s*Ảnh:\s*https?:\/\/\S+/i, '').trim();
}

function operationLabel(type: string): string {
    if (type === 'pg_check_login') {
        return 'PG Check Login';
    }

    if (type === 'baca_check_login') {
        return 'Bắc Á Check Login';
    }

    if (type === 'pg_balance') {
        return 'PG Số dư';
    }

    if (type === 'baca_balance') {
        return 'Bắc Á Số dư';
    }

    return type;
}

function operationStatusClass(status: OperationStatus): string {
    if (status === 'success') {
        return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
    }

    if (status === 'failed') {
        return 'bg-destructive/15 text-destructive';
    }

    if (status === 'running') {
        return 'bg-sky-500/15 text-sky-700 dark:text-sky-300';
    }

    return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
}

function operationStatusText(status: OperationStatus): string {
    if (status === 'queued') {
        return 'Đợi chạy';
    }

    if (status === 'running') {
        return 'Đang chạy';
    }

    if (status === 'success') {
        return 'Thành công';
    }

    return 'Thất bại';
}

function rowOperations(deviceId: number): DeviceOperation[] {
    return operationsByDevice.value[deviceId] ?? [];
}

function isBusy(deviceId: number): boolean {
    return rowOperations(deviceId).some((operation) => operation.status === 'queued' || operation.status === 'running');
}

function upsertOperation(nextOperation: DeviceOperation): void {
    const current = operationsByDevice.value[nextOperation.device_id] ?? [];
    const withoutCurrent = current.filter((operation) => operation.id !== nextOperation.id);
    const merged = [nextOperation, ...withoutCurrent]
        .sort((a, b) => b.id - a.id)
        .slice(0, 5);

    operationsByDevice.value = {
        ...operationsByDevice.value,
        [nextOperation.device_id]: merged,
    };

    if (nextOperation.device_balances) {
        applyDeviceBalances(nextOperation.device_id, nextOperation.device_balances);
    }
}

function applyDeviceBalances(deviceId: number, balances: DeviceBalances): void {
    const idx = tableItems.value.findIndex((item) => Number(item.id) === deviceId);
    if (idx < 0) return;
    const row = { ...tableItems.value[idx] } as Record<string, unknown>;
    if (balances.pg_balance !== undefined) row.pg_balance = balances.pg_balance;
    if (balances.baca_balance !== undefined) row.baca_balance = balances.baca_balance;
    if (balances.pg_balance_updated_at !== undefined) row.pg_balance_updated_at = balances.pg_balance_updated_at;
    if (balances.baca_balance_updated_at !== undefined) row.baca_balance_updated_at = balances.baca_balance_updated_at;
    tableItems.value.splice(idx, 1, row);
}

/**
 * Sau khi có danh sách (index không gọi DWIN từng dòng): một request batch theo `duo_api_key`.
 */
async function hydrateDeviceStatusesFromServer(): Promise<void> {
    const ids = tableItems.value.map((x) => Number(x.id)).filter((id) => Number.isFinite(id));
    if (ids.length === 0) {
        return;
    }

    try {
        const { data } = await axios.post<{ statuses: Record<string, string> }>('/api/managed-devices/status-batch', {
            ids,
        });
        const statuses = data.statuses ?? {};
        tableItems.value = tableItems.value.map((row) => {
            const id = String(row.id);
            const st = statuses[id];
            if (st === undefined) {
                return row;
            }
            return { ...row, device_status: st };
        });
    } catch {
        /* Giữ null — cột hiển thị "Đang tải…"; user bấm Tải lại */
    }
}

async function loadDevices(options?: { silent?: boolean }): Promise<void> {
    const silent = options?.silent === true;
    if (!silent) {
        loading.value = true;
    }

    try {
        const { data } = await axios.get(managedDevices.index.url(), {
            params: {
                page: serverOptions.value.page,
                per_page: serverOptions.value.rowsPerPage,
                search: searchDebounced.value,
            },
        });
        tableItems.value = data.data as TableItem[];
        serverItemsLength.value = Number(data.meta.total ?? 0);
        await hydrateDeviceStatusesFromServer();
        maybeStartDeviceStatusPoll();
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
}

function notePreview(raw: string | null | undefined): string {
    const s = typeof raw === 'string' ? raw.trim() : '';
    if (s === '') {
        return '—';
    }
    return s.length > 200 ? `${s.slice(0, 200)}…` : s;
}

function deviceNoteTitle(item: Device): string {
    const n = item.note;
    if (typeof n === 'string' && n.trim() !== '') {
        return n;
    }

    return 'Bấm dòng để thêm ghi chú';
}

function onDeviceRowClick(item: Record<string, unknown>): void {
    const d = item as Device;
    noteDeviceId.value = Number(d.id);
    noteDraft.value = typeof d.note === 'string' ? d.note : '';
    noteDialogOpen.value = true;
}

function onNoteDialogOpenChange(open: boolean): void {
    noteDialogOpen.value = open;
    if (!open) {
        noteDeviceId.value = null;
        noteDraft.value = '';
    }
}

function closeNoteDialog(): void {
    onNoteDialogOpenChange(false);
}

async function saveDeviceNote(): Promise<void> {
    if (noteDeviceId.value === null) {
        return;
    }

    savingNote.value = true;

    try {
        const { data } = await axios.patch<{ data: Record<string, unknown> }>(
            `/api/managed-devices/${noteDeviceId.value}/note`,
            { note: noteDraft.value },
        );

        const payload = data.data;
        if (payload && typeof payload === 'object') {
            mergeDeviceRow(payload);
        }

        toast.success('Đã lưu ghi chú.');
        closeNoteDialog();
    } catch {
        toast.error('Không lưu được ghi chú.');
    } finally {
        savingNote.value = false;
    }
}

function mergeDeviceRow(row: Record<string, unknown>): void {
    const id = Number(row.id);
    if (!Number.isFinite(id)) {
        return;
    }

    const idx = tableItems.value.findIndex((item) => Number(item.id) === id);
    if (idx < 0) {
        void loadDevices({ silent: true });
        return;
    }

    const merged = { ...tableItems.value[idx], ...row };
    tableItems.value.splice(idx, 1, merged);
}

function clearDeviceStatusPoll(): void {
    if (deviceStatusPollTimer !== null) {
        clearInterval(deviceStatusPollTimer);
        deviceStatusPollTimer = null;
    }
}

function tableHasTransitionalPowerStatus(): boolean {
    return tableItems.value.some((item) => {
        const s = String(item.device_status ?? '');
        return s === 'powering_on' || s === 'configuring';
    });
}

function maybeStartDeviceStatusPoll(): void {
    if (!tableHasTransitionalPowerStatus()) {
        clearDeviceStatusPoll();
        return;
    }

    if (deviceStatusPollTimer !== null) {
        return;
    }

    let attempts = 0;
    const maxAttempts = 24;

    deviceStatusPollTimer = setInterval(() => {
        attempts += 1;
        void loadDevices({ silent: true }).finally(() => {
            if (!tableHasTransitionalPowerStatus() || attempts >= maxAttempts) {
                clearDeviceStatusPoll();
            }
        });
    }, 2500);
}

async function loadOperationFeed(silent = false, options?: { force?: boolean }): Promise<void> {
    const force = options?.force === true;
    if (!force && loadingOperations.value) {
        return;
    }

    loadingOperations.value = true;

    try {
        const { data } = await axios.get(managedDevices.operations.feed.url());
        const payload = (data.operations ?? {}) as Record<string, DeviceOperation[]>;
        const next: Record<number, DeviceOperation[]> = {};

        for (const [deviceId, operations] of Object.entries(payload)) {
            const id = Number(deviceId);
            if (!Number.isFinite(id)) {
                continue;
            }

            next[id] = Array.isArray(operations) ? operations : [];
        }

        operationsByDevice.value = next;
    } catch (error) {
        if (!silent) {
            toast.error(errorMessage(error));
        }
    } finally {
        loadingOperations.value = false;
    }
}

async function refreshDevicesList(): Promise<void> {
    await loadDevices();
    await loadOperationFeed(true, { force: true });
    toast.success('Đã tải lại danh sách thiết bị.');
}

async function deleteOne(row: Device): Promise<void> {
    if (isBusy(row.id)) {
        toast.warning('Thiết bị đang chạy lệnh, vui lòng chờ hoàn tất.');

        return;
    }

    if (!window.confirm(`Xóa device ${row.name}?`)) {
        return;
    }

    try {
        await axios.delete(managedDevices.destroy.url({ device: row.id }));
        toast.success('Đã xóa device.');
        await loadDevices();
    } catch {
        toast.error('Đã xảy ra lỗi khi xóa device.');
    }
}

async function setPower(device: Device, action: 'on' | 'off'): Promise<void> {
    if (isBusy(device.id)) {
        toast.warning('Thiết bị đang chạy lệnh, vui lòng chờ hoàn tất.');

        return;
    }

    try {
        const { data } = await axios.post(managedDevices.power.url({ device: device.id }), { action });
        toast.success(typeof data.message === 'string' ? data.message : 'Đã cập nhật nguồn.');
        const payload = data.data as Record<string, unknown> | undefined;
        if (payload && typeof payload === 'object' && payload.id !== undefined) {
            mergeDeviceRow(payload);
            maybeStartDeviceStatusPoll();
        } else {
            await loadDevices({ silent: true });
        }
    } catch (error) {
        toast.error(errorMessage(error));
    }
}

async function runCheckLogin(device: Device, operationType: 'pg_check_login' | 'baca_check_login'): Promise<void> {
    if (isBusy(device.id)) {
        toast.warning('Thiết bị đang chạy lệnh khác, không thể chạy thêm.');

        return;
    }

    try {
        const { data } = await axios.post(managedDevices.operations.store.url({ device: device.id }), {
            operation_type: operationType,
        });
        toast.success(typeof data.message === 'string' ? data.message : 'Đã gửi lệnh.');
        await loadOperationFeed(true);
    } catch (error) {
        toast.error(errorMessage(error));
    }
}

async function runOperation(
    device: Device,
    operationType: 'pg_check_login' | 'baca_check_login' | 'pg_balance' | 'baca_balance' | 'baca_test_pin',
): Promise<void> {
    if (operationType === 'pg_check_login' || operationType === 'baca_check_login') {
        await runCheckLogin(device, operationType);
        return;
    }

    if (isBusy(device.id)) {
        toast.warning('Thiết bị đang chạy lệnh khác, không thể chạy thêm.');
        return;
    }

    try {
        const { data } = await axios.post(managedDevices.operations.store.url({ device: device.id }), {
            operation_type: operationType,
        });
        toast.success(typeof data.message === 'string' ? data.message : 'Đã gửi lệnh.');
        await loadOperationFeed(true);
    } catch (error) {
        toast.error(errorMessage(error));
    }
}

async function deleteSelected(): Promise<void> {
    if (selectedIds.value.length === 0) {
        return;
    }

    for (const id of selectedIds.value) {
        if (isBusy(id)) {
            toast.warning('Danh sách chọn có thiết bị đang chạy lệnh, không thể xóa.');

            return;
        }
    }

    if (!window.confirm(`Xóa ${selectedIds.value.length} device đã chọn?`)) {
        return;
    }

    try {
        await axios.delete(managedDevices.bulkDestroy.url(), { data: { ids: selectedIds.value } });
        selectedItems.value = [];
        toast.success('Đã xóa các device đã chọn.');
        await loadDevices();
    } catch {
        toast.error('Đã xảy ra lỗi khi xóa các device.');
    }
}

onMounted(() => {
    void loadOperationFeed(true);

    if (echo !== null) {
        echo.private('device-operations').listen(
            '.device-operation.updated',
            (event: { operation?: DeviceOperation }) => {
                if (event.operation) {
                    upsertOperation(event.operation);
                }
            },
        );

        echo.private('devices').listen(
            '.device.updated',
            (event: { device?: Record<string, unknown> }) => {
                if (event.device) {
                    mergeDeviceRow(event.device);
                    maybeStartDeviceStatusPoll();
                }
            },
        );
    }
});

onBeforeUnmount(() => {
    clearDeviceStatusPoll();
    if (echo !== null) {
        echo.leave('private-device-operations');
        echo.leave('private-devices');
    }
});
</script>

<template>
    <div>

        <Head title="Quản lý thiết bị" />
        <div class="flex flex-col gap-4 p-4">
            <Card>
                <CardHeader class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <CardTitle>Thiết bị</CardTitle>
                        <CardDescription>Quản lý danh sách thiết bị, thêm/sửa và xóa nhiều dòng.</CardDescription>
                    </div>
                    <div class="flex gap-2">
                        <AppButton type="button" color="error" variant="solid" :disabled="selectedIds.length === 0"
                            @click="deleteSelected">
                            Xóa đã chọn
                        </AppButton>
                        <AppButton :as="Link" :href="deviceManagement.create()" color="primary" variant="solid">
                            Thêm device
                        </AppButton>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:gap-4">
                        <div class="max-w-sm flex-1 space-y-2">
                            <Label for="search">Tìm kiếm</Label>
                            <Input id="search" v-model="searchInput" placeholder="Tên / dwin key / image id..."
                                autocomplete="off" />
                        </div>
                        <AppButton type="button" variant="outline" size="sm" :disabled="loading || loadingOperations"
                            class="shrink-0 self-start sm:self-auto" @click="refreshDevicesList">
                            <RefreshCw class="mr-1 size-4" :class="{ 'animate-spin': loading || loadingOperations }" />
                            Tải lại
                        </AppButton>
                    </div>
                    <div
                        class="overflow-x-auto rounded-lg border border-sidebar-border/70 bg-card p-2 dark:border-sidebar-border">
                        <Vue3EasyDataTable v-model:server-options="serverOptions" v-model:items-selected="selectedItems"
                            :headers="headers" :items="tableItems" :loading="loading"
                            :server-items-length="serverItemsLength" :rows-items="[10, 25, 50]" :rows-per-page="10"
                            buttons-pagination border-cell theme-color="#6366f1" @click-row="onDeviceRowClick">
                            <template #item-device_status="item">
                                <div class="flex items-center gap-2" :title="String(item.device_status ?? '')">
                                    <div v-if="item.device_status === 'off'"
                                        class="h-3 w-3 shrink-0 rounded-full bg-gray-500" />
                                    <div v-else-if="item.device_status === 'on'"
                                        class="h-3 w-3 shrink-0 rounded-full bg-green-500" />
                                    <div v-else class="h-3 w-3 shrink-0 rounded-full bg-amber-500" />
                                </div>
                            </template>
                            <template #item-pg_balance="item">
                                <div class="text-xs">
                                    <div class="font-medium text-foreground">
                                        {{ formatCurrency((item as Device).pg_balance) }}
                                    </div>
                                    <div class="text-muted-foreground">
                                        update: {{ formatUpdatedAt((item as Device).pg_balance_updated_at) }}
                                    </div>
                                </div>
                            </template>
                            <template #item-baca_balance="item">
                                <div class="text-xs">
                                    <div class="font-medium text-foreground">
                                        {{ formatCurrency((item as Device).baca_balance) }}
                                    </div>
                                    <div class="text-muted-foreground">
                                        update: {{ formatUpdatedAt((item as Device).baca_balance_updated_at) }}
                                    </div>
                                </div>
                            </template>
                            <template #item-note="item">
                                <div class=" cursor-pointer  text-left text-xs text-muted-foreground"
                                    :title="deviceNoteTitle(item as Device)">
                                    <div class="max-w-44 text-truncate overflow-hidden">
                                        {{ notePreview((item as Device).note) }}
                                    </div>
                                </div>
                            </template>
                            <template #item-actions="item">
                                <div class="flex flex-col gap-2 py-2" @click.stop v-if="!isBusy((item as Device).id)">
                                    <div class="flex flex-wrap items-center gap-2 pb-2">
                                        <AppButton v-if="item.device_status === 'off'" type="button" size="sm"
                                            variant="outline" color="primary" :disabled="isBusy((item as Device).id)"
                                            @click="setPower(item as Device, 'on')">
                                            Bật máy
                                        </AppButton>
                                        <AppButton v-else-if="item.device_status === 'on'" type="button" size="sm"
                                            variant="outline" color="error" :disabled="isBusy((item as Device).id)"
                                            @click="setPower(item as Device, 'off')">
                                            Tắt máy
                                        </AppButton>
                                        <span v-else class="text-xs text-muted-foreground"
                                            :title="String(item.device_status ?? '')">
                                            {{ deviceStatusDisplayLabel(String(item.device_status ?? '')) }}
                                        </span>

                                        <AppButton v-if="item.device_status == 'on' || item.device_status == 'off'"
                                            :as="Link" size="sm" color="warning" variant="solid"
                                            :disabled="isBusy((item as Device).id)"
                                            :href="deviceManagement.edit({ device: (item as Device).id })">
                                            Sửa
                                        </AppButton>
                                        <AppButton v-if="item.device_status == 'on' || item.device_status == 'off'"
                                            :as="Link" size="sm" color="primary" variant="solid"
                                            :disabled="isBusy((item as Device).id)"
                                            :href="deviceManagement.transfer({ device: (item as Device).id })">
                                            Chuyển tiền
                                        </AppButton>
                                        <AppButton v-if="item.device_status == 'on' || item.device_status == 'off'"
                                            type="button" size="sm" color="error" variant="solid"
                                            :disabled="isBusy((item as Device).id)" @click="deleteOne(item as Device)">
                                            Xóa
                                        </AppButton>
                                    </div>
                                    <div class="space-y-2 divide-y divide-border/60 px-2 py-1 border rounded"
                                        v-if="item.device_status == 'on'">
                                        <div class=" flex flex-wrap gap-2 py-1">
                                            <AppButton type="button" size="xs" variant="outline" color="info"
                                                :disabled="isBusy((item as Device).id)"
                                                @click="runCheckLogin(item as Device, 'pg_check_login')">
                                                <span>PG Check Login</span>
                                            </AppButton>
                                            <AppButton type="button" size="xs" variant="outline" color="warning"
                                                :disabled="isBusy((item as Device).id)"
                                                @click="runOperation(item as Device, 'pg_balance')">
                                                <span>PG Số dư</span>
                                            </AppButton>
                                        </div>
                                        <div class=" flex flex-wrap gap-2 py-1">
                                            <AppButton type="button" size="xs" variant="outline" color="info"
                                                :disabled="isBusy((item as Device).id)"
                                                @click="runCheckLogin(item as Device, 'baca_check_login')">
                                                <span>Bắc Á Check Login</span>
                                            </AppButton>
                                            <AppButton type="button" size="xs" variant="outline" color="warning"
                                                :disabled="isBusy((item as Device).id)"
                                                @click="runOperation(item as Device, 'baca_balance')">
                                                <span>Bắc Á Số dư</span>
                                            </AppButton>

                                        </div>
                                    </div>
                                </div>
                                <div v-else class="flex items-center justify-center p-1">
                                    <span class="loader"></span>
                                </div>
                            </template>
                            <template #expand="item">
                                <div class="space-y-2 rounded-md border border-border/70 bg-muted/30 p-3 text-xs">
                                    <p class="font-medium text-foreground">
                                        Lịch sử lệnh {{ (item as Device).name }}
                                    </p>
                                    <div v-if="rowOperations((item as Device).id).length === 0"
                                        class="text-muted-foreground">
                                        Chưa có lệnh nào.
                                    </div>
                                    <div v-for="operation in rowOperations((item as Device).id)" :key="operation.id"
                                        class="rounded border border-border/60 bg-background p-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium">{{ operationLabel(operation.operation_type)
                                            }}</span>
                                            <span class="rounded px-2 py-0.5 text-[11px]"
                                                :class="operationStatusClass(operation.status)">
                                                {{ operationStatusText(operation.status) }}
                                            </span>
                                            <span class="text-muted-foreground">
                                                {{ operation.requested_by_name ?? 'N/A' }}
                                            </span>
                                        </div>
                                        <div v-if="operation.result_message" class="mt-1 text-muted-foreground">
                                            <p>{{ extractResultText(operation.result_message) }}</p>
                                            <a v-if="extractReceiptUrl(operation.result_message)"
                                                :href="extractReceiptUrl(operation.result_message)!" target="_blank"
                                                class="mt-1.5 inline-block text-xs text-primary underline hover:text-primary/80">
                                                Xem ảnh biên lai
                                            </a>
                                            <a v-if="extractReceiptUrl(operation.result_message)"
                                                :href="extractReceiptUrl(operation.result_message)!" target="_blank">
                                                <img :src="extractReceiptUrl(operation.result_message)!" alt="Biên lai"
                                                    loading="lazy"
                                                    class="mt-1.5 max-h-48 rounded border border-border/60 object-contain" />
                                            </a>
                                        </div>
                                        <div v-if="operation.logs.length" class="mt-2 space-y-1">
                                            <div v-for="log in operation.logs" :key="log.id"
                                                class="rounded bg-muted/40 px-2 py-1 text-[11px]">
                                                <span class="font-medium uppercase">{{ log.stage }}</span>:
                                                {{ log.message }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </Vue3EasyDataTable>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="noteDialogOpen" @update:open="onNoteDialogOpenChange">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Ghi chú thiết bị</DialogTitle>
                </DialogHeader>
                <div class="space-y-2">
                    <Label for="device-note">Nội dung (tối đa 2000 ký tự)</Label>
                    <textarea id="device-note" v-model="noteDraft" rows="5" maxlength="2000"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary"
                        placeholder="Ghi chú nội bộ cho thiết bị này…" />
                </div>
                <DialogFooter class="gap-2 sm:gap-0">
                    <AppButton type="button" variant="outline" :disabled="savingNote" @click="closeNoteDialog">
                        Hủy
                    </AppButton>
                    <AppButton type="button" color="primary" variant="solid" :disabled="savingNote"
                        @click="saveDeviceNote">
                        Lưu
                    </AppButton>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
