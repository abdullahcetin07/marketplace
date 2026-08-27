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
  /** For the sitemap's lastmod (the product's own content date, not price/stock). */
  updated_at?: string;
};

/** A category or brand as it appears in a breadcrumb — id, name, and its slug URL. */
export type TaxonomyNode = { id: string; name: string; slug: string };

export type ProductDetail = {
  id: string;
  slug: string;
  title: string;
  description: string | null;
  images: string[];
  /** Higher-res versions of `images` (same order) for the lightbox; optional. */
  images_large?: string[];
  /** Smaller versions of `images` (same order) for the thumbnail strip; optional. */
  images_thumb?: string[];
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
  updated_at?: string;
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
  updated_at?: string;
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
  /**
   * `slug` is OPTIONAL: the offer references its store by uuid, and the public
   * store page is slug-addressed — the buy box renders the name as a link to
   * `/magaza/{slug}` only when the offer API carries it, plain text before then.
   */
  store: { id: string; name: string | null; city: string | null; slug?: string | null } | null;
};

export type ProductOffers = {
  product: { id: string; title: string; brand: string | null; category: string } | null;
  featured: OfferRow | null;
  other_sellers: OfferRow[];
  offer_count: number;
};

/**
 * One listing on a store's own page (Offer's storefront contributor, ADR-046).
 *
 * NOT a `ProductCard`: it comes from the store's `extensions.products`, so it
 * carries the price already (the contributor priced it) but no image and no
 * product slug — the card links by the product uuid, which `/urun/{id}` 301s to
 * the canonical slug (ADR-059).
 */
export type StoreProduct = {
  /** The offer uuid. */
  id: string;
  product_id: string;
  variant_id: string;
  title: string;
  brand: string | null;
  /** The product's primary image; null → the card shows a placeholder. */
  image?: string | null;
  /** The canonical slug; when present the card links straight to `/{slug}` instead
   *  of the `/urun/{uuid}` 301 hop. */
  slug?: string | null;
  price: string;
  list_price: string | null;
  currency: string;
  in_stock: boolean;
};

/**
 * A store's public storefront (ADR-034/036) — `GET /store/{slug}`.
 *
 * A STRICT ALLOW-LIST from the server: identity, branding, SEO and public
 * contact, plus `extensions` where other modules' sections land under their own
 * keys (Offer's `products` today). `contact.address` / `support_hours` are jsonb
 * the seller UI does not yet write, so they are usually null — rendered
 * defensively, never assumed a string.
 */
export type StoreDetail = {
  id: string;
  slug: string;
  name: string;
  locale: { language: string | null; currency: string | null; timezone: string | null };
  branding: {
    logo: string | null;
    banner: string | null;
    favicon: string | null;
    primary_color: string | null;
    accent_color: string | null;
    theme: string | null;
  };
  seo: {
    meta_title: string | null;
    meta_description: string | null;
    meta_keywords: string[];
    canonical_url: string | null;
    robots: string | null;
  };
  contact: {
    email: string | null;
    phone: string | null;
    address: Record<string, unknown> | null;
    support_hours: Record<string, unknown> | null;
  };
  extensions: {
    products?: { items: StoreProduct[]; total: number };
    /** Seller rating rollup (ADR-069): average of published reviews, computed on read.
     *  `value` is null (not 0) when there are no reviews — the card then shows no stars. */
    rating?: { value: string | null; count: number };
  };
};

/**
 * One published review on a product page (Reviews, ADR-066/069).
 *
 * NO buyer id and NO order-line id — those are private. `author_name` is already
 * masked server-side ("Abdullah Ç."). `seller` is the store the reviewer bought
 * from (the authoritative tag), which the seller filter reads.
 */
export type ProductReview = {
  id: string;
  rating: number;
  body: string | null;
  author_name: string;
  seller: { id: string; name: string | null };
  variant_label: string | null;
  images: { thumb: string; preview: string; large: string }[];
  created_at: string;
};

/** The rating rollup for a product — computed on read, never stored (ADR-069). */
export type ReviewSummary = {
  /** Decimal string ("4.3"); never parsed to a number (005 §28). */
  average: string;
  count: number;
  distribution: Record<'5' | '4' | '3' | '2' | '1', number>;
  with_images_count: number;
  /** Sellers who have reviews, for the "bu satıcıdan alanlar" filter. */
  sellers: { id: string; name: string | null; count: number }[];
};

export type ProductReviewsPage = {
  reviews: ProductReview[];
  summary: ReviewSummary;
  page: number;
  lastPage: number;
  total: number;
};

export type ReviewFilters = { seller?: string; withImages?: boolean; rating?: number; page?: number };

