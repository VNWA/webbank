<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios, { isAxiosError } from 'axios';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import DuoPlusMp4Select from '@/components/DuoPlusMp4Select.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import managedDevices from '@/routes/api/managed-devices';
import deviceManagement from '@/routes/device-management';

type DuoPlusInfoResponse = { resolved?: { name?: string; status?: string; device_status?: string; pg_video_id?: string; baca_video_id?: string } };
type Props = { statusOptions: string[] };

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Quản lý thiết bị', href: deviceManagement.index() },
            { title: 'Tạo mới', href: deviceManagement.create() },
        ],
    },
});

const createStep = ref(1);
const loadingInfo = ref(false);
const hasFetchedInfo = ref(false);
const fetchedName = ref('');
const formErrors = ref<Record<string, string>>({});
const form = ref({
    status: props.statusOptions[0] ?? 'normal',
    duo_api_key: '',
    image_id: '',
    name: '',
    pg_pass: '',
    pg_pin: '',
    baca_pass: '',
    baca_pin: '',
    pg_video_id: '',
    baca_video_id: '',
});

function errorMessage(err: unknown): string {
    if (!isAxiosError(err)) {
        return 'Đã xảy ra lỗi.';
    }

    const data = err.response?.data as Record<string, unknown> | undefined;

    return typeof data?.message === 'string' ? data.message : 'Đã xảy ra lỗi.';
}

async function fetchInfo(): Promise<void> {
    formErrors.value = {};
    loadingInfo.value = true;

    try {
        const { data } = await axios.post(managedDevices.duoplusInfo.url(), {
            duo_api_key: form.value.duo_api_key,
            image_id: form.value.image_id,
        });
        const info = (data as DuoPlusInfoResponse).resolved;

        if (info?.name) {
            form.value.name = info.name;
        }

        if (info?.status && props.statusOptions.includes(info.status)) {
            form.value.status = info.status;
        }

        if (info?.pg_video_id) {
            form.value.pg_video_id = info.pg_video_id;
        }

        if (info?.baca_video_id) {
            form.value.baca_video_id = info.baca_video_id;
        }

        fetchedName.value = form.value.name || 'Không có tên';
        hasFetchedInfo.value = true;
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        loadingInfo.value = false;
    }
}

async function confirmAndLoadFiles(): Promise<void> {
    createStep.value = 2;
}

async function submit(): Promise<void> {
    formErrors.value = {};

    try {
        await axios.post(managedDevices.store.url(), form.value);
        toast.success('Đã tạo device.');
        router.visit(deviceManagement.index());
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 422) {
            const errs = e.response.data?.errors as Record<string, string[]> | undefined;

            if (errs) {
                formErrors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0] ?? '']));
            }
        }

        toast.error(errorMessage(e));
    }
}
</script>

<template>
    <div class="p-4">
        <Head title="Tạo device" />
        <Card>
            <CardHeader>
                <CardTitle>Tạo device</CardTitle>
                <CardDescription>Bước {{ createStep }}/2</CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <template v-if="createStep === 1">
                    <template v-if="!hasFetchedInfo">
                        <div class="grid gap-2">
                            <Label for="duo_api_key">Dwin key</Label>
                            <Input id="duo_api_key" v-model="form.duo_api_key" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="image_id">Image ID</Label>
                            <Input id="image_id" v-model="form.image_id" />
                        </div>
                        <Button :disabled="loadingInfo" @click="fetchInfo">
                            {{ loadingInfo ? 'Đang lấy detail...' : 'Lấy detail' }}
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm">Có phải tên này không: <strong>{{ fetchedName }}</strong></p>
                        <div class="flex gap-2">
                            <Button variant="secondary" @click="hasFetchedInfo = false">Không đúng</Button>
                            <Button @click="confirmAndLoadFiles">
                                Đúng, sang bước 2
                            </Button>
                        </div>
                    </template>
                </template>

                <form v-else class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label>Tên thiết bị</Label>
                        <Input v-model="form.name" readonly />
                    </div>
                    <div class="grid gap-2">
                        <Label>Dwin key</Label>
                        <Input v-model="form.duo_api_key" readonly />
                    </div>
                    <div class="grid gap-2">
                        <Label>Image ID</Label>
                        <Input v-model="form.image_id" readonly />
                    </div>
                    <div class="grid gap-2">
                        <Label>PG Pass</Label>
                        <Input v-model="form.pg_pass" />
                    </div>
                    <div class="grid gap-2">
                        <Label>PG Pin</Label>
                        <Input v-model="form.pg_pin" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Baca Pass</Label>
                        <Input v-model="form.baca_pass" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Baca Pin</Label>
                        <Input v-model="form.baca_pin" />
                    </div>
                    <DuoPlusMp4Select v-model="form.pg_video_id" label="PG Video (mp4)" :duo-api-key="form.duo_api_key" />
                    <DuoPlusMp4Select v-model="form.baca_video_id" label="Baca Video (mp4)" :duo-api-key="form.duo_api_key" />
                    <div class="sm:col-span-2 flex gap-2 justify-end">
                        <Button type="button" variant="secondary" @click="createStep = 1; hasFetchedInfo = false">Quay lại</Button>
                        <Button type="submit">Lưu</Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
