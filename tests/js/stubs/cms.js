import { computed } from 'vue';

/**
 * `@statamic/cms`: only the fieldtype composable, in the shape core gives it.
 * The real `use()` returns more; the section reads `expose` and `isReadOnly`.
 */
export const Fieldtype = {
    emits: ['update:value', 'update:meta', 'focus', 'blur', 'replicator-preview-updated'],
    props: {
        value: { required: false, default: null },
        config: { type: Object, default: () => ({}) },
        handle: { type: String, default: 'zugaenge' },
        meta: { type: Object, default: () => ({}) },
        readOnly: { type: Boolean, default: false },
        id: { type: String, default: null },
    },
    use(emit, props) {
        return {
            expose: { handle: 'zugaenge' },
            isReadOnly: computed(() => props.readOnly || props.config?.visibility === 'read_only'),
        };
    },
};
