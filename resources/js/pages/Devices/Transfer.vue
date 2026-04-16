<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import http from '@/lib/axios';
import { dashboard } from '@/routes';
import deviceManagement from '@/routes/device-management';

type DeviceLite = {
    id: number;
    name: string;
    image_id: string;
};

type BankOption = {
    code: string;
    short_name: string;
    name: string;
    bin: string;
    lookup_supported: boolean;
};

type SavedRecipient = {
    id: string;
    label: string;
    bank_code: string;
    bank_name: string;
    account_number: string;
    recipient_name: string;
    last_used_at: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Quản lý thiết bị', href: deviceManagement.index() },
            { title: 'Chuyển tiền', href: '#' },
        ],
    },
});

const props = defineProps<{
    device: DeviceLite;
}>();

const step = ref<1 | 2>(1);

const bankOptions = ref<BankOption[]>([]);
const bankSearch = ref('');
const loadingBanks = ref(false);

const savedRecipients = ref<SavedRecipient[]>([]);
const selectedSavedRecipientId = ref<string>('');

const bankCode = ref('');
const accountNumberInput = ref('');
const recipientName = ref('');
const verifyingRecipient = ref(false);

const amountInput = ref('');
const contentInput = ref('CHUYEN TIEN');
const channel = ref<'pg' | 'baca'>('baca');
const submitting = ref(false);

const CONTENT_MAX_LEN = 35;

const filteredBanks = computed(() => {
    const q = bankSearch.value.trim().toLowerCase();
    if (q === '') {
        return bankOptions.value;
    }

    return bankOptions.value.filter((bank) => {
        return (
            bank.name.toLowerCase().includes(q) ||
            bank.short_name.toLowerCase().includes(q) ||
            bank.code.toLowerCase().includes(q)
        );
    });
});

const selectedBank = computed(() => bankOptions.value.find((b) => b.code === bankCode.value) ?? null);
const accountNumber = computed(() => accountNumberInput.value.replace(/\s+/g, '').trim());
const canContinueStep1 = computed(() => bankCode.value !== '' && accountNumber.value.length >= 6);

const PG_BANK_PATTERNS = ['PGB', 'PGBANK', 'PG BANK'];
const BACA_BANK_PATTERNS = ['BAB', 'BACABANK', 'BAC A BANK', 'BACA'];

function isSameBankAsChannel(bank: BankOption | null, ch: 'pg' | 'baca'): boolean {
    if (!bank) return false;
    const upper = (s: string) => s.toUpperCase().replace(/[^A-Z0-9]/g, '');
    const code = upper(bank.code);
    const short = upper(bank.short_name);
    const patterns = ch === 'pg' ? PG_BANK_PATTERNS : BACA_BANK_PATTERNS;
    return patterns.some((p) => {
        const up = upper(p);
        return code === up || short === up || code.includes(up) || short.includes(up);
    });
}

const sameBankWarning = computed<string | null>(() => {
    if (!selectedBank.value) return null;
    if (isSameBankAsChannel(selectedBank.value, channel.value)) {
        const label = channel.value === 'pg' ? 'PG Bank' : 'Bắc Á Bank';
        return `Không thể chuyển cùng ngân hàng (${label} → ${label}). Vui lòng đổi kênh chuyển.`;
    }
    return null;
});

function localStorageKey(deviceId: number): string {
    return `webBank.transfer.recipients.device.${deviceId}`;
}

function loadSavedRecipients(): void {
    try {
        const raw = window.localStorage.getItem(localStorageKey(props.device.id));
        if (!raw) {
            savedRecipients.value = [];
            return;
        }
        const parsed = JSON.parse(raw) as unknown;
        if (!Array.isArray(parsed)) {
            savedRecipients.value = [];
            return;
        }
        savedRecipients.value = parsed as SavedRecipient[];
    } catch {
        savedRecipients.value = [];
    }
}

function persistSavedRecipients(): void {
    window.localStorage.setItem(localStorageKey(props.device.id), JSON.stringify(savedRecipients.value.slice(0, 30)));
}

function pickSavedRecipient(): void {
    const rec = savedRecipients.value.find((r) => r.id === selectedSavedRecipientId.value) ?? null;
    if (!rec) return;
    bankCode.value = rec.bank_code;
    accountNumberInput.value = rec.account_number;
    recipientName.value = rec.recipient_name;
    step.value = 2;
}

async function loadBanks(): Promise<void> {
    if (loadingBanks.value) return;
    loadingBanks.value = true;
    try {
        const { data } = await http.get('/api/managed-devices/banklookup/banks');
        const banks = (data?.data?.banks ?? []) as BankOption[];
        bankOptions.value = Array.isArray(banks) ? banks : [];
    } catch (e) {
        toast.error('Không thể tải danh sách ngân hàng.');
        bankOptions.value = [];
    } finally {
        loadingBanks.value = false;
    }
}

