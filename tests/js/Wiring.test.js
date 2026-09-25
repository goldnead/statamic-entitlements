import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Wiring from '../../resources/js/pages/Wiring.vue';

const event = (handle, extra = {}) => ({
    handle,
    label: handle,
    description: '',
    class: 'X',
    mail: null,
    automations_owner: 'entitlements',
    flows: 0,
    hooks: 0,
    flows_text: 'keine',
    hooks_text: 'keine',
    ...extra,
});

const base = {
    events: [
        event('entitlements.granted', { automations_owner: 'automations' }),
        event('entitlements.limit_reached', {
            mail: { slug: 'entitlements-limit-reached', enabled: true, source: 'default', edit_url: null },
            flows: 2,
            flows_text: '2 hören zu',
            hooks: 1,
            hooks_text: '1 hört zu',
        }),
    ],
    automations: { installed: true, enabled: true, url: '/cp/automations' },
    webhooks: { installed: true, enabled: true, url: '/cp/webhook-manager/outbound', catalogue_url: '/cp/webhook-manager/debug' },
    emailTemplates: { installed: true, url: '/cp/collections/et_templates/entries' },
    settingsUrl: '/cp/brand-settings',
};

describe('the wiring screen', () => {
    it('lists every event with its mail and who listens', () => {
        const wrapper = mount(Wiring, { props: base });
        const row = wrapper.find('[data-handle="entitlements.limit_reached"]');

        expect(row.text()).toContain('entitlements-limit-reached');
        expect(row.text()).toContain('entitlements::cp.wiring_mail_default');
        expect(row.text()).toContain('2 hören zu');
        expect(row.text()).toContain('1 hört zu');
        // Not yet an entry: the import command is named.
        expect(row.text()).toContain('entitlements::cp.wiring_mail_import');
    });

    it('says where a grant trigger comes from', () => {
        const wrapper = mount(Wiring, { props: base });

        expect(wrapper.find('[data-handle="entitlements.granted"]').text()).toContain('entitlements::cp.wiring_by_automations');
    });

    it('links to the Webhook Manager\'s own trigger catalogue instead of copying it', () => {
        const wrapper = mount(Wiring, { props: base });

        expect(wrapper.findAll('a').map((a) => a.attributes('href'))).toContain('/cp/webhook-manager/debug');
    });

    it('says a sibling is missing rather than showing a zero', () => {
        const wrapper = mount(Wiring, {
            props: { ...base, automations: { installed: false, enabled: false, url: null }, webhooks: { installed: false, enabled: false, url: null } },
        });
        const row = wrapper.find('[data-handle="entitlements.limit_reached"]');

        expect(row.text()).toContain('entitlements::cp.wiring_not_installed');
        expect(row.text()).not.toContain('hören zu');
    });
});
