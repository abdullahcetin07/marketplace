import {
  AbsoluteFill,
  Img,
  Sequence,
  interpolate,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';
import { loadFont } from '@remotion/google-fonts/Manrope';
import type { ReelData, ReelScene } from './types';

const { fontFamily } = loadFont();
const FONT = `${fontFamily}, "Segoe UI", system-ui, sans-serif`;

// --- Brand tokens (storefront: orange #fb5607 + warm ink neutrals) ---
const BG = '#FAF7F2';
const INK = '#1C1917';
const ORANGE = '#FB5607';
const ORANGE_SOFT = '#FFE7D5';
const MUTED = '#7A6E64';

export const SCENE = 90; // frames per scene (3 s @ 30 fps)
export const totalFrames = (data: ReelData) => data.scenes.length * SCENE;

/** Text entrance: spring translateY + fade, with a tail fade-out. */
const useEnter = (delay = 0) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const s = spring({ frame: frame - delay, fps, config: { damping: 200 } });
  const y = interpolate(s, [0, 1], [55, 0]);
  const enter = interpolate(s, [0, 1], [0, 1]);
  const exit = interpolate(frame, [SCENE - 14, SCENE - 2], [1, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  return { transform: `translateY(${y}px)`, opacity: Math.min(enter, exit) };
};

/** A real catalogue product on a white tile — pops in (scale + tilt) and gently bobs. */
const ProductCard: React.FC<{ src: string; delay?: number; phase?: number; size?: number }> = ({
  src,
  delay = 0,
  phase = 0,
  size = 560,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const s = spring({ frame: frame - delay, fps, config: { damping: 160, mass: 0.9 } });
  const scale = interpolate(s, [0, 1], [0.82, 1]);
  const rot = interpolate(s, [0, 1], [-7, 0]);
  const bob = Math.sin((frame + phase) / 17) * 12;
  const enter = interpolate(s, [0, 1], [0, 1]);
  const exit = interpolate(frame, [SCENE - 14, SCENE - 2], [1, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  return (
    <div
      style={{
        width: size,
        height: size,
        background: '#fff',
        borderRadius: 48,
        padding: 44,
        boxShadow: '0 40px 90px rgba(28,25,23,0.16)',
        transform: `translateY(${bob}px) scale(${scale}) rotate(${rot}deg)`,
        opacity: Math.min(enter, exit),
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      <Img src={staticFile(src)} style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }} />
    </div>
  );
};

const Halo: React.FC<{ size?: number }> = ({ size = 760 }) => (
  <div
    style={{
      position: 'absolute',
      width: size,
      height: size,
      borderRadius: '50%',
      background: `radial-gradient(circle, ${ORANGE_SOFT} 0%, rgba(255,231,213,0) 68%)`,
    }}
  />
);

const Eyebrow: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <div style={{ fontFamily: FONT, fontWeight: 700, fontSize: 34, letterSpacing: 6, color: ORANGE, textAlign: 'center' }}>
    {children}
  </div>
);

const Headline: React.FC<{ line1: string; line2?: string; size?: number }> = ({ line1, line2, size = 100 }) => (
  <div style={{ fontFamily: FONT, fontWeight: 800, fontSize: size, lineHeight: 1.03, color: INK, letterSpacing: -2, textAlign: 'center' }}>
    {line1}
    {line2 ? (
      <>
        <br />
        {line2}
      </>
    ) : null}
  </div>
);

const HookScene: React.FC<{ s: Extract<ReelScene, { kind: 'hook' }> }> = ({ s }) => {
  const a = useEnter(0);
  const b = useEnter(10);
  return (
    <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', padding: 90 }}>
      <div style={{ ...a, marginBottom: 18 }}>
        <Eyebrow>{s.eyebrow}</Eyebrow>
      </div>
      <div style={a}>
        <Headline line1={s.line1} line2={s.line2} size={104} />
      </div>
      {s.image ? (
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center', marginTop: 40 }}>
          <Halo size={720} />
          <ProductCard src={s.image} size={520} delay={6} />
        </div>
      ) : (
        <div style={{ height: 60 }} />
      )}
      <div style={{ ...b, marginTop: 36, fontFamily: FONT, fontWeight: 600, fontSize: 50, color: MUTED, textAlign: 'center' }}>
        {s.sub}
      </div>
    </AbsoluteFill>
  );
};

const StepScene: React.FC<{ s: Extract<ReelScene, { kind: 'step' }> }> = ({ s }) => {
  const title = useEnter(8);
  const desc = useEnter(14);
  const badgeOnly = !s.image;
  return (
    <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', padding: 90 }}>
      {badgeOnly ? (
        <Badge n={s.n} big />
      ) : (
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <Halo size={860} />
          <ProductCard src={s.image!} size={620} phase={s.n.charCodeAt(0) * 7} />
          <div style={{ position: 'absolute', top: -30, left: -30 }}>
            <Badge n={s.n} />
          </div>
        </div>
      )}
      <div style={{ ...title, marginTop: badgeOnly ? 56 : 64 }}>
        <Headline line1={s.title} size={100} />
      </div>
      <div
        style={{
          ...desc,
          marginTop: 22,
          fontFamily: FONT,
          fontWeight: 500,
          fontSize: 46,
          lineHeight: 1.28,
          color: MUTED,
          maxWidth: 860,
          textAlign: 'center',
        }}
      >
        {s.desc}
      </div>
    </AbsoluteFill>
  );
};

const Badge: React.FC<{ n: string; big?: boolean }> = ({ n, big }) => {
  const size = big ? 200 : 150;
  return (
    <div
      style={{
        width: size,
        height: size,
        borderRadius: big ? 64 : 50,
        background: ORANGE,
        color: '#fff',
        fontFamily: FONT,
        fontWeight: 800,
        fontSize: big ? 116 : 86,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        boxShadow: '0 24px 48px rgba(251,86,7,0.32)',
      }}
    >
      {n}
    </div>
  );
};

const CtaScene: React.FC<{ s: Extract<ReelScene, { kind: 'cta' }> }> = ({ s }) => {
  const a = useEnter(0);
  const b = useEnter(10);
  const c = useEnter(18);
  const imgs = s.images ?? [];
  return (
    <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', padding: 90 }}>
      <div style={a}>
        <Eyebrow>{s.eyebrow}</Eyebrow>
      </div>
      <div style={{ ...a, marginTop: 22 }}>
        <Headline line1={s.line1} line2={s.line2} size={90} />
      </div>
      {imgs.length > 0 ? (
        <div style={{ display: 'flex', gap: 26, marginTop: 56 }}>
          {imgs.map((src, i) => (
            <ProductCard key={src + i} src={src} size={imgs.length === 1 ? 380 : 240} delay={6 + i * 4} phase={i * 30} />
          ))}
        </div>
      ) : (
        <div style={{ height: 40 }} />
      )}
      <div style={{ ...b, marginTop: 48, fontFamily: FONT, fontWeight: 500, fontSize: 44, color: MUTED, textAlign: 'center' }}>
        {s.sub}
      </div>
      <div
        style={{
          ...c,
          marginTop: 44,
          background: ORANGE,
          color: '#fff',
          fontFamily: FONT,
          fontWeight: 800,
          fontSize: 56,
          padding: '32px 72px',
          borderRadius: 999,
          boxShadow: '0 26px 50px rgba(251,86,7,0.32)',
        }}
      >
        raftabul.com
      </div>
    </AbsoluteFill>
  );
};

const Progress: React.FC<{ count: number }> = ({ count }) => {
  const frame = useCurrentFrame();
  const active = Math.floor(frame / SCENE);
  const within = (frame % SCENE) / SCENE;
  return (
    <div style={{ position: 'absolute', top: 70, left: 90, right: 90, display: 'flex', gap: 14 }}>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} style={{ flex: 1, height: 10, borderRadius: 999, background: ORANGE_SOFT, overflow: 'hidden' }}>
          <div style={{ height: '100%', width: i < active ? '100%' : i === active ? `${within * 100}%` : '0%', background: ORANGE }} />
        </div>
      ))}
    </div>
  );
};

