<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Accounts;

use App\Core\Domain\Accounts\AccountPanelContributorContract;
use App\Modules\Order\Presentation\Filament\Widgets\CustomerOrdersWidget;
use App\Shared\Enums\UserType;

/**
 * Order's panel on a shopper's account page (Order.md §17).
 *
 * Registered from this module's own provider, so Identity renders the orders
 * without importing Order and without a Core query method existing for it.
 */
final class CustomerOrdersPanel implements AccountPanelContributorContract
{
    public function appliesTo(UserType $type): bool
    {
        // A CUSTOMER's orders only. A seller's own purchases are a different
        // question and a staff account has none.
        return $type === UserType::Customer;
    }

    public function widget(): string
    {
        return CustomerOrdersWidget::class;
    }

    public function sortOrder(): int
    {
        // First among the panels: what they bought is the question an admin
        // opens a customer page to answer.
        return 10;
    }
}
