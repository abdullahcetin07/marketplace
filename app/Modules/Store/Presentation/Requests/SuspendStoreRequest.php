<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;
use App\Modules\Store\Domain\Models\Store;

/**
 * An admin suspending a store, with a reason for the forensic record (ADR-027).
 * Authorised by `store.suspend` via StorePolicy — a platform power, not membership.
 */
final class SuspendStoreRequest extends BaseRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $store instanceof Store && $this->actor()?->can('suspend', $store) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
