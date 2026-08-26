<script setup>
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    CommandPaletteItem,
    DocsCallout,
    DropdownItem,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Icon,
    Listing,
} from '@statamic/cms/ui';

const props = defineProps({
    initialColumns: { type: Array, required: true },
    filters: { type: Array, default: () => [] },
    hasAny: { type: Boolean, default: false },
    listingUrl: { type: String, required: true },
    createUrl: { type: String, required: true },
    canGrant: { type: Boolean, default: false },
    perPage: { type: Number, default: 50 },
});

// Nothing here is a config value. The page receives urls, labels and booleans
// the server already decided on — handing a config array to the browser is how a
// token ends up in a page's props.
function reload() {
    router.reload();
}

// Green only for the two states that actually let somebody in. An "active"
// badge next to a grant that grants nothing is the kind of screen that ends in a
// support ticket, and it is exactly what the system this replaced showed:
// a not-yet-started grant was labelled expired.
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
    <Head :title="__('entitlements::cp.title')" />

    <div class="max-w-page mx-auto">
        <template v-if="!hasAny">
            <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
            <header class="py-8 pt-16 text-center">
                <h1
                    class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3"
                >
                    <Icon name="key" class="size-5 text-gray-500" />{{ __('entitlements::cp.title') }}
                </h1>
            </header>

            <EmptyStateMenu :heading="__('entitlements::cp.empty_heading')">
                <EmptyStateItem
                    v-if="canGrant"
                    :href="createUrl"
                    icon="key"
                    :heading="__('entitlements::cp.create_grant')"
                    :description="__('entitlements::cp.empty_create_description')"
                />
                <EmptyStateItem
                    icon="book-open-cover"
                    :heading="__('entitlements::cp.empty_docs_heading')"
                    :description="__('entitlements::cp.empty_docs_description')"
                    href="https://github.com/goldnead/statamic-entitlements#readme"
                    target="_blank"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('entitlements::cp.title')" icon="key">
                <CommandPaletteItem
                    v-if="canGrant"
                    category="Actions"
                    :text="__('entitlements::cp.create_grant')"
                    icon="key"
                    :url="createUrl"
                    v-slot="{ text, url }"
                >
                    <Button :href="url" :text="text" variant="primary" />
                </CommandPaletteItem>
            </Header>

            <!--
                Server mode: the listing re-requests the same route as JSON on
                every search, sort, filter and page change. That matters more here
                than on most listings, because the state filter is resolved in SQL
                — filtering client-side would mean shipping every grant to the
                browser to find the expired ones.

                No action-url is passed. Bulk actions would need an
                ActionController and a set of Actions, and the destructive one —
                revoking access in bulk — must not be offered behind a checkbox
                before it can be offered behind a mandatory reason.
            -->
            <Listing
                :url="listingUrl"
                :columns="initialColumns"
                :filters="filters"
                :per-page="perPage"
                preferences-prefix="entitlements"
                sort-column="created_at"
                sort-direction="desc"
                push-query
                @refreshing="reload"
            >
                <template #cell-product_slug="{ row }">
                    <Link :href="row.show_url">{{ row.product_slug }}</Link>
                </template>

                <template #cell-subject="{ row }">
                    <!-- subjectLabel() already falls back to the raw key, so
                         the two are equal whenever the host named nobody. A
                         name reads as a name; a key stays monospaced, because
                         that is what it is. The key rides along only when it
                         adds something: a name is a display, not an identity,
                         and two members can share one. -->
                    <template v-if="row.subject !== row.subject_key">
                        <span class="text-sm">{{ row.subject }}</span>
                        <span class="font-mono text-2xs text-gray-500 dark:text-gray-400 ms-1.5">{{ row.subject_key }}</span>
                    </template>
                    <span v-else class="font-mono text-xs">{{ row.subject_key }}</span>
                </template>

                <template #cell-state="{ row }">
                    <Badge
                        size="sm"
                        pill
                        :color="badgeColour[row.state] || 'gray'"
                        :text="row.state_label"
                    />
                </template>

                <template #cell-expires_at="{ row }">
                    <span v-if="row.expires_at" class="whitespace-nowrap">{{ row.expires_at }}</span>
                    <span v-else class="text-gray-500">&mdash;</span>
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('entitlements::cp.view')" icon="eye" :href="row.show_url" />
                </template>
            </Listing>
        </template>

        <DocsCallout
            :topic="__('entitlements::cp.title')"
            url="https://github.com/goldnead/statamic-entitlements#readme"
        />
    </div>
</template>
