import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}
export function formatInt(n?: number | null): string {
    if (!n) return '0';
    return n.toLocaleString('vi-VN');
}

export function formatMoney(raw?: string | null): string {
    if (!raw) return '0 VND';
    const n = Number(raw);
    if (!Number.isFinite(n)) return '0 VND';
    return n.toLocaleString('vi-VN') + ' VND';
}
