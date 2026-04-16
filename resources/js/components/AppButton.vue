<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

type ButtonSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';
type ButtonVariant = 'solid' | 'outline' | 'soft' | 'ghost' | 'link';
type ButtonColor = 'primary' | 'neutral' | 'success' | 'warning' | 'error' | 'info';

const props = withDefaults(defineProps<{
    as?: string | Component;
    size?: ButtonSize;
    variant?: ButtonVariant;
    color?: ButtonColor;
    class?: string;
}>(), {
    as: 'button',
    size: 'md',
    variant: 'solid',
    color: 'primary',
    class: '',
});

const sizeClasses: Record<ButtonSize, string> = {
    xs: 'h-7 px-2.5 text-xs',
    sm: 'h-8 px-3 text-sm',
    md: 'h-9 px-4 text-sm',
    lg: 'h-10 px-5 text-base',
    xl: 'h-11 px-6 text-base',
};

const variantClasses: Record<ButtonColor, Record<ButtonVariant, string>> = {
    primary: {
        solid: 'bg-primary text-primary-foreground hover:bg-primary/90',
        outline: 'border border-primary/40 text-primary hover:bg-primary/10',
        soft: 'bg-primary/15 text-primary hover:bg-primary/20',
        ghost: 'text-primary hover:bg-primary/10',
        link: 'text-primary underline-offset-4 hover:underline',
    },
    neutral: {
        solid: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        outline: 'border border-border text-foreground hover:bg-accent',
        soft: 'bg-accent text-accent-foreground hover:bg-accent/80',
        ghost: 'text-foreground hover:bg-accent',
        link: 'text-foreground underline-offset-4 hover:underline',
    },
    success: {
        solid: 'bg-emerald-600 text-white hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-400',
        outline: 'border border-emerald-500/50 text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10',
        soft: 'bg-emerald-500/15 text-emerald-700 hover:bg-emerald-500/25 dark:text-emerald-300',
        ghost: 'text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-300',
        link: 'text-emerald-600 underline-offset-4 hover:underline dark:text-emerald-400',
    },
    warning: {
        solid: 'bg-amber-500 text-amber-950 hover:bg-amber-400',
        outline: 'border border-amber-400/60 text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-500/10',
        soft: 'bg-amber-400/20 text-amber-800 hover:bg-amber-400/30 dark:text-amber-200',
        ghost: 'text-amber-700 hover:bg-amber-400/15 dark:text-amber-300',
        link: 'text-amber-700 underline-offset-4 hover:underline dark:text-amber-300',
    },
    error: {
        solid: 'bg-destructive text-white hover:bg-destructive/90',
        outline: 'border border-destructive/50 text-destructive hover:bg-destructive/10',
        soft: 'bg-destructive/15 text-destructive hover:bg-destructive/20',
        ghost: 'text-destructive hover:bg-destructive/10',
        link: 'text-destructive underline-offset-4 hover:underline',
    },
    info: {
        solid: 'bg-sky-600 text-white hover:bg-sky-700 dark:bg-sky-500 dark:hover:bg-sky-400',
        outline: 'border border-sky-500/50 text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-500/10',
        soft: 'bg-sky-500/15 text-sky-700 hover:bg-sky-500/25 dark:text-sky-300',
        ghost: 'text-sky-700 hover:bg-sky-500/10 dark:text-sky-300',
        link: 'text-sky-700 underline-offset-4 hover:underline dark:text-sky-300',
    },
};

const classes = computed(() =>
    cn(
        'inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition disabled:pointer-events-none disabled:opacity-50',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
        sizeClasses[props.size],
        variantClasses[props.color][props.variant],
        props.class,
    ),
);
</script>

<template>
    <component :is="as" :class="classes" v-bind="$attrs">
        <slot />
    </component>
</template>
