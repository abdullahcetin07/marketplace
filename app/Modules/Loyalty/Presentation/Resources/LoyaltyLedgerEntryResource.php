<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Resources;

use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of "Puanlarım" history (ADR-081).
 *
 * @mixin LoyaltyLedgerEntry
 */
final class LoyaltyLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // The public id is the uuid; the internal id never leaves (#7).
            'id' => $this->uuid,
            'points' => $this->points,
            'source_type' => $this->source_type->value,
            /*
            | THE SUBJECT'S UUID, WHICH IS WHAT MAKES A ROW CLICKABLE — the order
            | a purchase paid for, the review that earned it. Phase 1 ships it as
            | an id rather than a link because Loyalty may not build another
            | module's URL.
            */
            'source_uuid' => $this->source_uuid,
            // Sent as data rather than a code the client maps: three strings in
            // two places is two places for them to drift.
            'label' => $this->source_type->label(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
