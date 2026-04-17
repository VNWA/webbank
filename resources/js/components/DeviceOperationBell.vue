<script setup lang="ts">
import axios from 'axios';
import { Bell } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import echo from '@/echo';

type OperationStatus = 'queued' | 'running' | 'success' | 'failed';
type DeviceOperationLog = { id: number; stage: string; message: string; level: string; created_at: string | null };
type DeviceOperation = {
    id: number;
    device_id: number;
    operation_type: string;
    status: OperationStatus;
    result_message: string | null;
    requested_by_name: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    logs: DeviceOperationLog[];
};

const open = ref(false);
const loading = ref(false);
const operations = ref<DeviceOperation[]>([]);

const pollTimer = ref<number | null>(null);

const runningCount = computed(() => operations.value.filter((o) => o.status === 'queued' || o.status === 'running').length);

function extractReceiptUrl(msg: string | null): string | null {
    if (!msg) return null;
    const m = msg.match(/https?:\/\/\S+\.(?:jpg|jpeg|png|webp)/i);
    return m ? m[0] : null;
}

function extractResultText(msg: string | null): string {
    if (!msg) return '';
    return msg.replace(/\s*Ảnh:\s*https?:\/\/\S+/i, '').trim();
}

function statusText(status: OperationStatus): string {
    if (status === 'queued') return 'Đợi chạy';
    if (status === 'running') return 'Đang chạy';
    if (status === 'success') return 'Thành công';
    return 'Thất bại';
}

function statusClass(status: OperationStatus): string {
    if (status === 'success') return 'text-emerald-600';
    if (status === 'failed') return 'text-destructive';
    if (status === 'running') return 'text-sky-600';
    return 'text-amber-600';
}

async function loadFeed(silent = false): Promise<void> {
    if (loading.value) return;
    loading.value = true;
    try {
        const { data } = await axios.get('/api/managed-device-operations/feed');
        const grouped = (data?.operations ?? {}) as Record<string, DeviceOperation[]>;
        const flat: DeviceOperation[] = [];
        for (const items of Object.values(grouped)) {
            if (Array.isArray(items)) flat.push(...items);
        }
        operations.value = flat.sort((a, b) => b.id - a.id).slice(0, 25);
    } catch {
        if (!silent) {
            // ignore toast here; bell should be non-blocking
        }
    } finally {
        loading.value = false;
    }
}

function upsertOperation(op: DeviceOperation): void {
    const idx = operations.value.findIndex((o) => o.id === op.id);
    if (idx >= 0) {
        operations.value.splice(idx, 1, op);
    } else {
        operations.value.unshift(op);
    }
    operations.value = operations.value.sort((a, b) => b.id - a.id).slice(0, 25);
}

function startPollingIfNeeded(): void {
    if (pollTimer.value !== null) return;
    pollTimer.value = window.setInterval(() => {
        void loadFeed(true);
    }, 5000);
}

function stopPolling(): void {
    if (pollTimer.value === null) return;
    window.clearInterval(pollTimer.value);
    pollTimer.value = null;
}

watch(
    () => runningCount.value,
    (n) => {
        if (n > 0) startPollingIfNeeded();
        else stopPolling();
    },
    { immediate: true },
);

onMounted(() => {
    void loadFeed(true);
    if (echo !== null) {
        echo.private('device-operations').listen('.device-operation.updated', (event: { operation?: DeviceOperation }) => {
            if (event.operation) {
                upsertOperation(event.operation);
            }
        });
    }
});

onBeforeUnmount(() => {
    stopPolling();
    if (echo !== null) {
        echo.leave('private-device-operations');
    }
});
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger :as-child="true">
            <Button variant="ghost" size="icon" class="relative h-9 w-9">
                <span v-if="runningCount > 0" class="bell-pulse absolute inset-0 rounded-md" />
                <Bell class="size-5" :class="runningCount > 0 ? 'bell-ring text-amber-500' : 'opacity-80'" />
                <span
                    v-if="runningCount > 0"
                    class="absolute -right-0.5 -top-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-semibold text-white"
                >
                    {{ runningCount }}
                </span>
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-[360px] max-h-[420px] overflow-y-auto">
            <DropdownMenuLabel class="flex items-center justify-between">
                <span>Tiến trình tác vụ</span>
                <button
                    class="text-xs text-muted-foreground hover:text-foreground"
                    type="button"
                    @click="loadFeed()"
                >
                    Làm mới
                </button>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />

            <div v-if="operations.length === 0" class="px-3 py-6 text-sm text-muted-foreground">
                Chưa có tác vụ nào.
            </div>

            <template v-else>
                <DropdownMenuItem v-for="op in operations" :key="op.id" class="flex flex-col items-start gap-1">
                    <div class="flex w-full items-center justify-between gap-3">
                        <div class="text-sm font-medium">
                            #{{ op.id }} • Device {{ op.device_id }}
                        </div>
                        <div class="text-xs font-medium" :class="statusClass(op.status)">
                            {{ statusText(op.status) }}
                        </div>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ op.operation_type }} • {{ op.requested_by_name ?? 'N/A' }}
                    </div>
                    <div v-if="op.result_message" class="text-xs text-muted-foreground line-clamp-2">
                        {{ extractResultText(op.result_message) }}
                        <a v-if="extractReceiptUrl(op.result_message)"
                            :href="extractReceiptUrl(op.result_message)!" target="_blank"
                            class="ml-1 text-primary underline hover:text-primary/80"
                            @click.stop>Xem ảnh</a>
                    </div>
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

<style scoped>
@keyframes bell-ring {
    0%, 100% { transform: rotate(0deg); }
    10% { transform: rotate(14deg); }
    20% { transform: rotate(-12deg); }
    30% { transform: rotate(10deg); }
    40% { transform: rotate(-8deg); }
    50% { transform: rotate(5deg); }
    60% { transform: rotate(-3deg); }
    70%, 100% { transform: rotate(0deg); }
}

@keyframes bell-pulse-glow {
    0%, 100% { opacity: 0; }
    50% { opacity: 0.35; }
}

.bell-ring {
    animation: bell-ring 1.2s ease-in-out infinite;
    transform-origin: top center;
}

.bell-pulse {
    background: radial-gradient(circle, rgb(245 158 11 / 0.45) 0%, transparent 70%);
    animation: bell-pulse-glow 2s ease-in-out infinite;
    pointer-events: none;
}
</style>
