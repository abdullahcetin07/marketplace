<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Requests;

use App\Core\Presentation\Requests\BaseRequest;

/**
 * "What do these cost?" — a listing's prices, in one round trip (ADR-058).
 *
 * ANONYMOUS, and that is why `authorize()` returns true here rather than the
 * platform default of false (`BaseRequest`). This is a public buyer read: the
 * prices it returns are already on every product page, and requiring a login to
 * see what something costs would be the end of the shop.
 *
 * A POST THAT READS. Deliberate, and the one place this API breaks REST's letter:
 * a listing asks about a page of products, and 24 uuids in a query string is
 * ~900 characters — past what some proxies and CDNs will forward, and impossible
 * to grow. The body is the only honest place for a variable-length list.
 *
 * THE LIST IS CAPPED. Uncapped, one request could ask for the buy box of the
 * entire catalogue — each entry costing a store-liveness and an availability
 * check — which is a denial-of-service written as a feature. 100 is four times
 * the biggest page the browse will serve.
 */
final class BuyBoxPricesRequest extends BaseRequest
{
    /**
     * The cap. @see the class docblock.
     */
    public const int MAX_PRODUCTS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            // Validated as uuids so a malformed list is refused at the edge
            // rather than becoming a hundred pointless lookups.
            'product_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function productUuids(): array
    {
        /** @var array<int, string> $uuids */
        $uuids = array_values(array_unique((array) $this->validated('product_ids')));

        return $uuids;
    }
}
