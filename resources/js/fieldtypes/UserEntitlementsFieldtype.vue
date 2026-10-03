<script setup>
/**
 * "Zugänge" on a user page: her grants, granting one more, revoking one.
 *
 * Reads from `entitlements.user`, writes nothing of its own. Granting posts the
 * same body the grant form under Users > Entitlements sends (subject_kind
 * `user`, the picked user, the product, the window) to the same action, and
 * revoking posts a reason to the existing revocation. The server's Gate decides;
 * the flags only spare a button that would answer 403.
 *
 * axios rather than the Inertia router: this is a field inside core's user
 * publish form, and both actions answer JSON. An Inertia visit from here would
 * replace the user page with whatever the grant action answers.
 */
import { computed, getCurrentInstance, onMounted, ref } from 'vue';
import { DateFormatter, Fieldtype } from '@statamic/cms';
import {
    Alert,
    Badge,
    Button,
    Combobox,
    ConfirmationModal,
    DatePicker,
    Description,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    Field,
    Input,
    Stack,
    StackContent,
    StackFooter,
    StackHeader,
    Textarea,
} from '@statamic/cms/ui';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, isReadOnly } = Fieldtype.use(emit, props);
defineExpose(expose);

// A read-only field (blueprint visibility, or a form opened read-only) lists
// the grants and offers neither action, whatever the permissions say.
const readOnly = computed(() => isReadOnly?.value === true);

const http = getCurrentInstance()?.proxy?.$axios;

const loading = ref(false);
const failed = ref(false);
const rows = ref([]);
const products = ref(null);
const canGrant = ref(false);
const indexUrl = ref(null);

const userId = computed(() => props.meta?.userId ?? null);
const canView = computed(() => props.meta?.canView === true);

