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
        expect(revoke.attributes('data-variant')).toBe('danger');
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
});
