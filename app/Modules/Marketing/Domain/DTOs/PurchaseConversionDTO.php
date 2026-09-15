<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain\DTOs;

/**
 * One server-side Meta "Purchase" conversion (Marketing.md).
 *
 * Money stays in MINOR UNITS here (the platform rule, ADR-005); the decimal
 * string Meta wants is built at the HTTP edge in the client, never as a float on
 * this object. `contents` line prices are already decimal strings for the same
 * reason — the caller converts once, from the line's minor-unit price.
 *
 * `eventId` is the payment uuid, and that is the whole deduplication mechanism:
 * the browser pixel sends the identical value as `eventID`, so Meta merges the
 * two. `contentIds` are PRODUCT uuids, matching the Meta catalog feed's `g:id`
 * (BUILD_GOOGLE_MERCHANT_FEED / Meta target) so dynamic ads resolve the item.
 *
 * The four browser signals are optional: they exist only when the pay request
 * was captured, and `fbp`/`fbc` only for a shopper who accepted marketing cookies.
 */
final class PurchaseConversionDTO
{
    /**
     * @param array<int, string> $contentIds product uuids
     * @param array<int, array{id: string, quantity: int, item_price: string}> $contents
     */
    public function __construct(
        public readonly string $eventId,
        public readonly int $valueMinor,
        public readonly string $currencyCode,
        public readonly ?string $email,
        public readonly array $contentIds,
        public readonly array $contents,
        public readonly int $eventTime,
        public readonly ?string $fbp = null,
        public readonly ?string $fbc = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $clientUserAgent = null,
    ) {}
}
