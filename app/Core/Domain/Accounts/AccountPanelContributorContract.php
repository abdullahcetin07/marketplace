<?php

declare(strict_types=1);

namespace App\Core\Domain\Accounts;

use App\Shared\Enums\UserType;

/**
 * A module's contribution to an account detail page in the admin panel.
 *
 * **THE SAME COMPOSITION SEAM AS THE STOREFRONT'S (ADR-036), FOR THE SAME
 * REASON.** Identity owns the account page — who this person is, their login
 * history, the suspend lever — and it is FROZEN. Everything else an admin wants
 * to see about a customer belongs to another module: their orders, their loyalty
 * points, their reviews, their questions. Each of those modules implements this
 * and registers it; Identity renders whatever is registered and names none of
 * them. The dependency points from the contributor to this Core seam, never from
 * Identity to the module.
 *
 * **IT HANDS OVER A WIDGET, NOT DATA.** A Filament relation manager needs an
 * Eloquent relation, and there is none to have: an order holds `customer_uuid`
 * as a bare string (ADR-040) precisely so Order and Identity need not know each
 * other's tables. So the contributor supplies a widget class that lives in ITS
 * module and reads ITS own models — which also means no Core query method has to
 * be invented for every panel somebody wants.
 *
 * Contract for implementers:
 *   - `appliesTo()` decides which account types get the panel. A customer's
 *     orders have no business on a staff page.
 *   - `widget()` is a class-string of a Filament widget. It is resolved at render
 *     time, so registering costs nothing.
 *   - The widget receives the account `$record` and must never throw: a panel
 *     that fails must not take the account page with it.
 *
 * @see App\Core\Support\AccountPanelRegistry
 * @see App\Core\Domain\Storefront\StorefrontContributorContract — the precedent
 */
interface AccountPanelContributorContract
{
    /**
     * Whether this panel belongs on that kind of account's page.
     */
    public function appliesTo(UserType $type): bool;

    /**
     * The Filament widget class that renders the panel.
     *
     * @return class-string
     */
    public function widget(): string;

    /**
     * Where it sits among the other panels — lower comes first. Identity's own
     * sections are the page body; these sit below it.
     */
    public function sortOrder(): int;
}
