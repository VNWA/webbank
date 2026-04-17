<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import axios, { isAxiosError } from 'axios';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Vue3EasyDataTable from 'vue3-easy-data-table';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import managedUsers from '@/routes/api/managed-users';
import userManagement from '@/routes/user-management';

type TableHeader = {
    text: string;
    value: string;
    sortable?: boolean;
    width?: number;
};

type TableItem = Record<string, unknown>;

type TableServerOptions = {
    page: number;
    rowsPerPage: number;
    sortBy?: string | string[];
    sortType?: 'asc' | 'desc' | ('asc' | 'desc')[];
};

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    roles_label: string;
};

type Props = {
    assignableRoles: string[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Quản lý người dùng',
                href: userManagement.index(),
            },
        ],
    },
});

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id as number);

const headers: TableHeader[] = [
    { text: 'Họ tên', value: 'name', sortable: true },
    { text: 'Email', value: 'email', sortable: true },
    { text: 'Vai trò', value: 'roles_label', sortable: false, width: 140 },
    { text: '', value: 'actions', width: 160 },
];

const tableItems = ref<TableItem[]>([]);
const loading = ref(false);
const serverItemsLength = ref(0);
const serverOptions = ref<TableServerOptions>({
    page: 1,
    rowsPerPage: 10,
});

const searchInput = ref('');
const searchDebounced = ref('');

watchDebounced(
    searchInput,
    (val) => {
        searchDebounced.value = val.trim();
        serverOptions.value = { ...serverOptions.value, page: 1 };
    },
    { debounce: 350 },
);

function mapUser(row: {
    id: number;
    name: string;
    email: string;
    roles: string[];
}): ManagedUser {
    return {
        ...row,
        roles_label: row.roles.join(', '),
    };
}

function errorMessage(err: unknown): string {
    if (!isAxiosError(err)) {
        return 'Đã xảy ra lỗi.';
    }

    const data = err.response?.data as Record<string, unknown> | undefined;

    if (data?.message && typeof data.message === 'string') {
        return data.message;
    }

    const errors = data?.errors;

    if (errors && typeof errors === 'object') {
        const parts: string[] = [];

        for (const v of Object.values(errors)) {
            if (Array.isArray(v)) {
                parts.push(...v.filter((x) => typeof x === 'string'));
            }
        }

        if (parts.length > 0) {
            return parts.join(' ');
        }
    }

    return 'Đã xảy ra lỗi.';
}

async function loadUsers(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await axios.get(managedUsers.index.url(), {
            params: {
                page: serverOptions.value.page,
                per_page: serverOptions.value.rowsPerPage,
                search: searchDebounced.value,
            },
        });
        tableItems.value = (
            data.data as Array<{
                id: number;
                name: string;
                email: string;
                roles: string[];
            }>
        ).map((u) => mapUser(u) as unknown as TableItem);
        serverItemsLength.value = data.meta.total as number;
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        loading.value = false;
    }
}

watch(
    [
        () => serverOptions.value.page,
        () => serverOptions.value.rowsPerPage,
        () => serverOptions.value.sortBy,
        () => serverOptions.value.sortType,
        searchDebounced,
    ],
    () => {
        void loadUsers();
    },
    { immediate: true },
);

const dialogOpen = ref(false);
const editingId = ref<number | null>(null);
const form = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: props.assignableRoles[0] ?? 'user',
});

const formErrors = ref<Record<string, string>>({});

function openCreate(): void {
    editingId.value = null;
    form.value = {
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: props.assignableRoles[0] ?? 'user',
    };
    formErrors.value = {};
    dialogOpen.value = true;
}

function openEdit(row: ManagedUser): void {
    editingId.value = row.id;
    form.value = {
        name: row.name,
        email: row.email,
        password: '',
        password_confirmation: '',
        role: row.roles[0] ?? props.assignableRoles[0] ?? 'user',
    };
    formErrors.value = {};
    dialogOpen.value = true;
}

