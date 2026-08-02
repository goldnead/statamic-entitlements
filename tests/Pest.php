<?php

use Goldnead\Entitlements\Tests\TestCase;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * Loaded before any test runs, so `class_exists()` gives the whole run the same
 * answer about the optional sibling addon. A fixture that declared itself lazily
 * inside one test would make every other test's result depend on file order.
 * See the fixture's own header for what it stands in for and why.
 */
require_once __DIR__.'/Fixtures/StandInActivityFacade.php';

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * A Control Panel user carrying exactly the given permissions.
 *
 * Lives here rather than in one of the test files so every file can reach it
 * without depending on which file Pest happened to load first.
 *
 * @param  list<string>  $permissions
 */
function cpUserWith(array $permissions): Statamic\Contracts\Auth\User
{
    $handle = 'role-'.Str::random(8);

    Role::make($handle)->addPermission($permissions)->save();

    $user = User::make()
        ->email(Str::random(8).'@example.test')
        ->assignRole($handle);

    $user->save();

    return $user;
}
