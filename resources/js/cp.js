import { inertia } from '@statamic/cms/api';

import Index from './pages/Entitlements/Index.vue';
import Show from './pages/Entitlements/Show.vue';

/*
 * Addon Inertia pages are resolved by name after core's own pages have been
 * tried (bootstrap/statamic.js), so a core page name always wins. The
 * `entitlements::` prefix keeps these two out of that contest entirely and out of
 * every other addon's way.
 *
 * Registration happens inside `Statamic.booting` because `inertia` is part of
 * the CP runtime this bundle is externalised against — it does not exist until
 * the Control Panel has started booting.
 */
Statamic.booting(() => {
    inertia.register('entitlements::Entitlements/Index', Index);
    inertia.register('entitlements::Entitlements/Show', Show);
});
