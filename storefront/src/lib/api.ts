/**
 * The typed client for the platform API (ADR-058).
 *
 * SAME ORIGIN, RELATIVE PATHS, NO BASE URL. nginx serves `/api` from PHP-FPM and
 * everything else from this app, so there is nothing to configure per environment
 * and no CORS. `next.config.mjs` proxies the same paths in development, which is
 * what keeps the two from diverging.
 *
 * EVERY RESPONSE IS THE ADR-009 ENVELOPE — `{ success, data, meta? }` — and this
 * module is the only place that knows it. Callers get the payload; a change to
 * the envelope is one file.
 *
 * MONEY ARRIVES AS DECIMAL STRINGS AND IS NEVER PARSED (005 §28). There is no
 * `Number(price)` anywhere in this app, deliberately: parsing a price to a float
 * to format it reintroduces exactly what integer storage on the server exists to
 * prevent. Strings in, strings rendered.
 *
 * SERVER-SIDE BY DEFAULT, so listing and product pages are indexable (§2.1).
 */

/** The success envelope every endpoint returns (ADR-009). */
type Envelope<T> = {
  success: boolean;
  data: T;
  meta?: Record<string, unknown>;
  message?: string | null;
};

export type Paginated<T> = {
  items: T[];
  page: number;
  perPage: number;
  total: number;
  lastPage: number;
};

export type ProductCard = {
  id: string;
  slug: string;
  title: string;
  image: string | null;
  category: { id: string; name: string; slug?: string };
  brand: { id: string; name: string; slug?: string } | null;
};

/** A category or brand as it appears in a breadcrumb — id, name, and its slug URL. */
export type TaxonomyNode = { id: string; name: string; slug: string };

export type ProductDetail = {
  id: string;
  slug: string;
  title: string;
  description: string | null;
  images: string[];
  category: { id: string; name: string; slug: string; path: TaxonomyNode[] };
  brand: TaxonomyNode | null;
  gtin: string | null;
  attributes: { name: string; value: string }[];
  variants: { id: string; label: string; is_default: boolean }[];
};

/** What a flat slug points at (ADR-059) — the catch-all route resolves this first. */
export type SlugMatch = {
  type: 'product' | 'category' | 'brand';
  id: string;
  slug: string;
  /** Differs from the requested slug only for a retired alias → 301 to it. */
  canonical_slug: string;
};

/** A node of the category tree (`/categories`). */
export type CategoryNode = {
  id: string;
  name: string;
  slug: string;
  parent_id: string | null;
  product_count: number;
  children: CategoryNode[];
};

/** One category's landing payload (`/categories/{slug}`). */
export type CategoryDetail = {
  id: string;
  name: string;
  slug: string;
  /** Root → self, for the breadcrumb. */
  path: TaxonomyNode[];
  children: (TaxonomyNode & { product_count: number })[];
};

export type Brand = {
  id: string;
  name: string;
  slug: string;
  logo?: string | null;
  product_count: number;
};

/** Keyed by product id — absent means "nobody sells it", never "free". */
export type BuyBoxPrices = Record<
  string,
  {
    from_price: string;
    /** the featured offer's list price, for the struck-through display; never parsed */
    list_price: string | null;
    currency: string;
    in_stock: boolean;
    /** number of merchants (not offers) selling it — see ADR-042/039 */
    seller_count: number;
  }
>;

export type OfferRow = {
  id: string;
  variant_id: string;
  price: string;
  list_price: string | null;
  currency: string;
  in_stock: boolean;
  store: { id: string; name: string | null; city: string | null } | null;
};

export type ProductOffers = {
  product: { id: string; title: string; brand: string | null; category: string } | null;
  featured: OfferRow | null;
  other_sellers: OfferRow[];
  offer_count: number;
};

export type ProductSort = 'newest' | 'price_asc' | 'price_desc';

export type BrowseParams = {
  q?: string;
  category?: string;
  brand?: string;
  sort?: ProductSort;
  page?: number;
  perPage?: number;
};

/**
 * How long a public read may be served from the Next cache.
 *
 * SHORT, NOT ZERO. These pages are anonymous and identical for everybody, so
 * caching them is most of what makes a listing fast — but a price or a sold-out
 * flag going stale is a promise the checkout will then break, and 60 seconds is
 * short enough that a shopper never sees a price the buy box will refuse.
 */
const PUBLIC_REVALIDATE_SECONDS = 60;

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/**
 * Absolute on the server, relative in the browser.
 *
 * `fetch` on the server has no notion of "this site", so a relative path throws
 * there. `INTERNAL_API_URL` lets the server talk to PHP-FPM directly over
 * localhost, skipping nginx, while the browser keeps using the same-origin
 * relative path that needs no configuration at all.
 */
