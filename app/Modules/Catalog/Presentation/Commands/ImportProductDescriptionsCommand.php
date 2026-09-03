<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Application\Services\DescriptionImport;
use Illuminate\Console\Command;

/**
 * Read approved product copy from a Markdown file and put it in the catalogue.
 *
 * The file is the shape `PRODUCT_DESCRIPTION_PILOT.md` already uses, because
 * that is what content-ops actually produces and reviews:
 *
 *     ### 1. La Roche-Posay Effaclar Duo+ SPF 30 — 40 ml
 *     `GTIN 3337875943963`
 *     {intro paragraph}
 *     - {benefit}
 *
 *     Kullanım: …
 *     ---
 *
 * **REPORTS BY DEFAULT; `--apply` IS THE ONLY THING THAT WRITES.** The dry run
 * prints exactly what each row would do, which is the review step for a batch of
 * copy nobody wants to discover in production.
 */
final class ImportProductDescriptionsCommand extends Command
{
    protected $signature = 'catalog:import-descriptions
                            {file : Markdown file of approved copy}
                            {--apply : Write the descriptions (default is a dry run)}
                            {--force : Overwrite copy that is not the generated template}';

    protected $description = 'Import approved product descriptions from a Markdown file, by GTIN';

    public function handle(DescriptionImport $import): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("Dosya yok: {$path}");

            return self::FAILURE;
        }

        $entries = $this->parse((string) file_get_contents($path));

        if ($entries === []) {
            $this->error('Dosyada GTIN\'li bir bölüm bulunamadı.');

            return self::FAILURE;
        }

        $report = $import->apply($entries, (bool) $this->option('apply'), (bool) $this->option('force'));

        $this->table(
            ['GTIN', 'Başlık', 'Durum', 'Ayrıntı'],
            array_map(static fn (array $row): array => [
                $row['gtin'],
                mb_substr($row['title'], 0, 44),
                $row['status'],
                mb_substr($row['detail'], 0, 40),
            ], $report['rows']),
        );

        $this->line(sprintf(
            'Toplam %d · yazılan %d · aynı %d · elle yazılmış %d · sağlık beyanı %d · bulunamadı %d',
            $report['total'],
            $report['written'],
            $report['skipped_identical'],
            $report['skipped_hand_written'],
            $report['blocked_claim'],
            $report['not_found'],
        ));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Kuru çalışma — hiçbir şey yazılmadı. Yazmak için --apply.');
        }

        /*
        | A BLOCKED CLAIM IS A FAILURE EXIT, not a line in a table nobody reads.
        | It means somebody approved a sentence Turkish cosmetics law does not
        | allow, and the run should stop being green about it.
        */
        return $report['blocked_claim'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{gtin: string, title: string, body: string}>
     */
    private function parse(string $markdown): array
    {
        /*
        | **SPLIT ON THE HEADING, NOT ON `---`.** The pilot file separated every
        | product with a horizontal rule; the first hundred-SKU batch used one
        | rule under the preamble and nothing between the entries. Splitting on
        | the rule found ONE product there and would have written all hundred
        | descriptions into it — caught by the dry run, which is why the dry run
        | is the default. A `###` heading starts an entry in both files, and in
        | any file a person would write.
        */
        $sections = preg_split('/^(?=###\s)/mu', $markdown) ?: [];

        $entries = [];

        foreach ($sections as $section) {
            if (preg_match('/^###\s*\d*\.?\s*(?<title>.+)$/mu', $section, $heading) !== 1) {
                continue;
            }

            if (preg_match('/`GTIN\s*(?<gtin>\d{8,14})`/u', $section, $code) !== 1) {
                continue;
            }

            /*
            | Everything after the GTIN line and BEFORE the next horizontal
            | rule. The rule is an end marker, and it has to be honoured rather
            | than merely trimmed: the pilot file ends with a note about quality
            | that quotes the forbidden phrase "akneyi tedavi eder" as an
            | example of what not to write. Swallowed into the last entry, that
            | note tripped the health-claim scan — the scan was right, the
            | boundary was wrong.
            |
            | Blank lines and `- ` bullets are kept: the storefront renders them
            | (commit 842def5).
            */
            $body = (string) preg_replace('/^.*`GTIN\s*\d{8,14}`\s*/us', '', $section);
            $body = trim((string) (preg_split('/^---\s*$/mu', $body)[0] ?? ''));

            if ($body === '') {
                continue;
            }

            $entries[] = [
                'gtin' => $code['gtin'],
                'title' => trim($heading['title']),
                'body' => $body,
            ];
        }

        return $entries;
    }
}
