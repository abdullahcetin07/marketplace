<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Filament\Resources\CustomerResource\Pages;

use App\Core\Support\AccountPanelRegistry;
use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource;
use App\Shared\Enums\UserType;
use Filament\Resources\Pages\ViewRecord;

/**
 * A shopper account, read-only, with its forensic login history. Suspend and
 * reinstate are reasoned row actions on the listing. @see ViewSeller
 *
 * **THE ONE POST-FREEZE CHANGE THIS PAGE CARRIES** (2026-10-01, owner's
 * decision): it renders whatever modules have registered against a customer
 * account. An admin answering "what did this person order" had to leave for the
 * orders list and search a name; everything else they might want — points,
 * reviews, questions — would have meant re-opening a frozen module once per
 * feature.
 *
 * **IT NAMES NO MODULE.** The widgets come from `AccountPanelRegistry`, the same
 * composition seam the storefront uses (ADR-036), so Order adds its own panel in
 * its own provider and Identity never learns it exists. Nothing here imports
 * anything outside Core.
 */
final class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getFooterWidgetsColumns(): int
    {
        // One per row: these are tables, not statistics tiles.
        return 1;
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Panels other modules contribute about this shopper.
     *
     * Below the account body on purpose: who the person is comes first, what
     * they have done with the shop comes after.
     *
     * @return array<int, class-string>
     */
    protected function getFooterWidgets(): array
    {
        return AccountPanelRegistry::widgetsFor(UserType::Customer);
    }
}
