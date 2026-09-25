<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    CommandPaletteItem,
    Description,
    DocsCallout,
    DropdownItem,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Icon,
    Listing,
} from '@statamic/cms/ui';

const props = defineProps({
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    createUrl: { type: String, required: true },
    canManage: { type: Boolean, default: false },
    fallbackProduct: { type: String, default: null },
});

const isEmpty = computed(() => props.rows.length === 0);
</script>

<template>
    <Head :title="[__('entitlements::cp.limits_title'), __('entitlements::cp.nav')]" />

    <div class="max-w-page mx-auto">
        <template v-if="isEmpty">
            <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="key" class="size-5 text-gray-500" />{{ __('entitlements::cp.limits_title') }}
                </h1>
            </header>

            <EmptyStateMenu :heading="__('entitlements::cp.limits_empty_heading')">
                <EmptyStateItem
                    v-if="canManage"
                    :href="createUrl"
                    icon="key"
                    :heading="__('entitlements::cp.limits_create')"
                    :description="__('entitlements::cp.limits_empty_create_description')"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('entitlements::cp.limits_title')" icon="key">
                <CommandPaletteItem
                    v-if="canManage"
                    category="Actions"
                    :text="__('entitlements::cp.limits_create')"
                    icon="key"
                    :url="createUrl"
                    v-slot="{ text, url }"
                >
                    <Button :href="url" :text="text" variant="primary" />
                </CommandPaletteItem>
            </Header>

            <Description class="mb-6">
                {{ __('entitlements::cp.limits_intro') }}
                {{
                    fallbackProduct
                        ? __('entitlements::cp.limits_fallback', { product: fallbackProduct })
                        : __('entitlements::cp.limits_fallback_none')
                }}
            </Description>

            <!-- Client mode: a site has a handful of products, not thousands. -->
            <Listing :items="rows" :columns="columns" preferences-prefix="entitlements-limits" sort-column="product_slug" sort-direction="asc">
                <template #cell-product_slug="{ row }">
                    <Link v-if="row.edit_url" :href="row.edit_url" class="font-mono">{{ row.product_slug }}</Link>
                    <span v-else class="font-mono">{{ row.product_slug }}</span>
                </template>

                <template #cell-limits="{ row }">
                    <div v-if="row.limits.length" class="flex flex-wrap gap-1.5">
                        <Badge v-for="limit in row.limits" :key="limit.key" pill :text="`${limit.label}: ${limit.text}`" />
                    </div>
                    <span v-else class="text-gray-500 dark:text-gray-400">{{ __('entitlements::cp.limits_none') }}</span>
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem v-if="row.edit_url" :text="__('entitlements::cp.limits_edit_action')" icon="edit" :href="row.edit_url" />
                </template>
            </Listing>
        </template>

        <DocsCallout :topic="__('entitlements::cp.limits_title')" url="https://github.com/goldnead/statamic-entitlements#limits" />
    </div>
</template>
