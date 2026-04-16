<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Vue3EasyDataTable from 'vue3-easy-data-table';
import AppButton from '@/components/AppButton.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import echo from '@/echo';
import http from '@/lib/axios';
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
    device_status: string;
    name: string;
    pg_pass: string;
    pg_pin: string;
    baca_pass: string;
    baca_pin: string;
    pg_video_id: string;
    baca_video_id: string;
};
type DeviceOperationLog = {
    id: number;
    level: string;
    stage: string;
    message: string;
    created_at: string | null;
};
type DeviceOperation = {
    id: number;
    device_id: number;
    operation_type: 'pg_check_login' | 'baca_check_login';
    status: OperationStatus;
    result_message: string | null;
    requested_by_name: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    logs: DeviceOperationLog[];
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
    { text: '', value: 'actions' },
];

const tableItems = ref<TableItem[]>([]);
const selectedItems = ref<TableItem[]>([]);
const loading = ref(false);
const serverItemsLength = ref(0);
const serverOptions = ref<TableServerOptions>({ page: 1, rowsPerPage: 10 });
const searchInput = ref('');
const searchDebounced = ref('');
const operationsByDevice = ref<Record<number, DeviceOperation[]>>({});
const loadingOperations = ref(false);

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

function operationLabel(type: string): string {
    if (type === 'pg_check_login') {
        return 'PG Check Login';
    }

    if (type === 'baca_check_login') {
        return 'Bắc Á Check Login';
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
}

async function loadDevices(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await http.get(managedDevices.index.url(), {
            params: {
                page: serverOptions.value.page,
                per_page: serverOptions.value.rowsPerPage,
                search: searchDebounced.value,
            },
        });
        tableItems.value = data.data as TableItem[];
        serverItemsLength.value = Number(data.meta.total ?? 0);
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        loading.value = false;
    }
}

async function loadOperationFeed(silent = false): Promise<void> {
    if (loadingOperations.value) {
        return;
    }

    loadingOperations.value = true;

    try {
        const { data } = await http.get(managedDevices.operations.feed.url());
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

async function deleteOne(row: Device): Promise<void> {
    if (isBusy(row.id)) {
        toast.warning('Thiết bị đang chạy lệnh, vui lòng chờ hoàn tất.');

        return;
    }

    if (!window.confirm(`Xóa device ${row.name}?`)) {
        return;
    }

    try {
        await http.delete(managedDevices.destroy.url({ device: row.id }));
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
        const { data } = await http.post(managedDevices.power.url({ device: device.id }), { action });
        toast.success(typeof data.message === 'string' ? data.message : 'Đã cập nhật nguồn.');
        await loadDevices();
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
        const { data } = await http.post(managedDevices.operations.store.url({ device: device.id }), {
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
        await http.delete(managedDevices.bulkDestroy.url(), { data: { ids: selectedIds.value } });
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
    }
});

onBeforeUnmount(() => {
    if (echo !== null) {
        echo.leave('private-device-operations');
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
                    <div class="max-w-sm space-y-2">
                        <Label for="search">Tìm kiếm</Label>
                        <Input id="search" v-model="searchInput" placeholder="Tên / dwin key / image id..."
                            autocomplete="off" />
                    </div>
                    <div
                        class="overflow-x-auto rounded-lg border border-sidebar-border/70 bg-card p-2 dark:border-sidebar-border">
                        <Vue3EasyDataTable v-model:server-options="serverOptions" v-model:items-selected="selectedItems"
                            :headers="headers" :items="tableItems" :loading="loading"
                            :server-items-length="serverItemsLength" :rows-items="[10, 25, 50]" :rows-per-page="10"
                            buttons-pagination border-cell theme-color="#6366f1">
                            <template #item-device_status="item">
                                <div class="flex items-center gap-2" :title="String(item.device_status ?? '')">
                                    <div v-if="item.device_status === 'off'"
                                        class="h-3 w-3 shrink-0 rounded-full bg-gray-500" />
                                    <div v-else-if="item.device_status === 'on'"
                                        class="h-3 w-3 shrink-0 rounded-full bg-green-500" />
                                    <div v-else class="h-3 w-3 shrink-0 rounded-full bg-amber-500" />
                                </div>
                            </template>
                            <template #item-actions="item">
                                <div class="flex flex-col gap-2 py-2" v-if="!isBusy((item as Device).id)">
                                    <div class="flex flex-wrap items-centeE gap-2 pb-2">
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
                                        <span v-else class="text-xs text-muted-foreground">Đang xử lí ({{
                                            item.device_status }})</span>

                                        <AppButton v-if="item.device_status == 'on' || item.device_status == 'off'"
                                            :as="Link" size="sm" color="warning" variant="solid"
                                            :disabled="isBusy((item as Device).id)"
                                            :href="deviceManagement.edit({ device: (item as Device).id })">
                                            Sửa
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
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="flex itesm-center justify-center p-1">
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
                                        <p v-if="operation.result_message" class="mt-1 text-muted-foreground">
                                            {{ operation.result_message }}
                                        </p>
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
    </div>
</template>
