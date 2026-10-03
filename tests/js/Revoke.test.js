import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Revoke from '../../resources/js/pages/Entitlements/Revoke.vue';
import { router } from './stubs/inertia.js';

const props = {
    title: 'Zugang Kurs für Clara Voss entziehen',
    submitUrl: '/cp/entitlements/7/revoke',
    cancelUrl: '/cp/entitlements/7',
};

let http;

function mountPage() {
    http = { post: vi.fn().mockResolvedValue({ data: { saved: true, redirect: '/cp/entitlements/7' } }) };

    return mount(Revoke, {
        props,
        global: { config: { globalProperties: { __: globalThis.__, $axios: http } } },
    });
}

beforeEach(() => router.reset());

describe('the revocation page', () => {
    it('names the action on its button, not "Save"', () => {
        const button = mountPage().find('[data-role="submit"]');

        expect(button.text()).toBe('entitlements::cp.revoke');
    });

    it('stays disabled until a reason is given', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-role="submit"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-stub="Textarea"]').setValue('Erstattet');

        expect(wrapper.find('[data-role="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('posts the reason and follows the redirect', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-stub="Textarea"]').setValue('Erstattet');
        await wrapper.find('[data-role="submit"]').trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/cp/entitlements/7/revoke', { reason: 'Erstattet' });
        expect(router.calls).toContainEqual({ method: 'visit', url: '/cp/entitlements/7', options: {} });
    });

    it('shows the server\'s refusal on the field', async () => {
        const wrapper = mountPage();
        http.post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { reason: ['Pflichtfeld.'] } } } });

        await wrapper.find('[data-stub="Textarea"]').setValue(' x');
        await wrapper.find('[data-role="submit"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Pflichtfeld.');
    });
});
