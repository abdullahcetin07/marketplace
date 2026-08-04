<?php

declare(strict_types=1);

namespace Database\Modules\Payment\Seeders;

use App\Modules\Payment\Domain\Models\CommissionRule;
use Illuminate\Database\Seeder;

/**
 * The platform default rate — the one rule the engine cannot work without
 * (ADR-061, Payment.md §6).
 *
 * INSTALLATION, NOT A FIXTURE. With no rules at all the resolver takes zero
 * commission, which is a defensible default for a platform that has not decided
 * yet — but it is not what anyone launching a marketplace means, and a silent 0%
 * is the kind of thing discovered in a month's accounts. So the catch-all row
 * ships.
 *
 * IT IS NOT A SPECIAL KIND OF ROW. All four scopes null simply makes it the least
 * specific rule there is, so it wins only when nothing else matches — which falls
 * out of the resolution rule rather than being a case in it.
 *
 * IDEMPOTENT ON "the rule with no scopes", and it does NOT overwrite the rate on
 * a re-run: an operator who set 12% must not have a deploy put it back to 18%.
 * The same discipline `LocalizationSeeder` keeps for exchange rates.
 */
final class CommissionRuleSeeder extends Seeder
{
    /**
     * 18% — a plausible Turkish marketplace rate, and deliberately a round one an
     * operator will notice and change rather than a suspiciously precise figure
     * they might assume was calculated.
     */
    public const string DEFAULT_RATE = '0.1800';

    public function run(): void
    {
        $existing = CommissionRule::query()
            ->whereNull('seller_org_uuid')
            ->whereNull('product_uuid')
            ->whereNull('brand_uuid')
            ->whereNull('category_uuid')
            ->first();

        if ($existing !== null) {
            return;
        }

        CommissionRule::query()->create([
            'label' => 'Platform varsayılanı',
            'rate' => self::DEFAULT_RATE,
            'priority' => 0,
            'is_active' => true,
        ]);
    }
}
