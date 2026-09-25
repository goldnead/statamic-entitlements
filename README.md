# Statamic Entitlements

Who may access what, and why — as one state machine, one table, and four events.

An entitlement is a grant: *this subject may use this product, from this source, for this window.*
This package writes them, resolves their state, answers access questions and announces the four
transitions worth reacting to. It does not create accounts, send mail, issue magic links or know
what a product is.

**Requires** `goldnead/statamic-brand-context` and `goldnead/statamic-identity-contracts`.
Nothing else.

---

## Why it exists

It was extracted from a live Statamic application that had been selling courses for months. Four
things were wrong there, and each one is a design decision here:

| In the source system | Here |
|---|---|
| State resolved in **two** places, which disagreed about `pending` — so an unconfirmed opt-in got access through one of them | One resolver. `Support\StateResolver` and nothing else. |
| A grant starting tomorrow reported **`expired`** on the customer's own account screen | `Scheduled`, a state of its own, with no access and its own event when the clock arrives |
| `revoked_at` was displayed, entered no decision, and **no write path ever set it** — 48 grants, 0 revocations | `revoke()` with a mandatory reason, read by the resolver, fired as an event |
| **No unique index** for five months; `firstOrCreate()` in PHP loses every race | Unique over the grant tuple, in the table's first migration, with the write path built around the violation |

## Requirements

- PHP 8.2+
- Laravel 12.40+ or 13
- Statamic 6
- `goldnead/statamic-brand-context` and `goldnead/statamic-identity-contracts`

Nothing else is required. Every sibling integration is optional and attached by `class_exists`.

## Installation

```bash
composer require goldnead/statamic-entitlements
php artisan migrate
```

Add the announcement pass to your scheduler. Two of the four events happen because the clock moved
and nothing wrote, so without this they never fire:

```php
// routes/console.php or App\Console\Kernel
Schedule::command('entitlements:announce')->everyFifteenMinutes();
```

It is safe to run as often as you like — see *Events* below.

## Usage

### Granting

```php
use Goldnead\Entitlements\Facades\Entitlements;

Entitlements::grant(
    subject: $user,                 // any Eloquent model, or a SubjectReference
    productSlug: 'chorleiter-kurs', // a free string; there is no catalogue
    source: 'mollie',               // a free string; see Sources
    sourceRef: $payment->id,        // the external id this grant came from
    expiresAt: now()->addYear(),
);
```

Two grants that agree on **(subject, product, source, sourceRef, brand)** are the same grant. Calling
`grant()` again returns the existing row and fires nothing. That tuple is deliberately not narrower:
a repeat purchase brings a new provider id, and the same product won by opt-in and later bought has a
different source — collapsing either would destroy real customer history.

Three things `grant()` will not do:

- widen an existing window (a retry is not a renewal),
- resurrect a revoked grant — use `restore()`, which is a decision somebody makes,
- fire an event for a grant that starts in the future.

```php
Entitlements::grantPending($contact, 'warmup-guide', 'newsletter_optin', 'warmup-guide');
Entitlements::claimPending($grant);            // true exactly once, under any concurrency
Entitlements::revoke($grant, 'Chargeback #1234', $actor);
Entitlements::restore($grant, $actor);
Entitlements::enterGracePeriod($grant, now()->addWeek());
```

### Asking

```php
Entitlements::allows($user, 'chorleiter-kurs');            // bool
Entitlements::decide($user, 'chorleiter-kurs');            // AccessDecision
Entitlements::activeProductSlugsFor($user);                // list<string>
Entitlements::forSubject($user);                           // Eloquent builder
```

`decide()` returns `allowed`, a machine-readable `reason` (`ENTITLED` / `NOT_ENTITLED`) and, on a
refusal, the state of the closest grant — so support can say "your access ran out on the 3rd" rather
than "no".

Access over several grants is an **OR**: a subject gets in as soon as *any* grant is `Active` or in a
grace period. A refunded purchase next to a valid one, or an expired trial next to a paid licence,
locks nobody out. This is load-bearing; de-duplicating grants is only ever lossless because of it.

