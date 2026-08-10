<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One ROW of a bulk import could not be turned into a product (ADR-074).
 *
 * **A ROW FAILURE IS NOT A BATCH FAILURE**, and this class exists to keep those
 * two things apart. An admin uploading four thousand products will have some bad
 * rows — a blank title, a category that does not accept products, a GTIN that
 * belongs to something else — and the one outcome nobody wants is three thousand
 * good rows rolled back because of them. Filament records this in
 * `failed_import_rows` and carries on.
 *
 * **THE MESSAGE IS TURKISH AND IS READ BY A HUMAN IN A SPREADSHEET.** It ends up
 * in the downloadable failure report beside the row it came from, so it has to say
 * what to fix in that row rather than what threw where. "Kategori yolu boş" is
 * useful; "CategoryNotFoundException" is not.
 *
 * NOT REPORTABLE, like every other domain refusal here: a spreadsheet with typos
 * is an ordinary Tuesday, not an incident.
 *
 * @see App\Modules\Catalog\Application\Import\CatalogRowImporter
 */
final class CatalogImportException extends BaseException
{
    /**
     * @param array<string, mixed> $context
     */
    public static function rowRejected(string $message, array $context = []): self
    {
        return self::make($message)
            ->withContext(['reason' => 'import_row_rejected', ...$context])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * A required cell is empty.
     */
    public static function missingColumn(string $column): self
    {
        return self::rowRejected(
            "Zorunlu alan boş: {$column}",
            ['column' => $column],
        );
    }

    /**
     * The path names a category that exists but is not a leaf that takes products.
     *
     * **NOT SILENTLY FLIPPED.** Setting `accepts_products` on somebody else's
     * category from a spreadsheet cell would change the moderated shape of the
     * catalogue (ADR-047) without a single person deciding to. The row fails and
     * says which category, which a human can fix in one click.
     */
    public static function categoryRejectsProducts(string $path, string $name): self
    {
        return self::rowRejected(
            "\"{$name}\" kategorisi ürün kabul etmiyor; kategori yolunun son basamağı ürün kabul eden bir kategori olmalı: {$path}",
            ['path' => $path, 'category' => $name],
        );
    }
}
