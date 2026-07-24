<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\Enums\OrganizationCapability as Cap;
use App\Modules\Organization\Domain\Enums\OrganizationRole as Role;

/*
|--------------------------------------------------------------------------
| Organization role → capability matrix (§5.1)
|--------------------------------------------------------------------------
|
| A UNIT test of the matrix — no database. Policies check the derived
| CAPABILITY, so getting this table right is getting authorization right.
*/

it('grants the owner every capability', function (): void {
    foreach (Cap::cases() as $capability) {
        expect(Role::Owner->allows($capability))->toBeTrue();
    }
});

it('lets a manager run the org but not transfer ownership or edit the bank account', function (): void {
    expect(Role::Manager->allows(Cap::MemberInvite))->toBeTrue()
        ->and(Role::Manager->allows(Cap::MemberRemove))->toBeTrue()
        ->and(Role::Manager->allows(Cap::OrganizationUpdate))->toBeTrue()
        ->and(Role::Manager->allows(Cap::OwnershipTransfer))->toBeFalse()
        ->and(Role::Manager->allows(Cap::BankAccountUpdate))->toBeFalse();
});

it('lets finance edit the bank account but not manage members', function (): void {
    expect(Role::Finance->allows(Cap::BankAccountUpdate))->toBeTrue()
        ->and(Role::Finance->allows(Cap::BankAccountView))->toBeTrue()
        ->and(Role::Finance->allows(Cap::MemberInvite))->toBeFalse()
        ->and(Role::Finance->allows(Cap::MemberRemove))->toBeFalse();
});

it('confines a viewer to reads', function (): void {
    expect(Role::Viewer->allows(Cap::OrganizationView))->toBeTrue()
        ->and(Role::Viewer->allows(Cap::MemberView))->toBeTrue()
        ->and(Role::Viewer->allows(Cap::OrganizationUpdate))->toBeFalse()
        ->and(Role::Viewer->allows(Cap::MemberInvite))->toBeFalse()
        ->and(Role::Viewer->allows(Cap::DocumentUpload))->toBeFalse();
});

it('never offers Owner as an assignable role (ownership is transfer-only)', function (): void {
    expect(Role::assignable())->not->toContain(Role::Owner)
        ->and(Role::assignable())->toContain(Role::Manager)
        ->and(Role::assignable())->toContain(Role::Viewer);
});
