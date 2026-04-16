<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import http from '@/lib/axios';
import managedDevices from '@/routes/api/managed-devices';

type FileOption = { id: string; name: string; original_file_name?: string };
type Pagination = { page: number; pagesize: number; total: number; total_page: number; has_more: boolean };
type DuoPlusFilesResponse = { files?: FileOption[]; pagination?: Pagination };

const props = defineProps<{
    label: string;
    duoApiKey: string;
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const loading = ref(false);
const keyword = ref('');
const options = ref<FileOption[]>([]);
const pagination = ref<Pagination>({ page: 0, pagesize: 100, total: 0, total_page: 0, has_more: false });

const selectedName = computed(() => options.value.find((item) => item.id === props.modelValue)?.name ?? '');

async function loadPage(page: number, append: boolean): Promise<void> {
    if (!props.duoApiKey || loading.value) {
        return;
    }

    loading.value = true;

    try {
        const { data } = await http.post(managedDevices.duoplusFiles.url(), {
            duo_api_key: props.duoApiKey,
            keyword: keyword.value.trim(),
            page,
            pagesize: pagination.value.pagesize,
        });
        const payload = data as DuoPlusFilesResponse;
        const files = Array.isArray(payload.files) ? payload.files : [];
        options.value = append ? [...options.value, ...files] : files;
        pagination.value = payload.pagination ?? { page, pagesize: pagination.value.pagesize, total: files.length, total_page: page, has_more: false };
    } finally {
        loading.value = false;
    }
}

function onSearch(): void {
    void loadPage(1, false);
}

function loadMore(): void {
    if (!pagination.value.has_more) {
        return;
    }

    void loadPage(pagination.value.page + 1, true);
}

watch(
    () => props.duoApiKey,
    (next) => {
        if (next) {
            void loadPage(1, false);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-2">
        <Label>{{ label }}</Label>
        <div class="rounded-md border border-border/70 p-3">
            <div class="mb-2 flex gap-2">
                <Input v-model="keyword" placeholder="Tìm file mp4..." @keydown.enter.prevent="onSearch" />
                <Button type="button" variant="secondary" :disabled="loading" @click="onSearch">Tìm</Button>
            </div>

            <select class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm" :value="modelValue"
                @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)">
                <option value="">-- Chọn file mp4 --</option>
                <option v-for="file in options" :key="file.id" :value="file.id">{{ file.name }}</option>
            </select>

            <p v-if="selectedName" class="mt-1 text-xs text-muted-foreground">Đã chọn: {{ selectedName }}</p>

            <div class="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                <span>Trang {{ pagination.page || 1 }} / {{ pagination.total_page || 1 }}</span>
                <Button type="button" size="sm" variant="outline" :disabled="loading || !pagination.has_more"
                    @click="loadMore">
                    {{ loading ? 'Đang tải...' : 'Tải thêm' }}
                </Button>
            </div>
        </div>
    </div>
</template>
