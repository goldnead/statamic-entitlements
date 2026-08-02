import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../../resources/js/pages/Entitlements/Index.vue';
import { router } from './stubs/inertia.js';

/*
 * The two Control Panel pages are the only surface no PHP test can reach, so
 * their own decisions are tested here: what the listing is fed, what the empty
 * state offers, and whether a user without `grant entitlements` is shown a
 * button they cannot use.
 */

const props = {
    initialColumns: [{ field: 'product_slug', label: 'Product' }],
    filters: [{ handle: 'entitlements_state' }],
    hasAny: true,
    listingUrl: '/cp/entitlements',
    createUrl: '/cp/entitlements/create',
    canGrant: true,
    perPage: 50,
};

beforeEach(() => router.reset());

describe('the entitlements index', () => {
    it('feeds the listing the props that decide whether it is a real listing', () => {
        const listing = mount(Index, { props }).findComponent({ name: 'Listing' });

        expect(listing.exists()).toBe(true);
        expect(listing.props('url')).toBe('/cp/entitlements');
        // No preferences-prefix means no saved views and no persisted columns —
        // the difference between a table and the Entries screen.
        expect(listing.props('preferencesPrefix')).toBe('entitlements');
        expect(listing.props('filters')).toHaveLength(1);
        expect(listing.props('perPage')).toBe(50);
        // Server mode. `items` would silently switch it to client mode, which has
        // no pagination and would resolve the state filter in the browser — over
        // every grant on the site.
        expect(listing.props('items')).toBeUndefined();
    });

    it('offers no bulk actions, because there is no action controller behind them', () => {
        // actionUrl is the hard gate for checkboxes and the bulk toolbar. Passing
        // one without the two action routes behind it produces a toolbar that
        // does nothing — and the destructive action here is revocation, which
        // must not be reachable without its mandatory reason.
        const listing = mount(Index, { props }).findComponent({ name: 'Listing' });

        expect(listing.props('actionUrl')).toBeUndefined();
    });

    it('shows the empty state instead of a listing when nothing has been granted', () => {
        const wrapper = mount(Index, { props: { ...props, hasAny: false } });

        expect(wrapper.findComponent({ name: 'Listing' }).exists()).toBe(false);
        expect(wrapper.find('[data-stub="EmptyStateMenu"]').exists()).toBe(true);
        // Core's empty state is a centred h1, not <Header>.
        expect(wrapper.find('header h1').exists()).toBe(true);
    });

    it('hides the grant action from a user who may not grant', () => {
        const wrapper = mount(Index, { props: { ...props, canGrant: false } });

        expect(wrapper.find('[data-stub="CommandPaletteItem"]').exists()).toBe(false);
    });

    it('wraps the primary action in a command palette item, as every core page does', () => {
        const wrapper = mount(Index, { props });

        expect(wrapper.find('[data-stub="CommandPaletteItem"]').exists()).toBe(true);
    });

    it('reloads through the inertia router when the listing asks to refresh', () => {
        const listing = mount(Index, { props }).findComponent({ name: 'Listing' });

        listing.vm.$emit('refreshing');

        expect(router.calls).toEqual([{ method: 'reload', options: {} }]);
    });
});
