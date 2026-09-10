/** Escape the five XML entities — narration contains quotes and ampersands. */
const xml = (s) =>
  s.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&apos;');

/**
 * Azure Speech tr-TR neural voice — free tier, strong Turkish. The other half
 * of the comparison.
 * @param {Record<string,string>} env
 */
export function create(env) {
  const region = env.AZURE_SPEECH_REGION;
  const voice = env.AZURE_SPEECH_VOICE ?? 'tr-TR-EmelNeural';
  return {
    id: 'azure',
    label: `Azure Speech · ${voice}`,
    async synthesize(text) {
      const ssml =
        `<speak version="1.0" xml:lang="tr-TR">` +
        `<voice name="${voice}">${xml(text)}</voice></speak>`;
      const res = await fetch(`https://${region}.tts.speech.microsoft.com/cognitiveservices/v1`, {
        method: 'POST',
        headers: {
          'Ocp-Apim-Subscription-Key': env.AZURE_SPEECH_KEY,
          'Content-Type': 'application/ssml+xml',
          'X-Microsoft-OutputFormat': 'audio-24khz-48kbitrate-mono-mp3',
          'User-Agent': 'raftabul-reels',
        },
        body: ssml,
      });
      if (!res.ok) {
        throw new Error(`Azure ${res.status}: ${await res.text()}`);
      }
      return Buffer.from(await res.arrayBuffer());
    },
  };
}
