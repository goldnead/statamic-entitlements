<?php

use Goldnead\Entitlements\Tests\TestCase;

/**
 * This package must not claim a route-parameter name application wide.
 *
 * An implicit route-model binding is global. A sibling addon that puts
 * `{entitlement}` — or `{contact}`, or `{record}` — in one of its own routes
 * would get this package's binder, which resolves against this package's table,
 * finds nothing, and aborts 404 on a page that has nothing to do with
 * entitlements. That is not hypothetical: a sibling's delete button died exactly
 * this way.
 *
 * The probes are mounted by the bed rather than by this test, because a sibling
 * registers its routes at boot and therefore ahead of Statamic's `{segments?}`
 * frontend catch-all. A route added from inside a test body sits behind that
 * catch-all and answers 404 no matter what the bindings do — which would make
 * this pass for entirely the wrong reason.
 */
it('binds no route parameter a sibling addon might also use', function (string $name) {
    $this->get('sibling-probe/'.$name.'/hello-world')
        ->assertOk()
        ->assertSee('hello-world');
})->with(TestCase::NAMES_A_SIBLING_MIGHT_USE);
