import Link from 'next/link';
import { fetchCategoryTree } from '@/lib/api';

/**
 * The category menu bar under the header (Trendyol-style).
 *
 * DATA-DRIVEN NOW (ADR-059). It reads the real category tree and links each
 * top-level category to its flat slug (`/cilt-bakimi`). Categories with nothing
 * sellable under them are dropped — an empty "Giyim (0)" in the menu is a dead end
 * a shopper learns to distrust. A server component, so the menu is in the first
 * HTML the crawler sees.
 */
export async function CategoryBar() {
  // The menu sits in the shell on every page; an API blip should drop the menu, not
  // 500 the whole site — so a failed read renders an empty bar rather than throwing.
  // Catch-all buckets that shouldn't sit in the shopfront menu.
  // 'magnezyum-...' is a malformed top-level category the catalog import created from a
  // product name (note the doubled label) — its real home is under Besin Takviyeleri; an
  // admin merge is the durable fix, this just keeps it out of the menu meanwhile.
  const hidden = new Set([
    'kategorisiz-urunler',
    'setler',
    'magnezyum-bisglisinatmagnezyum-bisglisinat',
  ]);

  const tree = await fetchCategoryTree().catch(() => []);
  const top = tree
    .filter((category) => category.product_count > 0 && !hidden.has(category.slug))
    .slice(0, 12);

  return (
    <nav className="border-b border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-950">
      <div className="mx-auto flex w-full max-w-page items-center gap-1 overflow-x-auto px-4 py-2 hide-scroll">
        {top.map((category) => {
          // The Outlet category gets the accent colour (it replaces the old
          // "Süper Fırsatlar" as the one highlighted entry).
          const highlight = /outlet/i.test(category.name);

          return (
            <Link
              key={category.id}
              href={`/${category.slug}`}
              className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-bold hover:bg-brand-50 hover:text-brand-700 dark:hover:bg-ink-900 ${
                highlight ? 'text-brand-600' : 'text-ink-600 dark:text-ink-300'
              }`}
            >
              {category.name}
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
