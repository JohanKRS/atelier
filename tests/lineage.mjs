import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { versionNumbers, buildTree, branches, forkDepth, validSize, editSize, aspectOf, upscaleSizes } from '../assets/lineage.js';

const node = (id, parent, created) => ({ id, parent, created });
// a ─ b ─ c       (linear edits stay on one line)
//       └ d ─ e   (a second edit of b forks)
const nodes = [node('c', 'b', 3), node('a', null, 1), node('e', 'd', 5), node('b', 'a', 2), node('d', 'b', 4)];
assert.deepEqual([...versionNumbers(nodes)], [['a', 1], ['b', 2], ['c', 3], ['d', 4], ['e', 5]]);

const pending = [{ id: 'j1', parent_id: 'e', created: 6 }, { id: 'j2', parent_id: 'gone', created: 7 }];
const { roots, children } = buildTree(nodes, pending);
assert.deepEqual(roots.map(r => r.node.id), ['a']);
assert.deepEqual(children.get('b').map(c => c.node.id), ['c', 'd']);
assert.deepEqual(children.get('e').map(c => c.job.id), ['j1'], 'a running edit hangs under the version it edits');
assert.equal([...children.values()].flat().some(c => c.job?.id === 'j2'), false, 'a request for another lineage is left out');

const chain = branches(roots[0], children);
assert.deepEqual(chain.map(step => step.entry.node.id), ['a', 'b'], 'single children continue the same line');
assert.deepEqual(chain[1].forks.map(fork => fork.map(step => step.entry.node?.id ?? step.entry.job.id)), [['c'], ['d', 'e', 'j1']]);

// Levels of forks, which is how far the deepest version steps in: a straight line of edits has none.
assert.equal(forkDepth(chain), 1);
assert.equal(forkDepth(branches({ node: node('x', null, 1) }, new Map())), 0);
{
  // Four rounds of two variations each, every round made from the first image of the one before.
  const deep = [node('r', null, 0)];
  for (let level = 1, parent = 'r'; level <= 4; level++) { deep.push(node(`${level}a`, parent, level * 2), node(`${level}b`, parent, level * 2 + 1)); parent = `${level}a`; }
  const tree = buildTree(deep);
  assert.equal(forkDepth(branches(tree.roots[0], tree.children)), 4);
}

// A version whose parent was deleted becomes a root of its own.
assert.deepEqual(buildTree([node('x', 'missing', 1), node('y', 'x', 2)]).roots.map(r => r.node.id), ['x']);

assert.equal(validSize(1536, 1024), true);
assert.equal(validSize(1000, 1000), false, 'multiples of 16 only');
assert.equal(validSize(3840, 1024), false, 'ratio above 3:1');
assert.equal(validSize(512, 512), false, 'too few pixels');
assert.equal(editSize(1536, 864, true), '1536x864', 'a valid size is kept');
assert.equal(editSize(4000, 3000, true), '3312x2480', 'too large: scaled down keeping proportions');
assert.equal(validSize(...editSize(4000, 3000, true).split('x').map(Number)), true);
assert.equal(validSize(...editSize(640, 480, true).split('x').map(Number)), true, 'too small: scaled up');
assert.equal(editSize(1000, 100, true), 'auto', 'beyond 3:1 the model picks the size');
assert.equal(editSize(1920, 1080, false), '1536x1024', 'fixed-size models get the closest shape');
assert.equal(editSize(900, 1600, false), '1024x1536');
assert.equal(editSize(0, 0, true), 'auto');
assert.equal(aspectOf('1536x1024'), 1.5);
assert.equal(aspectOf('auto', 0.75), 0.75);
const sizes = (w, h) => upscaleSizes(w, h).map(s => `${s.label} ${s.width}x${s.height}`);
assert.deepEqual(sizes(1024, 1024), ['1,5× 1536x1536', '2× 2048x2048', 'Máxima 2880x2880']);
assert.deepEqual(sizes(1536, 864), ['1,5× 2304x1296', '2× 3072x1728', 'Máxima 3840x2160']);
assert.deepEqual(sizes(512, 512), ['2× 1024x1024', '3× 1536x1536', 'Máxima 2880x2880'], 'sizes below the API minimum are skipped');
for (const [w, h] of [[2048, 1536], [1000, 700], [3328, 1872]]) for (const s of upscaleSizes(w, h)) assert.equal(validSize(s.width, s.height), true);
assert.deepEqual(upscaleSizes(3840, 2160), [], 'already at the maximum');
assert.deepEqual(upscaleSizes(1000, 100), [], 'beyond 3:1');
console.log('PASS upscale sizes: proportions kept, API limits respected, nothing offered past the maximum');
console.log('PASS version tree: numbering, forks, pending edits, orphans; edit sizes keep proportions within API limits');
