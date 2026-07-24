<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * The lifecycle of a platform invitation (ADR-031).
 *
 * Shared vocabulary, not Organization's: the invitation mechanism is Core
 * infrastructure reusable by any module (Store, Team, Admin…). It lives here
 * alongside the other cross-cutting enums so a consumer references it without
 * importing another module.
 *
 *   Pending   — issued, awaiting the recipient.
 *   Accepted  — completed by an authenticated account (single-use).
 *   Rejected  — declined by the recipient.
 *   Expired   — passed its lifetime unaccepted.
 *   Cancelled — withdrawn by the issuer.
 *
 * @see App\Core\Domain\Concerns\HasInvitationLifecycle
 */
enum InvitationStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Whether an invitation in this state can still be accepted — only a pending
     * one (expiry is a separate time check).
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * A terminal state cannot transition further.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
