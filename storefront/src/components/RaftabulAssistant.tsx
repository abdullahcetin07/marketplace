'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { whatsappLink } from '@/lib/site';

/**
 * Raftabul Asistan — a guided (menu-driven) help hub, Trendyol-style.
 *
 * PHASE 1 of the approved hybrid: no LLM. Every option routes into a feature that
 * already exists (search, orders, returns, points, FAQ), and ANYTHING off the menu
 * hands off to WhatsApp — the human fallback the owner asked for. Phase 2 (free-text
 * AI) can later slot in as another screen without touching this structure.
 *
 * A client component because it owns open/screen state; it renders a floating button
 * that opens a small dialog. It sits ABOVE the WhatsApp FAB and stays under the cookie
 * banner (z-60). Menu-driven by design means no generated text, so the health-claim
 * rule can't be tripped here.
 */

type IconName = 'search' | 'package' | 'undo' | 'gift' | 'help' | 'chat' | 'shield';

type Node =
  | { t: 'link'; label: string; href: string; icon: IconName; note?: string }
  | { t: 'screen'; label: string; to: ScreenKey; icon: IconName; note?: string }
  | { t: 'wa'; label: string; message?: string; icon: IconName; note?: string };

type ScreenKey = 'root' | 'urun' | 'sss';
type Screen = { title: string; intro?: string; back?: ScreenKey; search?: boolean; nodes: Node[] };

const SEARCH_CHIPS = [
  'Güneş kremi',
  'Nemlendirici',
  'Vitamin C serum',
  'Cilt temizleme',
  'Saç bakımı',
  'Bebek bakımı',
];

const SCREENS: Record<ScreenKey, Screen> = {
  root: {
    title: 'Raftabul Asistan',
    intro: 'Merhaba! Size nasıl yardımcı olabilirim?',
    nodes: [
      { t: 'screen', label: 'Ürün bul', to: 'urun', icon: 'search', note: 'Ne aradığınızı yazın' },
      { t: 'link', label: 'Siparişim nerede?', href: '/hesap/siparislerim', icon: 'package', note: 'Kargo takibi' },
      { t: 'link', label: 'İade / değişim', href: '/sayfa/iade-degisim', icon: 'undo', note: '14 gün koşulsuz iade' },
      { t: 'link', label: 'Kampanyalar & puanım', href: '/hesap/puanlarim', icon: 'gift', note: 'Aldıkça Kazan' },
      { t: 'screen', label: 'Sık sorulan sorular', to: 'sss', icon: 'help' },
      { t: 'wa', label: 'Canlı destek', message: 'Merhaba, canlı destek almak istiyorum.', icon: 'chat', note: 'WhatsApp ile' },
    ],
  },
  urun: {
    title: 'Ürün bul',
    intro: 'Ne aramak istersiniz?',
    back: 'root',
    search: true,
    nodes: SEARCH_CHIPS.map(
      (q): Node => ({ t: 'link', label: q, href: `/urunler?q=${encodeURIComponent(q)}`, icon: 'search' }),
    ),
  },
  sss: {
    title: 'Sık sorulan sorular',
    back: 'root',
    nodes: [
      { t: 'link', label: 'Kargo & teslimat', href: '/sayfa/kargo', icon: 'package' },
      { t: 'link', label: 'İade & değişim', href: '/sayfa/iade-degisim', icon: 'undo' },
      { t: 'link', label: 'Güvenli alışveriş', href: '/sayfa/guvenli-alisveris', icon: 'shield' },
      { t: 'link', label: 'Tüm sorular', href: '/sayfa/sss', icon: 'help' },
    ],
  },
};

/** Off-menu / no-number fallback: WhatsApp when configured, else the İletişim page. */
function waHref(message?: string): { href: string; external: boolean } {
  const link = whatsappLink(message);
  return link ? { href: link, external: true } : { href: '/sayfa/iletisim', external: false };
}

