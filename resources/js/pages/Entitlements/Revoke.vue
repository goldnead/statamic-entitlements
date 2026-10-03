<script setup>
/**
 * Taking access away: one mandatory field and a button that says what it does.
 *
 * Core's PublishForm page did this until 1.6 and labelled the button "Save",
 * with no way to say otherwise. The server side is unchanged: the POST is
 * validated against the revocation blueprint and answers `{ saved, redirect }`,
 * which is why this posts with the CP's axios and then visits the redirect,
 * rather than expecting an Inertia response.
 */
import { getCurrentInstance, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Button,
    Card,
    CommandPaletteItem,
    Description,
    Field,
    Header,
    Panel,
    Textarea,
} from '@statamic/cms/ui';

const props = defineProps({
    title: { type: String, required: true },
    submitUrl: { type: String, required: true },
    cancelUrl: { type: String, required: true },
});

const http = getCurrentInstance()?.proxy?.$axios;

const reason = ref('');
const error = ref(null);
const saving = ref(false);

async function submit() {
    saving.value = true;
    error.value = null;

    try {
        const { data } = await http.post(props.submitUrl, { reason: reason.value });
        router.visit(data.redirect ?? props.cancelUrl);
    } catch (e) {
        error.value = e?.response?.data?.errors?.reason?.[0]
            ?? e?.response?.data?.message
            ?? __('entitlements::cp.user_section_load_failed');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="[title, __('entitlements::cp.title')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="key">
            <Button :href="cancelUrl" variant="ghost" :text="__('entitlements::cp.cancel')" />
            <CommandPaletteItem
                category="Actions"
                :text="__('entitlements::cp.revoke')"
                icon="key"
                :action="submit"
                prioritize
                v-slot="{ text, action }"
            >
                <Button
                    variant="primary"
                    :text="text"
                    :loading="saving"
                    :disabled="saving || reason.trim() === ''"
                    data-role="submit"
                    @click="action"
                />
            </CommandPaletteItem>
        </Header>

        <Panel>
            <Card>
                <Description class="mb-6">{{ __('entitlements::cp.section_revoke_instructions') }}</Description>
                <Field
                    id="entitlements-revoke-reason"
                    :label="__('entitlements::cp.field_reason')"
                    :instructions="__('entitlements::cp.field_reason_instructions')"
                    :error="error"
                    required
                >
                    <Textarea id="entitlements-revoke-reason" v-model="reason" :rows="4" />
                </Field>
            </Card>
        </Panel>
    </div>
</template>
