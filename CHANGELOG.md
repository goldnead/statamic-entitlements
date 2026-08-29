# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed: the last second of a period is inside the "active" figure

The three event figures compare their window half-open — `< midnight` rather than
`<= 23:59:59.999999` — because a query binding formats a date as `Y-m-d H:i:s` and drops the
fraction. `Active` could not inherit that: a stock is asked *of an instant*, so it writes its own
comparisons, and they were the inclusive kind.

Two consequences, both silent and both only on engines that keep the fraction. A grant that began at
23:59:59.500 on the closing day was counted by "granted" and missing from "active", on the same
screen for the same period. And a grant whose `expires_at` or `revoked_at` fell in that same
fraction was counted as gone by "expired"/"revoked" and still live by "active".

The running balance had the third half of it: `whereBetween` is inclusive at the far end, so an
arrival or a departure in the final second landed in no bucket at all and the chart disagreed with
its own headline.

## [1.2.0] — 2026-08-29

### Added: this addon's figures appear in Insights

From 1.1.0 `statamic-insights` is no longer a revenue report but the family's reporting layer: an
addon registers what it can count and gets the period, the comparison against the period before,
the chart, the breakdowns and two finished screens in return.

The coupling is optional in **both** directions. Without Insights nothing here is missing; without
this addon only its own group is missing over there. `suggest`, never `require`.

Every figure follows the contract's house rules: **null is not zero** (a rate with no denominator
has no answer and does not print 0 %), `available()` decides existence and never the data, gaps in
a series are filled by Insights rather than by the metric, and a filter a metric does not
understand is ignored rather than fatal.

Four figures: granted, revoked, expired, active. The active one is a stock and is therefore asked of
an instant rather than of a window.

Two things found here went back upstream. This package writes its timestamps in UTC unconditionally,
which had the clamp on "now" reading the wrong clock — one `zone()` now, instead of a restated
`untilNow()`. And the test that used to hold that the figures do **not** stop at a brand boundary is
reversed: a tile counting four brands beside one counting a single brand is a data leak between
customers, not a feature.

### Fixed: a figure counts the current brand only

While the family was being wired up this question got four different answers, and side by side on
one screen that is worse than none: one tile showed three other brands' turnover while its
neighbour filtered correctly. The rule now lives once, in `TableMetric::brandScoped()`, transcribed
from `BrandScope::apply()`; this package only names the column, and the figure, the chart and every
breakdown narrow together.

With no brand selected the tile reads **0 and stays**. A reader can make sense of a zero; a tile
that is not there he cannot notice.

## [1.1.1] — 2026-08-26

### Fixed

- **The listing now asks the host's `SubjectResolver`.** The detail view always did; the listing
  did not, so a host that had gone to the trouble of binding a resolver still read
  `App\Models\User:4` on the one screen people actually scan. The resolver worked. Nothing asked
  it.

  Found by opening the screen, and it is the failure mode this package's own suite could not see:
  with the shipped `MorphSubjectResolver` both paths return the same raw key, so the listing looked
  correct in every test that never bound one. The new test binds one, which is the only way the
  difference exists at all.

  The row now carries both `subject` (the resolved label, falling back to the key as
  `subjectLabel()` always has) and `subject_key` (the raw key). The listing prints the key beside
  the name only when it adds something: a name is a display, not an identity, and two members can
  share one.

## [1.1.0] — 2026-08-25

### Added

- **`renew()`** — push an existing grant's window forward. A subscription that renews every month
  must not create a grant every month, and `grant()` refuses to widen an existing window on purpose
  ("a retry is not a renewal"). That rule is right, and it left a gap: a billing cycle calling
  `grant()` either did nothing or wrote a second row, so a year of a membership was twelve rows and
  "does this person have access" became an aggregation.

  Deliberately narrow. It never shortens — a late webhook carries an old date and the time was paid
  for. It lifts a grace period, because payment is exactly what that period was waiting for. It
  refuses a revoked grant, because access taken away deliberately is not restored by a charge; that
  is what `restore()` is for and it is a decision somebody makes. And it returns `null` when there is
  nothing to renew, so the caller can fall back to `grant()` for a first payment.

- **`EntitlementRenewed`**, and not a second `EntitlementGranted`: the subject had access before and
  has it after, so a listener that welcomes people would otherwise greet the same person every month.
  Carries the window it replaces, because an audit asks "until when did they have it before" and the
  row no longer answers.

## [1.0.2] — 2026-08-25

### Fixed

- **A grant without an expiry date showed the word " UTC" in the listing.** `$date?->format(...).'
  UTC'` looks right and is not: the nullsafe operator short-circuits only the call, so a null date
  still concatenated. The detail panel filtered that string back out afterwards — a workaround that
  hid the bug rather than fixing it — while the listing printed it at the reader.

  Both now go through one method that returns null for a missing date. Found by installing this
  addon next to its twenty siblings in a demo and looking at the screen.

## [1.0.1] — 2026-08-05

### Fixed

- The breakpoint-less single-column grid utility is no longer used on the detail screen. Every
  addon in this family ships its own Tailwind build and all of them land in the same
  `addon-utilities` layer; media queries add no specificity, so that bare rule from whichever
  addon stylesheet loads last won against this screen's `lg:` variant and pinned the grid to one
  column at every width. Invisible when this addon is checked alone, visible as soon as two
  addons of the family are installed together. A grid falls back to one column on its own, and
  the overflow guard the utility's `minmax(0,1fr)` track provided is now explicit on the panels.

## [1.0.0] — 2026-08-03

### Added

- Grants with a polymorphic subject, product slug, source, source reference, window, grace period
  and revocation, brand-scoped from the first migration.
- One state machine — `Pending`, `Scheduled`, `Active`, `GracePeriod`, `Expired`, `Revoked` —
  resolved in exactly one place, with a query projection pinned to it by an exhaustive test.
- `Scheduled` as a state of its own, replacing the extracted system's habit of reporting a
  not-yet-started grant as expired on the customer's own account screen.
- Real revocation: `revoke()` with a mandatory reason, `restore()` as a separate decision, both read
  by the resolver.
- Idempotency enforced by a unique index over `(subject_type, subject_id, product_slug, source,
  source_ref, brand_id)`, present in the table's first migration, with `source_ref` NOT NULL so the
  constraint also binds grants with no external reference.
- Atomic `claimPending()` for exactly-once confirm-first delivery.
- Four events — `EntitlementGranted`, `EntitlementPending`, `EntitlementRevoked`,
  `EntitlementExpired` — each fired once per transition. The package itself sends nothing.
- `entitlements:announce`, the scheduled pass for the two transitions the clock causes.
- Control Panel: listing with state/source/product filters, detail screen with timeline, manual grant
  form, revocation form with a required reason, and three separate permissions.
- Extension points: `SubjectResolver`, `PackageResolver`, and a source display registry.
- Optional `statamic-activity` bridge, attached by `class_exists` and never required.
