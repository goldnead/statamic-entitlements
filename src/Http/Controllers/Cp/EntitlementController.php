<?php

namespace Goldnead\Entitlements\Http\Controllers\Cp;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Query\Scopes\Filters\EntitlementFilter;
use Goldnead\Entitlements\Support\Blueprints;
use Goldnead\Entitlements\Support\SourceRegistry;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\CP\PublishForm;
use Statamic\Facades\Scope;
use Statamic\Facades\User as StatamicUser;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;

/**
 * Entitlements in the Control Panel.
 *
 * `index()` serves two representations of one query — the Inertia page that
 * boots core's `<Listing>`, and the JSON that listing then fetches on every
 * search, sort, filter and page change. That is core's own arrangement (see
 * `FormsController`) and it is why there is no second "data" route.
 *
 * The two forms are `Statamic\CP\PublishForm`, which renders core's own
 * PublishForm page. No Vue is written for them at all, so they cannot drift away
 * from what a Statamic publish form looks like.
 *
 * ## Authorization
 *
 * Every action goes through the Gate, including the read ones, and the three
 * permissions are genuinely distinct — reading, granting and revoking are three
 * different jobs. The source system gated all of it behind one blanket
 * `access-admin`, which meant anybody who could open the Control Panel could
 * take a paying customer's access away.
 *
 * `Gate::authorize()` is used rather than anything read off the authenticated
 * user directly. `hasPermission()`, `isSuper()` and `id()` do not exist on an
 * Eloquent user, and calling them is how a Control Panel screen crashes on
 * exactly the sites that are hardest to reach for a fix. Where the actor is
 * needed as a value — for the audit trail on a revocation — it goes through
 * `Statamic\Facades\User::fromUser()`, which is the supported way to get from
 * whatever the guard returned to something Statamic understands.
 */
class EntitlementController extends Controller
{
    use QueriesFilters;

    /** Sorting is whitelisted: `sort` arrives from the query string and lands in an ORDER BY. */
    private const SORTABLE = ['product_slug', 'source', 'status', 'starts_at', 'expires_at', 'created_at'];

    private const MAX_PER_PAGE = 500;

    public function __construct(private readonly EntitlementManager $entitlements) {}

    public function index(FilteredRequest $request)
    {
        Gate::authorize('view entitlements');

        if ($request->wantsJson()) {
            return $this->listing($request);
        }

        return Inertia::render('entitlements::Entitlements/Index', [
            'initialColumns' => collect($this->columns())->map->toArray()->all(),
            'filters' => Scope::filters(EntitlementFilter::LISTING_KEY),
            'hasAny' => Entitlement::query()->exists(),
            'listingUrl' => cp_route('entitlements.index'),
            'createUrl' => cp_route('entitlements.create'),
            'canGrant' => Gate::allows('grant entitlements'),
            'perPage' => $this->perPage(null),
        ]);
    }

    public function create()
    {
        Gate::authorize('grant entitlements');

        return PublishForm::make(Blueprints::grant())
            ->title(__('entitlements::cp.create_grant'))
            ->icon('key')
            ->submittingTo(cp_route('entitlements.store'), 'POST');
    }

    public function store(FilteredRequest $request)
    {
        Gate::authorize('grant entitlements');

        $values = PublishForm::make(Blueprints::grant())->submit($request->all());

        $entitlement = $this->entitlements->grant(
            subject: new SubjectReference(
                trim((string) ($values['subject_type'] ?? '')),
                trim((string) ($values['subject_id'] ?? '')),
            ),
            productSlug: (string) ($values['product_slug'] ?? ''),
            // Not taken from the form. A grant made by hand in the Control Panel
            // has exactly one honest source, and letting an admin type
            // "thrivecart" into it would put a fabricated purchase into the
            // audit trail.
            source: (string) config('entitlements.manual.source', 'manual'),
            sourceRef: $this->single($values['source_ref'] ?? null),
            startsAt: $this->date($values['starts_at'] ?? null),
            expiresAt: $this->date($values['expires_at'] ?? null),
            actor: $this->actor(),
        );

        // `redirect` is the key core's PublishForm reads off the save response
        // (ui/Publish/Form.vue). Returning a 302 instead makes the XHR follow it
        // with the original verb and re-enter this action until the browser gives
        // up with ERR_TOO_MANY_REDIRECTS.
        return [
            'saved' => true,
            'redirect' => cp_route('entitlements.show', ['entitlement' => $entitlement->getKey()]),
        ];
    }

