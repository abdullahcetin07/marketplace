import Link from 'next/link';

/**
 * Prev / page-of / next, driven by a caller-supplied href builder.
 *
 * The three listing surfaces — search, category, brand — paginate the same way but
 * live at different URLs, so the one thing that differs (how a page number becomes a
 * link) is the one thing passed in. Everything a crawler follows stays a real `<a>`.
 */
export function Pagination({
  page,
  lastPage,
  hrefForPage,
}: {
  page: number;
  lastPage: number;
  hrefForPage: (page: number) => string;
}) {
  if (lastPage <= 1) return null;

  return (
    <nav className="flex items-center justify-center gap-3 pt-4 text-sm">
      {page > 1 && (
        <Link
          href={hrefForPage(page - 1)}
          className="rounded-xl border-2 border-ink-200 px-4 py-2 font-bold transition hover:border-brand-400 dark:border-ink-700"
        >
          ← Önceki
        </Link>
      )}

      <span className="font-semibold text-ink-500">
        {page} / {lastPage}
      </span>

      {page < lastPage && (
        <Link
          href={hrefForPage(page + 1)}
          className="rounded-xl border-2 border-ink-200 px-4 py-2 font-bold transition hover:border-brand-400 dark:border-ink-700"
        >
          Sonraki →
        </Link>
      )}
    </nav>
  );
}
