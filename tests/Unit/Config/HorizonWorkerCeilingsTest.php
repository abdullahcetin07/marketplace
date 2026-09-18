<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Horizon worker ceilings
|--------------------------------------------------------------------------
|
| The numbers are env-tunable so a deployment sharing a box (staging beside
| production, 2026-09-18) can hold itself down without renaming its APP_ENV —
| which would also flip Eloquent strict mode and the secure-cookie default.
|
| What is pinned is the DEFAULT: an unset variable must still mean production's
| own sizing, because production is the deployment that sets nothing.
|
*/

it('defaults to production sizing when nothing is set', function (): void {
    $production = config('horizon.environments.production');

    expect($production['supervisor-default']['maxProcesses'])->toBe(20)
        ->and($production['supervisor-default']['minProcesses'])->toBe(2)
        ->and($production['supervisor-search']['maxProcesses'])->toBe(8)
        ->and($production['supervisor-media']['maxProcesses'])->toBe(4);
});

it('keeps every ceiling an integer, whatever the environment hands over', function (): void {
    foreach (config('horizon.environments.production') as $supervisor) {
        expect($supervisor['minProcesses'])->toBeInt()
            ->and($supervisor['maxProcesses'])->toBeInt();
    }
});
