import Link from 'next/link';

/**
 * The category menu bar under the header (Trendyol-style).
 *
 * STATIC LABELS, not a fetch: these are the top-level merchandising shortcuts,
 * and each is a search into the real listing (`/urunler?category=`). When a
 * category-tree endpoint exists this becomes data-driven; until then a hard-coded
 * shortcut list is honest merchandising, not a lie about live categories.
 */
const items = [
  'Dermokozmetik', 'Cilt Bakımı', 'Güneş Ürünleri', 'Saç Bakımı',
  'Anne & Bebek', 'Vitamin & Takviye', 'Kişisel Bakım', 'Ağız & Diş', 'Medikal',
];

export function CategoryBar() {
  return (
    <nav className="border-b border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-950">
      <div className="mx-auto flex w-full max-w-page items-center gap-1 overflow-x-auto px-4 py-2 hide-scroll">
        <Link href="/urunler" className="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-bold text-brand-600 hover:bg-brand-50 dark:hover:bg-ink-900">
          Süper Fırsatlar
        </Link>
        {items.map((c) => (
          <Link
            key={c}
            href={`/urunler?category=${encodeURIComponent(c)}`}
            className="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-bold text-ink-600 hover:bg-brand-50 hover:text-brand-700 dark:text-ink-300 dark:hover:bg-ink-900"
          >
            {c}
          </Link>
        ))}
      </div>
    </nav>
  );
}
