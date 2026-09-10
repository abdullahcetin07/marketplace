const ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech';

/**
 * ElevenLabs multilingual — the quality benchmark in this comparison.
 * @param {Record<string,string>} env
 */
export function create(env) {
  const voiceId = env.ELEVENLABS_VOICE_ID;
  return {
    id: 'elevenlabs',
    label: 'ElevenLabs · eleven_multilingual_v2',
    async synthesize(text) {
      const res = await fetch(`${ENDPOINT}/${voiceId}`, {
        method: 'POST',
        headers: {
          'xi-api-key': env.ELEVENLABS_API_KEY,
          'content-type': 'application/json',
          accept: 'audio/mpeg',
        },
        body: JSON.stringify({
          text,
          model_id: 'eleven_multilingual_v2',
          voice_settings: { stability: 0.5, similarity_boost: 0.75 },
        }),
      });
      if (!res.ok) {
        throw new Error(`ElevenLabs ${res.status}: ${await res.text()}`);
      }
      return Buffer.from(await res.arrayBuffer());
    },
  };
}
