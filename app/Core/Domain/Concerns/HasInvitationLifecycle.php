<?php

declare(strict_types=1);

namespace App\Core\Domain\Concerns;

use App\Shared\Enums\InvitationStatus;

/**
 * The reusable invitation lifecycle (ADR-031).
 *
 * A module's invitation model uses this trait to get the status transitions,
 * the expiry check and the "can this still be accepted?" predicate. The module
 * owns the model — its target FK (organization, store, team…), its role, its
 * table — while Core owns the mechanism, so every consumer behaves the same.
 *
 * Requires these columns on the model:
 *   - `status`      cast here to InvitationStatus
 *   - `expires_at`  cast here to datetime, nullable
 *   - `token_hash`  the ONLY stored form of the token (never the raw token)
 *   - `accepted_at`, `accepted_by`  nullable, stamped on acceptance
 *
 * The raw token is issued and verified through
 * `App\Core\Domain\Contracts\InvitationTokenizerContract`, not here — this trait
 * touches only the lifecycle state, never the secret.
 *
 * @see App\Shared\Enums\InvitationStatus
 * @see App\Core\Domain\Contracts\InvitationTokenizerContract
 */
trait HasInvitationLifecycle
{
    public function initializeHasInvitationLifecycle(): void
    {
        $this->mergeCasts([
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the invitation can be accepted right now: pending and not expired.
     * The single predicate every accept path checks.
     */
    public function isAcceptable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /**
     * Complete the invitation. Single-use: a subsequent accept finds it no
     * longer pending.
     */
    public function markAccepted(?int $acceptedBy = null): void
    {
        $this->forceFill([
            'status' => InvitationStatus::Accepted,
            'accepted_at' => now(),
            'accepted_by' => $acceptedBy,
        ])->save();
    }

    public function markRejected(): void
    {
        $this->forceFill(['status' => InvitationStatus::Rejected])->save();
    }

    public function markCancelled(): void
    {
        $this->forceFill(['status' => InvitationStatus::Cancelled])->save();
    }

    public function markExpired(): void
    {
        $this->forceFill(['status' => InvitationStatus::Expired])->save();
    }
}
