import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { contentPages, getContentPage, type PageBlock } from '@/lib/pages';
import { absoluteUrl } from '@/lib/site';

/**
 * The static content pages behind the footer (Hakkımızda, SSS, KVKK…).
 *
 * ONE ROUTE, MANY PAGES. The copy lives in `lib/pages.ts`; this renders whichever
 * slug is asked for and 404s the rest. Static and cacheable — `generateStaticParams`
 * pre-renders every known page, so these cost nothing per request.
 */
export const dynamicParams = false;

export function generateStaticParams(): { slug: string }[] {
  return Object.keys(contentPages).map((slug) => ({ slug }));
}

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const page = getContentPage(slug);

  if (page === undefined) return { title: 'Sayfa bulunamadı' };

  return {
    title: page.title,
    description: page.description,
    alternates: { canonical: absoluteUrl(`/sayfa/${slug}`) },
  };
}

export default async function ContentPageView({ params }: Props) {
  const { slug } = await params;
  const page = getContentPage(slug);

  if (page === undefined) notFound();

  return (
    <div className="mx-auto w-full max-w-3xl py-2">
      <nav aria-label="Yol" className="mb-4 flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/" className="hover:text-brand-600">Ana Sayfa</Link>
        <span className="px-1">/</span>
        <span className="font-semibold text-ink-700 dark:text-ink-200">{page.title}</span>
      </nav>

      <h1 className="text-[1.8rem] font-extrabold leading-tight tracking-tight sm:text-[2.1rem]">{page.title}</h1>

      {page.intro !== undefined && (
        <p className="mt-3 text-[1.02rem] leading-relaxed text-ink-600 dark:text-ink-300">{page.intro}</p>
      )}

      <div className="mt-6 flex flex-col gap-4">
        {page.body.map((block, index) => (
          <Block key={index} block={block} />
        ))}
      </div>
    </div>
  );
}

function Block({ block }: { block: PageBlock }) {
  if ('h' in block) {
    return <h2 className="mt-3 text-lg font-extrabold tracking-tight text-ink-800 dark:text-ink-100">{block.h}</h2>;
  }

  if ('p' in block) {
    return <p className="text-[.95rem] leading-relaxed text-ink-600 dark:text-ink-300">{block.p}</p>;
  }

  if ('ul' in block) {
    return (
      <ul className="flex flex-col gap-2">
        {block.ul.map((item, index) => (
          <li key={index} className="flex gap-2.5 text-[.95rem] leading-relaxed text-ink-600 dark:text-ink-300">
            <span aria-hidden="true" className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-400" />
            {item}
          </li>
        ))}
      </ul>
    );
  }

  if ('qa' in block) {
    return (
      <div className="rounded-2xl border border-ink-100 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
        <p className="text-[.95rem] font-extrabold text-ink-800 dark:text-ink-100">{block.qa[0]}</p>
        <p className="mt-1.5 text-[.92rem] leading-relaxed text-ink-600 dark:text-ink-300">{block.qa[1]}</p>
      </div>
    );
  }

  // note
  return (
    <p className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[.9rem] leading-relaxed text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-200">
      {block.note}
    </p>
  );
}
