// Limpar: removes the light dots and the fine web of lines ("constellations") that the image model leaves on skin,
// hair and everything else, and that every new edit copies from the image it starts from. It runs on the device, at no
// cost, in a worker (clean-worker.js) and in the tests (Node). Nothing here knows what the picture shows.
//
// Three steps:
// 1. Dots. A pixel brighter or darker than every pixel of the ring 3 px around it, where that ring is itself even,
//    belongs to a dot up to 3 px wide on a plain surface and takes the ring's median. A thin line (glasses, a hair, an
//    eyelash) crosses the ring, and a pattern (plaid, sand, tortoiseshell) makes it uneven, so neither qualifies.
// 2. Non-local means. Every pixel becomes the weighted mean of the pixels up to SEARCH px away whose 7×7 surroundings
//    look alike. The web of lines differs from patch to patch and averages out; shading, contours and anything that
//    repeats along its own shape stay. Patch distances come from running sums, so each of the 121 shifts costs a few
//    passes over the image, and the image is done in bands to bound the memory.
// 3. Edges back. Averaging also softens what is really there, so wherever the cleaned image has an edge (a contour, the
//    line between two muscles, a vein, eyes, glasses, hair strands, lettering) the pixels of step 1 return, sharp as
//    they were. Only smooth surfaces keep the result of step 2.

/** strength: how different two patches may be and still average. keep/full: edge strength where the original starts to return and where it is fully back. */
export const LEVELS = {
  leve: { strength: 6, keep: 1.5, full: 4 },
  media: { strength: 8, keep: 2, full: 6 },
  forte: { strength: 10, keep: 3, full: 8 },
};
const SEARCH = 5, PATCH = 3, BAND = 192, RING = 3, DOT = 18, EVEN = 32;
const LUT_SIZE = 4096, LUT_MAX = 12; // exp(-x) for x in [0, 12); beyond that the weight is 0

/** How far a pixel's result reaches: the ring of step 1, the search and patch of step 2, and the edge strength of step 3 (gradient, spread, mean). */
export const MARGIN = RING + SEARCH + PATCH + 4;
/**
 * Rows to clean as separate images, one per worker, so several cores share the work. Each strip is cleaned from `top` to
 * `bottom`, which include MARGIN rows around its own rows `from`..`to`: only its own rows are kept, and they come out
 * exactly as they would from cleaning the whole image at once.
 */
export function strips(height, count) {
  const n = Math.max(1, Math.min(Math.floor(count) || 1, Math.ceil(height / 64)));
  return Array.from({ length: n }, (_, i) => {
    const from = Math.round(i * height / n), to = Math.round((i + 1) * height / n);
    return { from, to, top: Math.max(0, from - MARGIN), bottom: Math.min(height, to + MARGIN) };
  });
}

/**
 * @param {Uint8ClampedArray} rgba  the image, 4 bytes a pixel; not changed
 * @param {{strength: number, keep: number, full: number}} level  one of LEVELS
 * @param {(done: number) => void} [progress]  called with 0..1 as the work advances
 * @returns {Uint8ClampedArray} the cleaned image, alpha as it was
 */
export function clean(rgba, width, height, level, progress) {
  const W = width, H = height, N = W * H;
  if (!Number.isInteger(W) || !Number.isInteger(H) || W < 1 || H < 1 || rgba.length !== N * 4) throw new Error('Invalid image size.');
  const base = new Uint8ClampedArray(rgba);
  const Y = new Float32Array(N);
  luminance(base, Y);
  removeDots(base, Y, W, H);
  const out = new Uint8ClampedArray(base);
  average(base, Y, out, W, H, level.strength, done => progress?.(done * 0.95));
  keepEdges(base, out, Y, W, H, level.keep, level.full);
  progress?.(1);
  return out;
}

function clamp(v, n) { return v < 0 ? 0 : v >= n ? n - 1 : v; }
function luminance(rgba, Y) { for (let i = 0, j = 0; i < Y.length; i++, j += 4) Y[i] = 0.299 * rgba[j] + 0.587 * rgba[j + 1] + 0.114 * rgba[j + 2]; }

