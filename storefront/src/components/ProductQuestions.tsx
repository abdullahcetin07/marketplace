'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useCallback, useEffect, useMemo, useState } from 'react';
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

  return (
    <section id="sorular" className="scroll-mt-24">
      <h2 className="text-lg font-extrabold tracking-tight">
        Sorular &amp; Cevaplar {page.total > 0 && <span className="text-ink-400">({page.total})</span>}
      </h2>

      <div className="mt-3">
        <AskSeller productId={productId} />
      </div>

      {sellers.length > 1 && (
        <div className="mt-4">
          <select
            value={filters.seller ?? ''}
            onChange={(e) => setFilters({ seller: e.target.value === '' ? undefined : e.target.value })}
            className="rounded-xl border-2 border-ink-200 bg-white px-3 py-2 text-sm font-bold text-ink-700 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-200"
          >
            <option value="">Tüm satıcılar</option>
            {sellers.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </div>
      )}

      <ul className="mt-4 flex flex-col gap-3">
        {items.length === 0 && !loading && (
          <li className="rounded-2xl border border-dashed border-ink-200 px-5 py-8 text-center text-sm text-ink-500 dark:border-ink-700">
            Bu ürüne henüz yanıtlanmış bir soru yok. İlk soruyu siz sorabilirsiniz.
          </li>
        )}
        {items.map((question) => (
          <QuestionCard key={question.id} question={question} />
        ))}
      </ul>

      {page.page < page.lastPage && (
        <div className="mt-4 text-center">
          <button
            type="button"
            disabled={loading}
            onClick={() => void load({ ...filters, page: page.page + 1 }, true)}
            className="rounded-xl border-2 border-ink-200 px-5 py-2.5 text-sm font-bold text-ink-600 transition hover:border-brand-400 hover:text-brand-600 disabled:opacity-50 dark:border-ink-700 dark:text-ink-300"
          >
            {loading ? 'Yükleniyor…' : 'Daha fazla soru'}
          </button>
        </div>
      )}
    </section>
  );
}

/** The question card — the shopper's question and the seller's answer beneath it. */
function QuestionCard({ question }: { question: PublicQuestion }) {
  return (
    <li className="rounded-2xl border border-ink-100 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
      <div className="flex items-start gap-2.5">
        <span className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-ink-100 text-[.7rem] font-extrabold text-ink-500 dark:bg-ink-800">
          S
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-[.9rem] font-bold leading-snug">{question.body}</p>
          <span className="text-[.72rem] text-ink-400">
            {question.asker_name} · {formatDate(question.asked_at)}
          </span>
        </div>
      </div>

      {question.answer_body !== null && (
        <div className="mt-3 flex items-start gap-2.5 rounded-xl bg-brand-50/60 p-3 dark:bg-brand-500/10">
          <span className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-lg bg-brand-500 text-[.7rem] font-extrabold text-white">
            C
          </span>
          <div className="min-w-0 flex-1">
            <p className="whitespace-pre-line text-[.88rem] leading-relaxed text-ink-700 dark:text-ink-200">
              {question.answer_body}
            </p>
            <span className="text-[.72rem] font-semibold text-brand-600">
              {question.seller.name ?? 'Satıcı'}
              {question.answered_at !== null ? ` · ${formatDate(question.answered_at)}` : ''}
            </span>
          </div>
        </div>
      )}
    </li>
  );
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
