'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import {
  getProductQuestions,
  type ProductQuestionsPage,
  type PublicQuestion,
  type QuestionFilters,
} from '@/lib/api';
import { askQuestion, SessionApiError } from '@/lib/session-api';
import { ui } from '@/lib/ui';

/**
 * The "Sorular & Cevaplar" section of a product page (Questions, ADR-070/071).
 *
 * SERVER-SEEDED, CLIENT-INTERACTIVE — the first page is fetched on the server (SEO
 * + instant paint), the seller filter and "daha fazla" re-fetch in the browser.
 *
 * ONLY ANSWERED Q&A IS PUBLIC (an unanswered question is private to the seller), so
 * this list is never the place a shopper sees their own just-asked question — that
 * lives in "Sorularım". "Satıcıya Sor" therefore confirms "satıcıya iletildi" and
 * does NOT optimistically prepend anything here.
 *
 * NO RATING, NO SUMMARY — this is not Reviews. The seller filter is built from the
 * sellers actually present in the answered questions, so it appears only when more
 * than one merchant has answered.
 */
export function ProductQuestions({ productId, initial }: { productId: string; initial: ProductQuestionsPage }) {
  const [filters, setFilters] = useState<QuestionFilters>({});
  const [page, setPage] = useState<ProductQuestionsPage>(initial);
  const [items, setItems] = useState<PublicQuestion[]>(initial.questions);
  const [loading, setLoading] = useState(false);

  const load = useCallback(
    async (next: QuestionFilters, append: boolean) => {
      setLoading(true);
      try {
        const result = await getProductQuestions(productId, next);
        setPage(result);
        setItems((prev) => (append ? [...prev, ...result.questions] : result.questions));
      } finally {
        setLoading(false);
      }
    },
    [productId],
  );

  const [mounted, setMounted] = useState(false);
  useEffect(() => {
    if (!mounted) {
      setMounted(true);
      return;
    }
    void load({ ...filters, page: 1 }, false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.seller]);

  // The sellers who have answered, from what's loaded — no summary endpoint exists.
  const sellers = useMemo(() => {
    const map = new Map<string, string>();
    items.forEach((q) => {
      if (!map.has(q.seller.id)) map.set(q.seller.id, q.seller.name ?? 'Mağaza');
    });
    return [...map.entries()].map(([id, name]) => ({ id, name }));
  }, [items]);

  const rail = useRef<HTMLDivElement>(null);
  const scrollBy = (dir: 1 | -1) => rail.current?.scrollBy({ left: dir * 360, behavior: 'smooth' });

  return (
    <section id="sorular" className="scroll-mt-24">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-extrabold tracking-tight">
          Ürün Soru ve Cevapları {page.total > 0 && <span className="text-ink-400">({page.total})</span>}
        </h2>

        {sellers.length > 1 && (
          <select
            value={filters.seller ?? ''}
            onChange={(e) => setFilters({ seller: e.target.value === '' ? undefined : e.target.value })}
            className="rounded-xl border-2 border-ink-200 bg-white px-3 py-1.5 text-sm font-bold text-ink-700 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-200"
          >
            <option value="">Tüm satıcılar</option>
            {sellers.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        )}
      </div>

      <div className="mt-3">
        <AskSeller productId={productId} />
      </div>

      {items.length === 0 && !loading ? (
        <p className="mt-4 rounded-2xl border border-dashed border-ink-200 px-5 py-8 text-center text-sm text-ink-500 dark:border-ink-700">
          Bu ürüne henüz yanıtlanmış bir soru yok. İlk soruyu siz sorabilirsiniz.
        </p>
      ) : (
        <div className="relative mt-4">
          {/* Horizontal card rail (Trendyol-style). Snap + hidden scrollbar; the chevrons
              scroll it on desktop, touch handles it on mobile. */}
          <div
            ref={rail}
            className="hide-scroll flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth pb-1"
          >
            {items.map((question) => (
              <QuestionCard key={question.id} question={question} />
            ))}
          </div>

          {items.length > 1 && (
            <>
              <RailButton dir={-1} onClick={() => scrollBy(-1)} />
              <RailButton dir={1} onClick={() => scrollBy(1)} />
            </>
          )}
        </div>
      )}

      {page.page < page.lastPage && (
        <div className="mt-5 text-center">
          <button
            type="button"
            disabled={loading}
            onClick={() => void load({ ...filters, page: page.page + 1 }, true)}
            className="inline-flex items-center gap-1.5 rounded-full border border-ink-200 px-7 py-3 text-sm font-extrabold uppercase tracking-wide text-ink-600 transition hover:border-brand-400 hover:text-brand-600 disabled:opacity-50 dark:border-ink-700 dark:text-ink-300"
          >
            {loading ? 'Yükleniyor…' : 'Tüm soruları göster'}
            <ChevronIcon dir={1} className="h-4 w-4" />
          </button>
        </div>
      )}
    </section>
  );
}

/** One Q&A card in the rail — the shopper's question, then the seller's answer card. */
function QuestionCard({ question }: { question: PublicQuestion }) {
  const answered = answeredWithin(question.asked_at, question.answered_at);

  return (
    <article className="flex w-[300px] shrink-0 snap-start flex-col rounded-2xl border border-ink-100 bg-white p-4 dark:border-ink-800 dark:bg-ink-900 sm:w-[360px]">
      <h3 className="line-clamp-1 text-[.95rem] font-extrabold tracking-tight">{question.body}</h3>
      <span className="mt-1 text-[.72rem] font-semibold text-ink-400">
        {question.asker_name} · {formatDate(question.asked_at)}
      </span>

      {question.answer_body !== null && (
        <div className="mt-3 flex-1 rounded-xl bg-ink-50 p-3.5 dark:bg-ink-800/60">
          <div className="flex items-center gap-2.5">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-[.7rem] font-extrabold text-brand-700 ring-1 ring-brand-100 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-500/30">
              {initials(question.seller.name ?? 'Satıcı')}
            </span>
            <div className="min-w-0 leading-tight">
              <p className="truncate text-[.82rem]">
                <span className="font-extrabold text-ink-800 dark:text-ink-100">
                  {question.seller.name ?? 'Satıcı'}
                </span>{' '}
                <span className="text-ink-500">satıcısının cevabı</span>
              </p>
              {answered !== null && <span className="text-[.7rem] text-ink-400">{answered}</span>}
            </div>
          </div>
          <p className="mt-2.5 line-clamp-4 whitespace-pre-line text-[.86rem] leading-relaxed text-ink-600 dark:text-ink-300">
            {question.answer_body}
          </p>
        </div>
      )}
    </article>
  );
}

/** A round scroll chevron over the rail, desktop only (touch scrolls on mobile). */
function RailButton({ dir, onClick }: { dir: 1 | -1; onClick: () => void }) {
  return (
    <button
      type="button"
      aria-label={dir === 1 ? 'Sonraki' : 'Önceki'}
      onClick={onClick}
      className={`absolute top-1/2 hidden h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-ink-200 bg-white text-ink-500 shadow-sm transition hover:border-brand-400 hover:text-brand-600 dark:border-ink-700 dark:bg-ink-900 sm:grid ${
        dir === 1 ? '-right-2' : '-left-2'
      }`}
    >
      <ChevronIcon dir={dir} className="h-5 w-5" />
    </button>
  );
}

function ChevronIcon({ dir, className }: { dir: 1 | -1; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {dir === 1 ? <path d="m9 6 6 6-6 6" /> : <path d="m15 6-6 6 6 6" />}
    </svg>
  );
}

/** Two-letter avatar fallback — we have the seller's name but no logo on this surface. */
function initials(name: string): string {
  return name.trim().slice(0, 2).toUpperCase();
}

/** "9 saat içinde cevaplandı" — the gap between the question and its answer. */
function answeredWithin(askedIso: string, answeredIso: string | null): string | null {
  if (answeredIso === null) return null;
  const ms = new Date(answeredIso).getTime() - new Date(askedIso).getTime();
  if (!Number.isFinite(ms) || ms < 0) return null;

  const minutes = Math.max(1, Math.round(ms / 60000));
  if (minutes < 60) return `${minutes} dakika içinde cevaplandı`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} saat içinde cevaplandı`;
  return `${Math.round(hours / 24)} gün içinde cevaplandı`;
}

/**
 * "Satıcıya Sor" — a signed-in customer's question box. A signed-out visitor gets a
 * sign-in link instead; the question targets the buy-box seller, chosen by the
 * server, so nothing about the seller is picked here.
 */
function AskSeller({ productId }: { productId: string }) {
  const { status } = useSession();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (status === 'loading') return null;

  if (status === 'anonymous') {
    return (
      <Link
        href={`/giris?next=${encodeURIComponent(pathname)}`}
        className="inline-flex text-sm font-bold text-brand-600 hover:underline"
      >
        Satıcıya soru sormak için giriş yapın →
      </Link>
    );
  }

  if (sent) {
    return (
      <p className="rounded-xl bg-green-50 px-3.5 py-2.5 text-sm font-semibold text-green-700 dark:bg-green-950/40">
        Sorunuz satıcıya iletildi. Yanıtlandığında burada görünecek.
      </p>
    );
  }

  if (!open) {
    return (
      <button type="button" onClick={() => setOpen(true)} className={ui.btnPrimarySm}>
        Satıcıya Sor
      </button>
    );
  }

  async function submit() {
    if (body.trim().length < 5) {
      setError('Sorunuz en az 5 karakter olmalı.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await askQuestion(productId, body.trim());
      setSent(true);
    } catch (e) {
      setError(e instanceof SessionApiError ? e.message : 'Soru gönderilemedi.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col gap-2 rounded-2xl border border-ink-100 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
      <textarea
        value={body}
        onChange={(e) => setBody(e.target.value)}
        rows={3}
        maxLength={1000}
        autoFocus
        placeholder="Ürün hakkında satıcıya sormak istediğiniz soru"
        className="w-full rounded-lg border-2 border-ink-200 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-950"
      />
      {error !== null && <span className="text-xs font-semibold text-red-600">{error}</span>}
      <div className="flex items-center gap-3">
        <button type="button" onClick={() => void submit()} disabled={busy} className={ui.btnPrimarySm}>
          {busy ? 'Gönderiliyor…' : 'Gönder'}
        </button>
        <button
          type="button"
          onClick={() => setOpen(false)}
          disabled={busy}
          className="text-sm font-semibold text-ink-400 hover:text-ink-600 disabled:opacity-50"
        >
          Vazgeç
        </button>
      </div>
    </div>
  );
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' });
  } catch {
    return '';
  }
}