/** Batch card badges: product uuid → its average + count. Absent = unreviewed. */
export type ProductRatings = Record<string, { average: string; count: number }>;

const EMPTY_SUMMARY: ReviewSummary = {
  average: '0.0',
  count: 0,
  distribution: { '5': 0, '4': 0, '3': 0, '2': 0, '1': 0 },
  with_images_count: 0,
  sellers: [],
};

/**
 * One published Q&A on a product page (Questions, ADR-070/071).
 *
 * Only ANSWERED, non-hidden questions reach this — an unanswered one is private to
 * the seller. `asker_name` is masked server-side; `seller` is the merchant the
 * question was directed at (the buy-box winner at ask time).
 */
export type PublicQuestion = {
  id: string;
  asker_name: string;
  body: string;
  answer_body: string | null;
  seller: { id: string; name: string | null };
  asked_at: string;
  answered_at: string | null;
};

export type ProductQuestionsPage = {
  questions: PublicQuestion[];
  page: number;
  lastPage: number;
  total: number;
};

export type QuestionFilters = { seller?: string; page?: number };

export type ProductSort = 'newest' | 'price_asc' | 'price_desc';

export type BrowseParams = {
  q?: string;
  category?: string;
  brand?: string;
  sort?: ProductSort;
  page?: number;
  perPage?: number;
  /** Price bounds as decimal strings (TL); never parsed to a float here. */
  priceMin?: string;
  priceMax?: string;
};

