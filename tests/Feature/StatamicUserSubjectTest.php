<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Statamic\Facades\User;

/**
 * Ein Statamic-Nutzer als Subject. Bisher warf die Vorgabe dafür, und jeder
 * Aufrufer baute sich das Paar selbst (statamic-courses tut es bis heute).
 * Mit dem Datei-Repository ist das Paar `user` + Statamic-ID.
 */
it('takes a file-backed Statamic user as a subject', function () {
    $user = User::make()->email('anna@example.test');
    $user->save();

    $reference = Entitlements::reference($user);

    expect($reference->type)->toBe('user')
        ->and($reference->id)->toBe((string) $user->id());

    Entitlements::grant($user, 'kurs', 'manual');

    expect(Entitlements::allows($reference, 'kurs'))->toBeTrue();
});