Superusers are **not** special-cased. That is a host authorisation concern, and baking `$user->super`
into a domain answer would both bind this package to one user model and hide an override inside a
decision. Check it before you ask.

## Limits

A grant says yes or no. A limit says how many: 50 analyses per year, 10 arrangements at a time. A
permission is a product grant; an amount is a limit. There is no feature-flag catalogue.

Limits hang off a product slug, like grants. Set them in the Control Panel (`Entitlements → Limits`),
in code, or in config:

```php
Entitlements::setLimits('choir', [
    'analyses' => ['value' => 50, 'period' => 'year'],   // usage, counted here
    'arrangements' => 25,                                 // stock, counted by the app
    'exports' => null,                                    // unlimited
]);

// config/entitlements.php
'limits' => [
    'keys' => ['analyses' => ['label' => 'Analyses', 'period' => 'year']],
    'products' => ['free' => ['analyses' => 10, 'arrangements' => 3]],
    'fallback_product' => 'free',   // applies to everybody without a grant carrying the key
    'period_anchor' => 'grant',     // or 'calendar'
],
```

`null` is unlimited, `0` is none. A stored row wins over config per key.

**Which number applies.** Every grant that currently gives access counts, the subject's own and those
of the subjects it acts for (see `extendSubjects()` below). The **highest** value wins, unlimited above
any number; at equal height the subject's own grant wins. When that grant expires or is revoked, the
limit falls to the next grant's, then to the fallback product, then to 0. Nothing is recomputed: it is
derived on every read.

**Two kinds.**

```php
// Usage per period: counted here, reset with the term.
if (! Entitlements::consume($user, 'analyses')) {
    return response()->json(['message' => 'Analysis quota exceeded.'], 403);
}
Entitlements::release($user, 'analyses');            // the job failed: give it back

// Stock: the app counts, the addon decides.
if (! Entitlements::withinLimit($user, 'arrangements', $tenant->projects()->count())) {
    return response()->json(['message' => 'Project limit reached.'], 403);
}

// A stock read without a count uses the last count withinLimit() was given.
Entitlements::limit($user, 'analyses');              // int|null (null = unlimited)
Entitlements::remaining($user, 'analyses');          // int|null
Entitlements::quota($user, 'analyses')->toArray();   // limit, used, remaining, period, holder, product…
Entitlements::quotasFor($user);                      // every limit, keyed by key
Entitlements::resetUsage($user, 'analyses', $actor);
```

**Where usage is counted.** At the *holder*: the subject whose grant sets the limit. A choir member
using the choir's plan books against the choir's counter, so "50 per year for the choir" means 50.

**Periods.** `month` or `year`, anchored on the start of the grant that sets the limit (a yearly plan
bought on 14 March resets on 14 March), or on calendar months and years with
`period_anchor = calendar`. The fallback product always uses calendar periods.
`entitlements:announce` announces a period that ended with something used (`UsageReset`, reason
`period`).

**Concurrency.** A booking is one conditional UPDATE (`used = used + n WHERE used <= limit - n`); of
two bookings on the last slot exactly one affects the row. The counter row is created with
`insertOrIgnore` against a unique index. No `lockForUpdate()`, which SQLite compiles to nothing, and
no caught INSERT, which would abort a caller's transaction on Postgres.
`tests/Feature/Limits/ConcurrentConsumeTest.php` races two real processes on MySQL and Postgres in CI.

### Further subjects (teams)

```php
Entitlements::extendSubjects(new TeamSubjects);   // a Contracts\SubjectExpander
Entitlements::extendSubjects($teams);              // any object with relatedSubjects(SubjectReference)
Entitlements::extendSubjects(fn ($subject, SubjectReference $ref) => [new SubjectReference('team', '7')]);
// or tag a class: app()->tag([TeamSubjects::class], 'entitlements.subject-expanders');
```

Applies to `decide()`, `allows()`, `activeProductSlugsFor()` and every limit. **Not** to
`forSubject()`, `renew()` or any write: a refund against a person must never revoke the team's grant.
One level deep, never recursive; an expander that throws is logged and skipped.

## States

