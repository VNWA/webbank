<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Banknote, RefreshCw, Smartphone, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppButton from '@/components/AppButton.vue';
import { dashboard } from '@/routes';

export type DashboardStats = {
    users_count: number;
    devices_count: number;
    transfers_total_count: number;
    transfers_pg_count: number;
    transfers_baca_count: number;
    transfers_today_count: number;
    transfers_month_count: number;
    transfers_volume_total: string;
};

const props = defineProps<{
    stats: DashboardStats | null;
}>();

/** Dùng props trực tiếp để partial reload từ Inertia cập nhật đúng (không gọi axios `/api/...`). */
const stats = computed(() => props.stats);
const loadingStats = ref(false);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

function formatInt(n: number): string {
    return n.toLocaleString('vi-VN');
}

function formatMoney(raw: string): string {
    const n = Number(raw);
    if (!Number.isFinite(n)) return raw;
    return n.toLocaleString('vi-VN') + ' VND';
}

function refreshStats(): void {
    if (props.stats === null) {
        return;
    }

    loadingStats.value = true;

    router.reload({
        only: ['stats'],
        onSuccess: () => {
            toast.success('Đã cập nhật số liệu.');
        },
        onError: () => {
            toast.error('Không tải được thống kê.');
        },
        onFinish: () => {
            loadingStats.value = false;
        },
    });
}

</script>

<template>

    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-lg font-semibold text-foreground">Tổng quan</h1>
            <AppButton v-if="stats !== null" type="button" variant="outline" size="sm" :disabled="loadingStats"
                @click="refreshStats">
                <RefreshCw class="mr-1 size-4" :class="{ 'animate-spin': loadingStats }" />
                Làm mới số liệu
            </AppButton>
        </div>

        <p v-if="stats === null" class="text-sm text-muted-foreground">
            Bạn không có quyền xem thống kê hệ thống. Liên hệ quản trị viên nếu cần.
        </p>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Người dùng</CardTitle>
                    <Users class="size-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ formatInt(stats.users_count) }}</div>
                    <CardDescription>Tổng tài khoản trong hệ thống</CardDescription>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Thiết bị</CardTitle>
                    <Smartphone class="size-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ formatInt(stats.devices_count) }}</div>
                    <CardDescription>Cloud phone đã cấu hình</CardDescription>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Chuyển tiền (tất cả)</CardTitle>
                    <Banknote class="size-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ formatInt(stats.transfers_total_count) }}</div>
                    <CardDescription>PG: {{ formatInt(stats.transfers_pg_count) }} · Bắc Á:
                        {{ formatInt(stats.transfers_baca_count) }}</CardDescription>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Khối lượng giao dịch</CardTitle>
                    <Banknote class="size-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-xl font-bold leading-snug">{{ formatMoney(stats.transfers_volume_total) }}</div>
                    <CardDescription>Hôm nay: {{ formatInt(stats.transfers_today_count) }} · Tháng này:
                        {{ formatInt(stats.transfers_month_count) }}</CardDescription>
                </CardContent>
            </Card>
        </div>

        <Card v-if="stats !== null" class="border-dashed">
            <CardHeader>
                <CardTitle class="text-base">Gợi ý</CardTitle>
                <CardDescription>Xem chi tiết từng lần chuyển khoản thành công tại mục «Lịch sử chuyển tiền» trên menu.
                </CardDescription>
            </CardHeader>
        </Card>
    </div>
</template>
