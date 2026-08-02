/**
 * The Control Panel's extension registry. `cp.js` registers its two Inertia
 * pages through it; the stub records so the registration itself can be asserted
 * — a page that is never registered renders as a blank Control Panel screen with
 * no error anywhere.
 */
export const inertia = {
    pages: {},
    register(name, component) {
        this.pages[name] = component;
    },
    get(name) {
        return this.pages[name];
    },
    reset() {
        this.pages = {};
    },
};
