<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Offer\Presentation\Filament\Seller\Pages\ApiTokens;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| "API Anahtarları" — the page a seller integrates from
|--------------------------------------------------------------------------
|
| It is the only documentation most integrators will read, so what it shows is
| the product: the write doors, and since 2026-09-08 the read one. A missing
| translation key renders as `offer.tokens.read.heading` and still looks like a
| page — which is exactly the failure this file is here to catch.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

it('shows a seller how to read their product list back, not only how to push', function (): void {
    $this->actingAs(Seller::factory()->create(), 'seller');

    Livewire::test(ApiTokens::class)
        ->assertOk()
        // The read endpoint, its two filters and the paging ceiling — the three
        // things an integrator needs before writing their first loop.
        ->assertSee('/api/v1/seller/offers')
        ->assertSee('in_stock=1')
        ->assertSee('per_page')
        ->assertSee('meta.last_page')
        // And the key the rows come back on, which is the seller's own barcode.
        ->assertSee('"gtin": "8690000000001"', escape: false);
});

it('renders the Turkish copy rather than raw translation keys', function (): void {
    // A missing key is invisible in a screenshot and obvious here.
    $this->actingAs(Seller::factory()->create(), 'seller');

    Livewire::test(ApiTokens::class)
        ->assertOk()
        ->assertSee(__('offer.tokens.read.heading'))
        ->assertDontSee('offer.tokens.read.');
});
