<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Store — architecture rules
|--------------------------------------------------------------------------
|
| Module-specific structural guarantees. The cross-module isolation (Store may
| import Organization's Domain\Events only — ADR-033), DTO-suffix and Domain-purity
| rules live in tests/Architecture/LayeringTest.php (Store is listed there); these
| assert the module's own shape.
*/

arch('StoreRepository implements its contract')
    ->expect('App\Modules\Store\Infrastructure\Repositories\StoreRepository')
    ->toImplement('App\Modules\Store\Domain\Contracts\StoreRepositoryContract');

arch('the Domain layer never depends on Infrastructure or Presentation')
    ->expect('App\Modules\Store\Domain')
    ->not->toUse([
        'App\Modules\Store\Infrastructure',
        'App\Modules\Store\Presentation',
    ]);

arch('the Application layer never depends on Presentation')
    ->expect('App\Modules\Store\Application')
    ->not->toUse('App\Modules\Store\Presentation');

arch('enums are backed')
    ->expect('App\Modules\Store\Domain\Enums')
    ->toBeEnums();

arch('actions are final and extend the base action')
    ->expect('App\Modules\Store\Application\Actions')
    ->toBeFinal()
    ->toExtend('App\Core\Application\Actions\BaseAction');

arch('no debugging statements')
    ->expect('App\Modules\Store')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);

/*
| ADR-033, stated as a test at the module's own boundary too: Store never names
| an Organization model/service/repository. The event payload and (from Phase 2)
| a Core query contract are the only channels. Organization's Domain\Events are
| the one permitted import — the creation seam.
*/
arch('Store never imports Organization internals (ADR-033)')
    ->expect('App\Modules\Store')
    ->not->toUse([
        'App\Modules\Organization\Domain\Models',
        'App\Modules\Organization\Domain\Contracts',
        'App\Modules\Organization\Domain\DTOs',
        'App\Modules\Organization\Domain\Enums',
        'App\Modules\Organization\Application',
        'App\Modules\Organization\Infrastructure',
        'App\Modules\Organization\Presentation',
    ]);

/*
| The Domain layer is framework-agnostic: it must not reach for HTTP, the
| container, or the UI. (The global ADR-019 purity rule in LayeringTest already
| bars cache/request/encrypt; this pins the Store-specific surfaces.)
*/
arch('the Store Domain layer knows nothing of HTTP or Filament')
    ->expect('App\Modules\Store\Domain')
    ->not->toUse([
        'Illuminate\Http',
        'Filament',
        'App\Modules\Store\Presentation',
        'App\Modules\Store\Infrastructure',
    ]);

arch('Store DTOs are immutable value objects')
    ->expect('App\Modules\Store\Domain\DTOs')
    ->toBeFinal();

arch('Store controllers are final and extend the base controller')
    ->expect('App\Modules\Store\Presentation\Controllers')
    ->toBeFinal()
    ->toExtend('App\Core\Presentation\Controllers\BaseController');

arch('Store resources extend the base resource (allow-list discipline)')
    ->expect('App\Modules\Store\Presentation\Resources')
    ->toBeFinal()
    ->toExtend('App\Core\Presentation\Resources\BaseResource');
