<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Organization\Application\Actions\UpsertBankAccountAction;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationBankAccount;
use App\Modules\Organization\Presentation\Requests\UpsertBankAccountRequest;
use App\Modules\Organization\Presentation\Resources\OrganizationBankAccountResource;
use Illuminate\Http\JsonResponse;

/**
 * The organization's payout bank account. Capability-gated; the full IBAN never
 * leaves the API (the resource masks it).
 */
final class BankAccountController extends BaseController
{
    public function __construct(
        private readonly UpsertBankAccountAction $upsert,
    ) {}

    /**
     * GET /organizations/{organization}/bank-account
     */
    public function show(Organization $organization): JsonResponse
    {
        $account = $organization->bankAccount()->with('currency')->first()
            ?? new OrganizationBankAccount(['organization_id' => $organization->getKey()]);

        $this->authorize('view', $account);

        return $this->ok($account->exists ? new OrganizationBankAccountResource($account) : null);
    }

    /**
     * PUT /organizations/{organization}/bank-account
     */
    public function update(UpsertBankAccountRequest $request, Organization $organization): JsonResponse
    {
        $account = $this->upsert->run($request->toDto($organization->getKey()));

        return $this->ok(new OrganizationBankAccountResource($account->load('currency')));
    }
}
