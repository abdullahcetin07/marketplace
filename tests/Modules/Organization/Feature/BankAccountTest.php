<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Audit\Domain\Models\AuditEntry;
use App\Modules\Localization\Domain\Models\Currency;
use App\Modules\Organization\Application\Actions\UpsertBankAccountAction;
use App\Modules\Organization\Domain\DTOs\UpsertBankAccountDTO;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Organization bank account (Phase 4)
|--------------------------------------------------------------------------
|
| The IBAN is a payout credential: encrypted at rest, never in the audit trail,
| never shown in full to a non-owner. A failure here is a data-leak, not a
| feature bug.
*/

beforeEach(fn () => $this->seedPlatform());

function upsertBank(Organization $org, string $iban = 'TR330006100519786457841326'): OrganizationBankAccount
{
    return app(UpsertBankAccountAction::class)->run(new UpsertBankAccountDTO(
        organizationId: $org->getKey(),
        accountHolder: 'Acme Trading Ltd',
        iban: $iban,
        bankName: 'Test Bank',
        currencyCode: (string) Currency::query()->value('code'),
    ));
}

it('stores the IBAN encrypted at rest but readable through the model', function (): void {
    $org = Organization::factory()->create();
    $account = upsertBank($org, 'TR330006100519786457841326');

    // Readable through the cast…
    expect($account->iban)->toBe('TR330006100519786457841326');

    // …but ciphertext in the column.
    $raw = DB::table('organization_bank_accounts')->where('id', $account->getKey())->value('iban');
    expect($raw)->not->toBe('TR330006100519786457841326');
});

it('never writes the IBAN into the audit trail', function (): void {
    $org = Organization::factory()->create();
    $account = upsertBank($org, 'TR330006100519786457841326');

    $entry = AuditEntry::query()->forModel($account)->latest('id')->first();

    expect($entry)->not->toBeNull()
        // The non-secret change IS audited…
        ->and(array_keys($entry->new_values ?? []))->toContain('account_holder')
        // …but the IBAN never is, in any form.
        ->and(array_keys($entry->new_values ?? []))->not->toContain('iban');

    $json = (string) json_encode([$entry->old_values, $entry->new_values]);
    expect($json)->not->toContain('TR330006100519786457841326');
});

it('masks the IBAN to its last four digits', function (): void {
    $org = Organization::factory()->create();
    $account = upsertBank($org, 'TR330006100519786457841326');

    expect($account->maskedIban())->toContain('1326')
        ->and($account->maskedIban())->not->toContain('3300');
});

it('resets verification when the IBAN changes', function (): void {
    $org = Organization::factory()->create();
    $account = upsertBank($org);
    $account->forceFill(['verified_at' => now()])->save();

    $updated = upsertBank($org, 'TR000000000000000000000099');

    expect($updated->verified_at)->toBeNull();
});

it('gates the bank account on capability and organization (ADR-030)', function (): void {
    $org = Organization::factory()->create();
    $account = upsertBank($org);

    $finance = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Finance)
        ->create(['user_id' => $finance->getKey()]);

    $warehouse = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Warehouse)
        ->create(['user_id' => $warehouse->getKey()]);

    $outsider = Seller::factory()->create();

    expect($finance->can('update', $account))->toBeTrue()
        ->and($finance->can('view', $account))->toBeTrue()
        ->and($warehouse->can('view', $account))->toBeFalse()
        ->and($outsider->can('view', $account))->toBeFalse();
});
