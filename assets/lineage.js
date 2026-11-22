import { t, number } from './i18n.js';
// Pure helpers for version trees and request sizes. No DOM access: covered by tests/lineage.mjs.

/** Version numbers by creation order inside one lineage: the original is v1. */
export function versionNumbers(nodes) {
  const sorted = [...nodes].sort((a, b) => a.created - b.created || 0);
  return new Map(sorted.map((n, i) => [n.id, i + 1]));
}

/**
 * Children lists for a lineage. Pending requests hang under the version they edit.
 * Returns { roots, children } where children maps an id to an array of entries
 * ({ node } for saved versions, { job } for requests still running).
 */
export function buildTree(nodes, pending = []) {
  const ids = new Set(nodes.map(n => n.id));
  const children = new Map();
  const roots = [];
  const add = (parent, entry) => { if (!children.has(parent)) children.set(parent, []); children.get(parent).push(entry); };
  for (const node of [...nodes].sort((a, b) => a.created - b.created)) {
    if (node.parent && ids.has(node.parent)) add(node.parent, { node });
    else roots.push({ node });
  }
  for (const job of [...pending].sort((a, b) => a.created - b.created)) if (ids.has(job.parent_id)) add(job.parent_id, { job });
  return { roots, children };
}

/**
 * Splits a tree into branches for display: a version with exactly one child continues
 * on the same line; two or more children start indented forks. Deep linear edit
 * histories therefore stay compact.
 */
export function branches(entry, children) {
  const chain = [];
  let current = entry;
  while (current) {
    const kids = current.node ? (children.get(current.node.id) || []) : [];
    if (kids.length === 1) { chain.push({ entry: current, forks: [] }); current = kids[0]; continue; }
    chain.push({ entry: current, forks: kids.map(kid => branches(kid, children)) });
    current = null;
  }
  return chain;
}

/** How many levels of forks a branch (as returned by `branches`) nests: 0 for a straight line of edits. */
export function forkDepth(chain) {
  return Math.max(0, ...chain.map(({ forks }) => (forks.length ? 1 + Math.max(...forks.map(forkDepth)) : 0)));
}

export const FIXED_SIZES = ['1024x1024', '1024x1536', '1536x1024'];

/** Same limits the server enforces for flexible models. */
export function validSize(width, height) {
  if (![width, height].every(Number.isInteger) || width % 16 || height % 16) return false;
  const area = width * height;
  return Math.max(width, height) <= 3840 && Math.max(width, height) / Math.min(width, height) <= 3 && area >= 655360 && area <= 8294400;
}

/** Output size for editing a width × height image: keep its proportions when the model allows. */
export function editSize(width, height, flexible) {
  if (!width || !height) return 'auto';
  if (!flexible) {
    const ratio = width / height;
    return FIXED_SIZES.reduce((best, size) => {
      const [w, h] = size.split('x').map(Number);
      return Math.abs(Math.log(w / h / ratio)) < Math.abs(Math.log(best[0] / best[1] / ratio)) ? [w, h] : best;
    }, [1024, 1024]).join('x');
  }
  if (validSize(width, height)) return `${width}x${height}`;
  const ratio = width / height;
  if (ratio > 3 || ratio < 1 / 3) return 'auto';
  let scale = 1;
  const area = width * height;
  if (area > 8294400) scale = Math.sqrt(8294400 / area);
  if (area < 655360) scale = Math.sqrt(655360 / area);
  if (Math.max(width, height) * scale > 3840) scale = 3840 / Math.max(width, height);
  for (const round of [Math.round, Math.floor, Math.ceil]) {
    const w = round(width * scale / 16) * 16, h = round(height * scale / 16) * 16;
    if (validSize(w, h)) return `${w}x${h}`;
  }
  return 'auto';
}

/** Width / height of a request size, or the fallback for automatic sizes. */
export function aspectOf(size, fallback = 1) {
  const match = /^(\d+)x(\d+)$/.exec(size || '');
  return match ? Number(match[1]) / Number(match[2]) : fallback;
}

const MAX_AREA = 8294400, MAX_SIDE = 3840;

/**
 * Larger output sizes for upscaling a width × height image, keeping its proportions within the API limits.
 * Returns [{ label, width, height }], smallest first; empty when the image can't get any larger.
 */
export function upscaleSizes(width, height) {
  if (!width || !height || width / height > 3 || height / width > 3) return [];
  const limit = Math.min(Math.sqrt(MAX_AREA / (width * height)), MAX_SIDE / Math.max(width, height));
  const fit = scale => {
    for (const round of [Math.round, Math.floor]) {
      const w = round(width * scale / 16) * 16, h = round(height * scale / 16) * 16;
      if (validSize(w, h)) return [w, h];
    }
    return null;
  };
  const sizes = [];
  for (const [label, scale] of [[`${number(1.5)}×`, 1.5], ['2×', 2], ['3×', 3], [t('Maximum'), limit]]) {
    if (scale > limit + 1e-9) continue;
    const size = fit(scale);
    // Only sizes clearly larger than the original are worth a paid request.
    if (!size || size[0] * size[1] < width * height * 1.2 || sizes.some(s => s.width === size[0] && s.height === size[1])) continue;
    sizes.push({ label, width: size[0], height: size[1] });
  }
  return sizes;
}
