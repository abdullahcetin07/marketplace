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

import type {
  Address,
  AddressInput,
  CartView,
  Country,
  GeoPlace,
  Order,
  OrderReturn,
  PaymentView,
  ShipmentView,
  User,
} from './types';

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

/*
|------------------------------------------------------------------------------
| The address book (ADR-056)
|------------------------------------------------------------------------------
|
| EVERY CALL IS SCOPED BY THE SESSION, never by an id the client supplies. The API
| looks an address up by uuid AND owner, so "not yours" and "does not exist" are
| the same 404 — this client never has to think about it, which is the point of
| the server making them indistinguishable.
*/

export async function fetchAddresses(): Promise<Address[]> {
  return (await request<Address[]>('/api/v1/addresses')) ?? [];
}

function addressBody(input: AddressInput): Record<string, unknown> {
  return {
    label: input.label,
    recipient_name: input.recipientName,
    phone: input.phone,
    line1: input.line1,
    // Empty strings become null: an optional field left blank is ABSENT, not a
    // blank line printed on a parcel label.
    line2: input.line2 === '' ? null : input.line2,
    neighborhood: input.neighborhood === '' ? null : input.neighborhood,
    district: input.district === '' ? null : input.district,
    city: input.city,
    postal_code: input.postalCode === '' ? null : input.postalCode,
    country: input.country,
  };
}

export function createAddress(input: AddressInput): Promise<Address | null> {
  return request<Address>('/api/v1/addresses', { method: 'POST', body: addressBody(input) });
}

