<?php

namespace Goldnead\Entitlements\Contracts;

use Goldnead\Entitlements\Support\SubjectReference;

/**
 * Extension point: how an application's own idea of "somebody" becomes a
 * `(type, id)` pair, and how that pair reads in the Control Panel.
 *
 * The default {@see \Goldnead\Entitlements\Support\MorphSubjectResolver} handles
 * Eloquent models and explicit references, which covers users and CRM contacts.
 * Bind your own implementation to this interface when subjects are not Eloquent
 * models — a Statamic user from the file repository, a licence key, a device.
 *
 * `label()` exists so the listing does not have to load a row per record to show
 * a name. Returning the raw key is a perfectly good answer; returning something
 * that costs a query per row is not.
 */
interface SubjectResolver
{
    /** @throws \InvalidArgumentException when the value cannot be a subject. */
    public function reference(mixed $subject): SubjectReference;

    /** A short human-readable label, or null to fall back to the raw key. */
    public function label(SubjectReference $reference): ?string;
}
