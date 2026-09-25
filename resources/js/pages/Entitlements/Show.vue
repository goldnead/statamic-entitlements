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
    Dropdown,
    DropdownItem,
    DropdownMenu,
    Header,
    Heading,
    Panel,
    PanelHeader,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
} from '@statamic/cms/ui';

const props = defineProps({
    entitlement: { type: Object, required: true },
    timeline: { type: Array, default: () => [] },
    indexUrl: { type: String, required: true },
    revokeUrl: { type: String, required: true },
    restoreUrl: { type: String, required: true },
    canRevoke: { type: Boolean, default: false },
    canRestore: { type: Boolean, default: false },
    quotas: { type: Array, default: () => [] },
    limitsUrl: { type: String, default: null },
    resetUrl: { type: String, default: null },
});

const restoring = ref(false);

// The counter somebody asked to reset, while the confirmation is open.
const resetting = ref(null);

function confirmReset() {
    router.post(props.resetUrl, { ...resetting.value.reset, redirect: window.location.href }, { preserveScroll: true });
    resetting.value = null;
}

function quotaLimit(quota) {
    if (quota.source === 'none') return __('entitlements::cp.quota_none');
    if (quota.unlimited) return __('entitlements::cp.limits_unlimited');

    return String(quota.limit);
}

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
                    <!-- In the "…" menu rather than carrying `variant="danger"`,
                         which core reserves for the confirm button inside a
                         modal. Dropdown renders its own dots trigger. -->
                    <Dropdown>
                        <DropdownMenu>
                            <DropdownItem :href="url" :text="text" icon="key" variant="destructive" />
                        </DropdownMenu>
                    </Dropdown>
                </CommandPaletteItem>

                <Button
                    v-if="canRestore"
                    :text="__('entitlements::cp.restore')"
                    variant="primary"
                    @click="restoring = true"
                />
            </ButtonGroup>
        </Header>

        <!--
            The single-column grid utility is deliberately absent: a grid falls back
            to one column on its own, and every addon shipping a Tailwind build emits
            that same bare, breakpoint-less rule into the shared `addon-utilities`
            layer. Media queries add no specificity, so the stylesheet that loads last
            wins against this element's `lg:` variant. Do not name the class in a
            comment either — Tailwind scans comments as candidates and would emit it.
            `min-w-0` per panel rather than `*:min-w-0` on the container: this addon
            ships no stylesheet of its own, so only utilities that Statamic core
            already emits actually exist. Core has `min-w-0`; it has no child variant.
        -->
        <div class="grid lg:grid-cols-3 gap-6">
            <Panel class="min-w-0 lg:col-span-2 h-full flex flex-col">
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
                            <dd class="text-sm">
                                {{ entitlement.status_label || entitlement.status }}
                                <code v-if="entitlement.status_label && entitlement.status_label !== entitlement.status" class="text-2xs text-gray-600 dark:text-gray-400 ms-1">{{ entitlement.status }}</code>
                            </dd>
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

            <Panel class="min-w-0 h-full flex flex-col">
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

        <!-- The subject's limits as they apply now, across all its grants and the
             subjects it acts for, because "how many are left" is the question. -->
        <Panel v-if="quotas.length || limitsUrl" class="mt-6" data-panel="quotas">
            <PanelHeader class="flex items-center justify-between min-h-10">
                <Heading>{{ __('entitlements::cp.quotas') }}</Heading>
                <Button v-if="limitsUrl" size="sm" variant="ghost" :href="limitsUrl" :text="__('entitlements::cp.limits_title')" />
            </PanelHeader>

            <Card v-if="quotas.length === 0">
                <Description>{{ __('entitlements::cp.quotas_none') }}</Description>
            </Card>

            <Table v-else>
                <TableColumns>
                    <TableColumn>{{ __('entitlements::cp.limits_col_key') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.quota_col_limit') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.quota_col_used') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.quota_col_remaining') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.quota_col_until') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.quota_col_from') }}</TableColumn>
                    <TableColumn />
                </TableColumns>
                <TableRows>
                    <TableRow v-for="quota in quotas" :key="quota.key" :data-key="quota.key">
                        <TableCell>
                            <div class="font-medium">{{ quota.label }}</div>
                            <code v-if="quota.label !== quota.key" class="text-2xs text-gray-600 dark:text-gray-400">{{ quota.key }}</code>
                        </TableCell>
                        <TableCell class="tabular-nums">{{ quotaLimit(quota) }}</TableCell>
                        <TableCell class="tabular-nums">
                            <span v-if="quota.used !== null">{{ quota.used }}</span>
                            <!-- Only a stock limit that applies is counted by the app;
                                 without access there is nothing anybody counts. -->
                            <span v-else-if="quota.kind === 'stock' && quota.source !== 'none'" class="text-xs text-gray-500 dark:text-gray-400">{{ __('entitlements::cp.quota_stock_uncounted') }}</span>
                            <span v-else class="text-gray-500 dark:text-gray-400">&mdash;</span>
                        </TableCell>
                        <TableCell class="tabular-nums">
                            <Badge
                                v-if="quota.remaining !== null && quota.source !== 'none'"
                                pill
                                :color="quota.remaining === 0 ? 'red' : 'green'"
                                :text="String(quota.remaining)"
                            />
                            <span v-else class="text-gray-500 dark:text-gray-400">&mdash;</span>
                        </TableCell>
                        <TableCell class="whitespace-nowrap font-mono text-xs">{{ quota.period_end || '—' }}</TableCell>
                        <TableCell>
                            <span v-if="quota.source === 'grant'" class="font-mono text-xs">{{ quota.product }}</span>
                            <span v-else-if="quota.source === 'fallback'" class="text-xs">{{ __('entitlements::cp.quota_fallback') }}: <span class="font-mono">{{ quota.product }}</span></span>
                            <span v-else class="text-xs text-gray-500 dark:text-gray-400">&mdash;</span>
                            <div v-if="quota.held_elsewhere" class="text-2xs text-gray-600 dark:text-gray-400">
                                {{ __('entitlements::cp.quota_held_by', { holder: quota.holder_label }) }}
                            </div>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                v-if="quota.can_reset"
                                size="sm"
                                :text="__('entitlements::cp.usage_reset')"
                                @click="resetting = quota"
                            />
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>

        <ConfirmationModal
            :open="resetting !== null"
            :title="__('entitlements::cp.usage_reset_confirm_title')"
            :body-text="__('entitlements::cp.usage_reset_confirm_body')"
            :button-text="__('entitlements::cp.usage_reset')"
            @update:open="(open) => { if (!open) resetting = null; }"
            @confirm="confirmReset"
        />

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
