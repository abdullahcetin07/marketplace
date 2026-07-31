import Link from 'next/link';

/**
 * One 404 page for everything that is not there.
 *
 * IT SAYS NOTHING ABOUT WHY, and that mirrors the API: an unpublished product and
 * a product that never existed answer identically, so a page that distinguished
 * them would leak exactly what the backend refuses to.
 */
export default function NotFound() {
  return (
    <div className="flex flex-col items-center gap-4 py-24 text-center">
      <h1 className="text-2xl font-bold">Sayfa bulunamadı</h1>
      <p className="text-ink-500">Aradığınız sayfa kaldırılmış veya hiç var olmamış olabilir.</p>
      <Link
        href="/urunler"
        className="rounded-lg bg-brand-500 px-5 py-2.5 font-medium text-white transition hover:bg-brand-600"
      >
        Ürünlere göz at
      </Link>
    </div>
  );
}
