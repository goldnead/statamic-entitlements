import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '../../resources/js/pages/Entitlements/Show.vue';
import { router } from './stubs/inertia.js';

const entitlement = {
    id: 1,
    subject_type: 'user',
    subject_id: '42',
    subject_label: 'user:42',
    product_slug: 'course-a',
    source: 'mollie',
    source_label: 'Mollie',
    source_ref: 'tr_1',
    status: 'active',
    state: 'active',
    state_label: 'Active',
    grants_access: true,
    revoked_reason: null,
};

const props = {
    entitlement,
    timeline: [{ label: 'Created', value: '2026-08-03 10:00 UTC' }],
    indexUrl: '/cp/entitlements',
    revokeUrl: '/cp/entitlements/1/revoke',
    restoreUrl: '/cp/entitlements/1/restore',
    canRevoke: true,
    canRestore: false,
};

beforeEach(() => router.reset());

describe('the entitlement detail screen', () => {
    it('says whether the grant lets somebody in, not only what it is called', () => {
        // "Grace period" reads like a warning and grants access; "scheduled"
        // reads like a promise and does not. The state name on its own is not an
        // answer to the question somebody opened this screen with.
        const wrapper = mount(Show, { props });

        expect(wrapper.text()).toContain('Active');
        expect(wrapper.text()).toContain('entitlements::cp.grants_access_yes');
    });

    it('says so when the grant grants nothing', () => {
        const wrapper = mount(Show, {
            props: {
                ...props,
                entitlement: { ...entitlement, state: 'scheduled', state_label: 'Scheduled', grants_access: false },
            },
        });

        expect(wrapper.text()).toContain('entitlements::cp.grants_access_no');
    });

    it('sends revocation to a form rather than a confirmation dialog', () => {
        // The reason is mandatory, and a confirmation dialog has nowhere to put
        // one. The revoke action is a link to a publish form.
        const wrapper = mount(Show, { props });
        const revoke = wrapper
            .findAll('a')
            .find((link) => link.attributes('href') === '/cp/entitlements/1/revoke');

        expect(revoke).toBeDefined();
        // `destructive`, not `danger`: core reserves `danger` for the confirm
        // button inside a modal, so a destructive page action is a
        // `DropdownItem variant="destructive"` in the header's "…" menu.
        expect(revoke.attributes('data-variant')).toBe('destructive');
    });

    it('offers no revoke action to a user who may not revoke', () => {
        const wrapper = mount(Show, { props: { ...props, canRevoke: false } });

        expect(
            wrapper.findAll('a').some((link) => link.attributes('href') === '/cp/entitlements/1/revoke')
        ).toBe(false);
    });

    it('confirms a restore through core\'s overlay and posts through the inertia router', async () => {
        const wrapper = mount(Show, {
            props: {
                ...props,
                canRevoke: false,
                canRestore: true,
                entitlement: { ...entitlement, state: 'revoked', state_label: 'Revoked', grants_access: false },
            },
        });

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(false);

        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('entitlements::cp.restore'))
            .trigger('click');

        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(true);

        await wrapper.find('[data-role="confirm"]').trigger('click');

        // Never axios: the Inertia router owns the progress bar, the flash toast,
        // the dirty-state guard and back-button behaviour.
        expect(router.calls).toEqual([
            { method: 'post', url: '/cp/entitlements/1/restore', data: {}, options: { preserveScroll: true } },
        ]);
    });

    it('shows the revocation reason once there is one', () => {
        const wrapper = mount(Show, {
            props: {
                ...props,
                entitlement: { ...entitlement, state: 'revoked', grants_access: false, revoked_reason: 'Chargeback' },
            },
        });

        expect(wrapper.text()).toContain('Chargeback');
    });

    it('labels every timeline instant as UTC', () => {
        // Every date on this row decides access. A viewer-local rendering of one
        // is a support conversation nobody can win.
        const wrapper = mount(Show, { props });

        expect(wrapper.text()).toContain('2026-08-03 10:00 UTC');
    });

    describe('the limits panel', () => {
        const quota = {
            key: 'analyses',
            label: 'Analysen',
            kind: 'usage',
            limit: 50,
            unlimited: false,
            used: 50,
            remaining: 0,
            source: 'grant',
            product: 'chor',
            period: 'year',
            period_end: '2027-03-14 00:00 UTC',
            holder: 'team:7',
            holder_label: 'Chor Nord',
            held_elsewhere: true,
            can_reset: true,
            reset: { subject_type: 'team', subject_id: '7', key: 'analyses' },
        };

        const withQuotas = { ...props, quotas: [quota], limitsUrl: '/cp/entitlements/limits', resetUrl: '/cp/entitlements/usage/reset' };

        it('shows what is left and where it is counted', () => {
            const wrapper = mount(Show, { props: withQuotas });
            const row = wrapper.find('[data-key="analyses"]');

            expect(row.text()).toContain('Analysen');
            expect(row.text()).toContain('50');
            expect(row.text()).toContain('2027-03-14 00:00 UTC');
            expect(row.text()).toContain('entitlements::cp.quota_held_by');
        });

        it('resets through the Inertia router after a confirmation, at the holder', async () => {
            const wrapper = mount(Show, { props: withQuotas });

            await wrapper.find('[data-key="analyses"] button').trigger('click');
            expect(router.calls).toHaveLength(0);

            await wrapper.find('[data-stub="ConfirmationModal"] [data-role="confirm"]').trigger('click');

            expect(router.calls).toHaveLength(1);
            expect(router.calls[0]).toMatchObject({
                method: 'post',
                url: '/cp/entitlements/usage/reset',
                data: { subject_type: 'team', subject_id: '7', key: 'analyses' },
            });
        });

        it('says "counted by the app" only for a stock limit that applies', () => {
            const none = { ...quota, key: 'exports', label: 'exports', kind: 'stock', used: null, source: 'none', limit: 0, can_reset: false };
            const stock = { ...quota, key: 'arrangements', label: 'arrangements', kind: 'stock', used: null, source: 'grant', can_reset: false };
            const wrapper = mount(Show, { props: { ...withQuotas, quotas: [none, stock] } });

            expect(wrapper.find('[data-key="exports"]').text()).not.toContain('entitlements::cp.quota_stock_uncounted');
            expect(wrapper.find('[data-key="arrangements"]').text()).toContain('entitlements::cp.quota_stock_uncounted');
        });

        it('shows the stored status in words', () => {
            const wrapper = mount(Show, { props: { ...withQuotas, entitlement: { ...entitlement, status_label: 'Aktiv' } } });

            expect(wrapper.text()).toContain('Aktiv');
        });

        it('offers no reset without the permission', () => {
            const wrapper = mount(Show, { props: { ...withQuotas, quotas: [{ ...quota, can_reset: false }] } });

            expect(wrapper.find('[data-key="analyses"] button').exists()).toBe(false);
        });
    });
});
