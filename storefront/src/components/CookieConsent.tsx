'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { pushDataLayer } from '@/lib/analytics';
import { ui } from '@/lib/ui';

const STORAGE_KEY = 'raftabul.consent';

type Choice = 'granted' | 'denied';

/**
 * KVKK çerez onayı + Google Consent Mode v2 (ADR-085).
 *
 * THE DEFAULT IS ALREADY DENIED — the beforeInteractive script in
 * `GoogleTagManager` set Consent Mode v2 to denied before GTM loaded, so this
 * banner's job is only to REMEMBER a choice and, on "Kabul Et", `update` consent to
 * granted. Nothing here grants anything by rendering; the shopper does.
 *
 * A STORED CHOICE HIDES THE BANNER AND RE-APPLIES ITSELF. Consent Mode's default
 * resets to denied on every page load, so a returning "Kabul Et" visitor needs their
 * grant pushed again — silently, without seeing the banner twice.
 *
 * SSR-SAFE: all `window`/`localStorage` access is inside the mount effect. It is
 * gated on `NEXT_PUBLIC_GTM_ID` at the layout call site, so staging (no GTM) shows no
 * banner at all.
 */
export function CookieConsent() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const stored = readChoice();

    if (stored === null) {
      // No decision yet — show the banner; consent stays at the denied default.
      setVisible(true);

      return;
    }

    // Known choice: never show the banner, and re-apply it (the default is denied
    // again on this fresh load).
    apply(stored);
  }, []);

  function decide(choice: Choice) {
    apply(choice);

    /*
      **THE DECISION ITSELF IS MEASURED, OR NOTHING HERE CAN BE IMPROVED.** The
      choice lives in `localStorage` and was reported nowhere, so the consent
      rate was unknown and every change to this banner was a guess. The only
      number anyone had was "9 of 20 buyers had the pixel" — a biased sample,
      since people who buy are people who already trust the site.

      **THE `denied` BRANCH IS REPORTED TOO, AND IT IS NOT TRACKING THEM.** This
      carries no identifier and nothing about the person; under Consent Mode a
      denied visitor still sends a cookieless ping, which is exactly how a
      consent rate is counted without following anybody. Reporting only the
      accepts would make the denominator unknowable — the one number that
      matters.

      It is pushed AFTER `apply()`, so a granted choice is already in force when
      the event goes out and lands as a normal, consented hit.
    */
    pushDataLayer({ event: 'cookie_consent', consent_choice: choice });
    try {
      window.localStorage.setItem(STORAGE_KEY, choice);
    } catch {
      // a storage that refuses us only costs us re-asking next visit
    }
    try {
      // Let consent-gated marketing tags (Meta Pixel) load the moment consent is
      // granted, without waiting for the next page load.
      window.dispatchEvent(new CustomEvent('raftabul:consent', { detail: choice }));
    } catch {
      // a refused dispatch only means the pixel waits until the next navigation
    }
    setVisible(false);
  }

  if (!visible) return null;

  return (
    <div
      role="dialog"
      aria-label="Çerez tercihi"
      className="fixed inset-x-3 bottom-3 z-[60] mx-auto max-w-2xl sm:inset-x-4 sm:bottom-4"
    >
      <div className={`flex flex-col gap-3 p-4 shadow-[0_18px_50px_-20px_rgba(20,25,35,.45)] sm:flex-row sm:items-center sm:gap-4 sm:p-4 ${ui.card}`}>
        {/*
          THE COPY SAYS WHAT THE SHOPPER GETS (owner's wording, 2026-09-29). It
          used to read "deneyimini iyileştirmek ve trafiği ölçmek için" — which
          names what WE get, and "trafiği ölçmek" reads as surveillance to
          somebody deciding in one second.

          It is also accurate rather than softened: the consent this banner asks
          for is `ad_personalization` and `ad_user_data`, so "sana daha uygun
          fırsatlar" is the literal thing being granted, not a euphemism for it.
        */}
        <p className="flex-1 text-sm text-ink-600 dark:text-ink-300">
          Sana daha uygun fırsatlar sunmak ve siteyi geliştirmek için çerez kullanıyoruz.{' '}
          <Link href="/sayfa/gizlilik" className="font-bold text-brand-600 hover:underline">
            Gizlilik Politikası
          </Link>
        </p>
        <div className="flex shrink-0 gap-2.5">
          <button type="button" onClick={() => decide('denied')} className={`${ui.btnGhost} px-4 py-2 text-sm`}>
            Reddet
          </button>
          <button type="button" onClick={() => decide('granted')} className={ui.btnPrimarySm}>
            Kabul Et
          </button>
        </div>
      </div>
    </div>
  );
}

/** Read the stored choice, tolerating an unavailable or garbage-filled storage. */
function readChoice(): Choice | null {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY);

    return value === 'granted' || value === 'denied' ? value : null;
  } catch {
    return null;
  }
}

/** Push a Consent Mode v2 `update` for the choice. Denied is a no-op over the
 *  default, but sending it keeps the signal explicit. */
function apply(choice: Choice) {
  if (typeof window === 'undefined' || typeof window.gtag !== 'function') return;

  const state = choice === 'granted' ? 'granted' : 'denied';

  window.gtag('consent', 'update', {
    ad_storage: state,
    ad_user_data: state,
    ad_personalization: state,
    analytics_storage: state,
  });
}
