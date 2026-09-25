<script setup>
import { Head } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    Card,
    Description,
    DocsCallout,
    Header,
    Panel,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
} from '@statamic/cms/ui';

defineProps({
    events: { type: Array, required: true },
    automations: { type: Object, required: true },
    webhooks: { type: Object, required: true },
    emailTemplates: { type: Object, required: true },
    settingsUrl: { type: String, default: null },
});

function mailLabel(mail) {
    if (!mail.enabled) return __('entitlements::cp.wiring_mail_off');

    return __(`entitlements::cp.wiring_mail_${mail.source}`);
}
</script>

<template>
    <Head :title="[__('entitlements::cp.wiring_title'), __('entitlements::cp.nav')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="__('entitlements::cp.wiring_title')" icon="key">
            <Button v-if="settingsUrl" :href="settingsUrl" :text="__('entitlements::cp.wiring_open_settings')" />
            <Button
                v-if="webhooks.catalogue_url"
                :href="webhooks.catalogue_url"
                :text="__('entitlements::cp.wiring_open_catalogue')"
                icon="link"
            />
        </Header>

        <Description class="mb-6" :text="__('entitlements::cp.wiring_intro')" />

        <Panel>
            <Table>
                <TableColumns>
                    <TableColumn>{{ __('entitlements::cp.wiring_col_event') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.wiring_col_mail') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.wiring_col_automations') }}</TableColumn>
                    <TableColumn>{{ __('entitlements::cp.wiring_col_webhooks') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="event in events" :key="event.handle" :data-handle="event.handle">
                        <TableCell class="align-top">
                            <div class="font-medium">{{ event.label }}</div>
                            <code class="text-xs text-gray-600 dark:text-gray-400">{{ event.handle }}</code>
                            <div class="text-xs text-gray-600 dark:text-gray-400 max-w-md mt-1">{{ event.description }}</div>
                        </TableCell>

                        <TableCell class="align-top">
                            <template v-if="event.mail">
                                <a v-if="event.mail.edit_url" :href="event.mail.edit_url" class="text-xs font-mono underline">{{ event.mail.slug }}</a>
                                <code v-else class="text-xs">{{ event.mail.slug }}</code>
                                <div class="mt-1">
                                    <Badge pill :color="event.mail.enabled ? 'green' : 'gray'" :text="mailLabel(event.mail)" />
                                </div>
                                <div
                                    v-if="emailTemplates.installed && event.mail.source === 'default'"
                                    class="text-2xs text-gray-600 dark:text-gray-400 mt-1 max-w-xs"
                                >
                                    {{ __('entitlements::cp.wiring_mail_import') }}
                                </div>
                            </template>
                            <span v-else class="text-gray-500 dark:text-gray-400">{{ __('entitlements::cp.wiring_no_mail') }}</span>
                        </TableCell>

                        <TableCell class="align-top">
                            <span v-if="!automations.installed" class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('entitlements::cp.wiring_not_installed') }}
                            </span>
                            <template v-else>
                                <span class="tabular-nums">{{ event.flows_text }}</span>
                                <div
                                    v-if="event.automations_owner === 'automations'"
                                    class="text-2xs text-gray-600 dark:text-gray-400"
                                >
                                    {{ __('entitlements::cp.wiring_by_automations') }}
                                </div>
                            </template>
                        </TableCell>

                        <TableCell class="align-top">
                            <span v-if="!webhooks.installed" class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('entitlements::cp.wiring_not_installed') }}
                            </span>
                            <span v-else-if="!webhooks.enabled" class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('entitlements::cp.wiring_bridge_off') }}
                            </span>
                            <span v-else class="tabular-nums">{{ event.hooks_text }}</span>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>

        <Panel :heading="__('entitlements::cp.wiring_status')">
            <Card>
                <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                    <li class="py-3 first:pt-0 flex items-center justify-between gap-4">
                        <span class="font-medium">Automations</span>
                        <div class="flex items-center gap-2">
                            <Badge
                                pill
                                :color="automations.installed ? 'green' : 'gray'"
                                :text="automations.installed ? __('entitlements::cp.wiring_installed') : __('entitlements::cp.wiring_not_installed')"
                            />
                            <Button v-if="automations.url" size="sm" :href="automations.url" :text="__('entitlements::cp.wiring_open_automations')" />
                        </div>
                    </li>
                    <li class="py-3 flex items-center justify-between gap-4">
                        <span class="font-medium">Webhook Manager</span>
                        <div class="flex items-center gap-2">
                            <Badge
                                pill
                                :color="webhooks.installed ? 'green' : 'gray'"
                                :text="webhooks.installed ? __('entitlements::cp.wiring_installed') : __('entitlements::cp.wiring_not_installed')"
                            />
                            <Button v-if="webhooks.url" size="sm" :href="webhooks.url" :text="__('entitlements::cp.wiring_open_webhooks')" />
                        </div>
                    </li>
                    <li class="py-3 last:pb-0 flex items-center justify-between gap-4">
                        <span class="font-medium">Email Templates</span>
                        <div class="flex items-center gap-2">
                            <Badge
                                pill
                                :color="emailTemplates.installed ? 'green' : 'gray'"
                                :text="emailTemplates.installed ? __('entitlements::cp.wiring_installed') : __('entitlements::cp.wiring_not_installed')"
                            />
                            <Button v-if="emailTemplates.url" size="sm" :href="emailTemplates.url" :text="__('entitlements::cp.wiring_open_templates')" />
                        </div>
                    </li>
                </ul>
            </Card>
        </Panel>

        <DocsCallout :topic="__('entitlements::cp.wiring_title')" url="https://github.com/goldnead/statamic-entitlements#readme" />
    </div>
</template>
