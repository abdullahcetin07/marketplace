import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { validateScript } from './lib/validate-script.mjs';
import { voJobs } from './lib/vo-jobs.mjs';
import { pickProvider } from './lib/providers/index.mjs';

const [scriptId, ...rest] = process.argv.slice(2);
const providerId = (rest.find((a) => a.startsWith('--provider=')) ?? '').split('=')[1];

if (!scriptId || !providerId) {
  console.error('Kullanım: npm run vo -- <script-id> --provider=<elevenlabs|azure>');
  process.exit(1);
}

const modulePath = path.resolve('src/videos', `${scriptId}.ts`);
const mod = await import(pathToFileURL(modulePath).href);
const script = Object.values(mod).find((v) => v && typeof v === 'object' && v.id === scriptId);

if (!script) {
  console.error(`"${modulePath}" içinde id'si "${scriptId}" olan bir export bulunamadı.`);
  process.exit(1);
}

// The money gate: never pay for TTS on copy that breaks spec §2.3.
const check = validateScript(script);
if (!check.ok) {
  console.error('Script doğrulaması BAŞARISIZ — TTS çalıştırılmadı:');
  check.errors.forEach((e) => console.error(' -', e));
  process.exit(1);
}

const provider = pickProvider(providerId, process.env);
const jobs = voJobs(script, { provider: providerId, outDir: 'public/vo' });

const totalChars = jobs.reduce((n, j) => n + j.text.length, 0);
console.log(`${provider.label} · ${jobs.length} sahne · ${totalChars} karakter`);

for (const job of jobs) {
  await fs.mkdir(path.dirname(job.outPath), { recursive: true });
  const audio = await provider.synthesize(job.text);
  await fs.writeFile(job.outPath, audio);
  console.log(`  ✓ ${job.outPath} (${audio.length} bayt)`);
}

console.log('Bitti.');
