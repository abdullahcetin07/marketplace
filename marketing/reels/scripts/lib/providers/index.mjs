import { create as createElevenLabs } from './elevenlabs.mjs';
import { create as createAzure } from './azure.mjs';

/**
 * The pilot compares two providers. The adapter boundary exists so the pilot's
 * verdict swaps ONE entry here and nothing else in the pipeline.
 */
export const PROVIDERS = {
  elevenlabs: {
    id: 'elevenlabs',
    label: 'ElevenLabs',
    requiredEnv: ['ELEVENLABS_API_KEY', 'ELEVENLABS_VOICE_ID'],
    create: createElevenLabs,
  },
  azure: {
    id: 'azure',
    label: 'Azure Speech',
    requiredEnv: ['AZURE_SPEECH_KEY', 'AZURE_SPEECH_REGION'],
    create: createAzure,
  },
};

/**
 * @param {string} id
 * @param {Record<string,string|undefined>} env
 * @returns {{ id: string, label: string, synthesize: (text: string) => Promise<Buffer> }}
 */
export function pickProvider(id, env) {
  const entry = PROVIDERS[id];
  if (!entry) {
    throw new Error(`Bilinmeyen sağlayıcı "${id}". Geçerli: ${Object.keys(PROVIDERS).join(', ')}`);
  }
  const missing = entry.requiredEnv.filter((k) => !env[k]);
  if (missing.length > 0) {
    throw new Error(
      `${entry.label} için eksik ortam değişkeni: ${missing.join(', ')}. ` +
        `marketing/reels/.env dosyasına ekle (.env.example'a bak).`,
    );
  }
  return entry.create(env);
}
