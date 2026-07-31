/**
 * The BROWSER half of the API client — everything that carries a session.
 *
 * SEPARATE FROM `api.ts` ON PURPOSE. That module reads public data on the server
 * and is cached; this one runs only in the browser, sends cookies, and is never
 * cached. Mixing them would be one `credentials: 'include'` away from caching one
 * customer's basket and serving it to the next.
 *
 * SANCTUM SPA COOKIE AUTH (ADR-058), which is three facts:
 *
 *   1. `credentials: 'include'` on every call, so the session cookie rides along.
 *      Same origin, so this is not a CORS concession — it is just how fetch works.
 *   2. Any write needs the CSRF token. Laravel sets `XSRF-TOKEN` as a readable
 *      cookie and expects it echoed back in the `X-XSRF-TOKEN` header; the token
 *      is fetched once from `/sanctum/csrf-cookie` and then reused.
 *   3. `Accept: application/json`, so Laravel returns 401/422 as JSON rather than
 *      redirecting to a login page that does not exist in this app.
 *
 * WHY NOT A BEARER TOKEN. A token has to be stored somewhere the page's own
 * JavaScript can read, which makes any script injection an account takeover. An
 * httpOnly cookie cannot be read at all, and the same-origin deployment is what
 * makes it possible — the whole reason ADR-058 chose one origin.
 */

import type { CartView, User } from './types';

/** Laravel's envelope, again — see `api.ts`. */
type Envelope<T> = { success: boolean; data: T; message?: string | null };

export class SessionApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** Field-keyed validation messages, when the API returned 422. */
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'SessionApiError';
  }

  /** The first message for a field, for rendering under an input. */
  first(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

/**
 * Ask Laravel to set the CSRF cookie, once per page load.
 *
 * The promise is memoised rather than the token: two writes firing together
 * should share one round trip, and awaiting the same promise is how.
 */
let csrfRequest: Promise<void> | null = null;

async function ensureCsrfCookie(): Promise<void> {
  csrfRequest ??= fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  }).then(() => undefined);

  await csrfRequest;
}

function readCookie(name: string): string | undefined {
  return document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${name}=`))
    ?.split('=')[1];
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
};

/**
 * One session-carrying request.
 *
 * Returns `null` for 204 and for 401 — and the 401 case is a decision worth
 * naming: "you are not signed in" is an ordinary state for a storefront, not an
 * exception. Callers render a login prompt; they do not catch.
 */
async function request<T>(path: string, options: RequestOptions = {}): Promise<T | null> {
  const method = options.method ?? 'GET';

  if (method !== 'GET') await ensureCsrfCookie();

  const token = readCookie('XSRF-TOKEN');

  const response = await fetch(path, {
    method,
    credentials: 'include',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      // Laravel url-decodes this itself; the cookie arrives percent-encoded.
      ...(token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) }),
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  if (response.status === 204) return null;
  if (response.status === 401) return null;

  const payload = (await response.json().catch(() => null)) as
    | (Envelope<T> & { errors?: Record<string, string[]> })
    | null;

  if (!response.ok) {
    throw new SessionApiError(
      payload?.message ?? 'Bir şeyler ters gitti.',
      response.status,
      payload?.errors ?? {},
    );
  }

  return payload?.data ?? null;
}

/*
|------------------------------------------------------------------------------
| Auth
|------------------------------------------------------------------------------
*/

export function fetchMe(): Promise<User | null> {
  return request<User>('/api/v1/auth/me');
}

export async function login(email: string, password: string, remember: boolean): Promise<User> {
  const data = await request<{ user: User }>('/api/v1/auth/login', {
    method: 'POST',
    // `type: 'customer'` is required by the API and is not a choice this app
    // offers: a storefront signs in shoppers. A seller uses the seller panel,
    // and an admin cannot log in through this endpoint at all.
    body: { email, password, type: 'customer', remember },
  });

  if (data === null) {
    // A 401 from LOGIN is a wrong password, not "not signed in" — the one place
    // the null-on-401 convention would say the wrong thing.
    throw new SessionApiError('E-posta veya parola hatalı.', 401);
  }

  return data.user;
}

export type RegisterInput = {
  firstName: string;
  lastName: string;
  email: string;
  password: string;
  passwordConfirmation: string;
};

/**
 * Create a customer account.
 *
 * IT DOES NOT SIGN THEM IN, and the API is explicit about why: the address is
 * unverified until they click the emailed link. So this returns whether
 * verification is pending and the page says so, rather than pretending to a
 * session that does not exist.
 */
export async function register(input: RegisterInput): Promise<{ requiresVerification: boolean }> {
  const data = await request<{ requires_verification: boolean }>('/api/v1/auth/register', {
    method: 'POST',
    body: {
      first_name: input.firstName,
      last_name: input.lastName === '' ? null : input.lastName,
      email: input.email,
      password: input.password,
      password_confirmation: input.passwordConfirmation,
      type: 'customer',
      accepted_terms: true,
    },
  });

  return { requiresVerification: data?.requires_verification ?? true };
}

export async function logout(): Promise<void> {
  await request<null>('/api/v1/auth/logout', { method: 'POST' });
}

/*
|------------------------------------------------------------------------------
| Cart
|------------------------------------------------------------------------------
|
| EVERY CALL RETURNS THE WHOLE BASKET, because the API does: totals, the seller
| grouping and the availability flags all move when any line does, so a fragment
| would force the client to re-fetch or recompute — and recomputing a total in the
| browser is how two screens end up disagreeing about what something costs.
*/

export function fetchCart(): Promise<CartView | null> {
  return request<CartView>('/api/v1/cart');
}

export function addCartItem(offerId: string, quantity = 1): Promise<CartView | null> {
  return request<CartView>('/api/v1/cart/items', {
    method: 'POST',
    body: { offer_id: offerId, quantity },
  });
}

export function updateCartItem(itemId: string, quantity: number): Promise<CartView | null> {
  return request<CartView>(`/api/v1/cart/items/${encodeURIComponent(itemId)}`, {
    method: 'PATCH',
    body: { quantity },
  });
}

export function removeCartItem(itemId: string): Promise<CartView | null> {
  return request<CartView>(`/api/v1/cart/items/${encodeURIComponent(itemId)}`, {
    method: 'DELETE',
  });
}
