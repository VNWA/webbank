declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent;
    export default component;
}

declare module 'vue3-easy-data-table' {
    import type { DefineComponent } from 'vue';

    const Vue3EasyDataTable: DefineComponent<
        Record<string, unknown>,
        Record<string, unknown>,
        unknown
    >;

    export default Vue3EasyDataTable;
}
