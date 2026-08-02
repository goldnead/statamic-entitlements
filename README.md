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

Four, and the package sends nothing else — no mail, no notifications, no magic links.

| Event | When | Payload |
|---|---|---|
| `EntitlementGranted` | a grant becomes `Active`, including out of `Pending` and when a scheduled grant starts | grant, previous state, actor |
| `EntitlementPending` | a grant is parked without access | grant, actor |
| `EntitlementRevoked` | an explicit revocation | grant, reason, previous state, actor |
| `EntitlementExpired` | the window closed | grant, the instant access actually ended |

Each fires **once per transition**, not once per call: the write paths use conditional UPDATEs and
check the affected-row count, so a retried job or a double-clicked button produces one event.

`EntitlementGranted` at start time and `EntitlementExpired` are the two that no write causes. They
come from `entitlements:announce`, which claims a row's `announced_state` before firing — that is why
the pass is safe to run every minute, and why running it once a day means a customer learns their
access ended up to a day late.

Listeners registered synchronously reproduce the source system's ordering exactly: the welcome mail
still goes out inside the same request, after the grant is written.

## Extension points

Three, all optional, all null objects by default.

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

- `goldnead/statamic-activity` — records all four events into the ledger.
- `goldnead/statamic-leadhub` — lets a CRM contact be the subject.

Availability is checked with `class_exists` on a concrete class. Never `method_exists` on a facade:
a facade forwards through `__callStatic` and declares none of the methods it appears to have, so such
a guard answers `false` forever and disables itself silently. When the question really is about a
method, go through `Facade::getFacadeRoot()`.

## Control Panel

`Users → Entitlements`. A filterable listing (state, source, product), a detail screen with the
resolved state and a timeline, a manual grant form and a revocation form whose reason is mandatory.

Three permissions, because there are three different jobs:

- `view entitlements` — a support task
- `grant entitlements` — a commercial decision (restoring a revoked grant needs this one, not the next)
- `revoke entitlements` — the one that generates a refund request

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
