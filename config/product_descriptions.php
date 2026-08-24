<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Template-generated product descriptions (ADR-088)
    |--------------------------------------------------------------------------
    |
    | The catalogue arrived from a supplier feed with six columns and no
    | description (ADR-074), so ~7,000 sellable products carry none — which keeps
    | every one of them out of the Google Merchant feed (ADR-086), because Google
    | rejects an item with an empty description and a rejection counts against the
    | account.
    |
    | **DETERMINISTIC, NOT GENERATED.** Every sentence is assembled from fields the
    | product already carries: its title, its brand, its category, and tokens
    | parsed out of its own title. Nothing here invents a fact, and nothing here
    | makes a claim — which is not a style preference but Turkish law: a cosmetic
    | or a food supplement that claims to treat or prevent a disease is a
    | regulatory offence, not a marketing exaggeration.
    |
    | The trade is stated plainly: this is FEED ELIGIBILITY and an honest floor,
    | not content that ranks. Organic weight comes from category FAQs and reviews.
    |
    */

    /*
    | A product family decides two things: the noun the sentence uses for the
    | product, and the legally required sentence that closes it.
    |
    | The families are deliberately coarse. A finer split would need per-category
    | copy nobody has written, and a wrong legal footer is worse than a generic
    | one — a supplement missing "ilaç değildir" is the sentence that gets a
    | listing pulled.
    */
    'families' => [

        'cosmetic' => [
            'noun' => 'kişisel bakım ürünüdür',
            // Cosmetics regulation: external use, stated on the product.
            'legal' => 'Haricen kullanım içindir.',
        ],

        'supplement' => [
            'noun' => 'takviye edici gıdadır',
            // The exact wording the regulation expects. Do not paraphrase it.
            'legal' => 'Takviye edici gıdadır, ilaç değildir; hastalıkların tedavisinde kullanılmaz. Kullanmadan önce etiketi okuyunuz.',
        ],

        /*
        | Medical and health accessories: thermometers, supports, dressings. NO
        | legal footer claiming anything about use — the category is exactly where
        | a helpful-sounding sentence becomes a medical claim.
        */
        'medical' => [
            'noun' => 'sağlık ürünüdür',
            'legal' => 'Kullanmadan önce ürün etiketini ve kullanım talimatını okuyunuz.',
        ],

        'pet' => [
            'noun' => 'evcil hayvan ürünüdür',
            'legal' => 'Yalnızca evcil hayvanlar için kullanım içindir.',
        ],

        'general' => [
            'noun' => 'üründür',
            'legal' => '',
        ],
    ],

    /*
    | Root category slug => family. Matched against the product's ROOT ancestor,
    | so a product filed six levels deep still lands in the right family.
    |
    | An unmapped root falls back to `default_family` rather than failing: a new
    | department must not stop the sweep, and "üründür" with no legal footer is
    | the safe thing to say about something nobody has classified yet.
    */
    'roots' => [
        'cilt-bakimi' => 'cosmetic',
        'kisisel-bakim' => 'cosmetic',
        'makyaj' => 'cosmetic',
        'sac-bakimi' => 'cosmetic',
        'gunes-kremleri' => 'cosmetic',
        'parfum' => 'cosmetic',

        /*
        | `anne-ve-bebek` IS DELIBERATELY UNMAPPED, and the omission is the point.
        |
        | The department holds baby shampoo AND infant formula — a cosmetic and a
        | food, under different regulations. Mapped wholesale to `cosmetic` it
        | produced, on the live catalogue, "SMA Comfort 3 Devam Sütü … Haricen
        | kullanım içindir." — a legal footer telling a parent that formula is for
        | external use. It falls through to `general`, which says only what is
        | true and adds no footer at all. A department that mixes families is not
        | a family, and guessing at one is how a template becomes a liability.
        */

        'besin-takviyeleri' => 'supplement',
        'vitaminler' => 'supplement',
        'sporcu-gidalari' => 'supplement',

        'saglik-ve-medikal' => 'medical',

        'pet-shop' => 'pet',
    ],

    'default_family' => 'general',

    /*
    | NEVER OVERWRITE WRITTEN COPY. The command exists to fill a hole, not to
    | flatten what an editor wrote — and once real descriptions start arriving,
    | a run that ignored this would destroy them in bulk with no undo.
    */
    'only_empty' => true,

    /*
    | Tokens parsed OUT OF THE TITLE, used only when present. Nothing is inferred:
    | a title that does not say "50 ml" produces a sentence that does not mention
    | volume, rather than a guess.
    */
    'tokens' => [
        'spf' => '/\bSPF\s?(\d{1,3})\b/i',

        /*
        | NO `mg`, ON PURPOSE. In a supplement title a milligram figure is the
        | DOSE PER CAPSULE, not the net contents — "Tru Niagen 300mg 30 Kapsül"
        | is thirty capsules of 300 mg, and "Net miktarı 300 mg" was the sentence
        | this produced before the unit was dropped. Wrong about the quantity is
        | worse than silent about it.
        |
        | **CASE-INSENSITIVE, because supplier titles are not consistent.** The same
        | catalogue writes "500 Gr", "200 ML", "1 Lt" and "50 ml"; the first version
        | of this pattern listed a few spellings literally and silently produced no
        | quantity sentence for any of the others.
        */
        'amount' => '/\b(\d+(?:[.,]\d+)?)\s?(ml|lt|l|gr|g|kg)\b/iu',

        /*
        | A MULTIPLIER MEANS THE PARSED FIGURE IS NOT THE TOTAL. "12 x 5 ml Ampul"
        | is 60 ml, and the amount pattern above sees only the 5. When this
        | matches, the quantity sentence is dropped entirely rather than stated
        | wrongly.
        */
        'multipack' => '/\b\d+\s?[x×*]\s?\d+/iu',
        'pack' => '/\b(\d+)\s?\'?\s?(li|lı|lu|lü)\b/iu',
    ],

    /*
    | Form words, matched whole-word against the title. Ordered longest-first at
    | runtime so "duş jeli" cannot be shadowed by "jel".
    */
    'forms' => [
        'krem', 'jel', 'losyon', 'serum', 'sabun', 'şampuan', 'maske', 'damla',
        'tablet', 'kapsül', 'sprey', 'stick', 'yağ', 'köpük', 'toz', 'şurup',
        'merhem', 'solüsyon', 'peeling', 'tonik', 'balsam', 'macun',
    ],

    /*
    | A test scans every generated string for these and fails the build on a hit.
    | They live in configuration so the list is one grep from whoever adds a
    | template, and so that adding a template cannot quietly add a claim.
    |
    | **THEY ARE CLAIM-SHAPED PHRASES, NOT BARE WORDS, AND THAT IS NOT LAZINESS.**
    | The mandatory supplement footer is "…ilaç değildir; hastalıkların
    | TEDAVİSİNDE KULLANILMAZ" — a naive scan for `tedavi` or `hastalık` would
    | fail the build on the exact sentence the regulation requires, and the
    | obvious fix would be to delete the footer. A negation is the opposite of a
    | claim; the patterns below match the assertion and leave the disclaimer
    | alone.
    |
    | @see .claude/skills/raftabul-product-copy
    */
    'forbidden_claims' => [
        '/tedavi\s+eder/iu',
        '/tedavi\s+edici\s+etki/iu',
        '/iyileştir(ir|en|ici)/iu',
        '/(hastalığ|hastalık)\w*\s+(iyi\s+gelir|geçirir|önler|korur)/iu',
        '/şifa(lı|dır)?\b/iu',
        '/\bönler\b/iu',
        '/\bgeçirir\b/iu',
        '/\bkürdür\b/iu',
        '/ilaçtır/iu',
        '/teşhis\s+(eder|koyar)/iu',
        '/bağışıklığı\s+güçlendirir/iu',
        '/(dökülmesini|dökülmeyi)\s+durdurur/iu',
    ],
];
