import { h } from 'vue';

/**
 * Stand-ins for the `@statamic/cms/ui` components.
 *
 * Deliberately dumb: the real ones belong to Statamic and are Statamic's to
 * test. What these preserve is the contract this package relies on — a `text`
 * prop is rendered, attributes fall through to the root element, slots are
 * shown, clicks are emitted — so a wrong prop name or a swallowed slot still
 * fails here.
 */
function textual(tag, name) {
    return {
        name,
        props: { text: { type: [String, Number], default: null } },
        setup(props, { slots, attrs }) {
            return () => h(tag, { 'data-stub': name, ...attrs }, [props.text, slots.default?.()]);
        },
    };
}

function container(tag, name) {
    return {
        name,
        props: { heading: { type: String, default: null } },
        setup(props, { slots, attrs }) {
            return () => h(tag, { 'data-stub': name, ...attrs }, [props.heading, slots.default?.()]);
        },
    };
}

function clickable(name) {
    return {
        name,
        props: ['text', 'variant', 'size', 'icon', 'href', 'disabled'],
        emits: ['click'],
        setup(props, { attrs, emit, slots }) {
            return () =>
                h(
                    props.href ? 'a' : 'button',
                    {
                        'data-stub': name,
                        'data-variant': props.variant,
                        href: props.href,
                        disabled: props.disabled || undefined,
                        ...attrs,
                        onClick: (e) => emit('click', e),
                    },
                    [props.text, slots.default?.()]
                );
        },
    };
}

export const Button = clickable('Button');
export const ButtonGroup = container('div', 'ButtonGroup');
export const Badge = textual('span', 'Badge');
export const Card = container('div', 'Card');
export const Description = container('p', 'Description');
export const DocsCallout = textual('div', 'DocsCallout');
// A destructive page action lives in the header's "…" menu, so the two
// containers around DropdownItem have to exist here — otherwise the whole
// subtree renders as nothing and a test looks for a link never drawn.
export const Dropdown = container('div', 'Dropdown');
export const DropdownMenu = container('div', 'DropdownMenu');
export const DropdownItem = clickable('DropdownItem');
export const Header = textual('header', 'Header');
export const Heading = container('h2', 'Heading');
export const Icon = {
    name: 'Icon',
    props: ['name'],
    setup(props, { attrs }) {
        return () => h('i', { 'data-stub': 'Icon', 'data-icon': props.name, ...attrs });
    },
};
export const Panel = container('section', 'Panel');
export const Table = container('table', 'Table');
export const TableColumns = container('thead', 'TableColumns');
export const TableColumn = container('th', 'TableColumn');
export const TableRows = container('tbody', 'TableRows');
export const TableRow = container('tr', 'TableRow');
export const TableCell = container('td', 'TableCell');
export const PanelHeader = container('div', 'PanelHeader');
export const Subheading = container('h3', 'Subheading');

export const EmptyStateMenu = {
    name: 'EmptyStateMenu',
    props: ['heading'],
    setup(props, { slots, attrs }) {
        return () => h('div', { 'data-stub': 'EmptyStateMenu', ...attrs }, [props.heading, slots.default?.()]);
    },
};

export const EmptyStateItem = {
    name: 'EmptyStateItem',
    props: ['heading', 'description', 'icon', 'href'],
    setup(props, { attrs }) {
        return () => h('a', { 'data-stub': 'EmptyStateItem', href: props.href, ...attrs }, props.heading);
    },
};

/**
 * The real one wraps its default slot and also publishes the action to the
 * command palette. The scoped slot is what the pages here use, so the stub has
 * to hand back the same `{ text, url, action }`.
 */
export const CommandPaletteItem = {
    name: 'CommandPaletteItem',
    props: ['category', 'text', 'icon', 'url', 'action', 'prioritize'],
    setup(props, { slots }) {
        return () =>
            h('div', { 'data-stub': 'CommandPaletteItem' }, [
                slots.default?.({ text: props.text, url: props.url, action: props.action }),
            ]);
    },
};

/**
 * Server mode only, which is all this addon uses. The stub renders nothing but
 * records the props, because what a test here can meaningfully assert is that
 * the listing was fed correctly — no `preferences-prefix` means no saved views,
 * and a missing `url` means no listing at all.
 */
export const Listing = {
    name: 'Listing',
    props: [
        'url',
        'items',
        'columns',
        'filters',
        'perPage',
        'preferencesPrefix',
        'sortColumn',
        'sortDirection',
        'actionUrl',
        'pushQuery',
        'additionalParameters',
    ],
    emits: ['refreshing'],
    setup(props, { attrs, slots }) {
        return () => h('div', { 'data-stub': 'Listing', ...attrs }, slots.default?.());
    },
};

export const ConfirmationModal = {
    name: 'ConfirmationModal',
    props: ['open', 'title', 'bodyText', 'buttonText', 'danger', 'busy', 'disabled'],
    emits: ['update:open', 'confirm'],
    setup(props, { emit, slots }) {
        return () =>
            props.open
                ? h(
                      'div',
                      { 'data-stub': 'ConfirmationModal', 'data-title': props.title },
                      [
                          h('p', props.bodyText),
                          slots.default?.(),
                          h(
                              'button',
                              { 'data-role': 'confirm', disabled: props.disabled || undefined, onClick: () => emit('confirm') },
                              props.buttonText
                          ),
                      ]
                  )
                : null;
    },
};

export const Alert = textual('div', 'Alert');

/** Renders its default slot when open, so a test can reach the fields inside. */
export const Stack = {
    name: 'Stack',
    props: ['open', 'title', 'size', 'icon'],
    emits: ['update:open'],
    setup(props, { slots }) {
        return () => (props.open ? h('aside', { 'data-stub': 'Stack', 'data-title': props.title }, slots.default?.()) : null);
    },
};
export const StackHeader = textual('header', 'StackHeader');
export const StackContent = container('div', 'StackContent');
export const StackFooter = container('footer', 'StackFooter');

export const Field = {
    name: 'Field',
    props: ['label', 'instructions', 'errors', 'error', 'required', 'id'],
    setup(props, { slots }) {
        return () =>
            h('div', { 'data-stub': 'Field', 'data-label': props.label }, [
                props.label,
                slots.default?.(),
                props.errors?.length ? h('p', { 'data-role': 'error' }, props.errors[0]) : null,
                props.error ? h('p', { 'data-role': 'error' }, props.error) : null,
            ]);
    },
};

function model(tag, name, extra = []) {
    return {
        name,
        props: ['modelValue', 'options', ...extra],
        emits: ['update:modelValue'],
        setup(props, { emit, attrs }) {
            return () =>
                h(tag, {
                    'data-stub': name,
                    'data-options': props.options ? JSON.stringify(props.options) : undefined,
                    value: props.modelValue ?? '',
                    ...attrs,
                    onInput: (e) => emit('update:modelValue', e.target.value),
                });
        },
    };
}

export const Combobox = model('input', 'Combobox', ['placeholder', 'searchable', 'taggable']);
export const Input = model('input', 'Input');
export const Textarea = model('textarea', 'Textarea', ['rows']);
export const DatePicker = model('input', 'DatePicker', ['granularity', 'clearable']);