| State | Access | Becomes active by itself | Stored in `status` |
|---|---|---|---|
| `Pending` | no | no — waits for a confirmation | yes |
| `Scheduled` | no | **yes**, when `starts_at` arrives | no |
| `Active` | **yes** | — | yes |
| `GracePeriod` | **yes** | — | yes |
| `Expired` | no | no | no |
| `Revoked` | no | no | yes |

Resolution order:

1. `revoked_at` set **or** `status = revoked` → `Revoked`
2. `status = pending` → `Pending`
3. `status = grace_period` → `GracePeriod` while `grace_until` is ahead, else `Expired`
4. `starts_at` in the future → `Scheduled`
5. `expires_at` reached → `Expired`
6. otherwise → `Active`

Three states are never written down, because they are relationships between the row's dates and the
current time. `status` holds only what the clock cannot tell you. `Support\StateResolver` also
exposes the same six branches as query constraints; a test builds every combination of status and
timestamps and asserts the SQL and the PHP select exactly the same rows, so the projection cannot
drift into a second opinion.

## Events

Eight. The package sends one mail, "limit reached", and only when an operator switches it on.

| Event | Handle | When | Payload |
|---|---|---|---|
| `EntitlementGranted` | `entitlements.granted` | a grant becomes `Active`, including out of `Pending` and when a scheduled grant starts | grant, previous state, actor |
| `EntitlementPending` | `entitlements.pending` | a grant is parked without access | grant, actor |
| `EntitlementRenewed` | `entitlements.renewed` | the window moved later | grant, previous expiry, actor |
| `EntitlementRevoked` | `entitlements.revoked` | an explicit revocation | grant, reason, previous state, actor |
| `EntitlementExpired` | `entitlements.expired` | the window closed | grant, the instant access actually ended |
| `LimitReached` | `entitlements.limit_reached` | a limit is full; once per period (usage) or per filling (stock) | subject, holder, key, limit, used, kind, product, period |
| `UsageConsumed` | `entitlements.usage_consumed` | a booking went through | subject, holder, key, amount, used, limit, period |
| `UsageReset` | `entitlements.usage_reset` | a counter starts from zero (period ended, or by hand) | holder, key, previous, reason, actor |

The three limit events carry references and scalars only, plus `brandId`.

