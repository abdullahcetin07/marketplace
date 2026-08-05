<?php

declare(strict_types=1);

namespace Database\Modules\Shipping\Seeders;

use App\Modules\Shipping\Domain\Models\CargoCompany;
use Illuminate\Database\Seeder;

/**
 * The carriers a Turkish marketplace actually uses (ADR-063, Shipping.md §5).
 *
 * REGISTERED IN `DatabaseSeeder`, like `TaxRateSeeder` and for the same reason: a
 * seller cannot mark anything shipped without a carrier to pick, so an empty table
 * blocks the whole fulfilment flow on a required select. This is data the
 * application cannot work without, not an operator's editorial choice.
 *
 * `firstOrCreate`, NOT `updateOrCreate`, keyed on the immutable `code` — the
 * `TaxRateSeeder` precedent. The NAME and the TRACKING TEMPLATE are what an
 * operator maintains in the panel: a carrier changes its tracking URL and
 * operations fixes it that afternoon, and a seeder that "corrected" it back on the
 * next deploy would silently break every link again. So this only ever fills in
 * what is MISSING.
 *
 * THE TEMPLATES ARE A STARTING POINT, NOT A GUARANTEE. A carrier's public tracking
 * URL is not a contract with us — it changes without notice, and the panel is
 * where it gets fixed. That is the whole reason the URL is a column rather than a
 * `match` in code.
 *
 * @see App\Modules\Shipping\Domain\Models\CargoCompany
 */
final class CargoCompanySeeder extends Seeder
{
    /**
     * Ordered as a Turkish seller would expect to see them, not alphabetically:
     * `sort_order` is what operations tunes once it knows which carriers its
     * sellers actually use.
     *
     * @var array<int, array{code: string, name: string, template: string|null}>
     */
    private const array CARRIERS = [
        ['code' => 'yurtici', 'name' => 'Yurtiçi Kargo', 'template' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code={tracking_number}'],
        ['code' => 'aras', 'name' => 'Aras Kargo', 'template' => 'https://kargotakip.araskargo.com.tr/mobil/sonuc.aspx?code={tracking_number}'],
        ['code' => 'mng', 'name' => 'MNG Kargo', 'template' => 'https://service.mngkargo.com.tr/tr/takip/?takipNo={tracking_number}'],
        ['code' => 'ptt', 'name' => 'PTT Kargo', 'template' => 'https://gonderitakip.ptt.gov.tr/Track/Verify?q={tracking_number}'],
        ['code' => 'surat', 'name' => 'Sürat Kargo', 'template' => 'https://www.suratkargo.com.tr/KargoTakip/?kargotakipno={tracking_number}'],
        ['code' => 'hepsijet', 'name' => 'HepsiJET', 'template' => 'https://www.hepsijet.com/gonderi-takibi?trackingNumber={tracking_number}'],
        ['code' => 'trendyol-express', 'name' => 'Trendyol Express', 'template' => 'https://tyexpress.trendyol.com/kargo-takip/{tracking_number}'],
        ['code' => 'ups', 'name' => 'UPS Kargo', 'template' => 'https://www.ups.com/track?loc=tr_TR&tracknum={tracking_number}'],
    ];

    public function run(): void
    {
        foreach (self::CARRIERS as $index => $carrier) {
            CargoCompany::query()->firstOrCreate(
                ['code' => $carrier['code']],
                [
                    'name' => $carrier['name'],
                    'tracking_url_template' => $carrier['template'],
                    'is_active' => true,
                    // Ten apart, so an operator can slot a new carrier between two
                    // without renumbering the table.
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }
}
