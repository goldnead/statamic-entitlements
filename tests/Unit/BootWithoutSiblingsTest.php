<?php

use Symfony\Component\Process\Process;

/**
 * A site without the Webhook Manager, the automations addon and
 * email-templates, in a separate PHP process: this suite has two of them as dev
 * dependencies and would hide a provider that touches a missing class.
 */
it('boots and stays out of the way without the sibling addons', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../Boot/boot-without-siblings.php']);
    $process->setTimeout(120)->run();

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('booted, webhook bridge off, automations bridge off, email templates no')
        ->and($output)->not->toContain('not found');
});