async function load() {
    if (!props.meta?.rowsUrl) return;

    loading.value = true;
    failed.value = false;

    try {
        const { data } = await http.get(props.meta.rowsUrl);
        rows.value = data.rows ?? [];
        products.value = data.products ?? null;
        canGrant.value = data.canGrant === true;
        indexUrl.value = data.indexUrl ?? null;
    } catch (e) {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const badgeColour = {
    active: 'green',
    grace_period: 'amber',
    scheduled: 'blue',
    pending: 'purple',
    expired: 'gray',
    revoked: 'red',
};

// ------------------------------------------------------------------ granting

const granting = ref(false);
const saving = ref(false);
const errors = ref({});
const form = ref({ product_slug: null, starts_at: null, expires_at: null });

// Only catalogue entries; with a catalogue there is nothing to type.
const productOptions = computed(() => products.value ?? []);

/** An ISO instant in the viewer's locale and timezone, as core renders dates. */
function when(isoValue) {
    return isoValue ? DateFormatter.format(isoValue, 'datetime') : null;
}

function openGrant() {
    form.value = { product_slug: null, starts_at: null, expires_at: null };
    errors.value = {};
    granting.value = true;
}

/** A reka-ui date value, a string, or nothing, as an ISO instant. */
function iso(value) {
    if (!value) return null;
    if (typeof value === 'string') return value;

    const date = value.timeZone
        ? value.toDate()
        : value.toDate(Intl.DateTimeFormat().resolvedOptions().timeZone);

    return date.toISOString();
}

async function grant() {
    saving.value = true;
    errors.value = {};

    try {
        await http.post(props.meta.storeUrl, {
            subject_kind: 'user',
            subject_user: [userId.value],
            product_slug: form.value.product_slug,
            starts_at: iso(form.value.starts_at),
            expires_at: iso(form.value.expires_at),
        });

        granting.value = false;
        Statamic.$toast.success(__('entitlements::cp.user_grant_done'));
        await load();
    } catch (e) {
        if (e?.response?.status === 422) {
            errors.value = e.response.data?.errors ?? {};
        } else {
            Statamic.$toast.error(e?.response?.data?.message || __('entitlements::cp.user_section_load_failed'));
        }
    } finally {
        saving.value = false;
    }
}

// ------------------------------------------------------------------ revoking

const revoking = ref(null);
const reason = ref('');
const revokeError = ref(null);
const revokeBusy = ref(false);

function openRevoke(row) {
    reason.value = '';
    revokeError.value = null;
    revoking.value = row;
}

async function revoke() {
    if (!revoking.value) return;

    revokeBusy.value = true;
    revokeError.value = null;

    try {
        await http.post(revoking.value.revoke_url, { reason: reason.value });
        revoking.value = null;
        Statamic.$toast.success(__('entitlements::cp.user_revoke_done'));
        await load();
    } catch (e) {
        revokeError.value = e?.response?.data?.errors?.reason?.[0]
            ?? e?.response?.data?.message
            ?? __('entitlements::cp.user_section_load_failed');
    } finally {
        revokeBusy.value = false;
    }
}
</script>

<template>
    <div :id="id" data-entitlements-user-section>
        <Description v-if="!userId">{{ __('entitlements::cp.user_section_save_first') }}</Description>

        <Description v-else-if="!canView">{{ __('entitlements::cp.user_section_no_permission') }}</Description>

        <template v-else>
            <Alert v-if="failed" variant="error" :text="__('entitlements::cp.user_section_load_failed')" class="mb-3" />

            <Description v-if="!loading && !failed && rows.length === 0" class="mb-3">
                {{ __('entitlements::cp.user_section_empty') }}
            </Description>

            <!-- A list, not a table: each grant is a short block that wraps on a
                 phone and keeps its "…" menu in reach, rather than a six-column
                 table that scrolls sideways at 390 px. -->
            <ul v-if="rows.length" class="divide-y divide-content-border mb-3">
                <li v-for="row in rows" :key="row.id" :data-grant="row.id" class="py-3 flex items-start gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <a :href="row.show_url" class="font-medium">{{ row.product_label }}</a>
                            <Badge pill :color="badgeColour[row.state] || 'gray'" :text="row.state_label" />
                            <Badge
                                v-if="row.product_unknown"
                                pill
                                color="amber"
                                :text="__('entitlements::cp.product_unknown')"
                                v-tooltip="__('entitlements::cp.product_unknown_hint')"
                            />
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                            <code v-if="row.product_label !== row.product_slug" class="text-2xs">{{ row.product_slug }}</code>
                            <span>{{ __('entitlements::cp.user_col_source') }}: {{ row.source }}</span>
                            <span>{{ __('entitlements::cp.user_col_from') }}: {{ when(row.starts_at) || '–' }}</span>
                            <span>{{ __('entitlements::cp.user_col_until') }}: {{ when(row.expires_at) || '–' }}</span>
                        </div>
                    </div>
                    <Dropdown>
                        <DropdownMenu>
                            <DropdownItem :href="row.show_url" :text="__('entitlements::cp.user_open_grant')" icon="eye" />
                            <DropdownItem
                                v-if="row.can_revoke && !readOnly"
                                :text="__('entitlements::cp.user_revoke_action')"
                                icon="key"
                                variant="destructive"
                                @click="openRevoke(row)"
                            />
                        </DropdownMenu>
                    </Dropdown>
                </li>
            </ul>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="canGrant && !readOnly"
                    icon="plus"
                    :text="__('entitlements::cp.user_grant_action')"
                    @click="openGrant"
                />
                <Button
                    v-if="indexUrl"
                    variant="ghost"
                    size="sm"
                    :href="indexUrl"
                    :text="__('entitlements::cp.user_section_all')"
                />
            </div>
        </template>

        <!-- Core's stack, from the right, for creating a record: never an inline
             form above or below the table. -->
        <Stack
            :open="granting"
            size="narrow"
            :title="__('entitlements::cp.user_grant_title')"
            icon="key"
            @update:open="granting = $event"
        >
            <StackHeader :title="__('entitlements::cp.user_grant_title')" icon="key" />
            <StackContent>
                <div class="space-y-6">
                    <Description>{{ __('entitlements::cp.user_grant_intro') }}</Description>

                    <Field
                        :label="__('entitlements::cp.field_product')"
                        :instructions="products === null ? __('entitlements::cp.user_product_text_instructions') : __('entitlements::cp.field_product_instructions')"
                        :errors="errors.product_slug"
                        required
                        id="entitlements-user-product"
                    >
                        <Combobox
                            v-if="products !== null"
                            id="entitlements-user-product"
                            v-model="form.product_slug"
                            :options="productOptions"
                            :placeholder="__('entitlements::cp.field_product_placeholder')"
                            searchable
                        />
                        <Input v-else id="entitlements-user-product" v-model="form.product_slug" />
                    </Field>

                    <Field
                        :label="__('entitlements::cp.field_starts_at')"
                        :instructions="__('entitlements::cp.user_starts_instructions')"
                        :errors="errors.starts_at"
                    >
                        <DatePicker v-model="form.starts_at" granularity="minute" clearable />
                    </Field>

                    <Field
                        :label="__('entitlements::cp.field_expires_at')"
                        :instructions="__('entitlements::cp.user_expires_instructions')"
                        :errors="errors.expires_at"
                    >
                        <DatePicker v-model="form.expires_at" granularity="minute" clearable />
                    </Field>

                    <Alert v-if="errors.subject_user" variant="error" :text="errors.subject_user[0]" />
                </div>
            </StackContent>
            <StackFooter>
                <div class="flex items-center justify-end gap-2">
                    <Button variant="ghost" :text="__('entitlements::cp.cancel')" @click="granting = false" />
                    <Button
                        variant="primary"
                        :text="__('entitlements::cp.user_grant_submit')"
                        :loading="saving"
                        :disabled="saving || !form.product_slug"
                        @click="grant"
                    />
                </div>
            </StackFooter>
        </Stack>

        <!-- The reason is mandatory, so the confirmation carries a field. -->
        <ConfirmationModal
            :open="revoking !== null"
            :title="revoking ? __('entitlements::cp.user_revoke_title', { product: revoking.product_label }) : ''"
            :button-text="__('entitlements::cp.user_revoke_submit')"
            :busy="revokeBusy"
            :disabled="reason.trim() === ''"
            danger
            @update:open="(open) => { if (!open) revoking = null; }"
            @confirm="revoke"
        >
            <Field
                :label="__('entitlements::cp.field_reason')"
                :instructions="__('entitlements::cp.field_reason_instructions')"
                :error="revokeError"
                required
                id="entitlements-user-revoke-reason"
            >
                <Textarea id="entitlements-user-revoke-reason" v-model="reason" :rows="3" />
            </Field>
        </ConfirmationModal>
    </div>
</template>
