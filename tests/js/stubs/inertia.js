import { h } from 'vue';

/**
 * The Control Panel's Inertia re-exports. `router` records instead of
 * navigating: every mutation in these pages must go through it — a bare axios
 * call silently opts out of the progress bar, the flash toast, the dirty-state
 * guard and back-button behaviour — so recording the calls is exactly what a
 * test here needs to see.
 */
export const router = {
    calls: [],
    reload(options = {}) {
        this.calls.push({ method: 'reload', options });
    },
    get(url, options = {}) {
        this.calls.push({ method: 'get', url, options });
    },
    post(url, data = {}, options = {}) {
        this.calls.push({ method: 'post', url, data, options });
    },
    delete(url, options = {}) {
        this.calls.push({ method: 'delete', url, options });
    },
    reset() {
        this.calls = [];
    },
};

export const Head = {
    name: 'Head',
    props: ['title'],
    setup() {
        return () => null;
    },
};

export const Link = {
    name: 'Link',
    props: ['href'],
    setup(props, { slots, attrs }) {
        return () => h('a', { 'data-stub': 'Link', href: props.href, ...attrs }, slots.default?.());
    },
};