function Icon({ name }: { name: IconName }) {
  const common = {
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 2,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
    className: 'h-5 w-5 shrink-0',
  };
  switch (name) {
    case 'search':
      return (<svg {...common}><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>);
    case 'package':
      return (<svg {...common}><path d="m7.5 4.27 9 5.15" /><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" /><path d="m3.3 7 8.7 5 8.7-5" /><path d="M12 22V12" /></svg>);
    case 'undo':
      return (<svg {...common}><path d="M3 7v6h6" /><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13" /></svg>);
    case 'gift':
      return (<svg {...common}><rect x="3" y="8" width="18" height="4" rx="1" /><path d="M12 8v13" /><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7" /><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8" /><path d="M16.5 8a2.5 2.5 0 0 0 0-5C13 3 12 8 12 8" /></svg>);
    case 'help':
      return (<svg {...common}><circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" /><path d="M12 17h.01" /></svg>);
    case 'shield':
      return (<svg {...common}><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z" /></svg>);
    case 'chat':
      return (<svg {...common}><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z" /></svg>);
  }
}

/**
 * The visual row — PRESENTATIONAL only (a div). The interactive wrapper around it is
 * a Link, an anchor, or a button depending on the node, so we never nest a <button>
 * inside an <a> (invalid HTML). Hover lives on the row; the focus ring on the wrapper.
 */
function RowInner({ icon, label, note }: { icon: IconName; label: string; note?: string }) {
  return (
    <div className="flex w-full items-center gap-3 rounded-xl border border-ink-200 bg-white px-3.5 py-3 transition group-hover:border-brand-400 group-hover:bg-brand-50 dark:border-ink-700 dark:bg-ink-900 dark:group-hover:border-brand-500 dark:group-hover:bg-ink-800">
      <span className="text-brand-600 dark:text-brand-400"><Icon name={icon} /></span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-ink-800 dark:text-ink-100">{label}</span>
        {note ? <span className="block truncate text-xs text-ink-500">{note}</span> : null}
      </span>
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4 shrink-0 text-ink-300" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round"><path d="m9 18 6-6-6-6" /></svg>
    </div>
  );
}

/** Shared wrapper classes so link/button/anchor rows share focus + group-hover. */
const ROW_WRAP = 'group block rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-1';

export function RaftabulAssistant() {
  const [open, setOpen] = useState(false);
  const [screenKey, setScreenKey] = useState<ScreenKey>('root');
  const panelRef = useRef<HTMLDivElement>(null);

  const close = useCallback(() => setOpen(false), []);

  // Esc closes the panel — a dialog you can't dismiss with the keyboard is a trap.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') close();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, close]);

  // Reset to the root menu whenever it's reopened, so it never resumes mid-flow.
  useEffect(() => {
    if (open) setScreenKey('root');
  }, [open]);

  const screen = SCREENS[screenKey];

  function renderNode(node: Node, i: number) {
    if (node.t === 'link') {
      return (
        <Link key={i} href={node.href} onClick={close} className={ROW_WRAP}>
          <RowInner icon={node.icon} label={node.label} note={node.note} />
        </Link>
      );
    }
    if (node.t === 'screen') {
      return (
        <button key={i} type="button" onClick={() => setScreenKey(node.to)} className={`${ROW_WRAP} w-full text-left`}>
          <RowInner icon={node.icon} label={node.label} note={node.note} />
        </button>
      );
    }
    const { href, external } = waHref(node.message);
    return (
      <a
        key={i}
        href={href}
        onClick={close}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        className={ROW_WRAP}
      >
        <RowInner icon={node.icon} label={node.label} note={node.note} />
      </a>
    );
  }

  const fallback = waHref('Merhaba, farklı bir konuda yardım almak istiyorum.');

  return (
    <>
      {open && (
        <div
          ref={panelRef}
          role="dialog"
          aria-label="Raftabul Asistan"
          className="fixed inset-x-3 bottom-3 z-50 flex max-h-[72vh] flex-col overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-2xl shadow-black/20 sm:inset-x-auto sm:right-4 sm:bottom-4 sm:w-[370px] dark:border-ink-800 dark:bg-ink-900"
        >
          {/* Header */}
          <div className="flex items-center gap-2 border-b border-ink-100 bg-brand-500 px-4 py-3 text-white dark:border-ink-800">
            {screen.back ? (
              <button
                type="button"
                onClick={() => setScreenKey(screen.back!)}
                aria-label="Geri"
                className="-ml-1 grid h-7 w-7 place-items-center rounded-full hover:bg-white/20"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6" /></svg>
              </button>
            ) : (
              <span className="grid h-7 w-7 place-items-center rounded-full bg-white/20">
                <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4" fill="currentColor"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 9 4.6-1.3 8-4 8-9V6Z" /></svg>
              </span>
            )}
            <span className="flex-1 text-sm font-extrabold">{screen.title}</span>
            <button
              type="button"
              onClick={close}
              aria-label="Kapat"
              className="-mr-1 grid h-7 w-7 place-items-center rounded-full hover:bg-white/20"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
            </button>
          </div>

          {/* Body */}
          <div className="flex-1 overflow-y-auto p-3">
            {screen.intro ? <p className="mb-3 px-1 text-sm text-ink-600 dark:text-ink-300">{screen.intro}</p> : null}

            {screen.search ? (
              <form action="/urunler" onSubmit={close} className="mb-3 flex gap-2">
                <input
                  name="q"
                  type="search"
                  autoComplete="off"
                  placeholder="Ürün, marka ara…"
                  aria-label="Ürün ara"
                  className="min-w-0 flex-1 rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm text-ink-800 outline-none focus:border-brand-400 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-100"
                />
                <button type="submit" className="shrink-0 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-600">
                  Ara
                </button>
              </form>
            ) : null}

            <div className="flex flex-col gap-2">{screen.nodes.map(renderNode)}</div>
          </div>

          {/* Footer — the off-menu human fallback the owner asked for. */}
          <a
            href={fallback.href}
            onClick={close}
            {...(fallback.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
            className="flex items-center justify-center gap-2 border-t border-ink-100 bg-ink-50 px-4 py-3 text-xs font-semibold text-ink-600 hover:text-brand-600 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300"
          >
            <span className="text-[#25D366]"><Icon name="chat" /></span>
            Başka bir konu mu? WhatsApp&apos;tan yazın
          </a>
        </div>
      )}

      {/* Floating toggle — stacked above the WhatsApp FAB, under the cookie banner. */}
      {!open && (
        <button
          type="button"
          onClick={() => setOpen(true)}
          aria-label="Raftabul Asistan'ı aç"
          className="group fixed right-4 bottom-[9.5rem] z-40 flex h-14 items-center gap-2 rounded-full bg-brand-500 pr-4 pl-3.5 text-white shadow-lg shadow-black/25 transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 motion-reduce:transition-none lg:right-6 lg:bottom-[5.75rem]"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" className="h-6 w-6" fill="currentColor"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 9 4.6-1.3 8-4 8-9V6Z" opacity=".25" /><path d="M8 10h8M8 13h5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
          <span className="text-sm font-extrabold">Asistan</span>
        </button>
      )}
    </>
  );
}
