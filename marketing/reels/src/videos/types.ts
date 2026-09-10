/**
 * A YouTube video is DATA — one file per video. The same file feeds the long
 * horizontal render, the Shorts cuts, the thumbnail and the upload package.
 *
 * Health-claim rules (spec §2.3) are NOT a convention here — they are enforced
 * by `scripts/lib/validate-script.mjs`, which runs before any TTS is paid for.
 */
export type VideoSeries = 'S1' | 'S2' | 'S3';

export type VideoScene = {
  /** What the voice-over says. One scene = one TTS file = one mp3. */
  narration: string;
  /** Short on-screen headline. Deliberately NOT the same text as the narration. */
  onScreen: string;
  /** Optional catalogue product image, relative to `public/`. */
  image?: string;
  /**
   * A disclaimer scene ("bu bir tedavi önerisi değil") is the ONE place the
   * forbidden claim words may legally appear — because it negates them.
   * Marking it is deliberate: the validator both exempts this scene AND
   * requires that every S1/S2 video has one.
   */
  role?: 'disclaimer';
};

export type VideoScript = {
  /** Slug — render target and output directory name. ASCII only. */
  id: string;
  series: VideoSeries;
  /** The search query this video targets (spec §2.2). */
  query: string;
  /** Site page this video is embedded on and links to (spec §2.1). */
  targetUrl: string;
  /** YouTube title. */
  title: string;
  scenes: VideoScene[];
  /** Scene indexes (0-based) that become Shorts cuts. Three cuts per video. */
  shorts: number[][];
};
