import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateScript } from './validate-script.mjs';

/** Minimal valid script — every test starts from this and breaks one thing. */
const base = () => ({
  id: 'ornek-video',
  series: 'S2',
  query: 'niasinamid ne işe yarar',
  targetUrl: '/arama?q=niasinamid',
  title: 'Niasinamid nedir?',
  scenes: [
    { narration: 'Niasinamid, B3 vitamininin bir formudur.', onScreen: 'Niasinamid nedir?' },
    { narration: 'Serum ve nemlendiricilerde sık karşına çıkar.', onScreen: 'Nerede bulunur?' },
    { narration: 'Bu bilgi bir tedavi önerisi değildir.', onScreen: 'Not', role: 'disclaimer' },
  ],
  shorts: [[0], [1]],
});

test('temiz script geçer', () => {
  const r = validateScript(base());
  assert.equal(r.ok, true, r.errors.join(' | '));
  assert.deepEqual(r.errors, []);
});

test('sağlık beyanı içeren anlatım reddedilir ve sahneyi işaret eder', () => {
  const s = base();
  s.scenes[1].narration = 'Bu ürün egzamayı geçirir ve saç dökülmesini durdurur.';
  const r = validateScript(s);
  assert.equal(r.ok, false);
  assert.ok(r.errors.some((e) => e.includes('sahne 1')), r.errors.join(' | '));
  assert.ok(r.errors.some((e) => e.includes('geçirir')), r.errors.join(' | '));
});

test('disclaimer sahnesi yasaklı kelimeyi kullanabilir', () => {
  const s = base();
  s.scenes[2].narration = 'Buradaki bilgi bir tedavi önerisi değildir; hastalık şüphesinde hekime başvur.';
  const r = validateScript(s);
  assert.equal(r.ok, true, r.errors.join(' | '));
});

test('S2 videosunda disclaimer sahnesi yoksa reddedilir', () => {
  const s = base();
  s.scenes = s.scenes.slice(0, 2);
  s.shorts = [[0]];
  const r = validateScript(s);
  assert.equal(r.ok, false);
  assert.ok(r.errors.some((e) => e.includes('disclaimer')), r.errors.join(' | '));
});

test('boş anlatım reddedilir', () => {
  const s = base();
  s.scenes[0].narration = '   ';
  const r = validateScript(s);
  assert.equal(r.ok, false);
  assert.ok(r.errors.some((e) => e.includes('sahne 0')), r.errors.join(' | '));
});

test('kapsam dışı shorts indeksi reddedilir', () => {
  const s = base();
  s.shorts = [[0], [9]];
  const r = validateScript(s);
  assert.equal(r.ok, false);
  assert.ok(r.errors.some((e) => e.includes('shorts')), r.errors.join(' | '));
});

test('zorunlu alan eksikse reddedilir', () => {
  const s = base();
  delete s.targetUrl;
  const r = validateScript(s);
  assert.equal(r.ok, false);
  assert.ok(r.errors.some((e) => e.includes('targetUrl')), r.errors.join(' | '));
});
