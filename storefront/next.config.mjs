/**
 * The storefront runs on the SAME ORIGIN as the API (ADR-058).
 *
 * That is the whole reason there is no `NEXT_PUBLIC_API_URL` anywhere in this
 * app: nginx serves `/api` from PHP-FPM and everything else from this process,
 * so the client calls `/api/v1/...` relatively and the Sanctum session cookie
 * rides along with no CORS, no preflight and no cookie-domain juggling.
 *
 * In development that origin does not exist, so `rewrites()` proxies `/api` to
 * the Laravel dev server — the same relative paths work in both places, which is
 * what keeps development from diverging from production.
 *
 * @type {import('next').NextConfig}
 */
const nextConfig = {
  reactStrictMode: true,

  async rewrites() {
    const api = process.env.API_PROXY_TARGET;

    // Production: nginx owns /api and this app must not shadow it.
    if (!api) return [];

    return [
      { source: '/api/:path*', destination: `${api}/api/:path*` },
      // Sanctum's CSRF cookie route lives outside /api and is needed before any
      // authenticated POST.
      { source: '/sanctum/:path*', destination: `${api}/sanctum/:path*` },
    ];
  },

  images: {
    // Product imagery is served by the API's public disk on the same origin, so
    // the default loader needs no remote patterns. A CDN later is one entry here.
    formats: ['image/avif', 'image/webp'],
  },
};

export default nextConfig;