async function submitForm(): Promise<void> {
    formErrors.value = {};

    try {
        if (editingId.value === null) {
            await axios.post(managedUsers.store.url(), {
                name: form.value.name,
                email: form.value.email,
                password: form.value.password,
                password_confirmation: form.value.password_confirmation,
                role: form.value.role,
            });
            toast.success('Đã tạo người dùng.');
        } else {
            const body: Record<string, unknown> = {
                name: form.value.name,
                email: form.value.email,
                role: form.value.role,
            };

            if (form.value.password !== '') {
                body.password = form.value.password;
                body.password_confirmation = form.value.password_confirmation;
            }

            await axios.put(
                managedUsers.update.url({ user: editingId.value }),
                body,
            );
            toast.success('Đã cập nhật người dùng.');
        }

        dialogOpen.value = false;
        await loadUsers();
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 422) {
            const errs = e.response.data?.errors as
                | Record<string, string[]>
                | undefined;

            if (errs) {
                formErrors.value = Object.fromEntries(
                    Object.entries(errs).map(([k, v]) => [k, v[0] ?? '']),
                );
            }
        }

        toast.error(errorMessage(e));
    }
}

async function confirmDelete(row: ManagedUser): Promise<void> {
    if (row.id === currentUserId.value) {
        toast.error('Bạn không thể xóa chính mình.');

        return;
    }

    if (!window.confirm(`Xóa người dùng ${row.email}?`)) {
        return;
    }

    try {
        await axios.delete(managedUsers.destroy.url({ user: row.id }));
        toast.success('Đã xóa người dùng.');
        await loadUsers();
    } catch (e) {
        toast.error(errorMessage(e));
    }
}

function canDeleteRow(row: ManagedUser): boolean {
    return row.id !== currentUserId.value;
}
</script>

<template>
    <div>
        <Head title="Quản lý người dùng" />

        <div class="flex flex-col gap-4 p-4">
            <Card>
                <CardHeader
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <CardTitle>Người dùng</CardTitle>
                        <CardDescription>
                            Thêm, sửa, xóa và gán vai trò theo quyền của bạn.
                        </CardDescription>
                    </div>
                    <Button type="button" @click="openCreate">Thêm người dùng</Button>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="max-w-sm space-y-2">
                        <Label for="search">Tìm kiếm</Label>
                        <Input
                            id="search"
                            v-model="searchInput"
                            placeholder="Tên hoặc email…"
                            autocomplete="off"
                        />
                    </div>

                    <div
                        class="easy-table-wrap overflow-x-auto rounded-lg border border-sidebar-border/70 bg-card p-2 dark:border-sidebar-border"
                    >
                        <Vue3EasyDataTable
                            v-model:server-options="serverOptions"
                            :headers="headers"
                            :items="tableItems"
                            :loading="loading"
                            :server-items-length="serverItemsLength"
                            :rows-items="[10, 25, 50]"
                            :rows-per-page="10"
                            buttons-pagination
                            border-cell
                            table-class-name="min-w-full"
                            header-class-name="text-left"
                            theme-color="#6366f1"
                        >
                            <template #item-actions="item">
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        @click="openEdit(item as ManagedUser)"
                                    >
                                        Sửa
                                    </Button>
                                    <Button
                                        v-if="canDeleteRow(item as ManagedUser)"
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        @click="confirmDelete(item as ManagedUser)"
                                    >
                                        Xóa
                                    </Button>
                                </div>
                            </template>
                        </Vue3EasyDataTable>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ editingId === null ? 'Thêm người dùng' : 'Sửa người dùng' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            editingId === null
                                ? 'Nhập thông tin và mật khẩu ban đầu.'
                                : 'Để trống mật khẩu nếu không đổi.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="submitForm">
                    <div class="grid gap-2">
                        <Label for="name">Họ tên</Label>
                        <Input id="name" v-model="form.name" required />
                        <p v-if="formErrors.name" class="text-sm text-destructive">
                            {{ formErrors.name }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="off"
                        />
                        <p v-if="formErrors.email" class="text-sm text-destructive">
                            {{ formErrors.email }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="role">Vai trò</Label>
                        <select
                            id="role"
                            v-model="form.role"
                            class="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:outline-1 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option
                                v-for="r in assignableRoles"
                                :key="r"
                                :value="r"
                            >
                                {{ r }}
                            </option>
                        </select>
                        <p v-if="formErrors.role" class="text-sm text-destructive">
                            {{ formErrors.role }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="password">Mật khẩu</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            :required="editingId === null"
                            autocomplete="new-password"
                        />
                        <p v-if="formErrors.password" class="text-sm text-destructive">
                            {{ formErrors.password }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="password_confirmation">Xác nhận mật khẩu</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            :required="editingId === null"
                            autocomplete="new-password"
                        />
                    </div>

                    <DialogFooter class="gap-2 sm:justify-end">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="dialogOpen = false"
                        >
                            Hủy
                        </Button>
                        <Button type="submit">Lưu</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
