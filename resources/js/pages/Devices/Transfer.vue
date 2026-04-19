<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import deviceManagement from '@/routes/device-management';
import managedDevices from '@/routes/api/managed-devices';

type DeviceLite = {
    id: number;
    name: string;
    image_id: string;
};

/** Hàng từ bảng `banks` (Inertia). */
type BankRow = {
    id: number;
    code: string;
    name: string;
    short_name: string;
    pg_name: string;
    baca_name: string;
};

type SavedRecipient = {
    id: number;
    bank_id: number;
    bank_code: string;
    label: string;
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

const props = withDefaults(
    defineProps<{
        device: DeviceLite;
        banks: BankRow[];
        savedRecipients?: SavedRecipient[];
    }>(),
    { savedRecipients: () => [] },
);

const step = ref<1 | 2>(1);

const bankSearch = ref('');

const savedRecipients = ref<SavedRecipient[]>([...(props.savedRecipients ?? [])]);
const selectedSavedRecipientId = ref<string>('');
const savingRecipient = ref(false);

/** `id` bản ghi `banks` (select value). */
const bankId = ref<string>('');

const accountNumberInput = ref('');
const recipientName = ref('');
const verifyingRecipient = ref(false);

/** Chỉ chữ số (không lưu chuỗi đã format) — tránh ghép nhầm khi gõ/dán vào giữa ô đã có dấu chấm. */
const amountDigits = ref('');
const contentInput = ref('CHUYEN TIEN');
const channel = ref<'pg' | 'baca'>('baca');
const submitting = ref(false);

const CONTENT_MAX_LEN = 35;

const bankList = computed(() => props.banks ?? []);

const filteredBanks = computed(() => {
    const q = bankSearch.value.trim().toLowerCase();
    if (q === '') {
        return bankList.value;
    }

    return bankList.value.filter((bank) => {
        return (
            bank.name.toLowerCase().includes(q) ||
            bank.code.toLowerCase().includes(q) ||
            (bank.short_name && bank.short_name.toLowerCase().includes(q))
        );
    });
});

const selectedBank = computed(
    () => bankList.value.find((b) => String(b.id) === bankId.value) ?? null,
);

const accountNumber = computed(() => accountNumberInput.value.replace(/\s+/g, '').trim());
const canContinueStep1 = computed(() => bankId.value !== '' && accountNumber.value.length >= 6);

/** Chuỗi gõ tìm NH trên app PG / Bắc Á (ưu tiên pg_name / baca_name; nếu nhiều nhãn ` | ` thì lấy nhãn đầu để khớp list app). */
function appBankSearchName(b: BankRow, ch: 'pg' | 'baca'): string {
    const raw = (ch === 'pg' ? b.pg_name : b.baca_name).trim();
    if (raw !== '') {
        const first = raw.split(' | ')[0]?.trim() ?? '';
        if (first !== '') {
            return first;
        }
    }
    return b.name;
}

/** Nội bộ: mã NH nhận trùng NH vận hành kênh — PG = PGB, Bắc Á = BAB (theo `banks.code`). */
function isInternalForChannel(b: BankRow | null, ch: 'pg' | 'baca'): boolean {
    if (!b) {
        return false;
    }
    const c = b.code.trim().toUpperCase();
    if (ch === 'pg') {
        return c === 'PGB';
    }
    return c === 'BAB';
}

const internalTransferHint = computed<string | null>(() => {
    if (!selectedBank.value) {
        return null;
    }
    if (isInternalForChannel(selectedBank.value, channel.value)) {
        const label = channel.value === 'pg' ? 'PG Bank' : 'Bắc Á Bank';
        return `Ngân hàng nhận trùng kênh ${label} (mã ${selectedBank.value.code}): lệnh sẽ chạy luồng chuyển nội bộ trên app.`;
    }
    return null;
});

watch(
    () => props.savedRecipients,
    (v) => {
        savedRecipients.value = Array.isArray(v) ? [...v] : [];
    },
    { deep: true },
);

function pickSavedRecipient(): void {
    const sid = Number(selectedSavedRecipientId.value);
    const rec = Number.isFinite(sid) && sid > 0 ? (savedRecipients.value.find((r) => r.id === sid) ?? null) : null;
    if (!rec) {
        return;
    }
    const bid = typeof rec.bank_id === 'number' && rec.bank_id > 0 ? rec.bank_id : 0;
    const bank = bid > 0 ? bankList.value.find((b) => b.id === bid) ?? null : null;
    if (!bank) {
        toast.error('Ngân hàng đã lưu không còn trong danh sách — chọn lại NH ở bước 1.');
        return;
    }
    bankId.value = String(bank.id);
    accountNumberInput.value = rec.account_number;
    recipientName.value = rec.recipient_name;
    step.value = 2;
}

async function verifyAndContinue(): Promise<void> {
    if (!canContinueStep1.value) {
        return;
    }
    verifyingRecipient.value = true;
    recipientName.value = '';
    try {
        const { data } = await axios.post('/api/managed-devices/banklookup/account-name', {
            bank_id: Number(bankId.value),
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

async function saveRecipient(): Promise<void> {
    if (!selectedBank.value || recipientName.value.trim() === '') {
        toast.error('Chưa có đủ dữ liệu để lưu.');
        return;
    }
    if (savingRecipient.value) {
        return;
    }
    savingRecipient.value = true;
    try {
        const { data } = await axios.post(managedDevices.savedTransferRecipients.store.url(props.device), {
            bank_id: selectedBank.value.id,
            account_number: accountNumber.value,
            recipient_name: recipientName.value.trim(),
        });
        const list = data?.recipients;
        if (Array.isArray(list)) {
            savedRecipients.value = list as SavedRecipient[];
        }
        toast.success('Đã lưu tài khoản nhận.');
    } catch {
        toast.error('Không thể lưu tài khoản nhận.');
    } finally {
        savingRecipient.value = false;
    }
}

function backToStep1(): void {
    step.value = 1;
}

function formatVndFromDigits(digits: string): string {
    if (digits === '') {
        return '';
    }
    const value = Number(digits);
    if (!Number.isFinite(value)) {
        return '';
    }
    return value.toLocaleString('vi-VN');
}

function onAmountModelUpdate(v: string | number): void {
    amountDigits.value = String(v ?? '').replace(/\D/g, '');
}

/**
 * Dán vào ô số tiền: chỉ lấy số từ clipboard và thay toàn bộ (không chèn vào giữa chuỗi đã format).
 */
function onAmountPaste(e: ClipboardEvent): void {
    e.preventDefault();
    const text = e.clipboardData?.getData('text/plain') ?? '';
    amountDigits.value = text.replace(/\D/g, '');
}

/**
 * Chuẩn hoá nội dung chuyển khoản (gửi lên `operation_payload.content` / app bank).
 * - `trimFull: false` (khi đang gõ): không `.trim()` đầu-cuối để gõ dấu cách cuối câu vẫn giữ được; chỉ gộp nhiều khoảng trắng thành một.
 * - `trimFull: true` (lúc submit): trim toàn chuỗi trước khi gửi API.
 */
function normalizeTransferContent(raw: string, options?: { trimFull?: boolean }): string {
    const trimFull = options?.trimFull ?? false;
    let s = raw.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    s = s.replace(/đ/gi, 'D');
    s = s.toUpperCase();
    s = s.replace(/[^A-Z0-9 ]+/g, ' ');
    s = s.replace(/\s+/g, ' ');
    if (trimFull) {
        s = s.trim();
    } else {
        s = s.replace(/^\s+/, '');
    }
    if (s.length > CONTENT_MAX_LEN) {
        s = s.slice(0, CONTENT_MAX_LEN);
        if (trimFull) {
            s = s.trimEnd();
        }
    }
    return s;
}

watchDebounced(
    contentInput,
    (v) => {
        const next = normalizeTransferContent(v, { trimFull: false });
        if (next !== v) {
            contentInput.value = next;
        }
    },
    { debounce: 120 },
);

async function submitTransfer(): Promise<void> {
    const amount = Number(amountDigits.value);
    if (!Number.isFinite(amount) || amount <= 0) {
        toast.error('Số tiền không hợp lệ.');
        return;
    }
    if (!recipientName.value || !selectedBank.value) {
        toast.error('Thiếu thông tin người nhận.');
        return;
    }
    if (submitting.value) {
        return;
    }
    submitting.value = true;
    try {
        const operation_type = channel.value === 'pg' ? 'pg_transfer' : 'baca_transfer';
        const normalizedContent = normalizeTransferContent(contentInput.value, { trimFull: true });
        const bankSearchLabel = appBankSearchName(selectedBank.value, channel.value);
        await axios.post(`/api/managed-devices/${props.device.id}/operations`, {
            operation_type,
            operation_payload: {
                channel: channel.value,
                bank_id: selectedBank.value.id,
                bank_code: selectedBank.value.code,
                bank_name: bankSearchLabel,
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

watch(bankId, () => {
    if (step.value === 1) {
        recipientName.value = '';
    }
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
                        <span
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full border"
                            :class="
                                step === 1
                                    ? 'border-primary text-primary'
                                    : 'border-muted-foreground/40 text-muted-foreground'
                            "
                        >
                            1
                        </span>
                        <span :class="step === 1 ? 'font-medium text-foreground' : 'text-muted-foreground'">
                            Người nhận
                        </span>
                    </div>
                    <div class="h-px flex-1 bg-border/70" />
                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full border"
                            :class="
                                step === 2
                                    ? 'border-primary text-primary'
                                    : 'border-muted-foreground/40 text-muted-foreground'
                            "
                        >
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
                            <select
                                v-model="selectedSavedRecipientId"
                                class="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                            >
                                <option value="">-- Chọn nhanh --</option>
                                <option v-for="r in savedRecipients" :key="r.id" :value="String(r.id)">
                                    {{ r.label }} ({{ r.recipient_name }})
                                </option>
                            </select>
                            <Button type="button" variant="secondary" :disabled="selectedSavedRecipientId === ''" @click="pickSavedRecipient">
                                Dùng
                            </Button>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label>Ngân hàng</Label>
                            <div class="space-y-2">
                                <Input v-model="bankSearch" placeholder="Tìm theo tên hoặc mã..." />
                                <select
                                    v-model="bankId"
                                    class="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                                >
                                    <option value="">-- Chọn ngân hàng --</option>
                                    <option v-for="b in filteredBanks" :key="b.id" :value="String(b.id)">
                                        {{ b.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label>Số tài khoản</Label>
                            <Input v-model="accountNumberInput" inputmode="numeric" placeholder="VD: 0123456789" />
                            <p class="text-xs text-muted-foreground">Nhập STK rồi bấm “Tiếp tục” để xác minh tên người nhận (banklookup theo mã NH).</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <Button :as="Link" variant="secondary" :href="deviceManagement.index()">Quay lại</Button>
                        <Button type="button" :disabled="!canContinueStep1 || verifyingRecipient" @click="verifyAndContinue">
                            <Spinner v-if="verifyingRecipient" />
                            Tiếp tục
                        </Button>
                    </div>
                </div>

                <div v-else class="space-y-6">
                    <div class="rounded-md border border-border/70 bg-muted/30 p-3 text-sm">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-medium text-foreground">{{ recipientName }}</span>
                            <span class="text-muted-foreground"
                                >• {{ selectedBank?.name }} ({{ selectedBank?.code }}) • {{ accountNumber }}</span
                            >
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label>Kênh chuyển</Label>
                            <select
                                v-model="channel"
                                class="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                            >
                                <option value="baca">Bắc Á</option>
                                <option value="pg">PG Bank</option>
                            </select>
                            <p v-if="internalTransferHint" class="text-xs text-foreground/90">
                                {{ internalTransferHint }}
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                Tên NH gửi xuống lệnh: PG dùng `pg_name`, Bắc Á dùng `baca_name` (từ DB) để app gõ đúng ô tìm kiếm.
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label>Số tiền</Label>
                            <Input
                                :model-value="formatVndFromDigits(amountDigits)"
                                inputmode="numeric"
                                placeholder="VD: 1.000.000"
                                @update:model-value="onAmountModelUpdate"
                                @paste="onAmountPaste"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label>Nội dung</Label>
                            <!--
                              Nội dung chuyển khoản (payload `content` → lệnh pg_transfer / baca_transfer).
                              Giới hạn độ dài theo ngân hàng; khi gõ, script chỉ chuẩn hoá ký tự (không xoá khoảng trắng cuối đang gõ).
                            -->
                            <textarea
                                v-model="contentInput"
                                :maxlength="CONTENT_MAX_LEN"
                                rows="3"
                                class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary"
                                placeholder="VD: THANH TOAN HOA DON"
                                autocomplete="off"
                                spellcheck="false"
                            />
                            <p class="text-xs text-muted-foreground">
                                Khi gõ: IN HOA, bỏ dấu, chỉ A-Z / 0-9 / khoảng trắng (gộp nhiều space thành một). Khi gửi lệnh: trim đầu-cuối. Tối đa
                                {{ CONTENT_MAX_LEN }} ký tự.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <Button type="button" variant="secondary" @click="backToStep1">Quay lại bước 1</Button>
                        <div class="flex gap-2">
                            <Button type="button" variant="outline" :disabled="savingRecipient" @click="saveRecipient">
                                <Spinner v-if="savingRecipient" />
                                Lưu tài khoản
                            </Button>
                            <Button type="button" :disabled="submitting" @click="submitTransfer">
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
