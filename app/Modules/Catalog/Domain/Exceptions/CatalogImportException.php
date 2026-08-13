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
    /**
     * The catalogue already carries this product under a different barcode.
     *
     * **A RENEWED BARCODE IS THE SAME PRODUCT, AND THE ROW IS SKIPPED** (owner's
     * call, 2026-08-13). A supplier re-barcodes an item and the sheet arrives
     * carrying both, so `Bioderma Photoderm LEB SPF30 100 ml` shows up as
     * `3701129808047` and `...48`. The GTIN dedup cannot see it — that is a
     * DIFFERENT barcode — and the two rows then race for one slug.
     *
     * **IT IS REPORTED, NOT SWALLOWED.** The row lands in the failure report with
     * both barcodes named, because the admin is the only one who can decide
     * whether it really is a re-barcode or two genuinely different products that
     * happen to share a name.
     *
     * Before this existed the collision surfaced as an
     * `UniqueConstraintViolationException` escaping `resolveRecord()`, which
     * Filament cannot record as a row failure: it wrote the row with an EMPTY
     * reason and failed the whole JOB, so the queue re-ran the chunk and every
     * innocent row beside it was reported as failed too. 117 blank failures from
     * 78 genuine collisions.
     */
    public static function titleAlreadyInCatalog(string $title, ?string $existingGtin, ?string $rowGtin): self
    {
        return self::rowRejected(
            sprintf(
                'Bu isimde bir ürün katalogda zaten var: "%s" (kayıtlı barkod: %s, dosyadaki barkod: %s). Satır atlandı.',
                $title,
                $existingGtin ?? '—',
                $rowGtin ?? '—',
            ),
            ['title' => $title, 'existing_gtin' => $existingGtin, 'row_gtin' => $rowGtin],
        );
    }

    public static function categoryRejectsProducts(string $path, string $name): self
    {
        return self::rowRejected(
            "\"{$name}\" kategorisi ürün kabul etmiyor; kategori yolunun son basamağı ürün kabul eden bir kategori olmalı: {$path}",
            ['path' => $path, 'category' => $name],
        );
    }
}
