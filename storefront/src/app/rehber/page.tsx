import type { Metadata } from 'next';
import Link from 'next/link';
import { GUIDES } from '@/lib/guides';
import { jsonLd } from '@/lib/jsonld';
import { absoluteUrl } from '@/lib/site';

/**
 * The /rehber hub index (SEO) — the home for informational, non-branded content
 * that the audit flagged as the biggest structural gap. Lists the guides; each
 * card links to a full article that ties back to the catalogue.
 */
export const metadata: Metadata = {
  title: 'Bakım Rehberleri',
  description:
    'Cilt, saç ve kişisel bakımda doğru ürünü seçmenize yardımcı olan rehberler: marka karşılaştırmaları, cilt tipine göre öneriler ve bakım ipuçları — Raftabul.',
  alternates: { canonical: absoluteUrl('/rehber') },
};

export default function RehberIndex() {
  const itemListLd = {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    itemListElement: GUIDES.map((g, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      url: absoluteUrl(`/rehber/${g.slug}`),
      name: g.title,
    })),
  };

  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { name: 'Ana Sayfa', url: absoluteUrl('/') },
      { name: 'Rehber', url: absoluteUrl('/rehber') },
    ].map((item, index) => ({ '@type': 'ListItem', position: index + 1, name: item.name, item: item.url })),
  };

  return (
    <div className="flex flex-col gap-6">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(breadcrumbLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(itemListLd) }} />

      <nav aria-label="Sayfa yolu" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/" className="hover:text-brand-600">Ana Sayfa</Link>
        <span className="px-1">/</span>
        <span className="font-semibold text-ink-700 dark:text-ink-200">Rehber</span>
      </nav>

      <header className="flex flex-col gap-1.5">
        <h1 className="text-[1.8rem] font-extrabold leading-tight tracking-tight sm:text-[2.1rem]">Bakım Rehberleri</h1>
        <p className="max-w-[60ch] text-ink-500">
          Cilt, saç ve kişisel bakımda doğru ürünü seçmenize yardımcı olan rehberler — marka karşılaştırmaları,
          cilt tipine göre öneriler ve bakım ipuçları.
        </p>
      </header>

      {GUIDES.length === 0 ? (
        <p className="text-sm text-ink-500">Rehberler yakında.</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {GUIDES.map((g) => (
            <Link
              key={g.slug}
              href={`/rehber/${g.slug}`}
              className="group flex flex-col gap-2 rounded-2xl border border-ink-100 bg-white p-5 transition hover:border-brand-300 hover:shadow-sm dark:border-ink-800 dark:bg-ink-900"
            >
              <h2 className="text-[1.05rem] font-extrabold leading-snug tracking-tight group-hover:text-brand-600">
                {g.title}
              </h2>
              <p className="text-[.9rem] leading-relaxed text-ink-500">{g.teaser}</p>
              <span className="mt-auto pt-1 text-[.82rem] font-bold text-brand-600">Rehberi oku →</span>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