/** Step 1: a pixel that stands out by DOT from all the pixels of the ring RING px away, a ring whose own values span less than EVEN, takes the ring's median, per channel. */
function removeDots(base, Y, W, H) {
  if (W <= 2 * RING || H <= 2 * RING) return;
  const ring = [];
  for (let dy = -RING; dy <= RING; dy++) for (let dx = -RING; dx <= RING; dx++) if (Math.max(Math.abs(dy), Math.abs(dx)) === RING) ring.push(dy * W + dx);
  const count = ring.length, hits = [], values = new Float32Array(count), middle = count >> 1;
  for (let y = RING; y < H - RING; y++) {
    for (let x = RING; x < W - RING; x++) {
      const i = y * W + x, v = Y[i];
      let lo = Infinity, hi = -Infinity;
      for (let k = 0; k < count; k++) { const r = Y[i + ring[k]]; if (r < lo) lo = r; if (r > hi) hi = r; }
      if (hi - lo < EVEN && (v - hi > DOT || lo - v > DOT)) hits.push(i);
    }
  }
  // Medians come from the image as it was, so the pixels of one dot don't lean on each other's replacements.
  const fixed = new Uint8ClampedArray(hits.length * 3);
  hits.forEach((i, n) => {
    for (let c = 0; c < 3; c++) {
      for (let k = 0; k < count; k++) values[k] = base[(i + ring[k]) * 4 + c];
      values.sort(); fixed[n * 3 + c] = (values[middle - 1] + values[middle]) / 2;
    }
  });
  hits.forEach((i, n) => {
    const p = i * 4;
    base[p] = fixed[n * 3]; base[p + 1] = fixed[n * 3 + 1]; base[p + 2] = fixed[n * 3 + 2];
    Y[i] = 0.299 * base[p] + 0.587 * base[p + 1] + 0.114 * base[p + 2];
  });
}

/** Step 2: non-local means of `base` into `out`, weights from the luminance Y. */
function average(base, Y, out, W, H, strength, progress) {
  const lut = new Float32Array(LUT_SIZE + 1);
  for (let k = 0; k < LUT_SIZE; k++) lut[k] = Math.exp(-(k / LUT_SIZE) * LUT_MAX);
  // A window sum of squared differences becomes a LUT index: mean over the patch, over strength², scaled to the table.
  const area = (2 * PATCH + 1) ** 2, scale = LUT_SIZE / (LUT_MAX * strength * strength * area);
  const rowsMax = Math.min(BAND, H), ext = rowsMax + 2 * PATCH;
  const D = new Float32Array(ext * W), T = new Float32Array(ext * W), col = new Float32Array(W);
  const accR = new Float32Array(rowsMax * W), accG = new Float32Array(rowsMax * W), accB = new Float32Array(rowsMax * W), wsum = new Float32Array(rowsMax * W);
  const rowOf = new Int32Array(ext), shiftedRow = new Int32Array(ext), colOf = new Int32Array(W);
  for (let y0 = 0; y0 < H; y0 += BAND) {
    const rows = Math.min(BAND, H - y0), er = rows + 2 * PATCH;
    accR.fill(0); accG.fill(0); accB.fill(0); wsum.fill(0);
    for (let k = 0; k < er; k++) rowOf[k] = clamp(y0 - PATCH + k, H);
    for (let dy = -SEARCH; dy <= SEARCH; dy++) {
      for (let k = 0; k < er; k++) shiftedRow[k] = clamp(rowOf[k] + dy, H);
      for (let dx = -SEARCH; dx <= SEARCH; dx++) {
        for (let x = 0; x < W; x++) colOf[x] = clamp(x + dx, W);
        // Squared luminance difference to the shifted image, then its sum over the patch: along rows, then columns.
        for (let k = 0; k < er; k++) {
          const a = rowOf[k] * W, b = shiftedRow[k] * W, o = k * W;
          for (let x = 0; x < W; x++) { const d = Y[a + x] - Y[b + colOf[x]]; D[o + x] = d * d; }
          let s = 0;
          for (let x = -PATCH; x <= PATCH; x++) s += D[o + clamp(x, W)];
          for (let x = 0; x < W; x++) { T[o + x] = s; s += D[o + clamp(x + PATCH + 1, W)] - D[o + clamp(x - PATCH, W)]; }
        }
        col.fill(0);
        for (let k = 0; k < 2 * PATCH; k++) { const o = k * W; for (let x = 0; x < W; x++) col[x] += T[o + x]; }
        for (let r = 0; r < rows; r++) {
          const add = (r + 2 * PATCH) * W, sub = r * W, o = r * W, src = shiftedRow[r + PATCH] * W;
          for (let x = 0; x < W; x++) {
            const sum = col[x] + T[add + x];
            col[x] = sum - T[sub + x];
            const k = sum * scale;
            if (k >= LUT_SIZE) continue;
            const w = lut[k | 0], p = (src + colOf[x]) * 4;
            accR[o + x] += w * base[p]; accG[o + x] += w * base[p + 1]; accB[o + x] += w * base[p + 2]; wsum[o + x] += w;
          }
        }
      }
    }
    for (let r = 0; r < rows; r++) {
      for (let x = 0; x < W; x++) {
        const i = r * W + x, p = ((y0 + r) * W + x) * 4, w = wsum[i];
        out[p] = accR[i] / w; out[p + 1] = accG[i] / w; out[p + 2] = accB[i] / w; // a clamped array rounds to nearest
      }
    }
    progress((y0 + rows) / H);
  }
}

