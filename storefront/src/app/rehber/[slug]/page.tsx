import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { RelatedProducts } from '@/components/RelatedProducts';
import { getGuide, GUIDES, type GuideBlock } from '@/lib/guides';
import { jsonLd } from '@/lib/jsonld';
import { absoluteUrl } from '@/lib/site';

/**
 * A single /rehber guide (SEO — the long-tail informational surface the audit
 * flagged as missing). Editorial content from `lib/guides` rendered with real
 * H1→H2 structure, a comparison table, FAQ, and live product rails that turn the
 * read into a shopping path. Article + BreadcrumbList + FAQPage JSON-LD.
 *
 * The product carousels are live (RelatedProducts fetches), so the page opts out
 * of static rendering like the rest of the catalogue surfaces.
 */
export const dynamic = 'force-dynamic';

type Props = { params: Promise<{ slug: string }> };

export function generateStaticParams() {
  return GUIDES.map((g) => ({ slug: g.slug }));
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const guide = getGuide(slug);
  if (guide === null) return { title: 'Rehber bulunamadı' };

  return {
    title: guide.title,
    description: guide.metaDescription,
    alternates: { canonical: absoluteUrl(`/rehber/${guide.slug}`) },
    openGraph: { title: guide.title, description: guide.metaDescription, type: 'article' },
  };
}

export default async function GuidePage({ params }: Props) {
  const { slug } = await params;
  const guide = getGuide(slug);
  if (guide === null) notFound();

  const url = absoluteUrl(`/rehber/${guide.slug}`);

  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { name: 'Ana Sayfa', url: absoluteUrl('/') },
      { name: 'Rehber', url: absoluteUrl('/rehber') },
      { name: guide.title, url },
    ].map((item, index) => ({ '@type': 'ListItem', position: index + 1, name: item.name, item: item.url })),
  };

  const articleLd = {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: guide.title,
    description: guide.metaDescription,
    datePublished: guide.updated,
    dateModified: guide.updated,
    mainEntityOfPage: url,
    inLanguage: 'tr-TR',
    author: { '@type': 'Organization', name: 'Raftabul', url: absoluteUrl('/') },
    publisher: {
      '@type': 'Organization',
      name: 'Raftabul',
      logo: { '@type': 'ImageObject', url: absoluteUrl('/logo.png') },
    },
  };

  const faqLd =
    guide.faq.length > 0
      ? {
          '@context': 'https://schema.org',
          '@type': 'FAQPage',
          mainEntity: guide.faq.map((f) => ({
            '@type': 'Question',
            name: f.q,
            acceptedAnswer: { '@type': 'Answer', text: f.a },
          })),
        }
      : null;

  return (
    <article className="mx-auto flex w-full max-w-3xl flex-col gap-6">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(breadcrumbLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(articleLd) }} />
      {faqLd && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(faqLd) }} />}

      <nav aria-label="Sayfa yolu" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/" className="hover:text-brand-600">Ana Sayfa</Link>
        <span className="px-1">/</span>
        <Link href="/rehber" className="hover:text-brand-600">Rehber</Link>
      </nav>

      <header className="flex flex-col gap-2">
        <h1 className="text-[1.8rem] font-extrabold leading-tight tracking-tight text-balance sm:text-[2.1rem]">
          {guide.title}
        </h1>
        <time dateTime={guide.updated} className="text-[.8rem] text-ink-400">
          Güncelleme: {new Date(guide.updated).toLocaleDateString('tr-TR', { year: 'numeric', month: 'long', day: 'numeric' })}
        </time>
      </header>

      {guide.intro.map((p, i) => (
        <p key={i} className="text-[.95rem] leading-relaxed text-ink-600 dark:text-ink-300">{p}</p>
      ))}

      {guide.sections.map((section, si) => (
        <section key={si} className="flex flex-col gap-3">
          <h2 className="mt-4 text-xl font-extrabold tracking-tight">{section.heading}</h2>
          {section.blocks.map((block, bi) => (
            <Block key={bi} block={block} />
          ))}
        </section>
      ))}

      {guide.faq.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="mt-4 text-xl font-extrabold tracking-tight">Sıkça Sorulan Sorular</h2>
          <dl className="flex flex-col divide-y divide-ink-100 dark:divide-ink-800">
            {guide.faq.map((f, i) => (
              <div key={i} className="py-3.5">
                <dt className="font-bold text-ink-800 dark:text-ink-100">{f.q}</dt>
                <dd className="mt-1 text-[.92rem] leading-relaxed text-ink-600 dark:text-ink-300">{f.a}</dd>
              </div>
            ))}
          </dl>
        </section>
      )}

      <p className="mt-2 rounded-xl bg-ink-50 px-4 py-3 text-[.8rem] leading-relaxed text-ink-400 dark:bg-ink-900">
        Bu içerik bilgilendirme amaçlıdır. Kozmetik ürünler hastalıkların teşhis, tedavi ve önlenmesi amacıyla
        kullanılamaz; cilt sorunlarınız için hekiminize danışın.
      </p>
    </article>
  );
}

function Block({ block }: { block: GuideBlock }) {
  if (block.type === 'p') {
    return <p className="text-[.95rem] leading-relaxed text-ink-600 dark:text-ink-300">{block.text}</p>;
  }
  if (block.type === 'ul') {
    return (
      <ul className="flex list-disc flex-col gap-1.5 pl-5 text-[.95rem] leading-relaxed text-ink-600 dark:text-ink-300">
        {block.items.map((it, i) => (
          <li key={i}>{it}</li>
        ))}
      </ul>
    );
  }
  if (block.type === 'table') {
    return (
      <div className="overflow-x-auto rounded-2xl border border-ink-100 dark:border-ink-800">
        <table className="w-full border-collapse text-[.88rem]">
          <thead>
            <tr>
              {block.headers.map((h, i) => (
                <th key={i} className="border-b border-ink-100 bg-ink-50 px-3 py-2.5 text-left font-extrabold text-ink-600 dark:border-ink-800 dark:bg-ink-900">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {block.rows.map((row, ri) => (
              <tr key={ri}>
                {row.map((cell, ci) => (
                  <td key={ci} className="border-b border-ink-100 px-3 py-2.5 align-top text-ink-600 last:border-none dark:border-ink-800 dark:text-ink-300">
                    {ci === 0 ? <span className="font-bold text-ink-800 dark:text-ink-100">{cell}</span> : cell}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }
  // carousel — a live product rail tying the guide back to the catalogue.
  return (
    <div className="mt-1">
      <RelatedProducts
        title={block.title}
        query={block.brand ? { brand: block.brand } : { category: block.category }}
        href={block.href}
      />
    </div>
  );
}
