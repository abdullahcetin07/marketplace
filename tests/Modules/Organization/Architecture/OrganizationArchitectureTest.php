<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Organization — architecture rules
|--------------------------------------------------------------------------
|
| Module-specific structural guarantees. The cross-module isolation, DTO-suffix
| and Domain-purity rules live in tests/Architecture/LayeringTest.php (Organization
| is listed there); these assert the module's own shape.
*/

$repositories = [
    'OrganizationRepository',
    'OrganizationPlanRepository',
    'OrganizationMemberRepository',
    'OrganizationInvitationRepository',
    'OrganizationDocumentRepository',
    'OrganizationBankAccountRepository',
    'StoreOpeningRequestRepository',
];

foreach ($repositories as $repository) {
    arch("{$repository} implements its contract")
        ->expect("App\\Modules\\Organization\\Infrastructure\\Repositories\\{$repository}")
        ->toImplement("App\\Modules\\Organization\\Domain\\Contracts\\{$repository}Contract");
}

arch('the Domain layer never depends on Infrastructure or Presentation')
    ->expect('App\Modules\Organization\Domain')
    ->not->toUse([
        'App\Modules\Organization\Infrastructure',
        'App\Modules\Organization\Presentation',
    ]);

arch('the Application layer never depends on Presentation')
    ->expect('App\Modules\Organization\Application')
    ->not->toUse('App\Modules\Organization\Presentation');

arch('enums are backed')
    ->expect('App\Modules\Organization\Domain\Enums')
    ->toBeEnums();

arch('actions are final and extend the base action')
    ->expect('App\Modules\Organization\Application\Actions')
    ->toBeFinal()
    ->toExtend('App\Core\Application\Actions\BaseAction');

arch('no debugging statements')
    ->expect('App\Modules\Organization')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);
