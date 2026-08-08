'use client';

import Link from 'next/link';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import { ui } from '@/lib/ui';

/**
 * "Hesabım" — the account landing (§2.2).
 *
 * IT READS, IT DOES NOT EDIT. The platform has no customer self-service profile
 * write yet, so this shows who you are and points at the two things you can act on
 * — your orders and your addresses — rather than faking an "edit profile" form that
 * would 404 on submit. When a profile-update endpoint exists, the fields become
 * inputs here; until then, honesty beats a dead control.
 */
export default function AccountPage() {
  const { status, user } = useSession();

  if (status === 'loading') {
    return <p className="py-12 text-center text-ink-500">Yükleniyor…</p>;
  }

  if (status === 'anonymous' || user === null) {
    return <SignInPrompt next="/hesap" title="Hesabınızı görmek için giriş yapın" />;
  }

  const fullName = [user.first_name, user.last_name].filter(Boolean).join(' ');

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center gap-4">
        <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-brand-50 text-lg font-extrabold text-brand-700 dark:bg-brand-500/15">
          {initials(fullName || user.email)}
        </span>
        <div>
          <h1 className="text-[1.5rem] font-extrabold leading-tight tracking-tight">
            Merhaba{user.first_name ? `, ${user.first_name}` : ''} 👋
          </h1>
          <p className="text-sm text-ink-500">Hesap bilgilerin ve siparişlerin burada.</p>
        </div>
      </div>

      <section className={`p-5 ${ui.card}`}>
        <h2 className={`mb-4 ${ui.h2}`}>Hesap bilgileri</h2>
        <dl className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-400">Ad Soyad</dt>
            <dd className="mt-1 font-bold">{fullName || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-400">E-posta</dt>
            <dd className="mt-1 font-bold break-all">{user.email}</dd>
          </div>
        </dl>
      </section>

      <div className="grid gap-4 sm:grid-cols-2">
        <ShortcutCard
          href="/hesap/siparislerim"
          title="Siparişlerim"
          note="Geçmiş siparişlerini görüntüle ve takip et"
        />
        <ShortcutCard
          href="/hesap/adreslerim"
          title="Adreslerim"
          note="Teslimat ve fatura adreslerini yönet"
        />
        <ShortcutCard
          href="/hesap/sorularim"
          title="Sorularım"
          note="Satıcılara sorduğun sorular ve yanıtları"
        />
      </div>
    </div>
  );
}

function ShortcutCard({ href, title, note }: { href: string; title: string; note: string }) {
  return (
    <Link
      href={href}
      className={`group flex items-center justify-between gap-3 p-5 transition hover:border-brand-300 hover:shadow-[0_18px_40px_-24px_rgba(20,25,35,.28)] ${ui.card}`}
    >
      <div>
        <div className="font-extrabold tracking-tight">{title}</div>
        <div className="mt-0.5 text-sm text-ink-500">{note}</div>
      </div>
      <span className="text-brand-500 transition group-hover:translate-x-0.5">
        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
          <path d="m9 6 6 6-6 6" />
        </svg>
      </span>
    </Link>
  );
}

function initials(value: string): string {
  const parts = value.trim().split(/\s+/).filter(Boolean);
  const combo =
    parts.length > 1
      ? (parts[0]?.charAt(0) ?? '') + (parts[parts.length - 1]?.charAt(0) ?? '')
      : value;

  return combo.slice(0, 2).toUpperCase();
}