/** The filter facets a listing offers, from the browse `meta.facets` (ADR-080). */
export type ListingFacets = {
  brands: { slug: string; name: string; count: number }[];
  /** Price span of the current query, decimal strings; null until the backend sends it. */
  price: { min: string; max: string } | null;
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

/**
 * The points a buyer earns on a given TL amount (Loyalty, ADR-082) — a PUBLIC read so
 * the product page can show it to signed-out shoppers too. The backend computes the
 * integer from the amount + the operator earn rate; the storefront never multiplies a
 * price string itself. Degrades to null until the endpoint ships (or if loyalty is off),
 * so the campaigns box simply doesn't render.
 */
export async function getEarnPreview(
  amount: string,
): Promise<{ enabled: boolean; points: number } | null> {
  try {
    return await publicJson<{ enabled: boolean; points: number }>(
      `/api/v1/loyalty/earn-preview?amount=${encodeURIComponent(amount)}`,
    );
  } catch {
    return null;
  }
}

/**
 * These public batch reads are POST (they carry a list of ids), and they run BOTH
 * server-side (the listings' `ProductGrid`) and client-side (the homepage carousels).
 *
 * The client-side case needs CSRF. Sanctum decides a request is "stateful" from its
 * Origin/Referer, NOT its cookie — so a same-origin browser POST to `/api/v1/*` gets
 * the `web` group and CSRF protection, and answers **419** without a token (this is
 * why omitting credentials did nothing: the Origin still matched). SSR has no Origin,
 * so it stays stateless and needs none. So: in the browser, prime the XSRF-TOKEN
 * cookie and echo it back in the header, exactly as the session API does.
 */
let csrfPrimed: Promise<unknown> | undefined;
function ensurePublicCsrf(): Promise<unknown> {
  csrfPrimed ??= fetch('/sanctum/csrf-cookie', { credentials: 'include' }).catch(() => {});
  return csrfPrimed;
}
function xsrfCookie(): string | undefined {
  return document.cookie
    .split('; ')
    .find((row) => row.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];
}

async function publicPost<T>(path: string, body: unknown): Promise<T> {
  const inBrowser = typeof window !== 'undefined';
  const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
  let credentials: RequestCredentials = 'omit';

  if (inBrowser) {
    await ensurePublicCsrf();
    const token = xsrfCookie();
    // Laravel url-decodes this itself; the cookie arrives percent-encoded.
    if (token !== undefined) headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
    credentials = 'include';
  }

  const response = await fetch(apiUrl(path), {
    method: 'POST',
    headers,
    body: JSON.stringify(body),
    cache: 'no-store',
    credentials,
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

export async function browseProducts(
  params: BrowseParams = {},
): Promise<Paginated<ProductCard> & { facets: ListingFacets }> {
  const query = new URLSearchParams();

  if (params.q) query.set('q', params.q);
  if (params.category) query.set('category', params.category);
  if (params.brand) query.set('brand', params.brand);
  if (params.sort) query.set('sort', params.sort);
  if (params.priceMin) query.set('price_min', params.priceMin);
  if (params.priceMax) query.set('price_max', params.priceMax);
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

  const envelope = (await response.json()) as Envelope<ProductCard[]> & {
    meta?: { facets?: Partial<ListingFacets> } & Record<string, unknown>;
  };
  const meta = envelope.meta ?? {};

  // Facets ride in meta; absent until the backend ships ADR-080, so the filter UI
  // degrades to an empty brand list + open price bounds rather than breaking.
  const facets: ListingFacets = {
    brands: Array.isArray(meta.facets?.brands) ? meta.facets.brands : [],
    price: meta.facets?.price ?? null,
  };

  return {
    items: envelope.data,
    page: Number(meta.current_page ?? 1),
    perPage: Number(meta.per_page ?? envelope.data.length),
    total: Number(meta.total ?? envelope.data.length),
    lastPage: Number(meta.last_page ?? 1),
    facets,
  };
}

/** By slug OR uuid — the API resolves either (ADR-059). */
export function getProduct(idOrSlug: string): Promise<ProductDetail | null> {
  return publicJson<ProductDetail>(`/api/v1/products/${encodeURIComponent(idOrSlug)}`);
}

export function getProductOffers(idOrSlug: string): Promise<ProductOffers | null> {
  return publicJson<ProductOffers>(`/api/v1/products/${encodeURIComponent(idOrSlug)}/offers`);
}

/**
 * "Bu ürünü alanlar bunları da aldı" — products co-purchased with this one (same
 * basket), ranked by frequency.
 *
 * DEGRADES TO `[]` when the endpoint is absent or there is no purchase history yet,
 * so the section stays hidden and lights up on its own once sales create
 * co-purchase data — no frontend change needed when the backend ships it.
 */
export async function getAlsoBought(idOrSlug: string): Promise<ProductCard[]> {
  try {
    return (await publicJson<ProductCard[]>(`/api/v1/products/${encodeURIComponent(idOrSlug)}/also-bought`)) ?? [];
  } catch {
    return [];
  }
}

/**
 * "Çok Satanlar" — products ranked by units sold across paid orders. Degrades to
 * `[]` until sales exist, so the homepage strip stays hidden and appears on its own
 * once the backend endpoint ships and orders accumulate.
 */
export async function getBestSellers(): Promise<ProductCard[]> {
  try {
    return (await publicJson<ProductCard[]>('/api/v1/products/best-sellers')) ?? [];
  } catch {
    return [];
  }
}

/**
 * "En Çok Değerlendirilenler" — products ranked by published review count. Same
 * degrade-to-empty contract: hidden until reviews exist.
 */
export async function getMostReviewed(): Promise<ProductCard[]> {
  try {
    return (await publicJson<ProductCard[]>('/api/v1/products/most-reviewed')) ?? [];
  } catch {
    return [];
  }
}

/**
 * Type-ahead suggestions for the header search (ADR-090 Tier 2 / Meilisearch).
 *
 * A CLIENT-SIDE read, called on every debounced keystroke, so it uses a plain
 * relative fetch (same-origin, no cache) rather than the SSR `publicJson`. It
 * DEGRADES TO EMPTY on any error or before the engine ships — an autocomplete that
 * cannot reach the endpoint simply shows no dropdown, and the form's real GET to
 * `/urunler` still works. `products` carry a slug (→ `/{slug}`); `brands` and
 * `categories` are names only, so the UI runs them as a `?q=` search.
 */
export type SearchSuggestions = {
  products: { uuid: string; title: string; slug: string }[];
  brands: string[];
  categories: string[];
};

export async function searchSuggest(q: string): Promise<SearchSuggestions> {
  const empty: SearchSuggestions = { products: [], brands: [], categories: [] };
  if (q.trim().length < 2) return empty;

  try {
    const response = await fetch(`/api/v1/search/suggest?q=${encodeURIComponent(q)}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) return empty;

    const envelope = (await response.json()) as Envelope<Partial<SearchSuggestions>>;
    const data = envelope.data ?? {};

    return {
      products: Array.isArray(data.products) ? data.products : [],
      brands: Array.isArray(data.brands) ? data.brands : [],
      categories: Array.isArray(data.categories) ? data.categories : [],
    };
  } catch {
    return empty;
  }
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
 * A seller's public storefront by slug (ADR-034/035) — the page behind a
 * "Mağazayı ziyaret et" link.
 *
 * `store` is the canonical API segment even though the user-facing route is the
 * localised `/magaza/{slug}`; the backend registers both, and building the read
 * on the canonical one keeps a single cache key. Returns null on 404 — a missing
 * or non-live store looks identical, so existence never leaks (Store.md §12).
 */
export function getStore(slug: string): Promise<StoreDetail | null> {
  return publicJson<StoreDetail>(`/api/v1/store/${encodeURIComponent(slug)}`);
}

/**
 * Every live store's slug + last-modified, for the sitemap. The endpoint is
 * `/magazalar` (the `/stores` path is the seller panel's own, behind auth); the
 * public surface is the localised `/magaza/{slug}`. Degrades to [] on error.
 */
export async function allStoreSlugs(): Promise<{ slug: string; updated_at: string | null }[]> {
  return (await publicJson<{ slug: string; updated_at: string | null }[]>('/api/v1/magazalar')) ?? [];
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

/*
|------------------------------------------------------------------------------
| Reviews (Reviews module, ADR-066/069)
|------------------------------------------------------------------------------
*/

/**
 * A product's published reviews + rating summary.
 *
 * IT DEGRADES RATHER THAN THROWS. A broken or empty reviews read must never take
 * down the product page it hangs off — the same resilience the storefront
 * contributor promises server-side (ADR-036) — so a non-OK response returns an
 * empty page, not an error. The summary rides in `meta` (the average is a decimal
 * string and stays one; no `Number()` on a rating either).
 */
export async function getProductReviews(
  idOrSlug: string,
  filters: ReviewFilters = {},
): Promise<ProductReviewsPage> {
  const query = new URLSearchParams();
  if (filters.seller) query.set('seller', filters.seller);
  if (filters.withImages) query.set('with_images', '1');
  if (filters.rating) query.set('rating', String(filters.rating));
  if (filters.page && filters.page > 1) query.set('page', String(filters.page));
  const suffix = query.toString() === '' ? '' : `?${query.toString()}`;

  const empty: ProductReviewsPage = { reviews: [], summary: EMPTY_SUMMARY, page: 1, lastPage: 1, total: 0 };

  try {
    const response = await fetch(apiUrl(`/api/v1/products/${encodeURIComponent(idOrSlug)}/reviews${suffix}`), {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) return empty;

    const envelope = (await response.json()) as Envelope<ProductReview[]> & {
      meta?: {
        current_page?: number;
        last_page?: number;
        total?: number;
        summary?: ReviewSummary;
      };
    };
    const meta = envelope.meta ?? {};

    return {
      reviews: envelope.data ?? [],
      summary: meta.summary ?? EMPTY_SUMMARY,
      page: Number(meta.current_page ?? 1),
      lastPage: Number(meta.last_page ?? 1),
      total: Number(meta.total ?? 0),
    };
  } catch {
    return empty;
  }
}

/**
 * Rating badges for a whole listing in one call (mirrors `getBuyBoxPrices`).
 *
 * Degrades to `{}` — a missing star badge must never break a grid. An unreviewed
 * product is simply absent from the map (never `"0.0"`, which reads as "rated
 * zero"). The API returns `[]` for an all-empty batch; that normalises to `{}`.
 */
export async function getProductRatings(productIds: string[]): Promise<ProductRatings> {
  if (productIds.length === 0) return {};

  try {
    const data = await publicPost<{ ratings: ProductRatings | unknown[] }>('/api/v1/products/ratings', {
      product_ids: productIds,
    });

    return Array.isArray(data.ratings) ? {} : data.ratings;
  } catch {
    return {};
  }
}

/*
|------------------------------------------------------------------------------
| Questions ("Satıcıya Sor", Questions module ADR-070/071)
|------------------------------------------------------------------------------
*/

/**
 * A product's public (answered) Q&A. No summary — there is no rating to roll up.
 *
 * DEGRADES to an empty page rather than throwing: a broken Q&A read must not take
 * down the product page it hangs off, the same resilience the reviews read has.
 */
export async function getProductQuestions(
  idOrSlug: string,
  filters: QuestionFilters = {},
): Promise<ProductQuestionsPage> {
  const query = new URLSearchParams();
  if (filters.seller) query.set('seller', filters.seller);
  if (filters.page && filters.page > 1) query.set('page', String(filters.page));
  const suffix = query.toString() === '' ? '' : `?${query.toString()}`;

  const empty: ProductQuestionsPage = { questions: [], page: 1, lastPage: 1, total: 0 };

  try {
    const response = await fetch(apiUrl(`/api/v1/products/${encodeURIComponent(idOrSlug)}/questions${suffix}`), {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) return empty;

    const envelope = (await response.json()) as Envelope<PublicQuestion[]> & {
      meta?: { current_page?: number; last_page?: number; total?: number };
    };
    const meta = envelope.meta ?? {};

    return {
      questions: envelope.data ?? [],
      page: Number(meta.current_page ?? 1),
      lastPage: Number(meta.last_page ?? 1),
      total: Number(meta.total ?? 0),
    };
  } catch {
    return empty;
  }
}
