'use client';

import { useEffect, useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import { formatMoney } from '@/lib/money';
import * as api from '@/lib/session-api';
import { ui } from '@/lib/ui';
import { LOYALTY_SOURCE_LABELS, type LoyaltyBalance, type LoyaltyLedgerEntry } from '@/lib/types';

/**
 * "Puanlarım" (Loyalty, ADR-081) — the customer's points balance + history.
 *
 * THE BALANCE IS THE LEDGER, READ. The backend computes it on read (ADR-081); the
 * page never adds points up itself — it shows what the balance endpoint returns and
 * lists the ledger rows beneath it. Until the module ships, both endpoints 404 and
 * the fetchers degrade to a zero balance + empty history, so the page is safe to
 * ship ahead of the backend.
 */
export default function MyPointsPage() {
  const { status } = useSession();
  const [balance, setBalance] = useState<LoyaltyBalance | null>(null);
  const [ledger, setLedger] = useState<LoyaltyLedgerEntry[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (status === 'authenticated') {
      void Promise.all([api.fetchLoyaltyBalance(), api.fetchLoyaltyLedger()]).then(([b, l]) => {
        setBalance(b);
        setLedger(l);
        setLoading(false);
      });
    } else if (status === 'anonymous') {
      setLoading(false);
    }
  }, [status]);

  if (status === 'anonymous') {
    return <SignInPrompt next="/hesap/puanlarim" title="Puanlarınızı görmek için giriş yapın" />;
  }

  if (loading) return <p className="py-12 text-center text-ink-500">Yükleniyor…</p>;

  const points = balance?.points ?? 0;

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-[1.5rem] font-extrabold leading-tight tracking-tight">Puanlarım</h1>
        <p className="mt-1 text-sm text-ink-500">
          Alışveriş, üyelik ve yorumlarınızdan kazandığınız puanlar.
        </p>
      </div>

      {/* Balance card — the number the shopper came to see, with its TL value. */}
      <div className="flex items-center justify-between gap-4 rounded-2xl bg-gradient-to-br from-brand-500 to-brand-600 p-5 text-white">
        <div>
          <span className="text-[.8rem] font-bold uppercase tracking-wide text-white/80">Puan bakiyen</span>
          <div className="mt-1 flex items-baseline gap-2">
            <span className="text-3xl font-extrabold tabular-nums">{points.toLocaleString('tr-TR')}</span>
            <span className="text-sm font-bold text-white/85">puan</span>
          </div>
          {balance !== null && (
            <span className="mt-1 block text-sm font-semibold text-white/85">
              ≈ {formatMoney(balance.value, balance.currency)} değerinde
            </span>
          )}
        </div>
        <StarIcon />
      </div>

      <div>
        <h2 className="mb-2 text-sm font-extrabold tracking-tight text-ink-700 dark:text-ink-200">
          Puan geçmişi
        </h2>

        {ledger.length === 0 ? (
          <p className={`p-4 text-sm text-ink-500 ${ui.card}`}>
            Henüz puan hareketiniz yok. Alışveriş yaptıkça, üye oldukça ve yorum yaptıkça puan
            kazanırsınız.
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {ledger.map((entry) => (
              <LedgerRow key={entry.uuid} entry={entry} />
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

function LedgerRow({ entry }: { entry: LoyaltyLedgerEntry }) {
  const earned = entry.points >= 0;
  const label = entry.label ?? LOYALTY_SOURCE_LABELS[entry.source_type] ?? 'Puan';
  const date = new Date(entry.created_at).toLocaleDateString('tr-TR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });

  return (
    <li className={`flex items-center justify-between gap-3 p-3.5 ${ui.card}`}>
      <div className="min-w-0">
        <span className="block text-sm font-bold text-ink-700 dark:text-ink-200">{label}</span>
        <span className="text-[.78rem] text-ink-400">{date}</span>
      </div>
      <span
        className={`shrink-0 text-base font-extrabold tabular-nums ${
          earned ? 'text-green-600' : 'text-ink-500'
        }`}
      >
        {earned ? '+' : '−'}
        {Math.abs(entry.points).toLocaleString('tr-TR')}
      </span>
    </li>
  );
}

function StarIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-12 w-12 shrink-0 text-white/90" fill="currentColor" aria-hidden="true">
      <path d="M12 2.5l2.9 5.9 6.5.95-4.7 4.58 1.11 6.47L12 17.35 6.19 20.9 7.3 14.43 2.6 9.85l6.5-.95z" />
    </svg>
  );
}
