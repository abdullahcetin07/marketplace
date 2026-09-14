<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure;

use App\Modules\Marketing\Domain\Contracts\ConversionsApiContract;
use App\Modules\Marketing\Domain\DTOs\PurchaseConversionDTO;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends a Purchase to the Meta Conversions API (Graph `/{pixel}/events`).
 *
 * **HASHED PII, ALWAYS.** Meta requires match keys SHA-256'd, normalised first
 * (email lower-cased and trimmed). The raw e-mail never leaves as plaintext, and
 * `send_default_pii` is off elsewhere for the same reason.
 *
 * **DECIMAL AT THE EDGE, NOT A FLOAT.** `value` and each `item_price` are built
 * from minor units into a string here (ADR-005) — no float models money on the
 * way in; the JSON number is a string Meta parses.
 *
 * **DEDUP BY `event_id`.** The payment uuid is sent as `event_id`; the browser
 * pixel sends the same value as `eventID`, so Meta merges the pair into one
 * conversion (Marketing.md).
 *
 * **INERT WHEN UNCONFIGURED.** No token or `enabled=false` → returns without a
 * call. A 2xx is success; anything else throws so `BaseJob` retries the transient
 * failure and logs the permanent one.
 */
final class MetaConversionsApiClient implements ConversionsApiContract
{
    public function sendPurchase(PurchaseConversionDTO $dto): void
    {
        if (! (bool) config('marketing.meta.enabled')) {
            return;
        }

        $pixelId = (string) config('marketing.meta.pixel_id');
        $token = (string) config('marketing.meta.access_token');

        if ($pixelId === '' || $token === '') {
            return;
        }

        $version = (string) config('marketing.meta.api_version', 'v21.0');
        $testCode = (string) config('marketing.meta.test_event_code', '');

        $userData = [];

        if ($dto->email !== null && $dto->email !== '') {
            $userData['em'] = [hash('sha256', mb_strtolower(trim($dto->email)))];
        }

        $event = [
            'event_name' => 'Purchase',
            'event_time' => $dto->eventTime,
            'event_id' => $dto->eventId,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'currency' => $dto->currencyCode,
                'value' => $this->decimal($dto->valueMinor),
                'content_type' => 'product',
                'content_ids' => $dto->contentIds,
                'contents' => $dto->contents,
            ],
        ];

        // The token rides in the BODY, never the query string: a connection
        // failure's exception message quotes the URL, and that message is what
        // lands in `failed_jobs` and the error log.
        $payload = ['data' => [$event], 'access_token' => $token];

        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        $response = Http::asJson()
            ->timeout(15)
            ->post(
                "https://graph.facebook.com/{$version}/{$pixelId}/events",
                $payload,
            );

        if ($response->failed()) {
            // Body may echo the token back in an error; log status only.
            throw new RuntimeException(
                'Meta Conversions API rejected the event (HTTP '.$response->status().')',
            );
        }
    }

    /**
     * Minor units to a decimal string — `129.90`, never a float (ADR-005).
     */
    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) abs($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
