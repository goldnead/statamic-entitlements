<?php

use Statamic\Facades\Role;

/*
 * cpUserWith() saves a role through the file repository, and the file lives in
 * the testbench app under vendor/, which outlives the test and the run. A role
 * left behind makes the next run slower (858 stale roles took a test from
 * seconds to minutes). These two tests run in file order: the first one makes
 * a user, the second one asserts nothing of it survived the teardown.
 */

$handlesMadeHere = new ArrayObject;

it('creates a role for the helper user', function () use ($handlesMadeHere) {
    $user = cpUserWith(['access cp']);

    $handle = $user->roles()->first()->handle();
    $handlesMadeHere->append($handle);

    expect(Role::find($handle))->not->toBeNull();
});

it('leaves no role from the previous test in roles.yaml', function () use ($handlesMadeHere) {
    expect($handlesMadeHere)->toHaveCount(1);

    $handle = $handlesMadeHere[0];

    expect(Role::find($handle))->toBeNull()
        ->and(file_get_contents(resource_path('users/roles.yaml')))->not->toContain($handle);
});
