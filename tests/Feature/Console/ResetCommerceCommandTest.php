<?php

declare(strict_types=1);

use App\Console\Commands\ResetCommerceCommand;
use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Models\CustomerAddress;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| marketplace:reset-commerce — the most destructive thing here
|--------------------------------------------------------------------------
|
| **THE ASSERTIONS THAT MATTER ARE THE ONES ABOUT WHAT SURVIVES.** Proving a wipe
| wiped something is easy; the whole risk in this command is the other direction —
| a merchant who registered, opened a store and passed KYC having to do it again,
| or an audit trail disappearing along with the data it describes.
|
| So every test below checks a KEEP as well as a DELETE.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * Something in every table group the command touches, plus the things it must
 * not.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{seller: Seller, customer: Customer, org: Organization, store: Store, product: Product, offer: Offer, order: Order}
 */
function commerceToWipe(): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $brand = Brand::factory()->create();
    $product = Product::factory()->for($category, 'category')->for($brand)->published()->create([
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 12_000,
        stockQuantity: 10,
    ));

    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $customerId = (int) $customer->getKey();

    app(AddCartItemAction::class)->run($customerId, $customer->uuid, new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: 2,
    ));

    $address = app(CreateCustomerAddressAction::class)->run($customerId, $customer->uuid, new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run($customerId, $customer->uuid, new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    return [
        'seller' => $seller,
        'customer' => $customer,
        'org' => $organization,
        'store' => $store,
        'product' => $product,
        'offer' => $offer,
        'order' => $orders[0],
    ];
}

it('wipes the catalog and the commerce built on it', function (): void {
    $fixture = commerceToWipe();

    // The premise: there is something to delete in every group.
    expect(Product::query()->count())->toBeGreaterThan(0)
        ->and(Offer::query()->count())->toBeGreaterThan(0)
        ->and(StockItem::query()->count())->toBeGreaterThan(0)
        ->and(Order::query()->count())->toBeGreaterThan(0)
        ->and(OrderLine::query()->count())->toBeGreaterThan(0);

    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(Brand::query()->count())->toBe(0)
        ->and(Offer::query()->count())->toBe(0)
        ->and(StockItem::query()->count())->toBe(0)
        ->and(Order::query()->count())->toBe(0)
        ->and(OrderLine::query()->count())->toBe(0)
        ->and(DB::table('carts')->count())->toBe(0)
        ->and(DB::table('slugs')->count())->toBe(0);
});

it('leaves every account, store and organization standing — the point of the command', function (): void {
    $fixture = commerceToWipe();

    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    /*
     * **A MERCHANT MUST NOT HAVE TO REGISTER AGAIN.** They signed up, opened a
     * company, passed a store approval — none of that is test catalogue data, and
     * a reset that took it would cost the owner the one thing they cannot
     * re-enter themselves.
     */
    expect(Seller::query()->whereKey($fixture['seller']->getKey())->exists())->toBeTrue()
        ->and(Customer::query()->whereKey($fixture['customer']->getKey())->exists())->toBeTrue()
        ->and(Organization::query()->whereKey($fixture['org']->getKey())->exists())->toBeTrue()
        ->and(Store::query()->whereKey($fixture['store']->getKey())->exists())->toBeTrue();

    // And the roles that make those accounts usable.
    expect($fixture['seller']->fresh()->hasRole(config('marketplace.roles.seller')))->toBeTrue();
});

it('keeps the config lookups, which nobody wants to re-enter', function (): void {
    commerceToWipe();

    // Seeded so there is a real row to protect — `seedAll()` does not register
    // settings, and an assertion about an empty table proves nothing.
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    $taxRates = TaxRate::query()->count();
    $carriers = CargoCompany::query()->count();
    $settings = DB::table('settings')->count();

    expect($taxRates)->toBeGreaterThan(0)
        ->and($carriers)->toBeGreaterThan(0)
        ->and($settings)->toBeGreaterThan(0);

    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    /*
     * KDV BRACKETS AND CARRIERS ARE OPERATOR CONFIG, not test data — and
     * `tax_rates` in particular is what a product's KDV is resolved from at
     * authoring (ADR-055). Wiping it would make the owner's first real product
     * unsaveable.
     */
    expect(TaxRate::query()->count())->toBe($taxRates)
        ->and(CargoCompany::query()->count())->toBe($carriers)
        ->and(DB::table('currencies')->count())->toBeGreaterThan(0)
        ->and(DB::table('settings')->count())->toBe($settings);
});

it('never truncates the audit trail — including the record of this reset', function (): void {
    commerceToWipe();

    $before = DB::table('audit_entries')->count();

    expect($before)->toBeGreaterThan(0);

    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    /*
     * **APPEND-ONLY MEANS APPEND-ONLY** (CLAUDE.md non-negotiable #9). Truncating
     * this would destroy the record of everything that happened before the reset —
     * and the reset is the single event most likely to be asked about afterwards.
     */
    expect(DB::table('audit_entries')->count())->toBeGreaterThanOrEqual($before);
});

it('keeps a surviving customer’s address book unless asked', function (): void {
    $fixture = commerceToWipe();

    $addresses = CustomerAddress::query()->count();
    expect($addresses)->toBeGreaterThan(0);

    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    // The customer survives, so their address book does. An order snapshots both
    // addresses onto itself (ADR-056), so nothing dangles either way — this is
    // about what a returning shopper finds at checkout.
    expect(CustomerAddress::query()->count())->toBe($addresses);
});

it('wipes the address book when explicitly asked', function (): void {
    commerceToWipe();

    $this->artisan('marketplace:reset-commerce', [
        '--force' => true,
        '--include-addresses' => true,
    ])->assertSuccessful();

    expect(CustomerAddress::query()->count())->toBe(0);
});

it('writes nothing on a dry run', function (): void {
    commerceToWipe();

    $products = Product::query()->count();
    $orders = Order::query()->count();

    $this->artisan('marketplace:reset-commerce', ['--dry-run' => true])->assertSuccessful();

    /*
     * THE REHEARSAL HAS TO BE FREE, or nobody rehearses. It reports the same
     * counts the real run would delete, and it does so BEFORE the confirmation
     * gate — so an operator can read the blast radius without committing to
     * anything.
     */
    expect(Product::query()->count())->toBe($products)
        ->and(Order::query()->count())->toBe($orders);
});

it('refuses without a confirmation', function (): void {
    commerceToWipe();

    $products = Product::query()->count();

    // No `--force`, and the prompt answered "no".
    $this->artisan('marketplace:reset-commerce')
        ->expectsConfirmation(
            'TÜM test katalog + ticaret verisi silinecek (ürün/sipariş/ödeme/…), '
            .'hesaplar + mağazalar + config KALACAK. Devam?',
            'no',
        )
        ->assertFailed();

    expect(Product::query()->count())->toBe($products);
});

it('is safe to run twice — the second pass finds nothing and still succeeds', function (): void {
    commerceToWipe();

    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    /*
     * NOT A FEATURE ANYBODY ASKED FOR, but the property that decides whether a
     * half-finished run can be re-run: an operator who hit Ctrl-C, or a truncate
     * that failed on the last table, must be able to just do it again.
     */
    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    expect(Product::query()->count())->toBe(0);
});

it('names media model types that actually exist, and none that must survive', function (): void {
    /*
     * **THE COST OF THE LAYERING RULE, BOUGHT BACK HERE.** `app/Console` may not
     * reference a module's models (`LayeringTest` fails the build on it, and it
     * caught the first version of that list), so the command holds literal
     * strings — which no IDE rename will follow. This is what notices instead: a
     * renamed or moved model fails a test rather than silently orphaning every
     * file it owns.
     */
    foreach (ResetCommerceCommand::MEDIA_OF as $class) {
        expect(class_exists($class))->toBeTrue("MEDIA_OF names a class that does not exist: {$class}");
    }

    /*
     * AND THE DANGEROUS DIRECTION. Organization KYC documents, store opening
     * paperwork and store branding live in the same `media` table and belong to
     * the accounts that survive — the whole reason the table is never truncated
     * wholesale. One of these appearing in the delete list would destroy a
     * merchant's uploaded documents.
     */
    foreach (ResetCommerceCommand::MEDIA_KEPT as $class) {
        expect(class_exists($class))->toBeTrue("MEDIA_KEPT names a class that does not exist: {$class}")
            ->and(ResetCommerceCommand::MEDIA_OF)->not->toContain($class);
    }
});

it('deletes a deleted model’s media row and keeps a surviving one’s', function (): void {
    commerceToWipe();

    // One media row for a model that goes, one for a model that stays. Inserted
    // directly: what matters is the `model_type` filter, not how spatie got there.
    DB::table('media')->insert([
        [
            'model_type' => 'App\\Modules\\Catalog\\Domain\\Models\\Product',
            'model_id' => 1,
            'uuid' => (string) Str::uuid(),
            'collection_name' => 'images',
            'name' => 'urun',
            'file_name' => 'urun.jpg',
            'disk' => 'public',
            'size' => 1024,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'order_column' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'model_type' => 'App\\Modules\\Organization\\Domain\\Models\\OrganizationDocument',
            'model_id' => 1,
            'uuid' => (string) Str::uuid(),
            'collection_name' => 'documents',
            'name' => 'vergi-levhasi',
            'file_name' => 'vergi-levhasi.pdf',
            'disk' => 'public',
            'size' => 2048,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'order_column' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->artisan('marketplace:reset-commerce', ['--force' => true])->assertSuccessful();

    /*
     * **THE MERCHANT'S TAX CERTIFICATE SURVIVES.** `TRUNCATE media` was the
     * one-line version of this step and would have taken it — along with every
     * store logo on the platform — for the sake of four product images.
     */
    expect(DB::table('media')->where('model_type', 'App\\Modules\\Catalog\\Domain\\Models\\Product')->count())->toBe(0)
        ->and(DB::table('media')->where('model_type', 'App\\Modules\\Organization\\Domain\\Models\\OrganizationDocument')->count())->toBe(1);
});