    public function show(int $entitlement)
    {
        Gate::authorize('view entitlements');

        $model = Entitlement::query()->findOrFail($entitlement);

        $state = $model->state();

        return Inertia::render('entitlements::Entitlements/Show', [
            'entitlement' => [
                'id' => $model->getKey(),
                'subject_type' => $model->subject_type,
                'subject_id' => $model->subject_id,
                'subject_label' => $this->entitlements->subjectLabel(
                    new SubjectReference($model->subject_type, $model->subject_id)
                ),
                'product_slug' => $model->product_slug,
                'source' => $model->source,
                'source_label' => app(SourceRegistry::class)->label($model->source),
                'source_ref' => $model->hasSourceRef() ? $model->source_ref : null,
                'status' => $model->status,
                'state' => $state->value,
                'state_label' => $state->label(),
                'grants_access' => $state->grantsAccess(),
                'revoked_reason' => $model->revoked_reason,
            ],
            // The timeline is the answer to "what happened to this grant", which
            // is the only question anybody opens this screen to ask. Rendered in
            // UTC and labelled as such: every date on this row is an instant that
            // decides access, and a timezone-shifted rendering of one is a
            // support conversation nobody can win.
            'timeline' => $this->timeline($model),
            'indexUrl' => cp_route('entitlements.index'),
            'revokeUrl' => cp_route('entitlements.revoke.form', ['entitlement' => $model->getKey()]),
            'restoreUrl' => cp_route('entitlements.restore', ['entitlement' => $model->getKey()]),
            'canRevoke' => Gate::allows('revoke entitlements') && $state !== EntitlementState::Revoked,
            'canRestore' => Gate::allows('grant entitlements') && $state === EntitlementState::Revoked,
        ]);
    }

    public function revokeForm(int $entitlement)
    {
        Gate::authorize('revoke entitlements');

        $model = Entitlement::query()->findOrFail($entitlement);

        return PublishForm::make(Blueprints::revocation())
            ->title(__('entitlements::cp.revoke_title', ['product' => $model->product_slug]))
            ->icon('key')
            ->submittingTo(cp_route('entitlements.revoke', ['entitlement' => $model->getKey()]), 'POST');
    }

    public function revoke(FilteredRequest $request, int $entitlement)
    {
        Gate::authorize('revoke entitlements');

        $model = Entitlement::query()->findOrFail($entitlement);

        $values = PublishForm::make(Blueprints::revocation())->submit($request->all());

        $this->entitlements->revoke(
            $model,
            (string) ($values['reason'] ?? ''),
            $this->actor(),
        );

        return [
            'saved' => true,
            'redirect' => cp_route('entitlements.show', ['entitlement' => $model->getKey()]),
        ];
    }

    /**
     * Undoing a revocation needs the grant permission, not the revoke one.
     *
     * Restoring access is granting it. Someone trusted to take access away is
     * not automatically trusted to hand it back — that asymmetry is the entire
     * reason the two permissions are siblings rather than one.
     */
    public function restore(int $entitlement)
    {
        Gate::authorize('grant entitlements');

        $model = Entitlement::query()->findOrFail($entitlement);

        $this->entitlements->restore($model, $this->actor());

        return ['redirect' => cp_route('entitlements.show', ['entitlement' => $model->getKey()])];
    }

    /**
     * The acting Control Panel user, as an {@see Identity}.
     *
     * Through `Statamic\Facades\User::fromUser()` rather than off the guard's
     * return value: the authenticated user may be an Eloquent model with no
     * `id()`, no `email()` and no `name()` methods, and calling them is a 500 on
     * precisely the installs that use a custom user model. `getAuthIdentifier()`
     * is the one thing every Laravel user answers.
     */
    private function actor(): ?Identity
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $statamic = StatamicUser::fromUser($user);

