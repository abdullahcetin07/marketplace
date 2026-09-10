import path from 'node:path';

/**
 * One scene = one TTS file. Keeping them separate (instead of one long mp3) is
 * what lets scene duration come from audio in Faz 2c, and lets a single bad
 * sentence be re-synthesized without paying for the whole video again.
 *
 * @param {{ id: string, scenes: Array<{ narration: string }> }} script
 * @param {{ provider: string, outDir: string }} opts
 * @returns {Array<{ index: number, text: string, outPath: string }>}
 */
export function voJobs(script, { provider, outDir }) {
  return script.scenes.map((scene, index) => ({
    index,
    text: scene.narration.trim(),
    outPath: path.join(outDir, script.id, provider, `${String(index).padStart(2, '0')}.mp3`),
  }));
}