export function updateAddress(id: string, input: AddressInput): Promise<Address | null> {
  return request<Address>(`/api/v1/addresses/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: addressBody(input),
  });
}

export function deleteAddress(id: string): Promise<null> {
  return request<null>(`/api/v1/addresses/${encodeURIComponent(id)}`, { method: 'DELETE' });
}

export function setDefaultAddress(
  id: string,
  { shipping, billing }: { shipping: boolean; billing: boolean },
): Promise<Address | null> {
  return request<Address>(`/api/v1/addresses/${encodeURIComponent(id)}/default`, {
    method: 'POST',
    body: { shipping, billing },
  });
}

/** The country list for the address form — public, but fetched here for one client. */
export async function fetchCountries(): Promise<Country[]> {
  const response = await fetch('/api/v1/localization/countries', {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) return [];

  const envelope = (await response.json()) as Envelope<{ code: string; name: string }[]>;

  return envelope.data.map((country) => ({ code: country.code, name: country.name }));
}

/*
|------------------------------------------------------------------------------
| Geo cascade for TR addresses (ADR-056 amendment 2026-08-03)
|------------------------------------------------------------------------------
|
| PUBLIC, CACHED REFERENCE DATA served from Localization — il → ilçe → mahalle.
| The single source of truth: the form used to bundle its own il/ilçe list, which
| drifted from the registry by two names; reading the same tables the address is
| validated against is how the pick and the parcel can never disagree. Names in
| (what the client holds), names out (what it stores as city/district/neighborhood).
*/

async function fetchGeo(path: string): Promise<GeoPlace[]> {
  const response = await fetch(path, { headers: { Accept: 'application/json' } });

  if (!response.ok) return [];

  const envelope = (await response.json().catch(() => null)) as Envelope<GeoPlace[]> | null;

  return envelope?.data ?? [];
}

export function fetchProvinces(): Promise<GeoPlace[]> {
  return fetchGeo('/api/v1/geo/provinces');
}

export function fetchDistricts(province: string): Promise<GeoPlace[]> {
  return fetchGeo(`/api/v1/geo/districts?province=${encodeURIComponent(province)}`);
}

export function fetchNeighborhoods(province: string, district: string): Promise<GeoPlace[]> {
  return fetchGeo(
    `/api/v1/geo/neighborhoods?province=${encodeURIComponent(province)}&district=${encodeURIComponent(district)}`,
  );
}

/*
|------------------------------------------------------------------------------
| Checkout and orders (ADR-052/054/057)
|------------------------------------------------------------------------------
|
| TWO CALLS, NOT ONE, and the shape is the reservation window: `checkout` splits
| the basket by seller and HOLDS the stock; `place` confirms it. Payment will one
| day sit between them without either endpoint changing, which is the whole reason
| the two-step exists before there is a payment step to put in it.
*/

export type CheckoutResult = { orders: Order[]; checkoutGroupId: string };

export async function checkout(
  shippingAddressId: string,
  billingAddressId: string,
): Promise<CheckoutResult> {
  await ensureCsrfCookie();

  const token = readCookie('XSRF-TOKEN');

  const response = await fetch('/api/v1/checkout', {
    method: 'POST',
    credentials: 'include',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) }),
    },
    body: JSON.stringify({
      shipping_address_id: shippingAddressId,
      // Sent explicitly even when it is the same address: inferring "billing =
      // shipping when omitted" would silently put a home address on a company's
      // invoice, and nobody notices until an accountant does.
      billing_address_id: billingAddressId,
    }),
  });

  const payload = (await response.json().catch(() => null)) as
    | (Envelope<Order[]> & { errors?: Record<string, string[]>; meta?: { checkout_group_id?: string } })
    | null;

  if (!response.ok) {
    throw new SessionApiError(
      payload?.message ?? 'Siparişiniz oluşturulamadı.',
      response.status,
      payload?.errors ?? {},
    );
  }

  const orders = payload?.data ?? [];

  return {
    orders,
    // The group id rides in the envelope's meta; the first order carries it too,
    // and either is the handle the very next call needs.
    checkoutGroupId: payload?.meta?.checkout_group_id ?? orders[0]?.checkout_group_id ?? '',
  };
}

export async function placeCheckoutGroup(groupId: string): Promise<Order[]> {
  return (
    (await request<Order[]>(`/api/v1/checkout/${encodeURIComponent(groupId)}/place`, {
      method: 'POST',
    })) ?? []
  );
}

export async function fetchOrders(): Promise<Order[]> {
  return (await request<Order[]>('/api/v1/orders')) ?? [];
}

/*
|------------------------------------------------------------------------------
| Payment (ADR-060)
|------------------------------------------------------------------------------
|
| ONE PAYMENT PER CHECKOUT GROUP. `initiate` returns the PayTR iframe token the
| storefront embeds; the card and 3DS live inside that iframe and never reach this
| app. The server-to-server callback — not this client, not the redirect — is what
| actually confirms the payment, so the result page READS the status back rather
| than trusting where PayTR sent the browser.
*/

export async function initiatePayment(
  checkoutGroupId: string,
): Promise<{ paymentId: string; iframeToken: string } | null> {
  const data = await request<{ payment_uuid?: string; id?: string; iframe_token: string }>(
    `/api/v1/checkout/${encodeURIComponent(checkoutGroupId)}/pay`,
    { method: 'POST' },
  );

  if (data === null || !data.iframe_token) return null;

  return { paymentId: data.payment_uuid ?? data.id ?? '', iframeToken: data.iframe_token };
}

export function fetchPayment(uuid: string): Promise<PaymentView | null> {
  return request<PaymentView>(`/api/v1/payments/${encodeURIComponent(uuid)}`);
}

export function fetchOrder(id: string): Promise<Order | null> {
  return request<Order>(`/api/v1/orders/${encodeURIComponent(id)}`);
}

export function cancelOrder(id: string, reason: string): Promise<Order | null> {
  return request<Order>(`/api/v1/orders/${encodeURIComponent(id)}/cancel`, {
    method: 'POST',
    body: { reason: reason === '' ? null : reason },
  });
}

/*
|------------------------------------------------------------------------------
| Shipment (Shipping, ADR-063/064)
|------------------------------------------------------------------------------
|
| ONE SHIPMENT PER ORDER. `can_confirm_receipt` is the server's call, not ours — a
| button the client conjured for a shipment the API would refuse to confirm is a
| button that lies. `confirm` is the buyer saying the box arrived, which also starts
| their own return clock early (ADR-064).
*/

export function fetchOrderShipment(orderId: string): Promise<ShipmentView | null> {
  return request<ShipmentView>(`/api/v1/orders/${encodeURIComponent(orderId)}/shipment`);
}

export function confirmReceipt(orderId: string): Promise<ShipmentView | null> {
  return request<ShipmentView>(`/api/v1/orders/${encodeURIComponent(orderId)}/shipment/confirm`, {
    method: 'POST',
  });
}

/*
|------------------------------------------------------------------------------
| Returns (Payment S4 / Shipping)
|------------------------------------------------------------------------------
|
| THE RETURNABLE QUANTITY IS THE SERVER'S, not ours: it is the line's original
| quantity minus everything already refunded, and a client multiplying price by
| quantity comes out wrong on exactly the orders that were partly returned before.
| The buyer picks lines + quantities; the server prices the refund.
*/

export function fetchOrderReturn(orderId: string): Promise<OrderReturn | null> {
  return request<OrderReturn>(`/api/v1/orders/${encodeURIComponent(orderId)}/return`);
}

export function requestReturn(
  orderId: string,
  lines: { id: string; quantity: number }[],
  reason?: string,
): Promise<Record<string, unknown> | null> {
  return request<Record<string, unknown>>(`/api/v1/orders/${encodeURIComponent(orderId)}/return`, {
    method: 'POST',
    body: { lines, reason: reason === undefined || reason === '' ? null : reason },
  });
}