async function verifyAndContinue(): Promise<void> {
    if (!canContinueStep1.value) return;
    verifyingRecipient.value = true;
    recipientName.value = '';
    try {
        const { data } = await http.post('/api/managed-devices/banklookup/account-name', {
            bank: bankCode.value,
            account: accountNumber.value,
        });
        const name = String(data?.data?.recipient_name ?? '').trim();
        if (name === '') {
            toast.error('Không lấy được tên người nhận.');
            return;
        }
        recipientName.value = name;
        step.value = 2;
    } catch (e) {
        toast.error('Tra cứu tên người nhận thất bại.');
    } finally {
        verifyingRecipient.value = false;
    }
}

function saveRecipient(): void {
    if (!selectedBank.value || recipientName.value.trim() === '') {
        toast.error('Chưa có đủ dữ liệu để lưu.');
        return;
    }

    const now = new Date().toISOString();
    const id = `${bankCode.value}:${accountNumber.value}`;
    const existingIdx = savedRecipients.value.findIndex((r) => r.id === id);
    const next: SavedRecipient = {
        id,
        label: `${selectedBank.value.short_name || selectedBank.value.name} • ${accountNumber.value}`,
        bank_code: bankCode.value,
        bank_name: selectedBank.value.name,
        account_number: accountNumber.value,
        recipient_name: recipientName.value.trim(),
        last_used_at: now,
    };

    if (existingIdx >= 0) {
        savedRecipients.value.splice(existingIdx, 1);
    }

    savedRecipients.value.unshift(next);
    persistSavedRecipients();
    toast.success('Đã lưu tài khoản nhận.');
}

function backToStep1(): void {
    step.value = 1;
}

function normalizeCurrencyInput(raw: string): string {
    const digits = raw.replace(/[^\d]/g, '');
    if (digits === '') return '';
    const value = Number(digits);
    if (!Number.isFinite(value)) return '';
    return value.toLocaleString('vi-VN');
}

watchDebounced(
    amountInput,
    (v) => {
        const next = normalizeCurrencyInput(v);
        if (next !== v) amountInput.value = next;
    },
    { debounce: 120 },
);

function normalizeTransferContent(raw: string): string {
    // Uppercase + remove accents
    let s = raw.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    s = s.replace(/đ/gi, 'D');
    s = s.toUpperCase();
    // Keep A-Z 0-9 space only (banks often reject special chars)
    s = s.replace(/[^A-Z0-9 ]+/g, ' ');
    s = s.replace(/\s+/g, ' ').trim();
    if (s.length > CONTENT_MAX_LEN) {
        s = s.slice(0, CONTENT_MAX_LEN).trimEnd();
    }
    return s;
}

watchDebounced(
    contentInput,
    (v) => {
        const next = normalizeTransferContent(v);
        if (next !== v) contentInput.value = next;
    },
    { debounce: 120 },
);

async function submitTransfer(): Promise<void> {
    if (sameBankWarning.value) {
        toast.error(sameBankWarning.value);
        return;
    }
    const digits = amountInput.value.replace(/[^\d]/g, '');
    const amount = Number(digits);
    if (!Number.isFinite(amount) || amount <= 0) {
        toast.error('Số tiền không hợp lệ.');
        return;
    }
    if (!recipientName.value || !selectedBank.value) {
        toast.error('Thiếu thông tin người nhận.');
        return;
    }
    if (submitting.value) return;
    submitting.value = true;
    try {
        const operation_type = channel.value === 'pg' ? 'pg_transfer' : 'baca_transfer';
        const normalizedContent = normalizeTransferContent(contentInput.value);
        await http.post(`/api/managed-devices/${props.device.id}/operations`, {
            operation_type,
            operation_payload: {
                channel: channel.value,
                bank_code: bankCode.value,
                bank_name: selectedBank.value.short_name || selectedBank.value.name,
                account_number: accountNumber.value,
                recipient_name: recipientName.value,
                amount: Math.floor(amount),
                content: normalizedContent,
            },
        });
        toast.success('Đã đưa lệnh chuyển tiền vào hàng đợi.');
        router.visit(deviceManagement.index());
    } catch (e) {
        toast.error('Không thể gửi lệnh chuyển tiền.');
    } finally {
        submitting.value = false;
    }
}

watchDebounced(
    accountNumberInput,
    () => {
        // reset recipient name when account changes in step 1
        if (step.value === 1) {
            recipientName.value = '';
        }
    },
    { debounce: 250 },
);

onMounted(() => {
    void loadBanks();
    loadSavedRecipients();
});
</script>

