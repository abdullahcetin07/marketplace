'use client';

import Link from 'next/link';
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
 */
export function HeaderActions() {
  const { status, user, itemCount, signOut } = useSession();

  return (
    <div className="flex items-center gap-5 text-sm">
      <Link href="/sepet" className="relative hover:text-brand-600">
        Sepet
        {itemCount > 0 && (
          <span className="absolute -right-4 -top-2 rounded-full bg-brand-500 px-1.5 py-0.5 text-xs font-semibold text-white">
            {itemCount}
          </span>
        )}
      </Link>

      {status === 'loading' && <span className="w-16" aria-hidden />}

      {status === 'anonymous' && (
        <Link href="/giris" className="hover:text-brand-600">
          Giriş yap
        </Link>
      )}

      {status === 'authenticated' && user !== null && (
        <div className="flex items-center gap-3">
          <Link href="/hesap/siparislerim" className="hover:text-brand-600">
            Siparişlerim
          </Link>
          <span className="text-ink-500">{user.first_name}</span>
          <button
            type="button"
            onClick={() => void signOut()}
            className="text-ink-500 underline-offset-2 hover:text-brand-600 hover:underline"
          >
            Çıkış
          </button>
        </div>
      )}
    </div>
  );
}
