<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Generators;

use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\SkuGeneratorContract;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Str;

/**
 * A readable per-product prefix plus a random suffix, retried until free.
 *
 * WHY NOT SEQUENTIAL: a sequential SKU leaks catalog size and lets anyone
 * enumerate the range, the same reason internal ids never leave the application
 * (non-negotiable #7). Random keeps the code short enough to read aloud on a
 * warehouse floor without being a counter.
 *
 * The alphabet drops the characters people mis-transcribe — 0/O, 1/I — because
 * a SKU is read off a label by a human at least once in its life.
 *
 * @see App\Modules\Catalog\Domain\Contracts\SkuGeneratorContract
 */
final class DefaultSkuGenerator implements SkuGeneratorContract
{
    /**
     * Deliberately without 0, O, 1, I.
     */
    private const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * A bounded retry: with 32^8 possibilities a collision is already
     * vanishingly unlikely, so exhausting this means something else is wrong
     * and looping forever would only hide it.
     */
    private const int MAX_ATTEMPTS = 20;

    public function __construct(private readonly ProductRepositoryContract $products) {}

    public function generate(Product $product, string $combinationKey): string
    {
        $prefix = Str::upper(Str::substr(Str::slug($product->localized('title')), 0, 6));

        if ($prefix === '') {
            $prefix = 'SKU';
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $sku = $prefix.'-'.$this->randomCode();

            if (! $this->products->skuExists($sku)) {
                return $sku;
            }
        }

        // Fall back to something that cannot collide by construction rather
        // than returning a duplicate and letting the UNIQUE index throw.
        return $prefix.'-'.Str::upper(Str::uuid()->toString());
    }

    private function randomCode(int $length = 8): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
