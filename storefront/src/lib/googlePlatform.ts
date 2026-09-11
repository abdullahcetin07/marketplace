/**
 * Single loader for Google's `platform.js` (apis.google.com), shared by the
 * Google Customer Reviews opt-in (result page) and the rating badge (site-wide).
 *
 * ONE SCRIPT, MANY CONSUMERS. Both modules need `gapi`, but the page must load
 * platform.js only once. Callers pass a callback; it runs as soon as `gapi.load`
 * is available — immediately if the script is already there, otherwise queued and
 * drained when the single injected script finishes loading.
 */

type SurveyOptIn = { render: (opts: Record<string, unknown>) => void };
type RatingBadge = { render: (container: Element, opts: Record<string, unknown>) => void };
type Gapi = {
  load: (module: string, cb: () => void) => void;
  surveyoptin?: SurveyOptIn;
  ratingbadge?: RatingBadge;
};

declare global {
  interface Window {
    gapi?: Gapi;
    __raftabulGapiOnload?: () => void;
  }
}

const SCRIPT_ID = 'google-gapi-platform';
const queue: Array<() => void> = [];
let injected = false;

/** Run `cb` once `window.gapi.load` is ready; loads platform.js on first call. */
export function loadGapi(cb: () => void): void {
  if (typeof window === 'undefined') return;

  if (window.gapi?.load) {
    cb();

    return;
  }

  queue.push(cb);
  if (injected) return;
  injected = true;

  // platform.js calls this global (via ?onload=) once gapi is ready.
  window.__raftabulGapiOnload = () => {
    queue.splice(0).forEach((fn) => fn());
  };

  if (document.getElementById(SCRIPT_ID) === null) {
    const script = document.createElement('script');
    script.id = SCRIPT_ID;
    script.src = 'https://apis.google.com/js/platform.js?onload=__raftabulGapiOnload';
    script.async = true;
    script.defer = true;
    document.body.appendChild(script);
  }
}
