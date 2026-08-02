<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    ButtonGroup,
    Card,
    CommandPaletteItem,
    ConfirmationModal,
    Description,
    DocsCallout,
    Header,
    Heading,
    Panel,
    PanelHeader,
} from '@statamic/cms/ui';

const props = defineProps({
    entitlement: { type: Object, required: true },
    timeline: { type: Array, default: () => [] },
    indexUrl: { type: String, required: true },
    revokeUrl: { type: String, required: true },
    restoreUrl: { type: String, required: true },
    canRevoke: { type: Boolean, default: false },
    canRestore: { type: Boolean, default: false },
});

const restoring = ref(false);

// Every mutation goes through the Inertia router, never axios: the router owns
// the progress bar, the flash toast, the dirty-state guard and back-button
// behaviour, and a bare axios call silently opts out of all four.
function confirmRestore() {
    router.post(props.restoreUrl, {}, { preserveScroll: true });
    restoring.value = false;
}

const badgeColour = {
    active: 'green',
    grace_period: 'amber',
    scheduled: 'blue',
    pending: 'purple',
    expired: 'gray',
    revoked: 'red',
};
</script>

<template>
    <Head :title="[entitlement.product_slug, __('entitlements::cp.title')]" />

    <!-- Core's narrow variant for detail screens. data-max-width-wrapper keeps the
         header's own full-width toggle working; a bare max-w-* ignores it. -->
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="entitlement.product_slug" icon="key">
            <ButtonGroup role="group" :aria-label="__('entitlements::cp.grant_details')">
                <Button
                    :href="indexUrl"
                    :text="__('entitlements::cp.back_to_entitlements')"
                    variant="ghost"
                />

                <!-- Revocation is a form, not a button: the reason is mandatory and
                     a confirmation dialog has nowhere to put one. -->
                <CommandPaletteItem
                    v-if="canRevoke"
                    category="Actions"
                    :text="__('entitlements::cp.revoke')"
                    icon="key"
                    :url="revokeUrl"
                    prioritize
                    v-slot="{ text, url }"
                >
                    <Button :href="url" :text="text" variant="danger" />
                </CommandPaletteItem>

                <Button
                    v-if="canRestore"
                    :text="__('entitlements::cp.restore')"
                    variant="primary"
                    @click="restoring = true"
                />
            </ButtonGroup>
        </Header>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Panel class="lg:col-span-2 h-full flex flex-col">
                <PanelHeader class="flex items-center justify-between min-h-10">
                    <Heading>{{ __('entitlements::cp.grant_details') }}</Heading>

                    <!-- The state and what it means, side by side. The state name
                         alone is not enough: "grace period" reads like a warning
                         and grants access, "scheduled" reads like a promise and
                         does not. -->
                    <div class="flex items-center gap-2">
                        <Badge
                            size="sm"
                            pill
                            :color="badgeColour[entitlement.state] || 'gray'"
                            :text="entitlement.state_label"
                        />
                        <span class="text-xs" :class="entitlement.grants_access ? 'text-green-600' : 'text-gray-500'">
                            {{
                                entitlement.grants_access
                                    ? __('entitlements::cp.grants_access_yes')
                                    : __('entitlements::cp.grants_access_no')
                            }}
                        </span>
                    </div>
                </PanelHeader>

                <Card class="flex-1">
                    <dl class="divide-y divide-content-border">
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500">{{ __('entitlements::cp.subject') }}</dt>
                            <dd class="font-mono text-sm">{{ entitlement.subject_label }}</dd>
                        </div>
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500">{{ __('entitlements::cp.product') }}</dt>
                            <dd class="font-mono text-sm">{{ entitlement.product_slug }}</dd>
                        </div>
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500">{{ __('entitlements::cp.source') }}</dt>
                            <dd>{{ entitlement.source_label }}</dd>
                        </div>
                        <div v-if="entitlement.source_ref" class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500">{{ __('entitlements::cp.source_ref') }}</dt>
                            <dd class="font-mono text-sm">{{ entitlement.source_ref }}</dd>
                        </div>
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500">{{ __('entitlements::cp.stored_status') }}</dt>
                            <dd class="font-mono text-sm">{{ entitlement.status }}</dd>
                        </div>
                        <div v-if="entitlement.revoked_reason" class="py-3">
                            <dt class="text-gray-500">{{ __('entitlements::cp.revocation_reason') }}</dt>
                            <dd class="mt-1">{{ entitlement.revoked_reason }}</dd>
                        </div>
                    </dl>

                    <Description class="mt-4">
                        {{ __('entitlements::cp.stored_status_note') }}
                    </Description>
                </Card>
            </Panel>

            <Panel class="h-full flex flex-col">
                <PanelHeader class="flex items-center min-h-10">
                    <Heading>{{ __('entitlements::cp.timeline') }}</Heading>
                </PanelHeader>

                <Card class="flex-1">
                    <Description v-if="timeline.length === 0">
                        {{ __('entitlements::cp.no_dates') }}
                    </Description>

                    <ul v-else class="divide-y divide-content-border">
                        <li v-for="row in timeline" :key="row.label" class="py-3">
                            <div class="text-gray-500 text-xs uppercase tracking-wide">{{ row.label }}</div>
                            <!-- Always UTC and always labelled. Every date on this row
                                 decides access; a viewer-local rendering of one is a
                                 support conversation nobody can win. -->
                            <div class="font-mono text-sm whitespace-nowrap">{{ row.value }}</div>
                        </li>
                    </ul>
                </Card>
            </Panel>
        </div>

        <!-- Core's overlay, not a hand-built one: it participates in the portal
             stack, the esc-key binding stack and FocusScope trapping. -->
        <ConfirmationModal
            :open="restoring"
            :title="__('entitlements::cp.restore_confirm_title')"
            :body-text="__('entitlements::cp.restore_confirm_body')"
            :button-text="__('entitlements::cp.restore')"
            @update:open="restoring = $event"
            @confirm="confirmRestore"
        />

        <DocsCallout
            :topic="__('entitlements::cp.title')"
            url="https://github.com/goldnead/statamic-entitlements#readme"
        />
    </div>
</template>
