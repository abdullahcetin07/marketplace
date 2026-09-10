import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pickProvider, PROVIDERS } from './index.mjs';

test('iki sağlayıcı kayıtlı', () => {
  assert.deepEqual(Object.keys(PROVIDERS).sort(), ['azure', 'elevenlabs']);
});

test('bilinmeyen sağlayıcı, geçerli isimleri listeleyerek reddedilir', () => {
  assert.throws(() => pickProvider('yok', {}), /azure.*elevenlabs|elevenlabs.*azure/s);
});

test('eksik env değişkeni adıyla birlikte bildirilir', () => {
  assert.throws(() => pickProvider('elevenlabs', {}), /ELEVENLABS_API_KEY/);
  assert.throws(() => pickProvider('azure', {}), /AZURE_SPEECH_KEY/);
});

test('env tamamsa synthesize fonksiyonu olan bir sağlayıcı döner', () => {
  const p = pickProvider('azure', { AZURE_SPEECH_KEY: 'k', AZURE_SPEECH_REGION: 'westeurope' });
  assert.equal(p.id, 'azure');
  assert.equal(typeof p.synthesize, 'function');
  assert.equal(typeof p.label, 'string');
});
