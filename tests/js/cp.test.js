import { beforeEach, describe, expect, it } from 'vitest';
import { components, inertia } from './stubs/api.js';

beforeEach(() => {
    inertia.reset();
    globalThis.Statamic.bootCallbacks = [];
});

describe('the control panel entry point', () => {
    it('registers every page under a prefixed name', async () => {
        await import('../../resources/js/cp.js');

        globalThis.Statamic.boot();

        // Addon pages are resolved after core's own, so an unprefixed name a core
        // page also uses would simply never win. The prefix keeps these out
        // of that contest and out of every other addon's way.
        expect(Object.keys(inertia.pages).sort()).toEqual([
            'entitlements::Entitlements/Index',
            'entitlements::Entitlements/Show',
            'entitlements::Limits/Index',
            'entitlements::SetupRequired',
            'entitlements::Wiring',
        ]);

        // Core resolves `{type}-fieldtype`. Anything else is the red "Component
        // … does not exist" the old user page showed.
        expect(Object.keys(components.registered)).toContain('user_entitlements-fieldtype');
    });
});
