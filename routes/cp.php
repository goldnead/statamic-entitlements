<?php

use Goldnead\Entitlements\Http\Controllers\Cp\EntitlementController;
use Illuminate\Support\Facades\Route;

// The kill switch has to bite here as well as on the nav item. Hiding the entry
// while leaving the screens reachable by URL is not a disabled Control Panel.
if (! config('entitlements.cp.enabled', true)) {
    return;
}

/*
| The route parameter is `{entitlement}` and nothing is bound to it. An implicit
| route-model binding would claim the name application wide, so a sibling addon
| that puts `{entitlement}` in one of its own routes would get this addon's
| binder — which resolves against this addon's table, finds nothing and 404s a
| page that has nothing to do with entitlements. That is not hypothetical; it is
| how a sibling's delete button died.
|
| See tests/Feature/RouteParameterCollisionTest.php.
*/

Route::prefix('entitlements')->name('entitlements.')->group(function () {
    Route::get('/', [EntitlementController::class, 'index'])->name('index');
    Route::get('create', [EntitlementController::class, 'create'])->name('create');
    Route::post('/', [EntitlementController::class, 'store'])->name('store');

    // `create` is matched before `{entitlement}` and `{entitlement}` only matches
    // digits, so neither can swallow the other.
    Route::get('{entitlement}', [EntitlementController::class, 'show'])->name('show')->whereNumber('entitlement');

    Route::get('{entitlement}/revoke', [EntitlementController::class, 'revokeForm'])->name('revoke.form')->whereNumber('entitlement');
    Route::post('{entitlement}/revoke', [EntitlementController::class, 'revoke'])->name('revoke')->whereNumber('entitlement');
    Route::post('{entitlement}/restore', [EntitlementController::class, 'restore'])->name('restore')->whereNumber('entitlement');
});
