import { beforeEach, describe, expect, it } from 'vitest';
import { inertia } from './stubs/api.js';

beforeEach(() => {
    inertia.reset();
    globalThis.Statamic.bootCallbacks = [];
});

describe('the control panel entry point', () => {
    it('registers both pages under a prefixed name', async () => {
        await import('../../resources/js/cp.js');

        globalThis.Statamic.boot();

        // Addon pages are resolved after core's own, so an unprefixed name a core
        // page also uses would simply never win. The prefix keeps these two out
        // of that contest and out of every other addon's way.
        expect(Object.keys(inertia.pages).sort()).toEqual([
            'entitlements::Entitlements/Index',
            'entitlements::Entitlements/Show',
        ]);
    });
});
