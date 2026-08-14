import type { Config } from 'tailwindcss';

/**
 * "Enerjik + Kurumsal" — the approved direction (Storefront.md §2.3).
 *
 * ENERGETIC WITHOUT BEING LOUD: one warm accent doing the work of drawing the eye
 * to prices and buy buttons, against a restrained neutral ground that lets real
 * product photography be the colour on the page. A marketplace's job is to make
 * somebody else's goods look good.
 *
 * THE ACCENT IS DELIBERATELY NOT THE PANEL COLOURS. Admin is rose and the seller
 * panel is amber, precisely so an operator can tell at a glance which context they
 * are in; the storefront is a third audience and gets its own.
 *
 * The mockups referenced in §2.3 are not in this repository, so this encodes the
 * direction in words rather than matching pixels — noted in the commit as an open
 * item for whoever holds them.
 */
const config: Config = {
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#fff5ed',
          100: '#ffe8d4',
          200: '#ffcda8',
          300: '#ffa971',
          400: '#ff7a38',
          500: '#fb5607',
          600: '#ec4403',
          700: '#c33106',
          800: '#9b280d',
          900: '#7d240e',
        },
        ink: {
          50: '#f6f7f9',
          100: '#eceef2',
          200: '#d5dae3',
          300: '#b0bacb',
          400: '#8494ae',
          500: '#647694',
          600: '#4f5f7a',
          700: '#414d63',
          800: '#394253',
          900: '#333a47',
          950: '#22262f',
        },
      },
      fontFamily: {
        // Manrope (the approved §2.3 face), self-hosted via next/font so there is
        // no render-blocking CDN request and Turkish latin-ext ships in the same
        // subset; the system stack stays as the fallback until the font paints.
        sans: ['var(--font-manrope)', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
      },
      maxWidth: {
        page: '87.5rem',
      },
    },
  },
  plugins: [],
};

export default config;
