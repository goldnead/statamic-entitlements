# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
