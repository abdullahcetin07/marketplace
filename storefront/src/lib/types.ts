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
