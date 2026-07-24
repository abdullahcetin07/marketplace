<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Organization\Domain\Enums\StoreOpeningRequestStatus;
use App\Modules\Organization\Domain\Exceptions\StoreOpeningException;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;

/**
 * The organization withdraws a request before a decision.
 *
 * Only a draft or pending request can be cancelled; a decided one is terminal.
 */
final class CancelStoreOpeningRequestAction extends BaseAction
{
    public function handle(mixed ...$arguments): StoreOpeningRequest
    {
        /** @var StoreOpeningRequest $request */
        $request = $arguments[0];

        if (! $request->status->isCancellable()) {
            throw StoreOpeningException::invalidTransition();
        }

        $request->forceFill(['status' => StoreOpeningRequestStatus::Cancelled])->save();

        return $request;
    }
}
