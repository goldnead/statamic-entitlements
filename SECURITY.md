# Security Policy

## Supported versions

The latest minor release receives security fixes.

## Reporting a vulnerability

Please report privately to **info@adriangoldner.com** rather than in a public issue. Include the
version, what an attacker can reach, and the smallest reproduction you have.

## What this package decides

This package answers "may this subject use this product". A defect in it is an authorisation defect,
so the areas below are the ones worth attacking first, and the ones the suite covers hardest.

**State resolution.** `Support\StateResolver` is the only place that decides. It fails closed on
contradictory revocation signals (`revoked_at` set but `status` not, or the reverse), and `Pending`
and `Scheduled` grant nothing. Its SQL projection is pinned to the PHP definition by a test that
enumerates every combination of status and timestamps — a divergence between the two would be an
access defect that no single-branch test would catch.

**No unauthenticated routes.** Every route this package registers lives inside Statamic's
authenticated Control Panel group and is authorised through the Gate, read routes included. There is
no public endpoint and no token.

**Permissions.** `view`, `grant` and `revoke` are separate. Restoring a revoked grant requires
`grant`, not `revoke`.

**Superusers.** Deliberately not special-cased. If your application lets superusers bypass
entitlements, that check belongs in your application, in front of the question.

**Manual grants** are always written with source `manual`. The Control Panel form cannot be used to
fabricate a purchase from a payment provider in the audit trail.

**What crosses to the browser.** Page props carry urls, labels and booleans the server already
decided on. No configuration array and no token is ever handed to a page.