function apiUrl(path: string): string {
  const base =
    typeof window === 'undefined' ? (process.env.INTERNAL_API_URL ?? 'http://127.0.0.1') : '';

  return `${base}${path}`;
}

/** A public GET, cached briefly. Returns null on 404 rather than throwing. */
async function publicJson<T>(path: string): Promise<T | null> {
  const response = await fetch(apiUrl(path), {
    headers: { Accept: 'application/json' },
    next: { revalidate: PUBLIC_REVALIDATE_SECONDS },
  });

  if (response.status === 404) return null;

  if (!response.ok) {
    throw new ApiError(`GET ${path} failed`, response.status);
  }

  const envelope = (await response.json()) as Envelope<T>;

  return envelope.data;
}

/** A public POST (the batch price read). Never cached — it takes a body. */
async function publicPost<T>(path: string, body: unknown): Promise<T> {
  const response = await fetch(apiUrl(path), {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    cache: 'no-store',
  });

  if (!response.ok) {
    throw new ApiError(`POST ${path} failed`, response.status);
  }

  const envelope = (await response.json()) as Envelope<T>;

  return envelope.data;
}

/*
|------------------------------------------------------------------------------
| The composed buyer read (Storefront.md §1.1/§1.2)
|------------------------------------------------------------------------------
|
| Catalog answers what a product IS; Offer answers what it costs. Two calls, and
| the storefront is where they meet — which is exactly the composition ADR-058
| chose over putting a price column in the catalogue.
*/

export async function browseProducts(params: BrowseParams = {}): Promise<Paginated<ProductCard>> {
  const query = new URLSearchParams();

  if (params.q) query.set('q', params.q);
  if (params.category) query.set('category', params.category);
  if (params.brand) query.set('brand', params.brand);
  if (params.sort) query.set('sort', params.sort);
  if (params.page && params.page > 1) query.set('page', String(params.page));
  if (params.perPage) query.set('per_page', String(params.perPage));

  const suffix = query.toString() === '' ? '' : `?${query.toString()}`;

  const response = await fetch(apiUrl(`/api/v1/products${suffix}`), {
    headers: { Accept: 'application/json' },
    next: { revalidate: PUBLIC_REVALIDATE_SECONDS },
  });

  if (!response.ok) {
    throw new ApiError('Ürünler yüklenemedi', response.status);
  }

  const envelope = (await response.json()) as Envelope<ProductCard[]>;
  const meta = envelope.meta ?? {};

  return {
    items: envelope.data,
    page: Number(meta.current_page ?? 1),
    perPage: Number(meta.per_page ?? envelope.data.length),
    total: Number(meta.total ?? envelope.data.length),
    lastPage: Number(meta.last_page ?? 1),
  };
}

/** By slug OR uuid — the API resolves either (ADR-059). */
export function getProduct(idOrSlug: string): Promise<ProductDetail | null> {
  return publicJson<ProductDetail>(`/api/v1/products/${encodeURIComponent(idOrSlug)}`);
}

export function getProductOffers(idOrSlug: string): Promise<ProductOffers | null> {
  return publicJson<ProductOffers>(`/api/v1/products/${encodeURIComponent(idOrSlug)}/offers`);
}

/*
|------------------------------------------------------------------------------
| Flat slug URLs (ADR-059)
|------------------------------------------------------------------------------
|
| One namespace for product, category and brand, so the storefront resolves a
| slug to a type before it can render — and the resolver is that one hop. The
| category tree and brand list feed the nav menu and the two landing surfaces.
*/

export function resolveSlug(slug: string): Promise<SlugMatch | null> {
  return publicJson<SlugMatch>(`/api/v1/resolve/${encodeURIComponent(slug)}`);
}

export async function fetchCategoryTree(): Promise<CategoryNode[]> {
  return (await publicJson<CategoryNode[]>('/api/v1/categories')) ?? [];
}

export function getCategory(slug: string): Promise<CategoryDetail | null> {
  return publicJson<CategoryDetail>(`/api/v1/categories/${encodeURIComponent(slug)}`);
}

export async function fetchBrands(): Promise<Brand[]> {
  return (await publicJson<Brand[]>('/api/v1/brands')) ?? [];
}

export function getBrand(slug: string): Promise<Brand | null> {
  return publicJson<Brand>(`/api/v1/brands/${encodeURIComponent(slug)}`);
}

/**
 * Prices for a whole listing in one call.
 *
 * An empty input short-circuits rather than posting an empty list, which the API
 * would (correctly) refuse — a page with no cards needs no prices.
 */
export async function getBuyBoxPrices(productIds: string[]): Promise<BuyBoxPrices> {
  if (productIds.length === 0) return {};

  return publicPost<BuyBoxPrices>('/api/v1/offers/prices', { product_ids: productIds });
}
