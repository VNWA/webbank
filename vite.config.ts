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
        // Tắt bundle SSR: chỉ bật (và `inertia:start-ssr`) khi thật sự cần render phía Node — tránh lỗi origin khi F5 nếu không chạy SSR.
        inertia({
            ssr: false,
        }),
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
