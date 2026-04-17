import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        // SSR: dev dùng endpoint `/__inertia_ssr` trên Vite; production cần `npm run build:ssr` và `php artisan inertia:start-ssr`.
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
    // `lightningcss minify` đang fail với CSS của `vue3-easy-data-table` (var(--*) bị parse lỗi).
    build: {
        // Tắt minify CSS để build production không vỡ do parser lightningcss/esbuild.
        cssMinify: false,
    },
});
