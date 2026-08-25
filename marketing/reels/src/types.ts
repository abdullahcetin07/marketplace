/**
 * A reel is DATA. Add an entry to `reels.ts` and it becomes a renderable
 * composition automatically — no new component. Health-claim rules live in the
 * copy you write here (see the raftabul-reel skill / raftabul-product-copy).
 */
export type ReelScene =
  | { kind: 'hook'; eyebrow: string; line1: string; line2?: string; sub: string; image?: string }
  | { kind: 'step'; n: string; title: string; desc: string; image?: string }
  | { kind: 'cta'; eyebrow: string; line1: string; line2?: string; sub: string; images?: string[] };

export type ReelData = {
  /** Composition id — becomes the render target: `npm run render -- <id> out/<id>.mp4`. */
  id: string;
  scenes: ReelScene[];
};
