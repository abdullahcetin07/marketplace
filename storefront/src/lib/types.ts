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

export type OrderStatus =
  | 'pending'
  | 'awaiting_payment'
  | 'paid'
  | 'delivered'
  | 'refunded'
  | 'cancelled';

/** A shipment for one order (Shipping, ADR-063/064). */
export type ShipmentStatus = 'pending' | 'shipped' | 'delivered' | 'returned';

export type ShipmentView = {
  status: ShipmentStatus;
  status_label: string;
  carrier: string | null;
  tracking_number: string | null;
  tracking_url: string | null;
  shipped_at: string | null;
  delivered_at: string | null;
  delivered_via: string | null;
  /** Server-computed — the buyer may confirm receipt (do not derive on the client). */
  can_confirm_receipt: boolean;
};

/** One order line as the return screen sees it (Shipping S4). */
export type ReturnLine = {
  id: string;
  title: string;
  quantity: number;
  returned_quantity: number;
  /** How many units are still returnable — the client never computes this. */
  returnable_quantity: number;
  refundable_amount_minor: number;
  /** Decimal string, never parsed. */
  refundable_amount: string;
};

/** A buyer's return request on a delivered order (Returns, ADR-073). */
export type ReturnRequestStatus = 'requested' | 'approved' | 'rejected' | 'completed';

export type ReturnRequestView = {
  id: string;
  order_id: string;
  status: ReturnRequestStatus;
  status_label: string;
  reason: string | null;
  lines: { id: string; quantity: number }[];
  /** The cargo return code the seller shares on approval (null until then). */
  return_code: string | null;
  cargo: { name: string } | null;
  /** The seller's reason on a rejection. */
  decision_reason: string | null;
  decided_at: string | null;
  completed_at: string | null;
  created_at: string;
};

export type OrderReturn = {
  return_open: boolean;
  return_window_ends_at: string | null;
  currency: string;
  lines: ReturnLine[];
};

/** A buyer's request to cancel a paid, unshipped order (ADR-065). */
export type CancellationStatus = 'pending' | 'approved' | 'rejected';

export type CancellationRequest = {
  id: string;
  order_id: string;
  status: CancellationStatus;
  status_label: string;
  reason: string | null;
  /** The seller's reason on a rejection. */
  decision_reason: string | null;
  decided_at: string | null;
  created_at: string;
};

/** A review's moderation state (Reviews, ADR-068). */
export type ReviewModerationStatus = 'pending_review' | 'published' | 'rejected';

/** A delivered order line the buyer can still review (Reviews, ADR-067). */
export type EligibleReviewLine = {
  /** The order line uuid — what a review is submitted against. */
  order_line_uuid: string;
  seller: { id: string; name: string | null };
  variant_label: string | null;
  product_title: string;
  purchased_at: string | null;
};

/** The buyer's own review, any status — for the account area. */
export type MyReview = {
  id: string;
  product_uuid: string;
  product_title: string;
  rating: number;
  body: string | null;
  status: ReviewModerationStatus;
  images: { thumb: string; preview: string; large: string }[];
  created_at: string;
};

/** A product question's state (Questions, ADR-070). */
export type QuestionStatus = 'pending' | 'answered';

/** The buyer's own question, any status — for the account area. */
export type MyQuestion = {
  id: string;
  product_uuid: string;
  product_title: string;
  body: string;
  status: QuestionStatus;
  /** The seller's answer once given; null while pending. */
  answer_body: string | null;
  seller: { id: string; name?: string | null } | null;
  created_at: string;
  answered_at: string | null;
};

export type Order = {
  id: string;
  /** The human handle a customer quotes in an email (Order §2.3). */
  number: string;
  /** What ties one purchase's N seller orders together (ADR-052). */
  checkout_group_id: string;
  status: OrderStatus;
  seller_id: string;
  store_id: string;
  /**
   * The seller's store, for a "Mağazayı ziyaret et" link (ADR-034/035).
   *
   * OPTIONAL, on purpose: the order carries a `store_id` uuid but the public
   * store page is slug-addressed, so a link needs the name + slug the order API
   * surfaces separately. Rendered as a link only when present — absent before the
   * backend adds it, and a plain row until then.
   */
  store?: { name: string; slug: string } | null;
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
  paid: 'Hazırlanıyor',
  delivered: 'Teslim edildi',
  refunded: 'İade edildi',
  cancelled: 'İptal edildi',
};

export type Country = { code: string; name: string };

/** A payment for one checkout group (ADR-060). */
export type PaymentStatus =
  | 'pending'
  | 'paid'
  | 'failed'
  | 'expired'
  | 'refunded'
  | 'partially_refunded';

export type PaymentView = {
  id: string;
  checkout_group_id: string;
  status: PaymentStatus;
  /** Decimal string — never parsed to a number (005 §28). */
  amount: string;
  currency: string;
  paid_at: string | null;
  created_at: string;
};
