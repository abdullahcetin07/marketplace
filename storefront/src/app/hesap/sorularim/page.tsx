'use client';

import { useEffect, useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import * as api from '@/lib/session-api';
import { ui } from '@/lib/ui';
import type { MyQuestion } from '@/lib/types';

/**
 * "Sorularım" (Questions, ADR-070) — the buyer's own questions, ANY status.
 *
 * A PENDING QUESTION IS INVISIBLE EVERYWHERE ELSE — it is private until the seller
 * answers — so this is the one place a shopper sees "cevap bekliyor". The answer
 * appears here the moment it is published, beside the question that prompted it.
 */
export default function MyQuestionsPage() {
  const { status } = useSession();
  const [questions, setQuestions] = useState<MyQuestion[]>([]);
  const [loading, setLoading] = useState(true);

  async function reload() {
    const rows = await api.fetchMyQuestions().catch(() => null);
    setQuestions(rows ?? []);
    setLoading(false);
  }

  useEffect(() => {
    if (status === 'authenticated') void reload();
    else if (status === 'anonymous') setLoading(false);
  }, [status]);

  if (status === 'anonymous') {
    return <SignInPrompt next="/hesap/sorularim" title="Sorularınızı görmek için giriş yapın" />;
  }

  if (loading) return <p className="py-12 text-center text-ink-500">Yükleniyor…</p>;

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-[1.5rem] font-extrabold leading-tight tracking-tight">Sorularım</h1>
        <p className="mt-1 text-sm text-ink-500">Satıcılara sorduğunuz sorular ve yanıtları.</p>
      </div>

      {questions.length === 0 ? (
        <p className="text-sm text-ink-500">Henüz bir soru sormadınız.</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {questions.map((question) => (
            <QuestionRow key={question.id} question={question} onDeleted={reload} />
          ))}
        </ul>
      )}
    </div>
  );
}

function QuestionRow({ question, onDeleted }: { question: MyQuestion; onDeleted: () => void }) {
  const [busy, setBusy] = useState(false);
  const answered = question.status === 'answered';

  async function remove() {
    setBusy(true);
    try {
      await api.deleteQuestion(question.id);
      onDeleted();
    } finally {
      setBusy(false);
    }
  }

  return (
    <li className={`p-4 ${ui.card}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <span className="min-w-0 flex-1 text-[.82rem] font-bold text-ink-500">{question.product_title}</span>
        <span
          className={`rounded-full px-2.5 py-0.5 text-[.72rem] font-bold ${
            answered
              ? 'bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-300'
              : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'
          }`}
        >
          {answered ? 'Cevaplandı' : 'Cevap bekliyor'}
        </span>
      </div>

      <p className="mt-2 text-[.9rem] font-bold leading-snug">{question.body}</p>

      {question.answer_body !== null && (
        <div className="mt-2 rounded-xl bg-brand-50/60 p-3 dark:bg-brand-500/10">
          <p className="whitespace-pre-line text-[.88rem] leading-relaxed text-ink-700 dark:text-ink-200">
            {question.answer_body}
          </p>
          {question.seller?.name != null && (
            <span className="text-[.72rem] font-semibold text-brand-600">{question.seller.name}</span>
          )}
        </div>
      )}

      <div className="mt-2 flex justify-end">
        <button
          type="button"
          onClick={() => void remove()}
          disabled={busy}
          className="text-xs font-semibold text-ink-400 hover:text-red-600 disabled:opacity-50"
        >
          {busy ? 'Siliniyor…' : 'Soruyu sil'}
        </button>
      </div>
    </li>
  );
}
