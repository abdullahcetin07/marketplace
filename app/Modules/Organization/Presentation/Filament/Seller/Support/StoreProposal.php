<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Support;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Organization\Application\Actions\CreateStoreOpeningRequestAction;
use App\Modules\Organization\Domain\Contracts\StoreOpeningRequestRepositoryContract;
use App\Modules\Organization\Domain\DTOs\CreateStoreOpeningRequestDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\StoreOpeningRequest;
use Closure;
use Filament\Forms;

/**
 * The "what store do you want" fields, and what happens on submit — shared by
 * the two places a seller can ask for one.
 *
 * TWO ENTRY POINTS, ONE DEFINITION. Onboarding asks for a store as part of
 * registering the company (a seller who registers and then has to find a second
 * form has not finished onboarding); the "Mağazalarım" page asks for an
 * ADDITIONAL store later. They are the same request with the same rules, so
 * they are the same fields — duplicating them is how the two drift until one
 * validates a name the other accepts.
 *
 * ADR-028 IS UNTOUCHED. Nothing here creates a store: this raises a REQUEST. A
 * store still appears only when an admin approves and `StoreOpeningApproved`
 * fires.
 *
 * @see docs/modules/Organization.md §0.2
 */
final class StoreProposal
{
    /**
     * The proposal fields.
     *
     * The category is absent, as it is on the standalone form: choosing one
     * means picking from a taxonomy the seller has not seen yet, and the
     * reviewer can file it. The DTO keeps the slot.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function fields(): array
    {
        return [
            Forms\Components\TextInput::make('store_name')
                ->label(__('organization.store_request.name'))
                ->required()
                ->maxLength(255)
                ->helperText(__('organization.store_request.name_hint'))
                ->rule(static fn (): Closure => static::uniqueNameRule()),

            Forms\Components\TextInput::make('store_slug')
                ->label(__('organization.store_request.slug'))
                ->helperText(__('organization.store_request.slug_hint'))
                ->required()
                ->maxLength(255)
                ->rule('alpha_dash'),

            Forms\Components\Textarea::make('store_description')
                ->label(__('organization.store_request.description'))
                ->maxLength(2000)
                ->rows(3),
        ];
    }

    /**
     * "That name is taken" — checked against BOTH halves of the rule.
     *
     * An existing store (`StoreQueryContract`, so Organization never imports
     * Store) and a request still in play (this module's own table). Without the
     * second, two sellers could each hold a pending request for the same name
     * and the loser would only find out after review — the worst possible
     * moment, because they have already waited.
     *
     * The database's unique index on `LOWER(stores.name)` remains the guarantee
     * under both: two pending requests can be approved seconds apart, and only
     * the database can arbitrate that.
     */
    public static function uniqueNameRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $name = is_string($value) ? trim($value) : '';

            if ($name === '') {
                return;
            }

            $taken = app(StoreQueryContract::class)->storeNameExists($name)
                || app(StoreOpeningRequestRepositoryContract::class)->storeNameClaimed($name);

            if ($taken) {
                $fail(__('organization.store_request.name_taken'));
            }
        };
    }

    /**
     * Raise the request. A DRAFT — composing is not submitting.
     *
     * TWO REASONS IT IS NOT SUBMITTED HERE, and both are pre-existing rules
     * this reflow had no mandate to change:
     *
     *  1. `SubmitStoreOpeningRequestAction` refuses a company that is not yet
     *     operational (§3.1) — a business still pending its own KYC cannot
     *     queue storefronts — and a freshly registered organization is exactly
     *     that. Onboarding literally cannot submit.
     *  2. The standalone form already worked this way on purpose: a seller
     *     composes a request and then deliberately sends it, from the status
     *     list. Auto-submitting would remove a decision somebody chose to keep.
     *
     * So the reflow moved WHERE a request is raised, not what raising one
     * means. The status list is still where it is sent on, and that is also
     * where the store allowance is checked (§7.3).
     *
     * @param  array<string, mixed>  $data
     */
    public static function request(Organization $organization, array $data): StoreOpeningRequest
    {
        return app(CreateStoreOpeningRequestAction::class)->run(new CreateStoreOpeningRequestDTO(
            organizationId: (int) $organization->getKey(),
            requestedBy: (int) auth()->id(),
            storeName: (string) $data['store_name'],
            slug: (string) $data['store_slug'],
            // Catalog exists now, but a seller choosing a category before they
            // have seen the taxonomy is guesswork; the reviewer files it.
            categoryId: null,
            description: $data['store_description'] ?? null,
            reason: $data['reason'] ?? null,
        ));
    }
}
