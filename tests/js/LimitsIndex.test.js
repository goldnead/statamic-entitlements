import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LimitsIndex from '../../resources/js/pages/Limits/Index.vue';

const base = {
    rows: [
        { id: 'chor', product_slug: 'chor', limits: [{ key: 'analyses', label: 'Analysen', text: '50 je Jahr' }], grants: 3, edit_url: '/cp/entitlements/limits/chor/edit' },
    ],
    columns: [{ field: 'product_slug' }, { field: 'limits' }, { field: 'grants' }],
    createUrl: '/cp/entitlements/limits/create',
    canManage: true,
    fallbackProduct: null,
};

describe('the limits overview', () => {
    it('feeds the listing in client mode with a saved-views prefix', () => {
        const wrapper = mount(LimitsIndex, { props: base });
        const listing = wrapper.findComponent({ name: 'Listing' });

        expect(listing.props('items')).toHaveLength(1);
        expect(listing.props('preferencesPrefix')).toBe('entitlements-limits');
    });

    it('offers creating only with the permission', () => {
        expect(mount(LimitsIndex, { props: base }).html()).toContain('/cp/entitlements/limits/create');
        expect(mount(LimitsIndex, { props: { ...base, canManage: false } }).html()).not.toContain('/cp/entitlements/limits/create');
    });

    it('shows the empty state when no product has limits or grants', () => {
        const wrapper = mount(LimitsIndex, { props: { ...base, rows: [] } });

        expect(wrapper.find('[data-stub="EmptyStateMenu"]').exists()).toBe(true);
        expect(wrapper.findComponent({ name: 'Listing' }).exists()).toBe(false);
    });
});
