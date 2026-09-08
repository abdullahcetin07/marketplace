<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Application\Actions\UpdateProductAction;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Console\Command;

/**
 * Repairs the commas the supplier file turned into pipes — `Cicaplast Levres
 * 7|5 ml`, `Gözenek Sıkılaştırıcı| Siyah Nokta Karşıtı` — in product titles and
 * in the descriptions that quote them.
 *
 * **A SPACE BEFORE THE PIPE MEANS A HUMAN PUT IT THERE.** `Skin | Hair & Nails`
 * is how the product is actually branded; `7|5 ml` is a decimal comma that did
 * not survive a CSV. So the rule is positional and is the whole decision in this
 * class: a pipe **welded to the preceding character** becomes a comma, a pipe
 * with a space in front of it is left exactly as it is. A title carrying both
 * gets one fixed and the other kept, which is why this is a regular expression
 * rather than a skip-list.
 *
 * **IT DRIVES THE AUTHORING ACTION AND WRITES NO MODEL** — the ADR-074/076/088
 * rule. A `->update(['title_tr' => …])` would set the column and fire nothing,
 * leaving the row right in the table and stale in search, the storefront and
 * both feeds. **The slug is deliberately not in the DTO**: `UpdateProductAction`
 * re-slugs only when `slug` is present, and a corrected title must not move a
 * URL that Google has already indexed.
 *
 * Reports by default; `--apply` is the only thing that writes. A second run
 * changes nothing.
 */
final class FixPipeTitlesCommand extends Command
{
    protected $signature = 'catalog:fix-pipe-titles
                            {--apply : Write the corrections (default is a dry run)}
                            {--limit= : Stop after this many products}';

    protected $description = 'Turn the pipes a CSV left in product titles back into commas';

    public function handle(UpdateProductAction $update): int
    {
        $write = (bool) $this->option('apply');
        $limit = $this->option('limit');

        $query = Product::query()
            ->where(static function ($q): void {
                $q->where('title_tr', 'like', '%|%')->orWhere('description_tr', 'like', '%|%');
            })
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        $rows = [];
        $fixed = 0;
        $kept = 0;

        foreach ($query->get() as $product) {
            $title = (string) $product->title_tr;
            $description = (string) $product->description_tr;

            $newTitle = $this->repair($title);
            $newDescription = $this->repair($description);

            if ($newTitle === $title && $newDescription === $description) {
                $kept++;
                $rows[] = [$product->id, mb_substr($title, 0, 58), 'dokunulmadı'];

                continue;
            }

            if ($write) {
                $present = [];
                $present[] = $newTitle === $title ? null : 'title';
                $present[] = $newDescription === $description ? null : 'description';

                $update->run($product, new UpdateProductDTO(
                    title: $newTitle === $title ? [] : ['tr' => $newTitle],
                    description: $newDescription === $description ? [] : ['tr' => $newDescription],
                    present: array_values(array_filter($present)),
                ));
            }

            $fixed++;
            $rows[] = [$product->id, mb_substr($newTitle, 0, 58), $write ? 'düzeltildi' : 'düzeltilecek'];
        }

        $this->table(['ID', 'Başlık', 'Durum'], $rows);

        $this->line(sprintf(
            'Toplam %d · %s %d · dokunulmadı (bilerek konmuş ayraç) %d',
            count($rows),
            $write ? 'düzeltilen' : 'düzeltilecek',
            $fixed,
            $kept,
        ));

        if (! $write) {
            $this->newLine();
            $this->warn('Kuru çalışma — hiçbir şey yazılmadı. Yazmak için --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * A pipe welded to the character before it is a comma; one with a space in
     * front of it is the product's own punctuation.
     */
    private function repair(string $text): string
    {
        return (string) preg_replace('/(?<! )\|/u', ',', $text);
    }
}