<template>
    <div class="p-4 space-y-4">

        <Head title="Chuyển tiền" />

        <Card class="max-w-3xl">
            <CardHeader>
                <CardTitle>Chuyển tiền</CardTitle>
                <CardDescription>
                    Thiết bị: <span class="font-medium text-foreground">{{ device.name }}</span>
                    <span class="text-muted-foreground">({{ device.image_id }})</span>
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="flex items-center gap-2 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full border"
                            :class="step === 1 ? 'border-primary text-primary' : 'border-muted-foreground/40 text-muted-foreground'">
                            1
                        </span>
                        <span :class="step === 1 ? 'font-medium text-foreground' : 'text-muted-foreground'">
                            Người nhận
                        </span>
                    </div>
                    <div class="h-px flex-1 bg-border/70" />
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full border"
                            :class="step === 2 ? 'border-primary text-primary' : 'border-muted-foreground/40 text-muted-foreground'">
                            2
                        </span>
                        <span :class="step === 2 ? 'font-medium text-foreground' : 'text-muted-foreground'">
                            Số tiền & nội dung
                        </span>
                    </div>
                </div>

                <div v-if="step === 1" class="space-y-6">
                    <div v-if="savedRecipients.length" class="space-y-2">
                        <Label>Chọn tài khoản đã lưu</Label>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <select v-model="selectedSavedRecipientId"
                                class="h-10 w-full rounded-md border border-border bg-background px-3 text-sm">
                                <option value="">-- Chọn nhanh --</option>
                                <option v-for="r in savedRecipients" :key="r.id" :value="r.id">
                                    {{ r.label }} ({{ r.recipient_name }})
                                </option>
                            </select>
                            <Button type="button" variant="secondary" :disabled="selectedSavedRecipientId === ''"
                                @click="pickSavedRecipient">
                                Dùng
                            </Button>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label>Ngân hàng</Label>
                            <div class="space-y-2">
                                <Input v-model="bankSearch" placeholder="Tìm ngân hàng..." />
                                <div class="relative">
                                    <select v-model="bankCode"
                                        class="h-10 w-full rounded-md border border-border bg-background px-3 text-sm">
                                        <option value="">-- Chọn ngân hàng --</option>
                                        <option v-for="b in filteredBanks" :key="b.code" :value="b.code">
                                            {{ b.short_name || b.name }} ({{ b.code }})
                                        </option>
                                    </select>
                                    <div v-if="loadingBanks" class="absolute inset-y-0 right-3 flex items-center">
                                        <Spinner />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label>Số tài khoản</Label>
                            <Input v-model="accountNumberInput" inputmode="numeric" placeholder="VD: 0123456789" />
                            <p class="text-xs text-muted-foreground">Nhập STK rồi bấm “Tiếp tục” để xác minh tên người
                                nhận.</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <Button :as="Link" variant="secondary" :href="deviceManagement.index()">Quay lại</Button>
                        <Button type="button" :disabled="!canContinueStep1 || verifyingRecipient"
                            @click="verifyAndContinue">
                            <Spinner v-if="verifyingRecipient" />
                            Tiếp tục
                        </Button>
                    </div>
                </div>

                <div v-else class="space-y-6">
                    <div class="rounded-md border border-border/70 bg-muted/30 p-3 text-sm">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-medium text-foreground">{{ recipientName }}</span>
                            <span class="text-muted-foreground">• {{ selectedBank?.short_name || selectedBank?.name }} •
                                {{ accountNumber }}</span>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label>Kênh chuyển</Label>
                            <select v-model="channel"
                                class="h-10 w-full rounded-md border border-border bg-background px-3 text-sm">
                                <option value="baca">Bắc Á</option>
                                <option value="pg">PG Bank</option>
                            </select>
                            <p v-if="sameBankWarning" class="text-xs font-medium text-destructive">
                                {{ sameBankWarning }}
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                Chọn đúng kênh (PG/Bắc Á) tương ứng với app bank trên cloud phone.
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label>Số tiền</Label>
                            <Input v-model="amountInput" inputmode="numeric" placeholder="VD: 1.000.000" />
                        </div>
                        <div class="space-y-2">
                            <Label>Nội dung</Label>
                            <textarea v-model="contentInput" :maxlength="CONTENT_MAX_LEN" rows="3"
                                class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary"
                                placeholder="VD: THANH TOAN" />
                            <p class="text-xs text-muted-foreground">
                                Tự chuẩn hoá: IN HOA, bỏ dấu, chỉ A-Z/0-9/khoảng trắng. Tối đa {{ CONTENT_MAX_LEN }} ký
                                tự.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <Button type="button" variant="secondary" @click="backToStep1">Quay lại bước 1</Button>
                        <div class="flex gap-2">
                            <Button type="button" variant="outline" @click="saveRecipient">Lưu tài khoản</Button>
                            <Button type="button" :disabled="submitting || !!sameBankWarning" @click="submitTransfer">
                                <Spinner v-if="submitting" />
                                Gửi lệnh chuyển
                            </Button>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
