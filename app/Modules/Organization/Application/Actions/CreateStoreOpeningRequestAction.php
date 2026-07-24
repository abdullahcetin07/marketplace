<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\DTOs\CreateStoreOpeningRequestDTO;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;

/**
 * Start a Store Opening Request (ADR-028).
 *
 * Creates it as a DRAFT the seller can compose; nothing is submitted, no limit
 * is checked yet, and — of course — no store is created. Submission is a
 * separate, deliberate step.
 */
final class CreateStoreOpeningRequestAction extends BaseAction
{
    public function handle(mixed ...$arguments): StoreOpeningRequest
    {
        /** @var CreateStoreOpeningRequestDTO $data */
        $data = $arguments[0];

        return StoreOpeningRequest::query()->create([
            'organization_id' => $data->organizationId,
            'requested_by' => $data->requestedBy,
            'status' => StoreOpeningRequestStatus::Draft,
            'store_name' => $data->storeName,
            'slug' => $data->slug,
            'category_id' => $data->categoryId,
            'description' => $data->description,
            'reason' => $data->reason,
        ]);
    }
}
