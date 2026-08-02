/**
 * The Control Panel globals the pages read at runtime. In the browser they come
 * from the CP bundle; here they are the minimum that lets a component render.
 */
globalThis.__ = (key, replacements = {}) =>
    Object.entries(replacements).reduce((carry, [k, v]) => carry.replace(`:${k}`, v), key);

globalThis.Statamic = {
    booting(callback) {
        this.bootCallbacks = this.bootCallbacks || [];
        this.bootCallbacks.push(callback);
    },
    boot() {
        (this.bootCallbacks || []).forEach((callback) => callback());
    },
    $config: { get: () => undefined },
};

/**
 * In the Control Panel `__()` is a global property on the Vue app, so templates
 * call it unqualified. Test Utils builds its own app, which has no such global —
 * every page here would fail to render on its first translated string.
 */
import { config } from '@vue/test-utils';

config.global.mocks = { __: globalThis.__ };
config.global.config = { globalProperties: { __: globalThis.__ } };
