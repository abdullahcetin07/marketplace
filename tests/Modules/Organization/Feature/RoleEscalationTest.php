<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationBankAccountChanged;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Separation of duties around the payout IBAN (security audit, 2026-08-18)
|--------------------------------------------------------------------------
|
| A Manager holds `MemberUpdateRole` but not `BankAccountUpdate`; Finance holds the
| latter. The audit found the gap between those two facts was crossable in two
| requests: promote yourself to Finance, then replace the IBAN the platform wires
| payouts to. Within-tenant, but it defeats a deliberate control over where real
| money goes.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * An organization with a member in the given role.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{org: Organization, actor: Seller, membership: OrganizationMember}
 */
function orgWithRole(OrganizationRole $role): array
{
    /** @var Seller $owner */
    $owner = Seller::factory()->create();
    /** @var Seller $actor */
    $actor = Seller::factory()->create();

    $org = Organization::factory()->create(['owner_id' => $owner->getKey()]);

    OrganizationMember::factory()->for($org)->role(OrganizationRole::Owner)
        ->create(['user_id' => $owner->getKey()]);

    $membership = OrganizationMember::factory()->for($org)->role($role)
        ->create(['user_id' => $actor->getKey()]);

    return ['org' => $org, 'actor' => $actor, 'membership' => $membership];
}

it('refuses a manager promoting themselves', function (): void {
    $fixture = orgWithRole(OrganizationRole::Manager);

    /*
     * **NOBODY CHANGES THEIR OWN ROLE.** This is the first half of the chain: self
     * to Finance, then the IBAN.
     */
    $this->actingAs($fixture['actor'], 'seller')
        ->patchJson(
            '/api/v1/organizations/'.$fixture['org']->uuid.'/members/'.$fixture['membership']->uuid,
            ['role' => OrganizationRole::Finance->value],
        )
        ->assertForbidden();

    expect($fixture['membership']->fresh()->role)->toBe(OrganizationRole::Manager);
});

it('refuses a manager conferring a capability it does not hold', function (): void {
    $fixture = orgWithRole(OrganizationRole::Manager);

    /** @var Seller $colleague */
    $colleague = Seller::factory()->create();

    $their = OrganizationMember::factory()->for($fixture['org'])->role(OrganizationRole::Editor)
        ->create(['user_id' => $colleague->getKey()]);

    /*
     * **THE SECOND HALF: A THROWAWAY COLLEAGUE INSTEAD OF YOURSELF.** Closing
     * self-promotion alone would only have added a step. `MemberUpdateRole` is the
     * power to move people between roles, not to mint `BankAccountUpdate`.
     */
    $this->actingAs($fixture['actor'], 'seller')
        ->patchJson(
            '/api/v1/organizations/'.$fixture['org']->uuid.'/members/'.$their->uuid,
            ['role' => OrganizationRole::Finance->value],
        )
        ->assertForbidden();

    expect($their->fresh()->role)->toBe(OrganizationRole::Editor);
});

it('refuses a manager inviting somebody straight into finance', function (): void {
    $fixture = orgWithRole(OrganizationRole::Manager);

    // The same door by another name: invite a throwaway address as Finance, accept,
    // change the IBAN.
    $this->actingAs($fixture['actor'], 'seller')
        ->postJson('/api/v1/organizations/'.$fixture['org']->uuid.'/invitations', [
            'email' => 'yeni@ornek.com',
            'role' => OrganizationRole::Finance->value,
        ])
        ->assertForbidden();
});

it('still lets a manager grant a role it fully holds', function (): void {
    $fixture = orgWithRole(OrganizationRole::Manager);

    /** @var Seller $colleague */
    $colleague = Seller::factory()->create();

    $their = OrganizationMember::factory()->for($fixture['org'])->role(OrganizationRole::Viewer)
        ->create(['user_id' => $colleague->getKey()]);

    /*
     * THE RULE IS A SUBSET TEST, NOT A BAN. A Manager still runs its team — Editor's
     * capabilities are all ones a Manager has.
     */
    $this->actingAs($fixture['actor'], 'seller')
        ->patchJson(
            '/api/v1/organizations/'.$fixture['org']->uuid.'/members/'.$their->uuid,
            ['role' => OrganizationRole::Editor->value],
        )
        ->assertOk();

    expect($their->fresh()->role)->toBe(OrganizationRole::Editor);
});

it('announces an IBAN change with the destination masked', function (): void {
    Event::fake([OrganizationBankAccountChanged::class]);

    $fixture = orgWithRole(OrganizationRole::Finance);

    $url = '/api/v1/organizations/'.$fixture['org']->uuid.'/bank-account';

    $body = [
        'account_holder' => 'Turuncu Kasa A.Ş.',
        'iban' => 'TR330006100519786457841326',
        'bank_name' => 'Ziraat',
        'currency_code' => 'TRY',
    ];

    // The first write is not a CHANGE — there was nothing to move.
    $this->actingAs($fixture['actor'], 'seller')->putJson($url, $body)->assertOk();

    Event::assertNotDispatched(OrganizationBankAccountChanged::class);

    $this->actingAs($fixture['actor'], 'seller')
        ->putJson($url, [...$body, 'iban' => 'TR120006100519786457841327'])
        ->assertOk();

    /*
     * **MASKED, DELIBERATELY.** The trail has to show that the payout destination
     * moved, not become a second copy of every seller's bank details readable by
     * anybody who may read audits.
     */
    Event::assertDispatched(
        OrganizationBankAccountChanged::class,
        fn (OrganizationBankAccountChanged $event): bool => str_ends_with($event->newIbanMasked, '1327')
            && ! str_contains($event->newIbanMasked, '0006100519')
            && $event->previousIbanMasked !== null,
    );
});