/**
 * Step 3: where the cleaned image has an edge, `base` returns. The edge strength is the luminance gradient of the cleaned
 * image (so the web that was averaged away doesn't count), spread to the pixels on both sides of the edge and smoothed.
 * A and B are the only two scratch images: A is the luminance buffer, reused.
 */
function keepEdges(base, out, A, W, H, keep, full) {
  const N = W * H, B = new Float32Array(N);
  luminance(out, A);
  for (let y = 0; y < H; y++) {
    const up = clamp(y - 1, H) * W, mid = y * W, down = clamp(y + 1, H) * W;
    for (let x = 0; x < W; x++) {
      const l = clamp(x - 1, W), r = clamp(x + 1, W);
      const gx = A[up + r] + 2 * A[mid + r] + A[down + r] - A[up + l] - 2 * A[mid + l] - A[down + l];
      const gy = A[down + l] + 2 * A[down + x] + A[down + r] - A[up + l] - 2 * A[up + x] - A[up + r];
      B[mid + x] = Math.sqrt(gx * gx + gy * gy) / 8;
    }
  }
  // Largest value in each 3×3 neighbourhood: along rows into A, along columns back into B.
  for (let y = 0; y < H; y++) {
    const o = y * W;
    for (let x = 0; x < W; x++) A[o + x] = Math.max(B[o + clamp(x - 1, W)], B[o + x], B[o + clamp(x + 1, W)]);
  }
  for (let y = 0; y < H; y++) {
    const up = clamp(y - 1, H) * W, mid = y * W, down = clamp(y + 1, H) * W;
    for (let x = 0; x < W; x++) B[mid + x] = Math.max(A[up + x], A[mid + x], A[down + x]);
  }
  // Mean over 5×5, the same way.
  const R = 2, size = 2 * R + 1;
  for (let y = 0; y < H; y++) {
    const o = y * W; let s = 0;
    for (let x = -R; x <= R; x++) s += B[o + clamp(x, W)];
    for (let x = 0; x < W; x++) { A[o + x] = s / size; s += B[o + clamp(x + R + 1, W)] - B[o + clamp(x - R, W)]; }
  }
  const col = new Float32Array(W);
  for (let y = -R; y <= R; y++) { const o = clamp(y, H) * W; for (let x = 0; x < W; x++) col[x] += A[o + x]; }
  const range = full - keep;
  for (let y = 0; y < H; y++) {
    const add = clamp(y + R + 1, H) * W, sub = clamp(y - R, H) * W;
    for (let x = 0; x < W; x++) {
      const m = (col[x] / size - keep) / range, p = (y * W + x) * 4;
      col[x] += A[add + x] - A[sub + x];
      if (m <= 0) continue;
      if (m >= 1) { out[p] = base[p]; out[p + 1] = base[p + 1]; out[p + 2] = base[p + 2]; continue; }
      out[p] += m * (base[p] - out[p]); out[p + 1] += m * (base[p + 1] - out[p + 1]); out[p + 2] += m * (base[p + 2] - out[p + 2]);
    }
  }
}
