<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Str;

/**
 * Is this public identifier a uuid, or a slug?
 *
 * ONE LINE OF LOGIC WITH A NAME, because the platform has now shipped the bug it
 * prevents THREE TIMES:
 *
 *   1. ADR-049 — `stock_reservations.reference_uuid` took Order's
 *      `{order}:{variant}` key. Every checkout 500'd in production while 1 088
 *      tests stayed green.
 *   2. The geo cascade — `where('uuid', 'İstanbul')` resolving a province by name.
 *      Caught by hand on the live box.
 *   3. This one — `?category=Dermokozmetik` and `/products/{slug}`, both 500ing
 *      on the storefront's most ordinary calls.
 *
 * THE SHAPE OF THE MISTAKE IS ALWAYS THE SAME. On PostgreSQL a `uuid` column
 * compared with a non-uuid string is not a NON-MATCH — it is `SQLSTATE[22P02]
 * invalid input syntax for type uuid`, an unhandled 500. On SQLite the column is
 * text, the comparison quietly returns false, and every test passes. So the suite
 * cannot see it, code review does not see it, and it surfaces in production on
 * whichever endpoint a real user typed a real word into.
 *
 * SO THE RULE IS: DECIDE BY SHAPE BEFORE YOU QUERY. Never "try the uuid column,
 * fall back to the slug" — the first half of that sentence is the crash. A value
 * that does not look like a uuid must never reach a uuid column, and a lookup that
 * finds nothing must 404, never 500.
 *
 * IN `app/Shared` BECAUSE IT IS LAYER-NEUTRAL AND MORE THAN ONE MODULE NEEDS IT.
 * A controller asks it about a URL segment, and an Infrastructure query asks it
 * before touching a column — putting it in Catalog's Presentation layer would
 * have made `PublicProductBrowse` import Presentation from Infrastructure, which
 * `LayeringTest` refuses and rightly so. Localization's geo resolver makes the
 * same check inline; it is the second caller this class was waiting for.
 *
 * @see docs/Architecture_Decision_Record.md ADR-059
 */
final class PublicKey
{
    /**
     * Whether the value is shaped like a uuid and may safely touch a `uuid`
     * column.
     *
     * `Str::isUuid` matches the canonical 8-4-4-4-12 hex form, which is what
     * every uuid this application emits looks like — and, critically, what
     * PostgreSQL's `uuid` type will accept. A slug can never match it: slugs are
     * lowercase alphanumerics and hyphens, and the group lengths would have to
     * coincide exactly, on a value that also happens to be pure hex.
     */
    public static function looksLikeUuid(string $value): bool
    {
        return Str::isUuid($value);
    }
}
