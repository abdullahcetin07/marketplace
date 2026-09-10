import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { voJobs } from './vo-jobs.mjs';

const script = {
  id: 'ornek',
  scenes: [
    { narration: 'Birinci cümle.', onScreen: 'A' },
    { narration: '  İkinci cümle.  ', onScreen: 'B' },
  ],
};

test('her sahne için bir iş üretir', () => {
  const jobs = voJobs(script, { provider: 'azure', outDir: 'public/vo' });
  assert.equal(jobs.length, 2);
});

test('anlatımın kenar boşluklarını kırpar', () => {
  const jobs = voJobs(script, { provider: 'azure', outDir: 'public/vo' });
  assert.equal(jobs[1].text, 'İkinci cümle.');
});

test('çıktı yolu script id, sağlayıcı ve sıfır dolgulu indeks içerir', () => {
  const jobs = voJobs(script, { provider: 'elevenlabs', outDir: 'public/vo' });
  // Windows'ta path.join ters bölü üretir; testi platformdan bağımsız tut.
  assert.match(jobs[0].outPath.split(path.sep).join('/'), /public\/vo\/ornek\/elevenlabs\/00\.mp3$/);
  assert.match(jobs[1].outPath.split(path.sep).join('/'), /public\/vo\/ornek\/elevenlabs\/01\.mp3$/);
});

test('indeks sahne sırasını korur', () => {
  const jobs = voJobs(script, { provider: 'azure', outDir: 'public/vo' });
  assert.deepEqual(jobs.map((j) => j.index), [0, 1]);
});
