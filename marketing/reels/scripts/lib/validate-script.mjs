/**
 * Executable form of spec §2.3. Runs BEFORE any TTS call, because TTS is the
 * only step that costs money — a claim caught here is free, one caught after
 * render is a re-run.
 */

/** Asserting-claim vocabulary. A disclaimer scene negates these, so it is exempt. */
export const FORBIDDEN_CLAIM_WORDS = [
  'tedavi',
  'iyileştir',
  'geçirir',
  'önler',
  'engeller',
  'şifa',
  'kür',
  'hastalık',
  'ilaç gibi',
  'garanti eder',
];

const REQUIRED_FIELDS = ['id', 'series', 'query', 'targetUrl', 'title'];

/**
 * @param {object} script
 * @returns {{ ok: boolean, errors: string[] }}
 */
export function validateScript(script) {
  const errors = [];

  for (const field of REQUIRED_FIELDS) {
    if (typeof script?.[field] !== 'string' || script[field].trim() === '') {
      errors.push(`Zorunlu alan eksik veya boş: ${field}`);
    }
  }

  const scenes = Array.isArray(script?.scenes) ? script.scenes : [];
  if (scenes.length === 0) errors.push('En az bir sahne gerekli.');

  scenes.forEach((scene, i) => {
    if (typeof scene?.narration !== 'string' || scene.narration.trim() === '') {
      errors.push(`sahne ${i}: anlatım (narration) boş.`);
      return;
    }
    if (typeof scene?.onScreen !== 'string' || scene.onScreen.trim() === '') {
      errors.push(`sahne ${i}: ekran metni (onScreen) boş.`);
    }
    if (scene.role === 'disclaimer') return; // negates the words on purpose

    const lower = scene.narration.toLocaleLowerCase('tr-TR');
    for (const word of FORBIDDEN_CLAIM_WORDS) {
      if (lower.includes(word)) {
        errors.push(`sahne ${i}: sağlık beyanı riski — "${word}" geçiyor. (spec §2.3)`);
      }
    }
  });

  if (script?.series === 'S1' || script?.series === 'S2') {
    if (!scenes.some((s) => s?.role === 'disclaimer')) {
      errors.push('S1/S2 videosu bir disclaimer sahnesi içermeli (role: "disclaimer").');
    }
  }

  const shorts = Array.isArray(script?.shorts) ? script.shorts : [];
  shorts.forEach((cut, ci) => {
    if (!Array.isArray(cut) || cut.length === 0) {
      errors.push(`shorts[${ci}]: en az bir sahne indeksi içermeli.`);
      return;
    }
    for (const idx of cut) {
      if (!Number.isInteger(idx) || idx < 0 || idx >= scenes.length) {
        errors.push(`shorts[${ci}]: kapsam dışı sahne indeksi ${idx}.`);
      }
    }
  });

  return { ok: errors.length === 0, errors };
}
