<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Inventory\Domain\Exceptions\InventoryException;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Organization\Domain\Exceptions\InvitationException;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Exceptions\StoreException;

/*
|--------------------------------------------------------------------------
| Every message a user is shown is Turkish
|--------------------------------------------------------------------------
|
| The storefront renders the API's `message` verbatim, so an English string in a
| domain exception is an English string in front of a shopper — which is what a
| customer adding a sold-out product to their basket got until 2026-09-17 ("One
| of the items in your basket is no longer available.").
|
| Two things are pinned: the messages themselves, and the RULE that produced
| them — a factory must take its text from a language file, never from a literal.
| Without the second, the next exception written reintroduces the first.
|
*/

it('answers the shopper in Turkish', function (callable $make, string $expected): void {
    app()->setLocale('tr');

    expect($make()->userMessage())->toBe($expected);
})->with([
    'sold out' => [
        fn () => OrderException::offerNotSellable('offer-uuid'),
        'Sepetinizdeki ürünlerden biri artık satışta değil.',
    ],
    'not enough stock' => [
        fn () => OrderException::insufficientStock('offer-uuid', 3),
        'Sepetinizdeki ürünlerden biri için yeterli stok yok.',
    ],
    'empty basket' => [
        fn () => OrderException::cartIsEmpty(),
        'Sepetiniz boş.',
    ],
    'basket full' => [
        fn () => OrderException::cartIsFull(50),
        'Sepetinizde en fazla 50 farklı ürün olabilir.',
    ],
    'return window' => [
        fn () => OrderException::returnWindowClosed('order-uuid'),
        'Bu siparişin iade süresi doldu.',
    ],
    'seller: duplicate offer' => [
        fn () => OfferException::duplicateForVariant('variant-uuid'),
        'Bu varyant için zaten bir teklifiniz var. Yeni teklif açmak yerine mevcut teklifi düzenleyin.',
    ],
    'seller: price' => [
        fn () => OfferException::invalidPrice(),
        'Fiyat sıfırdan büyük olmalı.',
    ],
    'admin: barcode taken' => [
        fn () => CatalogException::gtinAlreadyInCatalog('8690000000000', 'product-uuid'),
        'Bu barkoda sahip bir ürün katalogda zaten var.',
    ],
    'inventory: reserve' => [
        fn () => InventoryException::insufficientStock('variant-uuid', 5, 1),
        'Bu miktarı ayırmak için yeterli stok yok.',
    ],
    'organization: already a member' => [
        fn () => InvitationException::alreadyMember(),
        'Bu şirketin zaten üyesisiniz.',
    ],
    'store: transition' => [
        fn () => StoreException::invalidTransition(StoreStatus::Draft, 'closed'),
        'Bu mağaza bu şekilde durum değiştiremez.',
    ],
]);

it('translates form errors instead of falling back to English', function (): void {
    app()->setLocale('tr');

    // `lang/tr/validation.php` is what stands between a Turkish storefront and
    // "The offer id field is required."
    expect(__('validation.required', ['attribute' => __('validation.attributes.email')]))
        ->toBe('E-posta adresi alanı zorunludur.')
        ->and(__('validation.uuid', ['attribute' => __('validation.attributes.offer_id')]))
        ->toBe('Ürün geçerli bir kimlik değil.')
        ->and(__('validation.min.numeric', ['attribute' => __('validation.attributes.quantity'), 'min' => 1]))
        ->toBe('Adet en az 1 olmalıdır.');
});

it('builds every domain exception message from a language file', function (): void {
    /*
     * A literal here is a message nobody can translate. The two allowed ones are
     * PaymentException's PSP detail, which the class deliberately keeps out of
     * `userMessage()` and hands to the error log instead (its own override
     * resolves the buyer's text from the context `reason`).
     */
    $allowed = ['PaymentException.php'];

    $offenders = [];

    foreach (glob(app_path('Modules/*/Domain/Exceptions/*.php')) as $file) {
        if (in_array(basename($file), $allowed, true)) {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (preg_match_all('/self::make\(\s*([\'"].*?[\'"])\s*[,)]/s', $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $literal) {
            $offenders[] = basename($file).': '.trim((string) preg_replace('/\s+/', ' ', $literal));
        }
    }

    expect($offenders)->toBe([]);
});