        // `email()` is the only one of the three on the User contract. `name()`,
        // `get()` and `id()` are not, and a Control Panel action that calls them
        // crashes on exactly the installs hardest to reach for a fix — the ones
        // with a custom user model. The name is worth having in the audit trail
        // but not worth a 500, so it is read defensively.
        //
        // `method_exists` is legitimate here because `$statamic` is an instance.
        // It would be useless against a *facade*, which forwards through
        // __callStatic and declares none of the methods it appears to have, so
        // the check would answer false forever.
        $name = $statamic !== null && method_exists($statamic, 'name') ? $statamic->name() : null;

        return Identity::user(
            id: (string) $user->getAuthIdentifier(),
            email: $statamic?->email(),
            name: is_string($name) ? $name : null,
        );
    }

    /**
     * @return list<array{label: string, value: string|null}>
     */
    private function timeline(Entitlement $model): array
    {
        $format = fn ($date) => $date?->format('Y-m-d H:i').' UTC';

        return array_values(array_filter([
            ['label' => __('entitlements::cp.timeline_created'), 'value' => $format($model->getAttribute('created_at'))],
            ['label' => __('entitlements::cp.timeline_starts'), 'value' => $format($model->starts_at)],
            ['label' => __('entitlements::cp.timeline_expires'), 'value' => $format($model->expires_at)],
            ['label' => __('entitlements::cp.timeline_grace'), 'value' => $format($model->grace_until)],
            ['label' => __('entitlements::cp.timeline_revoked'), 'value' => $format($model->revoked_at)],
        ], fn (array $row): bool => $row['value'] !== ' UTC'));
    }

    /** The <Listing> response contract: `data` plus `meta` carrying columns on every page. */
    private function listing(FilteredRequest $request): array
    {
        $query = Entitlement::query();

        $badges = $this->queryFilters(
            $query,
            $request->filters ?? [],
            ['handle' => EntitlementFilter::LISTING_KEY],
        );

        $this->applySearch($query, (string) $request->input('search', ''));

        $sort = in_array($request->input('sort'), self::SORTABLE, true)
            ? $request->input('sort')
            : 'created_at';

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';

        /** @var LengthAwarePaginator<Entitlement> $paginator */
        $paginator = $query
            ->orderBy($sort, $order)
            ->orderBy('id', $order)
            ->paginate($this->perPage($request->input('perPage')))
            ->withQueryString();

        return [
            'data' => array_map(fn (Entitlement $e) => $this->row($e), $paginator->items()),
            'meta' => [
                'columns' => collect($this->columns())->map->toArray()->all(),
                'activeFilterBadges' => $badges,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  Builder<Entitlement>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        // Escaped and anchored as a prefix, so the index stays usable and a `%`
        // typed by a user is a percent sign rather than a full table scan.
        $term = str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query->where(function ($query) use ($term): void {
            $query->where('product_slug', 'like', $term)
                ->orWhere('subject_id', 'like', $term)
                ->orWhere('source_ref', 'like', $term);
        });
    }

    private function perPage(mixed $requested): int
    {
        $default = (int) config('entitlements.cp.per_page', 50);
        $perPage = (int) ($requested ?: $default);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /** @return array<int, Column> */
    private function columns(): array
    {
        return [
            Column::make('product_slug')->label(__('entitlements::cp.col_product')),
            Column::make('subject')->label(__('entitlements::cp.col_subject'))->sortable(false),
            Column::make('state')->label(__('entitlements::cp.col_state'))->sortable(false),
            Column::make('source')->label(__('entitlements::cp.col_source')),
            Column::make('expires_at')->label(__('entitlements::cp.col_expires')),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Entitlement $entitlement): array
    {
        $state = $entitlement->state();

        return [
            'id' => $entitlement->getKey(),
            'product_slug' => $entitlement->product_slug,
            'subject' => $entitlement->subjectKey(),
            'state' => $state->value,
            'state_label' => $state->label(),
            'grants_access' => $state->grantsAccess(),
            'source' => app(SourceRegistry::class)->label($entitlement->source),
            'expires_at' => $entitlement->expires_at?->format('Y-m-d H:i').' UTC',
            'show_url' => cp_route('entitlements.show', ['entitlement' => $entitlement->getKey()]),
        ];
    }

    private function single(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return filled($value) ? (string) $value : null;
    }

    private function date(mixed $value): ?\DateTimeInterface
    {
        $value = $this->single($value);

        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
