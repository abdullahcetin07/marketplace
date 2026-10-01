<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Domain\Accounts\AccountPanelContributorContract;
use App\Shared\Enums\UserType;
use Throwable;

/**
 * The registry of modules that enrich an account detail page.
 *
 * The composition counterpart to `StorefrontRegistry`, and registered the same
 * way: a module adds itself in its own service provider —
 * `AccountPanelRegistry::register(CustomerOrdersPanel::class)` — and Identity's
 * account pages resolve whatever is there. Identity never names a contributor.
 *
 * **A BROKEN CONTRIBUTOR COSTS ITS OWN PANEL AND NOTHING ELSE.** Resolution is
 * guarded: a contributor that cannot be built, or whose class no longer exists
 * after a module is removed, is skipped. The account page is where an admin goes
 * to answer a question about a person, and it must open even when a panel on it
 * does not.
 *
 * @see App\Core\Domain\Accounts\AccountPanelContributorContract
 */
final class AccountPanelRegistry
{
    /**
     * Registered contributor class-strings, keyed by class name to dedupe.
     *
     * @var array<string, class-string<AccountPanelContributorContract>>
     */
    private static array $contributors = [];

    /**
     * @param class-string<AccountPanelContributorContract> $contributor
     */
    public static function register(string $contributor): void
    {
        self::$contributors[$contributor] = $contributor;
    }

    /**
     * The widget classes to render on this kind of account's page, in order.
     *
     * @return array<int, class-string>
     */
    public static function widgetsFor(UserType $type): array
    {
        $applicable = [];

        foreach (self::$contributors as $class) {
            try {
                $contributor = app($class);

                if (! $contributor instanceof AccountPanelContributorContract) {
                    continue;
                }

                if (! $contributor->appliesTo($type)) {
                    continue;
                }

                $applicable[] = ['sort' => $contributor->sortOrder(), 'widget' => $contributor->widget()];
            } catch (Throwable) {
                // A panel that cannot be built is a panel the admin does not
                // see — never a page they cannot open.
                continue;
            }
        }

        usort($applicable, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $entry): string => $entry['widget'], $applicable);
    }

    /**
     * Reset — for tests that register a contributor and must not leak it.
     */
    public static function flush(): void
    {
        self::$contributors = [];
    }
}
