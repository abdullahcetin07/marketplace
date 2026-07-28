<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A moderator sent a product proposal back to its seller with a reason
 * (`NeedsRevision`), for them to fix and re-submit.
 *
 * NOT IN §7's LIST, deliberately added: §2.6 rules that §3.1/§5 are normative
 * and that `NeedsRevision` is a real state of the lifecycle, and §7's
 * enumeration cannot announce a transition it omits. The alternative — a
 * silent state change — would leave the humane middle path (mirroring the KYC
 * document pattern) as the one transition with no forensic record.
 *
 * @see docs/modules/Catalog.md §2.6, §3.1, §7
 */
final class ProductRevisionRequested extends BaseEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productUuid,
        public readonly ?string $proposedByOrgUuid,
        public readonly string $reason,
        public readonly ?int $moderatedBy = null,
    ) {
        parent::__construct();
    }
}
