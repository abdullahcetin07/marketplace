'use client';

import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { useSession } from './SessionProvider';

/**
 * The right-hand side of the header: who you are, and what is in your basket.
 *
 * IT RENDERS NOTHING WHILE THE SESSION RESOLVES. A header that shows "Giriş yap"
 * for half a second and then swaps to the customer's name is worse than one that
 * appears a moment later — the flash reads as being signed out, which is exactly
 * the thing a shopper reacts to.
 *
 * THE BASKET LINK IS ALWAYS THERE, signed in or not: a shopper who clicks it
 * anonymously should land on a page that explains, not on a link that was hidden
 * from them for reasons they cannot see.
 *
 * SIGNED IN, THE NAME IS A MENU, not a label. "Hesabım", "Siparişlerim" and
 * "Adreslerim" were unreachable from the chrome — you had to know the URL. The
 * account menu is the one place they belong.
 */
export function HeaderActions() {
  const { status, user, itemCount, signOut } = useSession();
  const [open, setOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  // Close on an outside click or Escape — the two ways anyone expects a menu to go
  // away without having to aim back at the button that opened it.
  useEffect(() => {
    if (!open) return;

    function onDown(event: MouseEvent) {
      if (menuRef.current !== null && !menuRef.current.contains(event.target as Node)) setOpen(false);
    }
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false);
    }

    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);

    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="flex items-center gap-5 text-sm">
      <Link href="/sepet" className="relative flex items-center gap-1.5 font-semibold hover:text-brand-600">
        <CartIcon />
        <span className="hidden sm:inline">Sepet</span>
        {itemCount > 0 && (
          <span className="absolute -right-3 -top-2 grid h-[18px] min-w-[18px] place-items-center rounded-full bg-brand-500 px-1 text-[.68rem] font-extrabold text-white">
            {itemCount}
          </span>
        )}
      </Link>

      {status === 'loading' && <span className="w-16" aria-hidden />}

      {status === 'anonymous' && (
        <Link
          href="/giris"
          className="rounded-xl bg-brand-500 px-4 py-2 font-extrabold text-white transition hover:bg-brand-600"
        >
          Giriş yap
        </Link>
      )}

      {status === 'authenticated' && user !== null && (
        <div className="relative" ref={menuRef}>
          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            aria-haspopup="menu"
            aria-expanded={open}
            className="flex items-center gap-2 rounded-xl px-1.5 py-1 font-semibold transition hover:text-brand-600"
          >
            <span className="grid h-8 w-8 place-items-center rounded-full bg-brand-50 text-xs font-extrabold text-brand-700 dark:bg-brand-500/15">
              {initials(user.first_name || user.email)}
            </span>
            <span className="hidden max-w-[10ch] truncate sm:inline">{user.first_name || 'Hesabım'}</span>
            <svg viewBox="0 0 24 24" className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
              <path d="m6 9 6 6 6-6" />
            </svg>
          </button>

          {open && (
            <div
              role="menu"
              className="absolute right-0 top-[calc(100%+8px)] z-50 w-56 overflow-hidden rounded-2xl border border-ink-100 bg-white p-1.5 shadow-[0_24px_60px_-24px_rgba(20,25,35,.35)] dark:border-ink-800 dark:bg-ink-900"
            >
              <div className="px-3 py-2">
                <div className="truncate text-sm font-extrabold">{[user.first_name, user.last_name].filter(Boolean).join(' ') || 'Hesabım'}</div>
                <div className="truncate text-xs text-ink-500">{user.email}</div>
              </div>
              <div className="my-1 h-px bg-ink-100 dark:bg-ink-800" />

              {[
                { href: '/hesap', label: 'Hesabım' },
                { href: '/hesap/siparislerim', label: 'Siparişlerim' },
                { href: '/hesap/puanlarim', label: 'Puanlarım' },
                { href: '/hesap/adreslerim', label: 'Adreslerim' },
              ].map((item) => (
                <Link
                  key={item.href}
                  href={item.href}
                  role="menuitem"
                  onClick={() => setOpen(false)}
                  className="block rounded-xl px-3 py-2 text-sm font-bold text-ink-600 transition hover:bg-ink-50 hover:text-brand-600 dark:text-ink-300 dark:hover:bg-ink-800"
                >
                  {item.label}
                </Link>
              ))}

              <div className="my-1 h-px bg-ink-100 dark:bg-ink-800" />
              <button
                type="button"
                onClick={() => {
                  setOpen(false);
                  void signOut();
                }}
                role="menuitem"
                className="block w-full rounded-xl px-3 py-2 text-left text-sm font-bold text-ink-500 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40"
              >
                Çıkış yap
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function CartIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-[22px] w-[22px]" fill="none" stroke="currentColor" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round">
      <circle cx="9" cy="20" r="1.4" />
      <circle cx="18" cy="20" r="1.4" />
      <path d="M2 3h2.5l2 12.5A1.5 1.5 0 0 0 8 17h9a1.5 1.5 0 0 0 1.5-1.2L20.5 7H6" />
    </svg>
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
