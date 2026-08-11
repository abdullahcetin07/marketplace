<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Catalog\Application\Actions\AttachProductMediaAction;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\EditProduct;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Product gallery images (Catalog §6)
|--------------------------------------------------------------------------
|
| These exist because of a live 500 the rest of the suite could not have caught.
| Every media test in the codebase handed the action an UploadedFile OBJECT, and
| `addMedia(object)` works. The panel does not do that: its upload component
| STAGES the file on a disk first and hands the action a path RELATIVE to that
| disk, and `addMedia('01KYM….webp')` reads a relative path as an absolute one,
| looks in the working directory, and throws FileDoesNotExist.
|
| So the shape of the argument is the whole point here. The string case is
| tested against a staging disk that is DELIBERATELY not the collection's disk —
| in this environment the two happen to be the same local disk, and a test that
| only ever exercised that coincidence would pass while the real cross-disk copy
| (public disk = S3 in production) stayed unproven.
|
| Conversions are faked away: they are queued in production, this is the same
| queue, and what is under test is where the bytes land — not how they are
| resized.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    Storage::fake(config('marketplace.media.public_disk'));
    Storage::fake(config('filament.default_filesystem_disk'));
    Storage::fake('local');

    Queue::fake();
});

/**
 * A draft owned by a seller who can actually reach its edit page.
 *
 * @return array{seller: Seller, product: Product}
 */
function productWithGallery(): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()
        ->for($organization)
        ->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $root = Category::factory()->create(['name_tr' => 'Giyim']);
    $category = Category::factory()->childOf($root)->create(['name_tr' => 'Tişört']);

    $product = Product::factory()
        ->for($category, 'category')
        ->proposedBy((int) $organization->getKey(), (string) $organization->uuid)
        ->create();

    return ['seller' => $seller, 'product' => $product];
}

/**
 * Real JPEG bytes. The media library sniffs the file rather than trusting the
 * name, so a placeholder would be rejected by the collection's MIME list.
 */
function galleryImage(string $name = 'urun.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 320, 320);
}

it('attaches an image handed over as a disk-relative path', function (): void {
    // THE REGRESSION. `addMedia($path)` threw FileDoesNotExist here.
    ['product' => $product] = productWithGallery();

    $staging = 'local';
    $path = galleryImage()->store('staged', ['disk' => $staging]);

    app(AttachProductMediaAction::class)->run($product, [$path], $staging);

    $media = $product->fresh()->getFirstMedia('images');

    expect($media)->not->toBeNull()
        // The collection decides the disk, not the caller and not the staging
        // location — that is HasMedia's call (§6).
        ->and($media->disk)->toBe(config('marketplace.media.public_disk'));

    Storage::disk(config('marketplace.media.public_disk'))
        ->assertExists("{$media->id}/{$media->file_name}");
});

it('clears the staged copy off the upload disk once the gallery has it', function (): void {
    // Otherwise every upload leaves an orphan on a disk nothing sweeps.
    ['product' => $product] = productWithGallery();

    $path = galleryImage()->store('staged', ['disk' => 'local']);

    Storage::disk('local')->assertExists($path);

    app(AttachProductMediaAction::class)->run($product, [$path], 'local');

    Storage::disk('local')->assertMissing($path);
});

it('still accepts an UploadedFile object, and leaves it alone', function (): void {
    // The API path passes the request object straight through; it is the
    // caller's file and a Livewire component may still be rendering from it.
    ['product' => $product] = productWithGallery();

    $file = galleryImage();
    $original = $file->getRealPath();

    app(AttachProductMediaAction::class)->run($product, [$file]);

    expect($product->fresh()->hasImages())->toBeTrue()
        ->and(file_exists((string) $original))->toBeTrue();
});

it('uploads images through the seller panel edit page', function (): void {
    // The live flow, end to end and through the real component: Filament stages
    // the upload on its own disk and the action is handed the path. Nothing
    // here passes an object — that is the point.
    ['seller' => $seller, 'product' => $product] = productWithGallery();

    $this->actingAsSeller($seller);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('upload_images', data: ['files' => [galleryImage('tisort.jpg')]])
        ->assertHasNoActionErrors();

    $product = $product->fresh();

    expect($product->hasImages())->toBeTrue()
        ->and($product->imageUrl())->not->toBeNull();
});

it('attaches every file in a multi-file upload', function (): void {
    ['product' => $product] = productWithGallery();

    $paths = [
        galleryImage('bir.jpg')->store('staged', ['disk' => 'local']),
        galleryImage('iki.jpg')->store('staged', ['disk' => 'local']),
    ];

    app(AttachProductMediaAction::class)->run($product, $paths, 'local');

    expect($product->fresh()->getMedia('images'))->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| A gallery URL a browser can actually fetch
|--------------------------------------------------------------------------
|
| Conversions are QUEUED, so between the upload and the worker there is a window
| where an image exists and its `preview` does not. The window is minutes when the
| queue is healthy and unbounded when nothing is draining it — which is how this
| deployment ran for weeks.
|
| `Queue::fake()` above puts every test in this file inside that window, which is
| exactly the state these two assert against.
*/

it('serves the ORIGINAL while a conversion is still queued, never a dead path', function (): void {
    ['product' => $product] = productWithGallery();

    app(AttachProductMediaAction::class)->run($product, [galleryImage()]);

    $media = $product->fresh()->getFirstMedia('images');

    /*
     * THE BUG THIS CLOSES. Spatie builds a conversion URL by CONVENTION — it does
     * not look at the disk — so asking for one that has not been generated returns
     * a perfectly-formed path that 404s. The product page did exactly that for its
     * whole gallery, and the storefront rendered "görsel yok" for products whose
     * images had been uploaded and stored correctly all along.
     *
     * A full-size phone photo is the fallback, and that IS the trade: the page is
     * heavier until the worker catches up, and it works.
     */
    // `?v={updated_at}` — @see `HasMedia::cacheBusted()`. Spelled out rather than
    // regex-matched, so a change to the token's SOURCE fails here too.
    $version = '?v='.$media->updated_at?->getTimestamp();

    expect($media->hasGeneratedConversion('preview'))->toBeFalse()
        ->and($product->fresh()->imageUrls('preview'))->toBe([$media->getUrl().$version])
        // The listing thumbnail already had this fallback; it is asserted here so
        // the two cannot drift apart again.
        ->and($product->fresh()->imageUrl('thumb'))->toBe($media->getUrl().$version);
});

it('prefers the conversion the moment it exists', function (): void {
    ['product' => $product] = productWithGallery();

    app(AttachProductMediaAction::class)->run($product, [galleryImage()]);

    $media = $product->fresh()->getFirstMedia('images');
    $media->generated_conversions = ['preview' => true];
    $media->save();

    $version = '?v='.$media->updated_at?->getTimestamp();

    // The fallback is a fallback, not a policy: a generated conversion is smaller,
    // webp, and the whole reason the conversions exist.
    expect($product->fresh()->imageUrls('preview'))->toBe([$media->getUrl('preview').$version])
        ->and($media->getUrl('preview'))->not->toBe($media->getUrl());
});
