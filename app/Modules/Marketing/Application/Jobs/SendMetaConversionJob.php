<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Jobs;

use App\Core\Application\Jobs\BaseJob;
use App\Modules\Marketing\Domain\Contracts\ConversionsApiContract;
use App\Modules\Marketing\Domain\DTOs\PurchaseConversionDTO;

/**
 * Ships one Purchase to Meta off the request path.
 *
 * QUEUED, BECAUSE THE CALLBACK MUST NOT WAIT ON META. PayTR retries its callback
 * until it hears "OK"; blocking that response on an outbound HTTP call to Graph
 * would turn a slow Meta into failed payments. The listener builds the payload
 * synchronously (it already holds the order data) and hands the network I/O here.
 *
 * IDEMPOTENT AT META, NOT HERE. A retried callback re-dispatches this job, but
 * every event carries `event_id = payment uuid`; Meta dedups on it, so a repeat
 * is a no-op on their side. `BaseJob` gives the retries/backoff for a transient
 * Graph failure; `parent::__construct()` is mandatory (CLAUDE.md).
 */
final class SendMetaConversionJob extends BaseJob
{
    public function __construct(private readonly PurchaseConversionDTO $dto)
    {
        parent::__construct();
    }

    public function handle(ConversionsApiContract $client): void
    {
        $client->sendPurchase($this->dto);
    }
}
