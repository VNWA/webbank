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
export function formatInt(n: number): string {
    return n.toLocaleString('vi-VN');
}

export function formatMoney(raw: string): string {
    const n = Number(raw);
    if (!Number.isFinite(n)) return raw;
    return n.toLocaleString('vi-VN') + ' VND';
}
