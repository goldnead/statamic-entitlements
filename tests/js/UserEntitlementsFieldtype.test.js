import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import UserEntitlementsFieldtype from '../../resources/js/fieldtypes/UserEntitlementsFieldtype.vue';

const rowsUrl = '/cp/entitlements/users/u-1';
const storeUrl = '/cp/entitlements';

const answer = (overrides = {}) => ({
    subject: 'user:u-1',
    rows: [
        {
            id: 7,
            product_slug: 'kurs',
            product_label: 'Der Kurs',
            product_unknown: false,
            state: 'active',
            state_label: 'Active',
            grants_access: true,
            source: 'Manual grant',
            starts_at: null,
            expires_at: '2027-01-01T00:00:00Z',
            show_url: '/cp/entitlements/7',
            revoke_url: '/cp/entitlements/7/revoke',
            can_revoke: true,
        },
    ],
    canGrant: true,
    canRevoke: true,
    products: [
        { value: 'kurs', label: 'Der Kurs' },
        { value: 'masterclass', label: 'Masterclass' },
    ],
    indexUrl: '/cp/entitlements',
    ...overrides,
});

let http;

function mountSection(meta = {}, data = answer()) {
    http = {
        get: vi.fn().mockResolvedValue({ data }),
        post: vi.fn().mockResolvedValue({ data: { saved: true } }),
    };

    return mount(UserEntitlementsFieldtype, {
        props: {
            value: null,
            handle: 'zugaenge',
            meta: { userId: 'u-1', canView: true, rowsUrl, storeUrl, ...meta },
        },
        global: {
            config: { globalProperties: { __: globalThis.__, $axios: http } },
            directives: { tooltip: {} },
        },
    });
}

beforeEach(() => {
    globalThis.Statamic.$toast = { success: vi.fn(), error: vi.fn() };
});

describe('the Zugänge section on a user page', () => {
    it('lists her grants by name with state, source and dates', async () => {
        const wrapper = mountSection();
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith(rowsUrl);
        const row = wrapper.find('[data-grant="7"]');
        expect(row.text()).toContain('Der Kurs');
        expect(row.text()).toContain('kurs');
        expect(row.text()).toContain('Active');
        expect(row.text()).toContain('Manual grant');
        // Formatted like core, in the viewer's locale, not a monospaced UTC stamp.
        expect(row.text()).toContain('formatted(2027-01-01T00:00:00Z,datetime)');
        expect(row.find('a').attributes('href')).toBe('/cp/entitlements/7');
    });

    it('asks to save first on a user that does not exist yet', () => {
        const wrapper = mountSection({ userId: null, rowsUrl: null });

        expect(wrapper.text()).toContain('entitlements::cp.user_section_save_first');
        expect(http.get).not.toHaveBeenCalled();
    });

    it('reads nothing without the view permission', () => {
        const wrapper = mountSection({ canView: false, rowsUrl: null });

        expect(wrapper.text()).toContain('entitlements::cp.user_section_no_permission');
        expect(http.get).not.toHaveBeenCalled();
    });

    it('grants with the exact body of the manual grant form, then reloads', async () => {
        const wrapper = mountSection();
        await flushPromises();

        const button = wrapper.findAll('[data-stub="Button"]').find((b) => b.text() === 'entitlements::cp.user_grant_action');
        await button.trigger('click');

        const picker = wrapper.find('[data-stub="Combobox"]');
        expect(JSON.parse(picker.attributes('data-options')).map((o) => o.value)).toEqual(['kurs', 'masterclass']);
        // Picking only: with a catalogue nothing can be typed in.
        expect(wrapper.findComponent({ name: 'Combobox' }).props('taggable')).toBeFalsy();
        await picker.setValue('kurs');

        const submit = wrapper.findAll('[data-stub="Button"]').find((b) => b.text() === 'entitlements::cp.user_grant_submit');
        await submit.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith(storeUrl, {
            subject_kind: 'user',
            subject_user: ['u-1'],
            product_slug: 'kurs',
            starts_at: null,
            expires_at: null,
        });
        expect(http.get).toHaveBeenCalledTimes(2);
        expect(globalThis.Statamic.$toast.success).toHaveBeenCalled();
    });

    it('shows the server\'s validation error on the product field', async () => {
        const wrapper = mountSection();
        await flushPromises();

        http.post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { product_slug: ['Pflichtfeld.'] } } } });

        await wrapper.findAll('[data-stub="Button"]').find((b) => b.text() === 'entitlements::cp.user_grant_action').trigger('click');
        await wrapper.find('[data-stub="Combobox"]').setValue('x');
        await wrapper.findAll('[data-stub="Button"]').find((b) => b.text() === 'entitlements::cp.user_grant_submit').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-stub="Stack"]').text()).toContain('Pflichtfeld.');
    });

    it('offers no grant button without the grant permission', async () => {
        const wrapper = mountSection({}, answer({ canGrant: false, products: null }));
        await flushPromises();

        expect(wrapper.text()).not.toContain('entitlements::cp.user_grant_action');
    });

    it('revokes only with a reason, posted to the revocation', async () => {
        const wrapper = mountSection();
        await flushPromises();

        const revoke = wrapper.findAll('[data-stub="DropdownItem"]').find((i) => i.text() === 'entitlements::cp.user_revoke_action');
        await revoke.trigger('click');

        const confirm = wrapper.find('[data-role="confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.find('[data-stub="Textarea"]').setValue('Erstattet');
        expect(wrapper.find('[data-role="confirm"]').attributes('disabled')).toBeUndefined();

        await wrapper.find('[data-role="confirm"]').trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/cp/entitlements/7/revoke', { reason: 'Erstattet' });
        expect(http.get).toHaveBeenCalledTimes(2);
    });

    it('offers neither action when the field is read-only', async () => {
        http = {
            get: vi.fn().mockResolvedValue({ data: answer() }),
            post: vi.fn(),
        };

        const wrapper = mount(UserEntitlementsFieldtype, {
            props: {
                value: null,
                handle: 'zugaenge',
                readOnly: true,
                meta: { userId: 'u-1', canView: true, rowsUrl, storeUrl },
            },
            global: {
                config: { globalProperties: { __: globalThis.__, $axios: http } },
                directives: { tooltip: {} },
            },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Der Kurs');
        expect(wrapper.text()).not.toContain('entitlements::cp.user_grant_action');
        expect(wrapper.text()).not.toContain('entitlements::cp.user_revoke_action');
    });

    it('offers no revoke where the server says it may not', async () => {
        const base = answer();
        const wrapper = mountSection({}, answer({ rows: [{ ...base.rows[0], can_revoke: false }] }));
        await flushPromises();

        expect(wrapper.text()).not.toContain('entitlements::cp.user_revoke_action');
    });
});
