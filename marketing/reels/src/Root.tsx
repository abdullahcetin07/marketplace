import { Composition } from 'remotion';
import { Reel, totalFrames } from './Reel';
import { REELS } from './reels';

/**
 * One composition per reel in `reels.ts`. Add a data entry there and it shows up
 * here automatically — render it with `npm run render -- <id> out/<id>.mp4`, or
 * preview every reel with `npm run studio`.
 *
 * Vertical 1080×1920, 30 fps; length = scene count × 3 s (set in Reel.tsx).
 */
export const RemotionRoot: React.FC = () => {
  return (
    <>
      {REELS.map((data) => (
        <Composition
          key={data.id}
          id={data.id}
          component={Reel}
          durationInFrames={totalFrames(data)}
          fps={30}
          width={1080}
          height={1920}
          defaultProps={{ data }}
        />
      ))}
    </>
  );
};
