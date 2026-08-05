<?php

declare(strict_types=1);

/*
| Shipping module strings. Presentation and refusal reasons only — behaviour
| lives in the Shipping actions.
|
| @see docs/modules/Shipping.md
*/

return [
    'singular' => 'Kargo',
    'plural' => 'Kargolar',

    'errors' => [
        'not_found' => 'Böyle bir kargo kaydı bulunamadı.',
        'not_awaiting_handover' => 'Bu sipariş zaten kargoya verilmiş.',
        'carrier_unavailable' => 'Seçilen kargo firması kullanılamıyor.',
        'seller_cannot_deliver' => 'Teslim edildi bilgisini satıcı giremez; alıcının onayı ya da kargo süresi belirler.',
        'never_deleted' => 'Kargo kaydı silinmez.',
    ],

    'shipment' => [
        'order_number' => 'Sipariş no',
        'status_label' => 'Durum',
        'carrier' => 'Kargo firması',
        'tracking_number' => 'Takip numarası',
        'tracking_number_hint' => 'Kargo firmasının verdiği takip numarası. Alıcı bu numarayla kargosunu takip eder.',
        'shipped_at' => 'Kargoya verildi',
        'delivered_at' => 'Teslim edildi',
        'delivered_via' => 'Teslim bilgisi kaynağı',
        'seller' => 'Satıcı',
        'ship' => 'Kargoya ver',
        'ship_confirm' => 'Kargo firmasını ve takip numarasını girin. Kaydettikten sonra değiştirilemez — yanlış girerseniz destek ile iletişime geçin.',
        'shipped' => 'Sipariş kargoya verildi.',
        'empty' => 'Kargoya verilecek sipariş yok.',
        'empty_hint' => 'Bir siparişin ödemesi alındığında burada görünür.',
    ],

    'cargo' => [
        'singular' => 'Kargo firması',
        'plural' => 'Kargo firmaları',
        'code' => 'Kod',
        'code_hint' => 'Değişmez makine kodu — bir kez belirlenir, sonra düzenlenmez.',
        'name' => 'Ad',
        'tracking_url_template' => 'Takip adresi şablonu',
        'tracking_url_hint' => 'Takip numarasının geleceği yere {tracking_number} yazın. Firmanın takip sayfası yoksa boş bırakın.',
        'is_active' => 'Aktif',
        'sort_order' => 'Sıra',
        'never_deleted' => 'Kargo firması silinmez; pasifleştirilir.',
    ],
];
