<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { isAxiosError } from 'axios';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import DuoPlusMp4Select from '@/components/DuoPlusMp4Select.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import http from '@/lib/axios';
import { dashboard } from '@/routes';
import managedDevices from '@/routes/api/managed-devices';
import deviceManagement from '@/routes/device-management';

type Device = {
    id: number;
    name: string;
    duo_api_key: string;
    image_id: string;
    pg_pass: string;
    pg_pin: string;
    baca_pass: string;
    baca_pin: string;
    pg_video_id: string;
    baca_video_id: string;
};
type Props = { device: Device };

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Quản lý thiết bị', href: deviceManagement.index() },
        ],
    },
});

const formErrors = ref<Record<string, string>>({});
const form = ref({
    pg_pass: props.device.pg_pass,
    pg_pin: props.device.pg_pin,
    baca_pass: props.device.baca_pass,
    baca_pin: props.device.baca_pin,
    pg_video_id: props.device.pg_video_id,
    baca_video_id: props.device.baca_video_id,
});

function errorMessage(err: unknown): string {
    if (!isAxiosError(err)) {
        return 'Đã xảy ra lỗi.';
    }

    const data = err.response?.data as Record<string, unknown> | undefined;

    return typeof data?.message === 'string' ? data.message : 'Đã xảy ra lỗi.';
}

async function submit(): Promise<void> {
    formErrors.value = {};

    try {
        await http.put(managedDevices.update.url({ device: props.device.id }), form.value);
        toast.success('Đã cập nhật device.');
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
        <Head title="Sửa device" />
        <Card>
            <CardHeader>
                <CardTitle>Sửa device</CardTitle>
                <CardDescription>Chỉ chỉnh PG/Baca pass, pin và video id</CardDescription>
            </CardHeader>
            <CardContent>
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label>Tên thiết bị</Label>
                        <Input :model-value="device.name" readonly />
                    </div>
                    <div class="grid gap-2">
                        <Label>Dwin key</Label>
                        <Input :model-value="device.duo_api_key" readonly />
                    </div>
                    <div class="grid gap-2">
                        <Label>Image ID</Label>
                        <Input :model-value="device.image_id" readonly />
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
                    <DuoPlusMp4Select v-model="form.pg_video_id" label="PG Video (mp4)" :duo-api-key="props.device.duo_api_key" />
                    <DuoPlusMp4Select v-model="form.baca_video_id" label="Baca Video (mp4)" :duo-api-key="props.device.duo_api_key" />
                    <div class="sm:col-span-2 flex gap-2 justify-end">
                        <Button type="button" variant="secondary" @click="router.visit(deviceManagement.index())">Hủy</Button>
                        <Button type="submit">Lưu</Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
