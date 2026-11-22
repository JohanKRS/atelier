import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { clean, strips, LEVELS, MARGIN } from '../assets/clean.js';

// A 96 × 96 picture with everything the filter has to tell apart: a plain surface with a faint irregular web and
// isolated dots (to remove), and a hard edge, a thin line and a plaid pattern (to keep).
const W = 96, H = 96;
let seed = 12345;
const random = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; };
const image = new Uint8ClampedArray(W * H * 4);
const at = (x, y) => (y * W + x) * 4;
const set = (x, y, v, a = 255) => { const p = at(x, y); image[p] = v; image[p + 1] = v; image[p + 2] = v; image[p + 3] = a; };
const plaid = (x, y) => 70 + (x % 8 < 2 ? 70 : 0) + (y % 8 < 2 ? 70 : 0);
for (let y = 0; y < H; y++) {
  for (let x = 0; x < W; x++) {
    if (x >= 64) set(x, y, y < 48 ? 40 : 200);                 // right: a hard edge between dark and light
    else if (y >= 64) set(x, y, plaid(x, y));                  // bottom left: plaid
    else set(x, y, 120 + Math.round((random() - 0.5) * 16));   // top left: a plain surface with a faint web of noise
  }
}
const dots = [[10, 10], [30, 22], [50, 40], [22, 50]];
for (const [x, y] of dots) { set(x, y, 235); set(x + 1, y, 235); set(x, y + 1, 235); set(x + 1, y + 1, 235); }  // 2 × 2 light dots
set(40, 12, 20);                                               // a dark 1 px dot
for (let x = 4; x < 60; x++) set(x, 32, 30);                   // a thin dark line across the surface
image[at(5, 5) + 3] = 100;                                     // one translucent pixel

const before = new Uint8ClampedArray(image);
const progress = [];
const out = clean(image, W, H, LEVELS.media, done => progress.push(done));
assert.deepEqual(image, before, 'the input is not changed');
assert.equal(out.length, image.length);
for (let i = 3; i < out.length; i += 4) assert.equal(out[i], image[i], 'alpha stays as it was');
assert.ok(progress.length >= 2 && progress.at(-1) === 1 && progress.every((v, i) => i === 0 || v >= progress[i - 1]), 'progress rises to 1');

// Dots on the plain surface are gone.
for (const [x, y] of [...dots, [40, 12]]) assert.ok(Math.abs(out[at(x, y)] - 120) < 12, `the dot at ${x},${y} is gone (${out[at(x, y)]})`);
// The faint web is averaged out: the surface is much more even, away from the line and the dots.
const spread = data => { let n = 0, sum = 0, sq = 0; for (let y = 40; y < 60; y++) for (let x = 4; x < 18; x++) { const v = data[at(x, y)]; n++; sum += v; sq += v * v; } return Math.sqrt(sq / n - (sum / n) ** 2); };
assert.ok(spread(out) < spread(image) * 0.45, `the surface is evened out (${spread(image).toFixed(2)} to ${spread(out).toFixed(2)})`);
// The thin line, the hard edge and the plaid are still there, as sharp as they were.
for (let x = 8; x < 56; x += 6) assert.ok(out[at(x, 32)] < 50, `the thin line stays (${out[at(x, 32)]} at ${x})`);
for (const x of [70, 80, 90]) { assert.ok(Math.abs(out[at(x, 47)] - 40) <= 2 && Math.abs(out[at(x, 48)] - 200) <= 2, 'the hard edge stays sharp'); }
let plaidDrift = 0, plaidCount = 0;
for (let y = 68; y < 92; y++) for (let x = 4; x < 60; x++) { plaidDrift += Math.abs(out[at(x, y)] - image[at(x, y)]); plaidCount++; }
assert.ok(plaidDrift / plaidCount < 3, `the plaid pattern stays (${(plaidDrift / plaidCount).toFixed(2)} off on average)`);

// A stronger level evens the surface more; the same input gives the same output.
assert.ok(spread(clean(image, W, H, LEVELS.forte)) <= spread(clean(image, W, H, LEVELS.leve)), 'forte smooths at least as much as leve');
assert.deepEqual(clean(image, W, H, LEVELS.media), out, 'the result does not depend on anything but the image and the level');

// Strips, one per worker: each is cleaned with the rows around it, and its own rows come out as from the whole image.
const parts = strips(H, 4);
assert.deepEqual(parts, [{ from: 0, to: 48, top: 0, bottom: 48 + MARGIN }, { from: 48, to: 96, top: 48 - MARGIN, bottom: 96 }]);
assert.deepEqual(strips(1000, 4).map(part => part.to - part.from), [250, 250, 250, 250]);
assert.deepEqual(strips(40, 4), [{ from: 0, to: 40, top: 0, bottom: 40 }], 'a short image is one strip');
const stitched = new Uint8ClampedArray(image.length);
for (const part of parts) {
  const piece = clean(image.slice(part.top * W * 4, part.bottom * W * 4), W, part.bottom - part.top, LEVELS.media), skip = (part.from - part.top) * W * 4;
  stitched.set(piece.subarray(skip, skip + (part.to - part.from) * W * 4), part.from * W * 4);
}
assert.deepEqual(stitched, out, 'strips cleaned apart join into exactly the whole image cleaned at once');

// Small and odd sizes work; a size that doesn't match the data is refused.
assert.equal(clean(new Uint8ClampedArray(4).fill(200), 1, 1, LEVELS.media)[0], 200);
assert.equal(clean(new Uint8ClampedArray(5 * 3 * 4).fill(90), 5, 3, LEVELS.forte).every((v, i) => i % 4 === 3 || v === 90), true);
assert.throws(() => clean(new Uint8ClampedArray(16), 3, 3, LEVELS.media), /Invalid image size/);
console.log('PASS cleanup filter: dots and the faint web removed, lines, edges and patterns kept, alpha untouched, deterministic, strips for parallel workers, any size');
