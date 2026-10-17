<?php

use Goldnead\Entitlements\Tests\TestCase;
use Statamic\Facades\Role;

/*
 * cpUserWith() saves a role through the file repository, and the file lives in
 * the testbench app under vendor/, which outlives the test and the run. A role
 * left behind makes the next run slower (858 stale roles took a test from
 * seconds to minutes). TestCase::tearDown() runs removeRememberedRoles(); this
 * test runs the same step by hand and checks the file.
 */
it('removes the role cpUserWith() created, from memory and from roles.yaml', function () {
    $user = cpUserWith(['access cp']);
    $handle = $user->roles()->first()->handle();

    expect(Role::find($handle))->not->toBeNull()
        ->and(file_get_contents(resource_path('users/roles.yaml')))->toContain($handle);

    TestCase::removeRememberedRoles();

    expect(Role::find($handle))->toBeNull()
        ->and(file_get_contents(resource_path('users/roles.yaml')))->not->toContain($handle);
});
