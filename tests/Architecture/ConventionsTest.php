<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture Tests — Conventions
|--------------------------------------------------------------------------
|
| Mechanical rules that are cheap to enforce and expensive to fix in bulk once
| a codebase has grown.
|
*/

arch('strict types are declared everywhere')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging statements survive review')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('enums are backed and final by construction')
    ->expect('App\Shared\Enums')
    ->toBeEnums()
    // HasEnumHelpers is a trait that lives alongside them.
    ->ignoring('App\Shared\Enums\Concerns');

arch('traits live in the traits namespace')
    ->expect('App\Shared\Traits')
    ->toBeTraits();

arch('contracts are interfaces')
    ->expect('App\Core\Domain\Contracts')
    ->toBeInterfaces();

arch('base classes are abstract')
    ->expect([
        'App\Core\Application\Actions\BaseAction',
        'App\Core\Application\Jobs\BaseJob',
        'App\Core\Application\Services\BaseService',
        'App\Core\Domain\DataTransferObjects\BaseDTO',
        'App\Core\Domain\Events\BaseEvent',
        'App\Core\Domain\Exceptions\BaseException',
        'App\Core\Infrastructure\Observers\BaseObserver',
        'App\Core\Infrastructure\Repositories\BaseRepository',
        'App\Core\Presentation\Controllers\BaseController',
        'App\Core\Presentation\Policies\BasePolicy',
        'App\Core\Presentation\Requests\BaseRequest',
        'App\Core\Presentation\Resources\BaseResource',
    ])
    ->toBeAbstract();

arch('service providers are final')
    ->expect('App\Providers')
    ->toBeFinal();

arch('console commands are final and extend Command')
    ->expect('App\Console\Commands')
    ->toBeFinal()
    ->toExtend(Illuminate\Console\Command::class);

arch('repositories implement the persistence contract')
    ->expect('App\Core\Infrastructure\Repositories')
    ->toImplement(App\Core\Domain\Contracts\RepositoryContract::class);

/*
| Laravel's own recommended baseline: no env() outside config, models in the
| right place, and so on.
*/
/*
| ADR-026: the TOTP library is reachable only through its provider. No service,
| controller or action may name Google2FA directly.
*/
arch('nothing outside the TOTP provider depends on Google2FA')
    ->expect('PragmaRX\Google2FA')
    ->toOnlyBeUsedIn('App\Modules\Identity\Infrastructure\Totp');

/*
| Laravel's preset assumes the DEFAULT skeleton — controllers only in
| App\Http\Controllers, providers only in App\Providers. This is a modular
| monolith (ADR-002): controllers live in App\Modules\*\Presentation\Controllers
| and each module owns its *ServiceProvider. The module layout is enforced by
| LayeringTest and the per-module architecture tests, so the preset is scoped to
| the non-module code it was written for.
*/
arch()->preset()->laravel()->ignoring('App\Modules');

/*
| The security preset flags every md5/sha1 call, on the assumption that a hash
| is protecting something. None of these are: the timezone repository hashes a
| name to shorten a cache key, and the rest implement Laravel's own email
| verification convention, where the URL signature is the credential and
| `sha1(email)` only proves the link matches the account.
*/
arch()->preset()->security()->ignoring([
    'App\Modules\Localization\Infrastructure\Repositories\TimezoneRepository',
    'App\Modules\Identity\Application\Actions\VerifyEmailAction',
    'App\Modules\Identity\Application\Services\AuthService',
    'App\Modules\Identity\Infrastructure\Notifications\VerifyEmailNotification',
]);
