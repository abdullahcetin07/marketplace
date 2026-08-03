/**
 * The shapes the session-carrying endpoints return.
 *
 * Kept apart from the public read types in `api.ts` for the same reason the two
 * clients are: one is anonymous and cacheable, the other belongs to one person.
 */

export type User = {
  id: string;
  first_name: string;
  last_name: string | null;
  email: string;
};

/** One line of the basket, priced LIVE by the API (Order §2.1). */
export type CartLine = {
  id: string;
  offer_id: string;
  product_id: string;
  variant_id: string;
  seller_id: string;
  store_id: string;
  title: string | null;
  quantity: number;
  /**
   * Null when the offer stopped being sellable while it sat in the basket.
   *
   * NOT ZERO, and the distinction matters on screen: zero reads as free, while
   * null is "we cannot price this any more". The cart never stored a price to
   * fall back on — that is the point of it storing none (ADR-053's mirror image).
   */
  unit_price: string | null;
  line_total: string | null;
  currency: string | null;
  available: boolean;
};

/** One seller's share of the basket — one order after checkout (ADR-052). */
export type CartSellerGroup = {
  seller_id: string;
  store_id: string;
  line_count: number;
  items_total: string;
};

export type CartView = {
  items: CartLine[];
  sellers: CartSellerGroup[];
  items_total: string;
  currency: string | null;
  has_unavailable_items: boolean;
  /** How many orders this basket becomes — the split, shown before checkout. */
  order_count: number;
};

/** An empty basket, for a visitor who has none yet. */
export const EMPTY_CART: CartView = {
  items: [],
  sellers: [],
  items_total: '0.00',
  currency: null,
  has_unavailable_items: false,
  order_count: 0,
};

/** One address in the customer's book (ADR-056). */
export type Address = {
  id: string;
  label: string;
  recipient_name: string;
  phone: string;
  line1: string;
  line2: string | null;
  /** Mahalle (ADR-056 amendment 2026-08-03) — optional, TR structured. */
  neighborhood: string | null;
  district: string | null;
  city: string;
  postal_code: string | null;
  /** ISO-2 — never an internal id (#7). */
  country: string;
  is_default_shipping: boolean;
  is_default_billing: boolean;
};

export type AddressInput = {
  label: string;
  recipientName: string;
  phone: string;
  line1: string;
  line2: string;
  neighborhood: string;
  district: string;
  city: string;
  postalCode: string;
  country: string;
};

/** One geo place from the Localization geo endpoint — il, ilçe or mahalle. */
export type GeoPlace = { id: string; name: string; code?: string };

/**
 * The frozen address an order carries (ADR-053/056).
 *
 * NOT an `Address`, and the difference is the point: this is a SNAPSHOT, so it
 * has no id to edit and its country is a name and a code rather than a reference.
 * A customer who moves house changes their book, not this.
 */
export type AddressSnapshot = {
  label: string | null;
  recipient_name: string | null;
  phone: string | null;
  line1: string | null;
  line2: string | null;
  neighborhood: string | null;
  district: string | null;
  city: string | null;
  postal_code: string | null;
  country_code: string | null;
  country_name: string | null;
};

export type OrderLine = {
  id: string;
  offer_id: string;
  product_id: string;
  variant_id: string;
  /** The title as it was BOUGHT, not as the catalogue reads today (ADR-053). */
  title: string;
  variant: string | null;
  quantity: number;
  unit_price: string;
  line_total: string;
  tax_rate: string;
  line_tax: string;
};

export type OrderStatus = 'pending' | 'awaiting_payment' | 'cancelled';

export type Order = {
  id: string;
  /** The human handle a customer quotes in an email (Order §2.3). */
  number: string;
  /** What ties one purchase's N seller orders together (ADR-052). */
  checkout_group_id: string;
  status: OrderStatus;
  seller_id: string;
  store_id: string;
  items_total: string;
  tax_total: string;
  grand_total: string;
  currency: string;
  shipping_address: AddressSnapshot;
  billing_address: AddressSnapshot;
  lines?: OrderLine[];
  placed_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  created_at: string;
};

export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  pending: 'Ödeme adımında',
  awaiting_payment: 'Ödeme bekliyor',
  cancelled: 'İptal edildi',
};

export type Country = { code: string; name: string };
