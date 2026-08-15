<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\DTOs\GrantPointsDTO;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Write one row to the points ledger (ADR-081).
 *
 * **IDEMPOTENT, AND SILENTLY SO.** A re-emitted event, a queue retry, a sweep run
 * twice in a day — each must leave the balance unchanged, and none of them is an
 * error worth raising: the desired state is already true. So a repeat returns the
 * row that exists rather than throwing, and the caller needs no "have I done this"
 * check of its own.
 *
 * **CHECKED AND CAUGHT, BECAUSE A CHECK ALONE IS A RACE.** Two workers processing
 * the same event pass the `existsFor()` test together; the unique index is what
 * actually decides, and catching its violation turns the loser of that race into
 * the same no-op as the ordinary repeat.
 *
 * **NOTHING IS GRANTED WHEN THE PROGRAMME IS OFF**, and nothing when the points
 * round to zero: a ledger full of zero-point rows is history nobody can read, and
 * it would make "you earned points" an email about nothing.
 *
 * @see docs/modules/Loyalty.md
 */
final class GrantPointsAction extends BaseAction
{
    public function __construct(private readonly LoyaltyLedgerRepositoryContract $ledger) {}

    public function handle(mixed ...$arguments): ?LoyaltyLedgerEntry
    {
        /** @var GrantPointsDTO $data */
        $data = $arguments[0];

        if (! settings('loyalty.enabled', true)) {
            return null;
        }

        if ($data->points <= 0 || $data->customerUuid === '') {
            return null;
        }

        if ($this->ledger->existsFor($data->source, $data->sourceUuid)) {
            return null;
        }

        try {
            return $this->ledger->create([
                // No `uuid` here — `HasUuid` mints it on `creating`.
                'customer_uuid' => $data->customerUuid,
                'points' => $data->points,
                'source_type' => $data->source,
                'source_uuid' => $data->sourceUuid,
                'meta' => $data->meta,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The race, lost. Somebody else credited it; the balance is right.
            return null;
        }
    }
}