**Webhook Manager.** All eight are triggers under the handles above. Body:
`event`, `event_id` (sha1 of the moment's own parts, for de-duplication), `occurred_at`, `brand`
(`{id, handle}`), `subject_type`, `subject_id`, then `entitlement` (id, product, source, source_ref,
state, subject, starts/expires/grace/revoked dates, revoked_reason) or `holder` + `limit` (key,
label, kind, limit, unlimited, used, remaining, product, period, period_start, period_end) or
`holder` + `reset`. Never `meta`, never an actor's address. Dispatched after the commit, in the
event's brand.

**Automations.** The three limit events are triggers registered by this addon (filter: limit key,
product). The five grant triggers ship with statamic-automations itself.

**Mail.** "Limit reached" goes to the person who reached it (`Entitlements::mailRecipientsUsing()`
to change that, for a team's owner), switched on per brand in the settings. The text is the
email-templates template `entitlements-limit-reached`, registered with its registry (trigger,
event, placeholders with examples, defaults; on an email-templates without a registry through the
`email-templates.sources` tag). `php artisan email-templates:import --source=Entitlements` makes it an
editable entry; until then the default goes out. Without email-templates the bundled text is sent.

Each fires **once per transition**, not once per call: the write paths use conditional UPDATEs and
check the affected-row count, so a retried job or a double-clicked button produces one event.

`EntitlementGranted` at start time and `EntitlementExpired` are the two that no write causes. They
come from `entitlements:announce`, which claims a row's `announced_state` before firing — that is why
the pass is safe to run every minute, and why running it once a day means a customer learns their
access ended up to a day late.

Listeners registered synchronously reproduce the source system's ordering exactly: the welcome mail
still goes out inside the same request, after the grant is written.

## Extension points

Four, all optional, all null objects by default. The fourth, `Contracts\SubjectExpander`, is
described under [Further subjects](#further-subjects-teams).

**`Contracts\SubjectResolver`** — how your idea of "somebody" becomes a `(type, id)` pair. The default
handles Eloquent models through the morph map and explicit `SubjectReference`s. Bind your own for
subjects that are not Eloquent models, and to give the Control Panel readable labels.

**`Contracts\PackageResolver`** — expanding a bundle into the products it contains. This package has
no catalogue by design; given the product being asked about, return the bundle slugs that would also
grant it. Unbound, bundles do not exist.

**`config('entitlements.sources')`** — display names for sources. A registry, never a whitelist: an
unregistered source writes, resolves and grants access exactly like a registered one.

## Optional siblings

Attached by `class_exists`, never by Composer. With none installed the package still writes grants,
resolves states, decides access, fires events and serves the Control Panel — asserted in
`tests/Feature/WithoutAnyBridgeTest.php`.

- `goldnead/statamic-activity` — records the grant events into the ledger.
- `goldnead/statamic-leadhub` — lets a CRM contact be the subject.
- `goldnead/statamic-webhook-manager` — all eight events as outbound webhook triggers.
- `goldnead/statamic-automations` — the three limit events as automation triggers.
- `goldnead/statamic-email-templates` — the "limit reached" mail as an editable template.

`tests/Unit/BootWithoutSiblingsTest.php` boots the addon in its own process with the last three
hidden from the autoloader.

Availability is checked with `class_exists` on a concrete class. Never `method_exists` on a facade:
a facade forwards through `__callStatic` and declares none of the methods it appears to have, so such
a guard answers `false` forever and disables itself silently. When the question really is about a
method, go through `Facade::getFacadeRoot()`.

## Control Panel

`Users → Entitlements`. A filterable listing (state, source, product), a detail screen with the
resolved state and a timeline, a manual grant form and a revocation form whose reason is mandatory.

`Entitlements → Limits` lists every product with limits or grants and edits a product's limits;
the grant detail screen shows the subject's limits as they apply now (used, left, period end, where
it is counted) with a reset. `Entitlements → Wiring` lists the eight events, the mail that goes with
one, and how many automations and outbound webhooks listen, with links to the Webhook Manager's
trigger catalogue.

Four permissions, because there are four different jobs:

- `view entitlements` — a support task
- `grant entitlements` — a commercial decision (restoring a revoked grant needs this one, not the next)
- `revoke entitlements` — the one that generates a refund request
- `manage entitlements limits` — changing what a plan allows, and resetting a counter

Manual grants are always written with source `manual`; the form does not let an admin type
`thrivecart` and fabricate a purchase in the audit trail.

Set `entitlements.cp.enabled` to `false` to remove the screens. The switch bites on the routes as
well as on the nav entry.

## Multi-brand

Every grant carries a `brand_id` and is scoped by `goldnead/statamic-brand-context`. Single-brand
installs are unaffected; the scope is a no-op there.

`brand_id` is part of the unique key. Without it, a grant in one brand would block the identical
grant in another — one tenant deciding what another may write. The cost is that the same purchase
booked against two brands produces two rows without complaint, which is a reporting question rather
than a leak.

## Notes on the schema

`source_ref` is **`NOT NULL`, default `''`**. NULLs never collide in a unique index on any engine, so
a nullable column here would switch idempotency off for every grant without an external reference —
manual grants, opt-ins, everything a human creates by hand, which are exactly the rows that get
double-submitted. Absence is the empty string; `hasSourceRef()` asks the question.

The unique index carries MySQL prefix lengths, keeping the key at 2448 of InnoDB's 3072 bytes.
`tests/Unit/IndexKeyLengthTest.php` compiles the migration through Laravel's MySQL grammar without a
server and fails if that ever stops being true.

## Testing

```bash
composer test          # SQLite
composer test:mysql    # the identical suite against real MySQL — not optional here
composer lint
composer analyse
npm test               # the two Control Panel pages
```

The MySQL leg is required rather than nice to have: this package's central guarantee is enforced by
a unique index, and everything that can make such an index quietly useless — InnoDB's key limit,
utf8mb4 byte arithmetic, prefix comparison under a case-insensitive collation — exists only on the
real engine.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
