'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import * as sessionApi from '@/lib/session-api';
import { EMPTY_CART, type CartView, type User } from '@/lib/types';

/**
 * Who is signed in, and what is in their basket — the two facts almost every
 * interactive part of this app needs.
 *
 * ONE PROVIDER FOR BOTH, deliberately, because they are the same fact seen twice:
 * this platform has no guest basket (ADR-056 — authenticated customers only), so
 * a cart exists exactly when a user does. Splitting them would mean two
 * providers that must never disagree.
 *
 * IT IS THE ONLY PLACE THAT HOLDS CART STATE. Every mutation returns the whole
 * basket from the API and replaces what is here, so the header badge, the cart
 * page and the add button cannot drift — and no total is ever computed in the
 * browser.
 *
 * IT RESOLVES ITSELF ON MOUNT, once. A server component cannot read the session
 * cookie for us (it is httpOnly and this app's server has no user context), so
 * the first thing the client does is ask `/auth/me`. Until that answers, `status`
 * is `loading` and the header renders neither "Giriş yap" nor an account link —
 * flashing the wrong one is worse than a moment of nothing.
 */

type SessionStatus = 'loading' | 'authenticated' | 'anonymous';

type SessionValue = {
  status: SessionStatus;
  user: User | null;
  cart: CartView;
  /** How many units are in the basket — what the header badge shows. */
  itemCount: number;
  signIn: (email: string, password: string, remember: boolean) => Promise<void>;
  signOut: () => Promise<void>;
  addItem: (offerId: string, quantity?: number) => Promise<void>;
  updateItem: (itemId: string, quantity: number) => Promise<void>;
  removeItem: (itemId: string) => Promise<void>;
  refreshCart: () => Promise<void>;
};

const SessionContext = createContext<SessionValue | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<SessionStatus>('loading');
  const [user, setUser] = useState<User | null>(null);
  const [cart, setCart] = useState<CartView>(EMPTY_CART);

  const loadCart = useCallback(async () => {
    const next = await sessionApi.fetchCart();
    setCart(next ?? EMPTY_CART);
  }, []);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      const me = await sessionApi.fetchMe();

      if (cancelled) return;

      setUser(me);
      setStatus(me === null ? 'anonymous' : 'authenticated');

      // No basket call for a visitor who is not signed in: the endpoint would
      // 401, and asking is a round trip that tells us nothing we do not know.
      if (me !== null) await loadCart();
    })();

    return () => {
      cancelled = true;
    };
  }, [loadCart]);

  const signIn = useCallback(
    async (email: string, password: string, remember: boolean) => {
      const signedIn = await sessionApi.login(email, password, remember);

      setUser(signedIn);
      setStatus('authenticated');

      // Their basket may predate this session — a customer who added things on
      // their phone last week expects to find them.
      await loadCart();
    },
    [loadCart],
  );

  const signOut = useCallback(async () => {
    await sessionApi.logout();

    setUser(null);
    setStatus('anonymous');
    // Cleared locally as well as server-side: leaving the last basket on screen
    // after sign-out would show one person's shopping to whoever uses the
    // browser next.
    setCart(EMPTY_CART);
  }, []);

  const addItem = useCallback(async (offerId: string, quantity = 1) => {
    setCart((await sessionApi.addCartItem(offerId, quantity)) ?? EMPTY_CART);
  }, []);

  const updateItem = useCallback(async (itemId: string, quantity: number) => {
    setCart((await sessionApi.updateCartItem(itemId, quantity)) ?? EMPTY_CART);
  }, []);

  const removeItem = useCallback(async (itemId: string) => {
    setCart((await sessionApi.removeCartItem(itemId)) ?? EMPTY_CART);
  }, []);

  const value = useMemo<SessionValue>(
    () => ({
      status,
      user,
      cart,
      itemCount: cart.items.reduce((total, line) => total + line.quantity, 0),
      signIn,
      signOut,
      addItem,
      updateItem,
      removeItem,
      refreshCart: loadCart,
    }),
    [status, user, cart, signIn, signOut, addItem, updateItem, removeItem, loadCart],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);

  if (value === null) {
    throw new Error('useSession must be used inside <SessionProvider>');
  }

  return value;
}