const BrandBar: React.FC = () => (
  <div
    style={{
      position: 'absolute',
      bottom: 74,
      left: 90,
      right: 90,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      fontFamily: FONT,
      color: INK,
    }}
  >
    <div style={{ fontWeight: 800, fontSize: 40, letterSpacing: 1 }}>
      raftabul<span style={{ color: ORANGE }}>.com</span>
    </div>
    <div style={{ fontWeight: 600, fontSize: 32, color: MUTED }}>Onaylı · Orijinal · Kargo bedava</div>
  </div>
);

export const Reel: React.FC<{ data: ReelData }> = ({ data }) => {
  const frame = useCurrentFrame();
  const blobX = interpolate(frame, [0, totalFrames(data)], [-120, 120]);
  const blobY = interpolate(frame, [0, totalFrames(data)], [-80, 80]);

  return (
    <AbsoluteFill style={{ background: BG }}>
      <div
        style={{
          position: 'absolute',
          width: 900,
          height: 900,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${ORANGE_SOFT} 0%, rgba(255,231,213,0) 70%)`,
          top: 260 + blobY,
          right: -320 + blobX,
          filter: 'blur(10px)',
        }}
      />
      <Progress count={data.scenes.length} />

      {data.scenes.map((s, i) => (
        <Sequence key={i} from={SCENE * i} durationInFrames={SCENE}>
          {s.kind === 'hook' ? <HookScene s={s} /> : s.kind === 'step' ? <StepScene s={s} /> : <CtaScene s={s} />}
        </Sequence>
      ))}

      <BrandBar />
    </AbsoluteFill>
  );
};
