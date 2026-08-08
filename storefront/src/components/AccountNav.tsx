'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useSession } from './SessionProvider';
import { ui } from '@/lib/ui';

/**
 * The account area's own navigation (§2.2).
 *
 * WHY A SHARED RAIL AND NOT LINKS PER PAGE. "Hesabım", "Siparişlerim" and
 * "Adreslerim" are one place a customer visits, and they were three URLs with no
 * way between them — you reached an address book by typing its path. One rail,
 * rendered by the account layout, makes the section a section: every page knows
 * where the others are and which one you are on.
 *
 * ACTIVE STATE IS THE PATH, not props threaded down. `usePathname` is the honest
 * source — it is where the browser actually is — so a deep link highlights the
 * right item without the page having to announce itself.
 */
const LINKS = [
  { href: '/hesap', label: 'Hesabım', exact: true, icon: UserIcon },
  { href: '/hesap/siparislerim', label: 'Siparişlerim', icon: BoxIcon },
  { href: '/hesap/sorularim', label: 'Sorularım', icon: ChatIcon },
  { href: '/hesap/adreslerim', label: 'Adreslerim', icon: PinIcon },
];

export function AccountNav() {
  const pathname = usePathname();
  const { signOut } = useSession();

  return (
    <nav className={`flex h-fit flex-col p-2 ${ui.card}`}>
      {LINKS.map(({ href, label, exact, icon: Icon }) => {
        const active = exact ? pathname === href : pathname.startsWith(href);

        return (
          <Link
            key={href}
            href={href}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-bold transition ${
              active
                ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200'
                : 'text-ink-600 hover:bg-ink-50 dark:text-ink-300 dark:hover:bg-ink-800'
            }`}
          >
            <Icon />
            {label}
          </Link>
        );
      })}

      <button
        type="button"
        onClick={() => void signOut()}
        className="mt-1 flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-left text-sm font-bold text-ink-500 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40"
      >
        <ExitIcon />
        Çıkış yap
      </button>
    </nav>
  );
}

function UserIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21c0-4 4-6 8-6s8 2 8 6" />
    </svg>
  );
}

function BoxIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 8 12 3 3 8v8l9 5 9-5V8z" />
      <path d="m3 8 9 5 9-5M12 13v8" />
    </svg>
  );
}

function ChatIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7a8.5 8.5 0 0 1-.9-3.8 8.38 8.38 0 0 1 8.5-8.5 8.38 8.38 0 0 1 8.5 8.5z" />
    </svg>
  );
}

function PinIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 21s7-6.4 7-11a7 7 0 1 0-14 0c0 4.6 7 11 7 11z" />
      <circle cx="12" cy="10" r="2.5" />
    </svg>
  );
}

function ExitIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
      <path d="M16 17l5-5-5-5M21 12H9" />
    </svg>
  );
}
