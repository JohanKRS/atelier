// A visual novel project (a story of kind vn), inside the story page: the tree of choices, the editor of a scene (its
// script, its exit and its gameplay notes), the art (sprites, backgrounds, CGs), the routes and endings, the variables
// and the audio, the gameplay notes, a playable preview, the person's documents brought in and the game for Ren'Py.
// stories.js hands in the story, its requests and the chat; the server (Vn.php) checks everything and keeps it.
// Text from a person or a model is only ever set as text: never through innerHTML.
import { t, number } from './i18n.js';

const SVGNS = 'http://www.w3.org/2000/svg';
const ACTIVE = new Set(['queued', 'running', 'cancelling']);
const EXIT_TYPES = [['none', t('No exit')], ['jump', t('Go to')], ['choice', t('Choice')], ['branch', t('Branch')], ['end', t('Ending')]];
const ENDING_KINDS = [['good', t('Good', { ctx: 'ending' })], ['normal', t('Normal')], ['bad', t('Bad', { ctx: 'ending' })], ['true', t('True', { ctx: 'ending' })], ['secret', t('Secret', { ctx: 'ending' })]];
const ENDING_LABEL = Object.fromEntries(ENDING_KINDS);
const FRAMINGS = [['full', t('Full body')], ['knees', t('Down to the knees')], ['waist', t('Down to the waist')]];
const ASPECTS = ['16:9', '4:3', '3:2'];
const VAR_TYPES = [['flag', t('Yes/no')], ['number', t('Number')], ['text', t('Text')]];
const POSITIONS = { left: 18, centerleft: 34, center: 50, centerright: 66, right: 82 };
const AUTO_SLOTS = [['center'], ['centerleft', 'centerright'], ['left', 'center', 'right']];
const VIEWS = [['tree', t('Tree'), 'branch'], ['art', t('Art'), 'image'], ['routes', t('Routes and endings'), 'star'], ['config', t('Variables and game'), 'sliders'], ['gameplay', t('Gameplay'), 'list'], ['play', t('Play', { ctx: 'game' }), 'play']];
const IMPORT_TARGETS = [['notes', t('Design document (bible)')], ['chapter', t('Chapter (base)')], ['character', t('Character sheet')], ['place', t('Place sheet')], ['gameplay', t('Gameplay notes')], ['plan', t('Planning')]];
const W = 212, H = 80, COL = 296, ROW = 116, BAND = 90;

export const SCRIPT_HELP = [
  [t('Ana: Hi!'), t('a line, with the name as on the sheet')],
  [t('Ana (happy): Hi!'), t('a line that changes the sprite\'s expression first')],
  [t('> Narration'), t('narration (or any line without “Name:”)')],
  [t('@bg Central Café, night, fade'), t('background: place, variant and transition')],
  [t('@show Ana, happy, left'), t('shows or changes the sprite (left, centerleft, center, centerright, right)')],
  ['@hide Ana', t('takes it off the screen')],
  [t('@cg first_kiss'), t('full-screen CG, until the next @bg')],
  [t('@music cafe_theme, fade 2'), t('music (or @music stop)')],
  [t('@sound door'), t('sound effect')],
  [t('@set ana_affinity += 1'), t('changes a variable (=, += or -=)')],
  [t('@if ana_affinity >= 2: Ana: Great!'), t('the step only happens if the condition holds')],
  ['@pause 1.5 · @effect shake · @effect flash', t('pause, shake, flash')],
  [t('# note for the team'), t('the player does not see it')],
];

// ---------------------------------------------------------------------------------------------------------------------
// What the server does, again here for the tree and the preview (Vn.php: slug, attrs, spriteFor, backgroundFor,
// evaluate, apply)
// ---------------------------------------------------------------------------------------------------------------------
export function slug(text, max = 40) {
  let t = String(text ?? '').trim().toLowerCase().replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/ß/g, 'ss').normalize('NFD').replace(/[̀-ͯ]/g, '');
  t = t.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, max).replace(/_+$/, '');
  if (!t) return 'x';
  return /^\d/.test(t) ? `x${t}` : t;
}
export const attrs = text => String(text ?? '').toLowerCase().replace(/\s+/g, ' ').trim().slice(0, 60);
export function findCast(cast, name, kind = null) {
  const which = String(name ?? '').trim(), low = which.toLowerCase().replace(/\s+/g, ' ');
  const c = cast.find(x => x.id === which || x.name.toLowerCase() === low);
  return c && (kind === null || c.kind === kind) ? c : null;
}
export function spriteFor(sprites, characterId, wanted, outfit = '') {
  const mine = sprites.filter(s => s.character === characterId);
  if (!mine.length) return null;
  const words = attrs(wanted).split(' ').filter(Boolean);
  if (!words.length) return mine.find(s => s.outfit === outfit) || mine[0];
  const want = [...words].sort().join(' ');
  for (const s of mine) if ([s.outfit, s.expression].filter(Boolean).sort().join(' ') === want) return s;
  if (words.length === 1) return mine.find(s => s.expression === words[0] && s.outfit === outfit) || mine.find(s => s.expression === words[0]) || mine.find(s => s.outfit === words[0]) || null;
  return null;
}
export function backgroundFor(backgrounds, placeId, name, variant) {
  const v = attrs(variant);
  const mine = backgrounds.filter(b => (placeId && b.place === placeId) || slug(b.name) === slug(name));
  if (!mine.length) return null;
  return mine.find(b => b.variant === v) || (v === '' ? mine[0] : null);
}
export const truthy = v => typeof v === 'string' ? v !== '' : Boolean(v);
export function evaluate(t, vars) {
  switch (t[0]) {
    case 'var': return vars[t[1]] ?? false;
    case 'num': case 'str': case 'bool': return t[1];
    case 'not': return !truthy(evaluate(t[1], vars));
    case 'and': return truthy(evaluate(t[1], vars)) && truthy(evaluate(t[2], vars));
    case 'or': return truthy(evaluate(t[1], vars)) || truthy(evaluate(t[2], vars));
    case 'cmp': {
      let a = evaluate(t[2], vars), b = evaluate(t[3], vars);
      if (typeof a === 'boolean' || typeof b === 'boolean') { a = typeof a === 'boolean' ? Number(a) : a; b = typeof b === 'boolean' ? Number(b) : b; }
      if (!(typeof a === 'number' && typeof b === 'number')) { a = String(a); b = String(b); }
      return { '==': a === b, '!=': a !== b, '<': a < b, '<=': a <= b, '>': a > b, '>=': a >= b }[t[1]] ?? false;
    }
  }
  return false;
}
export function apply(set, vars) {
  const value = evaluate(set.value, vars), out = { ...vars };
  if (set.op === '=') { out[set.var] = value; return out; }
  const now = Number(out[set.var]) || 0, n = Number(value) || 0;
  out[set.var] = set.op === '+=' ? now + n : now - n;
  return out;
}
export function targets(exit) {
  const out = [];
  if (exit.to) out.push(exit.to);
  for (const o of [...(exit.options || []), ...(exit.cases || [])]) out.push(o.to);
  if (exit.else) out.push(exit.else);
  return [...new Set(out)];
}
/**
 * Where each scene goes on the tree when the person hasn't placed it: in columns by the number of steps from the start
 * (each column in the order of the ones that lead to it), then each part the start doesn't reach, in a band below.
 */
export function layout(scenes, start) {
  const by = new Map(scenes.map(s => [s.label, s])), parents = new Map(), placed = new Map(), done = new Set();
  for (const s of scenes) for (const to of targets(s.exit)) if (by.has(to)) { if (!parents.has(to)) parents.set(to, []); parents.get(to).push(s.label); }
  let top = 0;
  for (const root of [start, ...scenes.map(s => s.label)]) {
    if (!by.has(root) || done.has(root)) continue;
    const cols = [], queue = [[root, 0]];
    done.add(root);
    while (queue.length) {
      const [l, d] = queue.shift();
      (cols[d] ||= []).push(l);
      for (const to of targets(by.get(l).exit)) if (by.has(to) && !done.has(to)) { done.add(to); queue.push([to, d + 1]); }
    }
    for (let d = 1; d < cols.length; d++) {
      const prev = new Map(cols[d - 1].map((l, i) => [l, i]));
      const score = l => { const ps = (parents.get(l) || []).filter(p => prev.has(p)); return ps.length ? ps.reduce((a, p) => a + prev.get(p), 0) / ps.length : 1e9; };
      cols[d] = cols[d].map((l, i) => [l, score(l), i]).sort((a, b) => a[1] - b[1] || a[2] - b[2]).map(x => x[0]);
    }
    const tallest = Math.max(...cols.map(c => c.length));
    cols.forEach((c, d) => c.forEach((l, i) => placed.set(l, { x: d * COL, y: top + (i + (tallest - c.length) / 2) * ROW })));
    top += tallest * ROW + BAND;
  }
  return placed;
}
/** The expression of a sprite when none is given. */
const NEUTRAL = t('neutral', { ctx: 'expression' });
/** "uniform happy" → { outfit: 'uniform', expression: 'happy' }, with the outfits the character already has as a guide. */
export function splitAttrs(text, known = []) {
  const words = attrs(text).replace('(base)', '').split(' ').filter(Boolean);
  if (!words.length) return { outfit: '', expression: NEUTRAL };
  if (words.length === 1) return known.includes(words[0]) ? { outfit: words[0], expression: NEUTRAL } : { outfit: '', expression: words[0] };
  const outfit = words.find(w => known.includes(w)) ?? words[0];
  return { outfit, expression: words.filter(w => w !== outfit).join('_') };
}

export function createVn(ctx) {
  const { api, h, icon, toast, confirmAction, askName, imageUrl, openViewer, trackJob, launchQueued, formatUsd, plural, dateTime, $ } = ctx;
  const fill = (el, ...children) => el.replaceChildren(...children.flat().filter(c => c !== null && c !== undefined && c !== false));
  const svg = (tag, attributes = {}, ...children) => {
    const el = document.createElementNS(SVGNS, tag);
    for (const [k, val] of Object.entries(attributes)) if (val !== null && val !== undefined && val !== false) el.setAttribute(k, val);
    for (const c of children.flat()) if (c) el.append(c);
    return el;
  };
  const num = n => number(n || 0);
  const v = {
    view: 'tree', scene: null, draft: null, dirty: false, saving: false, conflict: false, lint: null, lintTimer: null, lintToken: 0,
    tree: { x: 40, y: 40, z: 1, route: '', problems: false, fitted: null }, drag: null, pointers: new Map(), lastPointer: 0,
    root: null, rootKey: '', stale: false, play: null, art: { forms: null }, importing: null,
  };
  const story = () => ctx.story();
  const data = () => story()?.vn || null;
  const cast = () => story()?.cast?.characters || [];
  const sceneBy = which => data()?.scenes.find(s => s.id === which || s.label === which) || null;
  const routeOf = id => data()?.routes.find(r => r.id === id) || null;
  const problemsOf = label => (data()?.analysis.problems || []).filter(p => p.scene === label);
  const by = who => who === 'agent' ? t('by the writer') : who === 'mcp' ? t('by an assistant via MCP') : who === 'person' ? t('by you') : '';

  // -----------------------------------------------------------------------------------------------------------------
  // The pane
  // -----------------------------------------------------------------------------------------------------------------
  /** The views' tabs, over the project (and over a chapter's base when one is open). */
  function bar() {
    const a = data()?.analysis, errors = (a?.problems || []).filter(p => p.level === 'error').length;
    return h('nav', { class: 'vn-bar', 'aria-label': t('Parts of the project') },
      ...VIEWS.map(([id, label, ic]) => h('button', { type: 'button', class: 'vn-tab', 'aria-pressed': String(v.view === id || (id === 'tree' && v.view === 'scene')),
        onclick: () => open(id) }, icon(ic), h('span', { text: label }), id === 'tree' && errors ? h('span', { class: 'vn-count error', text: String(errors), title: plural(errors, t('{n} error'), t('{n} errors')) }) : null)));
  }
  /** The pane for the view: built when the view changes, updated in place when the project changes. */
  function render(doc, { force = false } = {}) {
    if (!data()) { fill(doc, h('p', { class: 'hint', text: t('Loading the project…') })); return; }
    const key = `${story().id}:${v.view}:${v.scene || ''}`;
    if (!force && v.root && v.root.isConnected && v.root.parentNode === doc && v.rootKey === key) { update(); return; }
    v.rootKey = key; v.stale = false; v.sig = signature();
    const body = h('div', { class: `vn-body vn-${v.view}` });
    v.root = h('div', { class: 'vn-root', onfocusout: () => setTimeout(() => { if (v.stale && !busyTyping()) update(); }, 0) }, bar(), body);
    fill(doc, v.root);
    ({ tree: buildTree, scene: buildScene, art: buildArt, routes: buildRoutes, config: buildConfig, gameplay: buildGameplay, play: buildPlay })[v.view]?.(body);
  }
  /** What a redraw of the view depends on: the project as the server sent it (two saves in the same second leave the
   *  story's time the same, so its contents count, hashed once per copy) and the art's requests. */
  const hashes = new WeakMap();
  const hashOf = d => {
    if (!d) return '';
    let x = hashes.get(d);
    if (x === undefined) { const t = JSON.stringify(d); let a = 2166136261; for (let i = 0; i < t.length; i++) a = Math.imul(a ^ t.charCodeAt(i), 16777619); x = `${t.length}.${(a >>> 0).toString(36)}`; hashes.set(d, x); }
    return x;
  };
  const signature = () => `${story()?.updated}|${hashOf(data())}|${[...(ctx.pending()?.values() || [])].filter(j => j.for === 'vn').map(j => `${j.id}:${j.status}`).join(',')}`;
  const busyTyping = () => { const a = document.activeElement; return Boolean(a && v.root?.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)); };
  /** The project changed (the writer, a picture that came, a save): the view shows it, without losing what is being typed. */
  function update() {
    if (!v.root?.isConnected || !data()) return;
    const sig = signature();
    if (sig === v.sig && !v.stale) return;
    if (busyTyping() && !['scene', 'play', 'art', 'tree'].includes(v.view)) { v.stale = true; return; }
    v.sig = sig;
    const old = v.root.querySelector('.vn-bar'); if (old) old.replaceWith(bar());
    const body = v.root.querySelector('.vn-body');
    if (v.view === 'scene') { updateScene(body); return; }
    if (v.view === 'play') { playStale(); return; }
    if (v.view === 'art') { updateArt(); return; }
    if (v.view === 'tree') { if (!v.drag) drawTree(); else v.sig = ''; return; }
    v.stale = false;
    ({ routes: buildRoutes, config: buildConfig, gameplay: buildGameplay })[v.view]?.(body);
  }
  function refresh() { const doc = $('#story-doc'); if (doc && v.view !== 'chapter' && story()?.kind === 'vn') render(doc); }
  /** Opens a view (with a scene: its editor). */
  function open(view, scene = null) {
    if (v.view === 'scene' && v.dirty && (view !== 'scene' || scene !== v.scene) && !window.confirm(t('Discard the changes to this scene?'))) return false;
    if (v.view === 'play' && view !== 'play') stopPlay();
    v.view = view; v.scene = view === 'scene' ? scene : null;
    if (view !== 'scene') { v.draft = null; v.dirty = false; v.conflict = false; }
    ctx.tab('doc');
    ctx.rendered();
    return true;
  }
  function reset() { stopPlay(); Object.assign(v, { view: 'tree', scene: null, draft: null, dirty: false, conflict: false, root: null, rootKey: '', lint: null, stale: false }); v.tree.fitted = null; v.art.forms = null; }

  // -----------------------------------------------------------------------------------------------------------------
  // The tree of choices
  // -----------------------------------------------------------------------------------------------------------------
  const treeUi = { canvas: null, world: null, edges: null, nodes: null, panel: null, positions: new Map(), elements: new Map() };
  function buildTree(body) {
    const d = data(), a = d.analysis;
    const counts = { error: 0, warn: 0, info: 0 };
    for (const p of a.problems) counts[p.level]++;
    treeUi.canvas = h('div', { class: 'vn-canvas', tabindex: 0, 'aria-label': t('Choice tree: drag to move, Ctrl + wheel to zoom') });
    treeUi.world = h('div', { class: 'vn-world' });
    treeUi.edges = svg('svg', { class: 'vn-edges', 'aria-hidden': 'true' });
    treeUi.nodes = h('div', { class: 'vn-nodes' });
    treeUi.world.append(treeUi.edges, treeUi.nodes);
    treeUi.canvas.append(treeUi.world);
    treeUi.panel = h('aside', { class: 'vn-problems', hidden: !v.tree.problems });
    const routeSelect = h('select', { 'aria-label': t('Highlight a route'), onchange: e => { v.tree.route = e.target.value; drawTree(); } },
      h('option', { value: '', text: t('All routes') }), ...d.routes.map(r => h('option', { value: r.id, text: r.name, selected: v.tree.route === r.id })));
    fill(body,
      h('div', { class: 'vn-toolbar' },
        h('button', { type: 'button', class: 'btn primary small', onclick: () => newScene() }, icon('plus'), h('span', { text: t('Scene') })),
        d.routes.length ? routeSelect : null,
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: `btn ghost small vn-problems-toggle${counts.error ? ' error' : counts.warn ? ' warn' : ''}`, 'aria-pressed': String(v.tree.problems),
          title: t('The project\'s errors and warnings: scenes with no exit, endings nobody reaches, choices that trap the player, missing art'),
          onclick: () => { v.tree.problems = !v.tree.problems; treeUi.panel.hidden = !v.tree.problems; drawProblems(); body.querySelector('.vn-problems-toggle').setAttribute('aria-pressed', String(v.tree.problems)); } },
          icon('alert'), h('span', { text: counts.error || counts.warn ? `${counts.error ? plural(counts.error, t('{n} error'), t('{n} errors')) : ''}${counts.error && counts.warn ? ' · ' : ''}${counts.warn ? plural(counts.warn, t('{n} warning'), t('{n} warnings')) : ''}` : t('No warnings') })),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Zoom out'), 'aria-label': t('Zoom out'), onclick: () => zoomBy(1 / 1.25) }, h('span', { class: 'vn-zoom-sign', text: '−' })),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Zoom in'), 'aria-label': t('Zoom in'), onclick: () => zoomBy(1.25) }, icon('plus')),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Show the whole tree'), 'aria-label': t('Show the whole tree'), onclick: () => fit() }, icon('expand')),
        h('button', { type: 'button', class: 'btn ghost small', title: t('Arrange the scenes in columns from the starting scene (undoes what you dragged)'), onclick: arrange }, icon('sort'), h('span', { text: t('Arrange') })),
        h('button', { type: 'button', class: 'btn ghost small', title: t('Ask the writer to check and fix the tree'), onclick: () => ctx.prefillChat(t('Check the choice tree (Atelier\'s errors and warnings) and fix what is needed, without changing the story already written. ')) }, icon('feather'), h('span', { text: t('Writer') }))),
      h('div', { class: 'vn-stage-wrap' }, treeUi.canvas, treeUi.panel));
    bindCanvas(treeUi.canvas);
    drawTree();
    drawProblems();
    if (v.tree.fitted !== story().id) { v.tree.fitted = story().id; requestAnimationFrame(() => fit()); }
  }
  function drawProblems() {
    const panel = treeUi.panel; if (!panel || panel.hidden) return;
    const a = data().analysis;
    const played = a.played ? (a.played.truncated ? t('Atelier played {n} combinations of choices and variables and stopped there: the project is too big to test them all.', { n: num(a.played.states) }) : t('Atelier played every path ({n} combinations of choices and variables).', { n: num(a.played.states) })) : '';
    fill(panel,
      h('div', { class: 'vn-problems-head' }, h('strong', { text: t('Errors and warnings') }), h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'icon-btn tiny', 'aria-label': t('Close'), title: t('Close'), onclick: () => { v.tree.problems = false; panel.hidden = true; v.root?.querySelector('.vn-problems-toggle')?.setAttribute('aria-pressed', 'false'); } }, icon('close'))),
      played ? h('p', { class: 'hint', text: played }) : null,
      a.problems.length ? h('ul', { class: 'vn-problem-list' }, ...a.problems.map(p => h('li', { class: p.level },
        icon(p.level === 'info' ? 'check' : 'alert'),
        h('span', {}, p.scene ? h('button', { type: 'button', class: 'link-btn vn-scene-link', text: p.scene, onclick: () => open('scene', sceneBy(p.scene)?.id || null) }) : null, p.scene ? ' ' : null, p.text)))) : h('p', { class: 'hint', text: t('Nothing to fix: every scene has an exit, every ending is reached and no choice traps the player.') }));
  }
  /** The nodes and the arrows, at the places kept (or worked out). */
  function drawTree() {
    if (!treeUi.nodes?.isConnected) return;
    const d = data(), a = d.analysis, auto = layout(d.scenes, a.start), labels = new Set(d.scenes.map(s => s.label));
    const reach = new Set(a.reachable), bad = new Map();
    for (const p of a.problems) if (p.scene && p.level !== 'info') bad.set(p.scene, bad.get(p.scene) === 'error' || p.level === 'error' ? 'error' : 'warn');
    treeUi.positions = new Map(); treeUi.elements = new Map();
    for (const s of d.scenes) treeUi.positions.set(s.id, s.pos ? { ...s.pos } : { ...(auto.get(s.label) || { x: 0, y: 0 }) });
    const nodes = d.scenes.map(s => {
      const p = treeUi.positions.get(s.id), route = routeOf(s.route), e = s.exit;
      const exitText = e.type === 'jump' ? `→ ${e.to}` : e.type === 'choice' ? `${t('Choice')} · ${plural(e.options.length, t('{n} option'), t('{n} options'))}` : e.type === 'branch' ? `${t('Branch')} · ${plural(e.cases.length + (e.else ? 1 : 0), t('{n} path'), t('{n} paths'))}`
        : e.type === 'end' ? t('Ending {kind}: {title}', { kind: ENDING_LABEL[e.kind] || '', title: e.title }) : t('No exit');
      const el = h('button', { type: 'button', class: `vn-node exit-${e.type}${bad.has(s.label) ? ` ${bad.get(s.label)}` : ''}${a.start && !reach.has(s.label) ? ' unreached' : ''}${v.tree.route && s.route !== v.tree.route ? ' dim' : ''}${s.label === a.start ? ' start' : ''}`,
        'data-id': s.id, title: `${s.title || s.label}${s.summary ? `\n${s.summary}` : ''}`,
        onclick: () => { if (Date.now() - v.lastPointer > 400) open('scene', s.id); } },
        h('span', { class: 'vn-node-top' },
          route ? h('span', { class: 'vn-dot', title: route.name }) : null,
          h('span', { class: 'vn-node-label', text: s.label }),
          s.label === a.start ? h('span', { class: 'vn-badge start', text: t('Start') }) : null,
          s.by === 'agent' ? h('span', { class: 'vn-by', title: t('Written by the writer'), text: 'E' }) : null),
        h('span', { class: 'vn-node-title', text: s.title || t('Untitled') }),
        h('span', { class: 'vn-node-exit', text: exitText }),
        h('span', { class: 'vn-node-words', text: s.words ? `${num(s.words)} palavras` : t('no script') }));
      if (route) el.querySelector('.vn-dot').style.background = route.color;
      el.style.left = `${p.x}px`; el.style.top = `${p.y}px`;
      treeUi.elements.set(s.id, el);
      return el;
    });
    // The scenes an exit leads to that don't exist yet, as ghosts to create.
    // Each goes in the next column, under the ones before it, in a place no scene or other ghost takes.
    const ghosts = new Map(), taken = [...treeUi.positions.values()];
    const free = at => !taken.some(q => Math.abs(q.x - at.x) < W + 12 && Math.abs(q.y - at.y) < H + 12);
    for (const s of d.scenes) for (const to of targets(s.exit)) if (!labels.has(to) && !ghosts.has(to)) {
      const p = treeUi.positions.get(s.id), at = { x: p.x + COL, y: p.y };
      for (let i = 0; i < 200 && !free(at); i++) at.y += H + 16;
      ghosts.set(to, at); taken.push(at);
      const el = h('div', { class: 'vn-ghost' }, h('span', { class: 'vn-node-label', text: to }), h('span', { class: 'muted', text: t('does not exist yet') }),
        h('button', { type: 'button', class: 'btn secondary small', onclick: e => { e.stopPropagation(); newScene({ label: to }); } }, icon('plus'), h('span', { text: t('Create') })));
      el.style.left = `${at.x}px`; el.style.top = `${at.y}px`;
      nodes.push(el);
    }
    treeUi.ghosts = ghosts;
    fill(treeUi.nodes, ...nodes);
    if (!d.scenes.length) treeUi.nodes.append(h('div', { class: 'vn-empty' }, icon('branch'), h('strong', { text: t('No scenes yet') }),
      h('p', { class: 'hint', text: t('Each scene is a node of the tree: its script and, at the end, an exit (go to another scene, a choice of the player, a branch by the variables or an ending). Create the first one, import your documents or ask the writer to build the tree from the design document.') }),
      h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn primary small', onclick: () => newScene() }, icon('plus'), h('span', { text: t('First scene') })),
        h('button', { type: 'button', class: 'btn secondary small', onclick: openImport }, icon('upload'), h('span', { text: t('Import documents') })))));
    drawEdges();
  }
  function drawEdges() {
    const d = data(), byLabel = new Map(d.scenes.map(s => [s.label, s])), out = [];
    const arrow = cls => svg('marker', { id: `vn-arrow-${cls}`, viewBox: '0 0 10 10', refX: '9', refY: '5', markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse' }, svg('path', { d: 'M0 0L10 5L0 10z', class: `vn-arrow ${cls}` }));
    out.push(svg('defs', {}, arrow('jump'), arrow('choice'), arrow('branch'), arrow('missing')));
    for (const s of d.scenes) {
      const from = treeUi.positions.get(s.id), e = s.exit;
      const links = e.type === 'jump' ? [{ to: e.to, text: '', cls: 'jump' }]
        : e.type === 'choice' ? e.options.map(o => ({ to: o.to, text: o.text, cls: 'choice', cond: o.if }))
        : e.type === 'branch' ? [...e.cases.map(c => ({ to: c.to, text: t('if {condition}', { condition: c.if }), cls: 'branch' })), ...(e.else ? [{ to: e.else, text: t('else'), cls: 'branch' }] : [])] : [];
      const seen = new Map();
      for (const l of links) {
        const target = byLabel.get(l.to), to = target ? treeUi.positions.get(target.id) : treeUi.ghosts?.get(l.to);
        if (!to) continue;
        const k = seen.get(l.to) || 0; seen.set(l.to, k + 1);
        const x1 = from.x + W, y1 = from.y + H / 2, x2 = to.x, y2 = to.y + H / 2;
        const self = target && target.id === s.id;
        const dx = Math.max(60, Math.abs(x2 - x1) / 2);
        const path = self ? `M${x1} ${y1} C${x1 + 70} ${y1 - 70}, ${from.x - 70} ${from.y - 70}, ${x2} ${y2}`
          : x2 > x1 ? `M${x1} ${y1} C${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`
          : `M${x1} ${y1} C${x1 + 120} ${y1 + 140}, ${x2 - 120} ${y2 + 140}, ${x2} ${y2}`;
        const cls = target ? l.cls : 'missing';
        const dim = v.tree.route && s.route !== v.tree.route ? ' dim' : '';
        out.push(svg('path', { d: path, class: `vn-edge ${cls}${l.cond ? ' cond' : ''}${dim}`, 'marker-end': `url(#vn-arrow-${cls})` }));
        if (l.text) {
          const mx = (x1 + x2) / 2, my = x2 > x1 ? (y1 + y2) / 2 + k * 14 : Math.max(y1, y2) + 105 + k * 14;
          const text = l.text.length > 30 ? `${l.text.slice(0, 29)}…` : l.text;
          out.push(svg('text', { x: String(mx), y: String(my - 5), class: `vn-edge-label ${cls}${dim}`, 'text-anchor': 'middle' }, document.createTextNode(text)));
        }
      }
    }
    fill(treeUi.edges, ...out);
    applyView();
  }
  function applyView() { if (treeUi.world) treeUi.world.style.transform = `translate(${v.tree.x}px, ${v.tree.y}px) scale(${v.tree.z})`; }
  function zoomAt(cx, cy, factor) {
    const r = treeUi.canvas.getBoundingClientRect(), z = Math.min(2.5, Math.max(0.15, v.tree.z * factor)), f = z / v.tree.z;
    const px = cx - r.left, py = cy - r.top;
    v.tree.x = px - (px - v.tree.x) * f; v.tree.y = py - (py - v.tree.y) * f; v.tree.z = z; applyView();
  }
  function zoomBy(factor) { const r = treeUi.canvas.getBoundingClientRect(); zoomAt(r.left + r.width / 2, r.top + r.height / 2, factor); }
  function fit() {
    if (!treeUi.canvas?.isConnected) return;
    const ps = [...treeUi.positions.values(), ...(treeUi.ghosts?.values() || [])];
    const r = treeUi.canvas.getBoundingClientRect();
    if (!ps.length || !r.width) { v.tree.x = 40; v.tree.y = 40; v.tree.z = 1; applyView(); return; }
    const minX = Math.min(...ps.map(p => p.x)), minY = Math.min(...ps.map(p => p.y)), maxX = Math.max(...ps.map(p => p.x)) + W, maxY = Math.max(...ps.map(p => p.y)) + H;
    const z = Math.min(1.2, Math.max(0.15, Math.min((r.width - 60) / (maxX - minX), (r.height - 60) / (maxY - minY))));
    v.tree.z = z; v.tree.x = (r.width - (maxX - minX) * z) / 2 - minX * z; v.tree.y = (r.height - (maxY - minY) * z) / 2 - minY * z; applyView();
  }
  function bindCanvas(canvas) {
    canvas.addEventListener('wheel', e => {
      e.preventDefault();
      if (e.ctrlKey || e.metaKey) zoomAt(e.clientX, e.clientY, Math.exp(-e.deltaY * 0.0022));
      else { v.tree.x -= e.deltaX; v.tree.y -= e.deltaY; applyView(); }
    }, { passive: false });
    canvas.addEventListener('pointerdown', e => {
      if (e.button !== 0 && e.pointerType === 'mouse') return;
      // Buttons inside the canvas (a ghost's Criar, the empty state's) keep their click, which pointer capture would take;
      // a scene's node (a button too) is dragged or opened here.
      if (!e.target.closest('.vn-node') && e.target.closest('button, a, input, select, textarea')) return;
      v.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (v.pointers.size === 2) { const [p, q] = [...v.pointers.values()]; v.drag = { pinch: Math.hypot(p.x - q.x, p.y - q.y), moved: true }; return; }
      const node = e.target.closest('.vn-node');
      v.drag = { id: node?.dataset.id || null, x: e.clientX, y: e.clientY, moved: false, ox: v.tree.x, oy: v.tree.y, pos: node ? { ...treeUi.positions.get(node.dataset.id) } : null };
      canvas.setPointerCapture(e.pointerId);
    });
    canvas.addEventListener('pointermove', e => {
      if (!v.drag || !v.pointers.has(e.pointerId)) return;
      v.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (v.drag.pinch) {
        const [p, q] = [...v.pointers.values()]; if (!q) return;
        const dist = Math.hypot(p.x - q.x, p.y - q.y);
        zoomAt((p.x + q.x) / 2, (p.y + q.y) / 2, dist / v.drag.pinch); v.drag.pinch = dist; return;
      }
      const dx = e.clientX - v.drag.x, dy = e.clientY - v.drag.y;
      if (!v.drag.moved && Math.hypot(dx, dy) < 5) return;
      v.drag.moved = true;
      if (v.drag.id) {
        const p = { x: Math.round(v.drag.pos.x + dx / v.tree.z), y: Math.round(v.drag.pos.y + dy / v.tree.z) };
        treeUi.positions.set(v.drag.id, p);
        const el = treeUi.elements.get(v.drag.id); if (el) { el.style.left = `${p.x}px`; el.style.top = `${p.y}px`; el.classList.add('dragging'); }
        drawEdges();
      } else { v.tree.x = v.drag.ox + dx; v.tree.y = v.drag.oy + dy; applyView(); }
    });
    const end = e => {
      v.pointers.delete(e.pointerId);
      const drag = v.drag; if (!drag) return;
      if (drag.pinch) { if (!v.pointers.size) v.drag = null; return; }
      v.drag = null; v.lastPointer = Date.now();
      if (e.type === 'pointercancel') { drawTree(); return; }
      if (drag.id && !drag.moved) { open('scene', drag.id); return; }
      if (drag.id && drag.moved) savePositions({ [drag.id]: treeUi.positions.get(drag.id) });
    };
    canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', end);
    canvas.addEventListener('keydown', e => {
      if (e.target !== canvas) return;
      const step = 60, moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
      if (moves[e.key]) { e.preventDefault(); v.tree.x += moves[e.key][0]; v.tree.y += moves[e.key][1]; applyView(); }
      else if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomBy(1.25); }
      else if (e.key === '-') { e.preventDefault(); zoomBy(1 / 1.25); }
      else if (e.key === '0') { e.preventDefault(); fit(); }
    });
  }
  async function savePositions(positions) {
    const s = story();
    for (const [id, pos] of Object.entries(positions)) { const sc = data().scenes.find(x => x.id === id); if (sc) sc.pos = pos; }
    try { await api('vn-scene', { story: s.id, mode: 'positions', positions }); }
    catch (e) { toast(e.message, { tone: 'error' }); ctx.reload(); }
  }
  async function arrange() {
    const d = data(); if (!d.scenes.some(s => s.pos)) { fit(); return; }
    const ok = await confirmAction({ title: t('Arrange the tree?'), message: t('The scenes go back to columns from the starting scene, and the places where you dragged each one are lost.'), confirm: t('Arrange') });
    if (!ok) return;
    const positions = Object.fromEntries(d.scenes.map(s => [s.id, null]));
    for (const s of d.scenes) s.pos = null;
    drawTree(); fit();
    try { await api('vn-scene', { story: story().id, mode: 'positions', positions }); } catch (e) { toast(e.message, { tone: 'error' }); }
  }
  /** A new scene (with a label an exit already leads to, or a title asked here), opened in the editor. */
  async function newScene({ label = '', chapter = undefined } = {}) {
    const title = label ? label.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase()) : await askName({ title: t('New scene'), label: t('Title of the scene'), confirm: t('Create'), maxLength: 200,
      message: t('The title becomes the scene\'s label (the name the exits use), and it can be changed later.') });
    if (!title) return;
    try {
      const { scene } = await api('vn-scene', { story: story().id, mode: 'add', fields: { title, ...(label ? { label } : {}), ...(chapter !== undefined ? { chapter } : {}) } });
      await ctx.reload();
      open('scene', scene.id);
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }

  // -----------------------------------------------------------------------------------------------------------------
  // A scene: its fields, its script (checked as it is typed), its exit and its gameplay notes
  // -----------------------------------------------------------------------------------------------------------------
  const draftOf = s => ({ id: s.id, rev: s.rev, label: s.label, title: s.title, chapter: s.chapter, route: s.route, summary: s.summary, notes: s.notes, script: s.script,
    exit: JSON.parse(JSON.stringify(s.exit, (k, val) => k === 'set' && Array.isArray(val) ? val.join('; ') : val)) });
  const sceneUi = { fields: null, exit: null, lint: null, banner: null, missing: null, save: null, meta: null };
  function buildScene(body) {
    const s = sceneBy(v.scene);
    if (!s) { v.view = 'tree'; v.scene = null; render($('#story-doc'), { force: true }); return; }
    if (!v.draft || v.draft.id !== s.id) { v.draft = draftOf(s); v.dirty = false; v.conflict = false; v.lint = null; }
    const d = data(), dr = v.draft, chapters = story().chapters;
    const changed = () => { const was = v.dirty; v.dirty = true; sceneUi.save.disabled = false; if (!was) drawSceneProblems(sceneBy(v.scene)); };
    const input = (field, attributes = {}) => {
      const el = h(attributes.rows ? 'textarea' : 'input', { ...attributes, oninput: e => { dr[field] = e.target.value; changed(); if (field === 'script') scheduleLint(); } });
      el.value = dr[field] ?? '';
      return el;
    };
    const script = input('script', { rows: 18, class: 'vn-script', spellcheck: 'true', 'aria-label': t('Script of the scene'), maxlength: 60000,
      placeholder: t('@bg Central Café, night\n@show Ana, happy, center\nAna: You came!\n> She smiles and pulls up a chair.') });
    script.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveScene(); } });
    sceneUi.script = script;
    sceneUi.banner = h('div', { class: 'vn-banner', hidden: true });
    sceneUi.lint = h('div', { class: 'vn-lint', 'aria-live': 'polite' });
    sceneUi.missing = h('div', { class: 'vn-scene-problems' });
    sceneUi.meta = h('p', { class: 'muted vn-scene-meta' });
    sceneUi.save = h('button', { type: 'button', class: 'btn primary small', disabled: !v.dirty, onclick: saveScene, title: t('Save (Ctrl+S)') }, icon('check'), h('span', { text: t('Save') }));
    sceneUi.exit = h('div', { class: 'vn-exit' });
    const chapterSelect = h('select', { 'aria-label': t('Chapter'), onchange: e => { dr.chapter = e.target.value || null; changed(); } },
      h('option', { value: '', text: t('No chapter') }), ...chapters.map((c, i) => h('option', { value: c.id, text: `${i + 1}. ${c.title || t('Untitled')}`, selected: dr.chapter === c.id })));
    const routeSelect = h('select', { 'aria-label': t('Route'), onchange: e => { dr.route = e.target.value || null; changed(); } },
      h('option', { value: '', text: t('No route') }), ...d.routes.map(r => h('option', { value: r.id, text: r.name, selected: dr.route === r.id })));
    const inserts = [[t('Speech'), `${cast().find(c => c.kind === 'character')?.name || t('Name')}: `], [t('Narration'), '> '], [t('Background', { ctx: 'vn' }), '@bg '], [t('Show'), '@show '], [t('Remove', { ctx: 'take' }), '@hide '], ['CG', '@cg '],
      [t('Music'), '@music '], [t('Sound'), '@sound '], [t('Variable'), '@set '], [t('If…'), '@if '], [t('Note'), '# ']];
    const people = cast().filter(c => c.kind === 'character');
    fill(body,
      h('div', { class: 'vn-toolbar' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => open('tree') }, icon('back'), h('span', { text: t('Tree') })),
        h('span', { class: 'vn-scene-n', text: t('Scene {n}', { n: s.number ?? '' }) }),
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'btn ghost small', title: t('Play from this scene (what is saved)'), onclick: () => { if (!v.dirty || window.confirm(t('The preview uses what is saved. Play anyway?'))) { v.dirty = false; startPlayFrom(s.label); } } }, icon('play'), h('span', { text: t('Test') })),
        h('button', { type: 'button', class: 'btn ghost small', title: t('Ask the writer for a change in this scene'), onclick: () => ctx.prefillChat(t('In scene {label} (“{title}”): ', { label: s.label, title: s.title || s.label })) }, icon('feather'), h('span', { text: t('Writer') })),
        h('button', { type: 'button', class: 'icon-btn small danger', title: t('Delete the scene'), 'aria-label': t('Delete the scene'), onclick: deleteScene }, icon('trash')),
        sceneUi.save),
      sceneUi.banner,
      h('div', { class: 'vn-scene-grid' },
        h('label', { class: 'vn-wide' }, t('Title'), input('title', { maxlength: 200 })),
        h('label', {}, h('span', { title: t('The name the exits use to get here (letters, numbers and _). Changing it here changes it in every exit.') }, t('Label')), input('label', { maxlength: 48, class: 'mono', spellcheck: 'false' })),
        h('label', {}, t('Chapter'), chapterSelect),
        h('label', {}, t('Route'), routeSelect)),
      h('label', {}, t('Summary (what happens; the writer reads it)'), input('summary', { rows: 2, maxlength: 3000 })),
      h('div', { class: 'vn-script-head' }, h('span', { class: 'field-label', text: t('Script') }), h('span', { class: 'grow' }),
        h('details', { class: 'vn-help' }, h('summary', { text: t('How to write') }),
          h('table', {}, h('tbody', {}, ...SCRIPT_HELP.map(([code, what]) => h('tr', {}, h('td', {}, h('code', { text: code })), h('td', { text: what }))))),
          h('p', { class: 'hint', text: t('Transitions: none, fade, dissolve, flash, slow. Conditions: ==, !=, <, <=, >, >=, and, or, not, parentheses, true, false, numbers and "text".') }))),
      h('div', { class: 'vn-inserts', role: 'group', 'aria-label': t('Insert in the script') },
        ...inserts.map(([label, text]) => h('button', { type: 'button', class: 'chip', onclick: () => insertLine(text) }, label)),
        ...people.slice(0, 8).map(c => h('button', { type: 'button', class: 'chip person', title: t('A line by {name}', { name: c.name }), onclick: () => insertLine(`${c.name}: `) }, c.name))),
      script, sceneUi.lint, sceneUi.missing,
      h('section', { class: 'vn-exit-wrap' }, h('h3', { text: t('Exit: what comes next') }), sceneUi.exit),
      h('label', {}, t('Gameplay notes for this scene (for you and the writer; they go as a comment in Ren\'Py)'), input('notes', { rows: 3, maxlength: 6000 })),
      sceneUi.meta,
      h('datalist', { id: 'vn-labels' }, ...d.scenes.filter(x => x.id !== s.id).map(x => h('option', { value: x.label, label: x.title || x.label }))));
    drawExit(); drawSceneProblems(s); drawLint(); updateMeta(s);
    if (v.lint === null) lintNow();
  }
  function updateMeta(s) {
    if (sceneUi.meta) sceneUi.meta.textContent = [s.words ? plural(s.words, t('{n} word'), t('{n} words')) : '', s.words ? plural(s.lines, t('{n} line'), t('{n} lines')) : '',
      [t('revision {n}', { n: s.rev }), s.by ? by(s.by) : '', s.updated ? dateTime(s.updated) : ''].filter(Boolean).join(', ')].filter(Boolean).join(' · ');
  }
  function insertLine(text) {
    const ta = sceneUi.script; if (!ta) return;
    const start = ta.selectionStart, before = ta.value.slice(0, start), after = ta.value.slice(ta.selectionEnd);
    const lead = before && !before.endsWith('\n') ? '\n' : '';
    ta.value = before + lead + text + after;
    const at = (before + lead + text).length;
    ta.focus(); ta.setSelectionRange(at, at);
    ta.dispatchEvent(new Event('input'));
  }
  function updateScene(body) {
    const s = sceneBy(v.scene);
    if (!s) { if (!v.dirty) open('tree'); else showBanner(t('This scene was deleted (maybe by the writer). Saving creates it again with your text? No: copy what you want and go back to the tree.'), false); return; }
    if (s.rev !== v.draft?.rev) {
      if (!v.dirty) { v.draft = null; render(body.closest('.story-doc'), { force: true }); return; }
      showBanner(t('This scene was changed {by} while you were editing. Saving now replaces the new version with yours.', { by: by(s.by) || t('elsewhere') }), true);
      v.conflict = true;
    }
    drawSceneProblems(s); updateMeta(s);
    const dl = $('#vn-labels'); if (dl) fill(dl, ...data().scenes.filter(x => x.id !== s.id).map(x => h('option', { value: x.label, label: x.title || x.label })));
  }
  function showBanner(text, reloadable) {
    const b = sceneUi.banner; if (!b) return;
    b.hidden = false;
    fill(b, icon('alert'), h('span', { text }), reloadable ? h('button', { type: 'button', class: 'btn secondary small', onclick: () => { v.draft = null; v.dirty = false; v.conflict = false; render($('#story-doc'), { force: true }); } }, t('See the new version')) : null);
  }
  function drawSceneProblems(s) {
    const box = sceneUi.missing; if (!box || !s) return;
    const a = data().analysis, mine = problemsOf(s.label).filter(p => p.code !== 'script');
    const missing = [];
    for (const m of a.missing.sprites) if (m.scenes.includes(s.label)) missing.push(`sprite ${m.character} (${m.attrs})`);
    for (const m of a.missing.backgrounds) if (m.scenes.includes(s.label)) missing.push(t('background {place}{variant}', { place: m.place, variant: m.variant ? ` (${m.variant})` : '' }));
    for (const m of a.missing.cgs) if (m.scenes.includes(s.label)) missing.push(`CG ${m.name}`);
    for (const m of a.missing.audio) if (m.scenes.includes(s.label)) missing.push(`${m.kind === 'music' ? t('music') : 'som'} ${m.name}`);
    fill(box,
      v.dirty && (mine.length || missing.length) ? h('p', { class: 'hint', text: t('In the saved version (save to check again):') }) : null,
      mine.length ? h('ul', { class: 'vn-problem-list' }, ...mine.map(p => h('li', { class: p.level }, icon(p.level === 'info' ? 'check' : 'alert'), h('span', { text: p.text })))) : null,
      missing.length ? h('p', { class: 'vn-missing-line' }, h('span', { text: t('Does not exist yet: {names}. ', { names: missing.join(', ') }) }),
        h('button', { type: 'button', class: 'link-btn', onclick: () => open('art') }, t('Make it in Art'))) : null);
  }
  function scheduleLint() { clearTimeout(v.lintTimer); v.lintTimer = setTimeout(lintNow, 450); }
  async function lintNow() {
    const dr = v.draft; if (!dr || !story()) return;
    const token = ++v.lintToken;
    try {
      const result = await api('vn-parse', { story: story().id, script: dr.script });
      if (token !== v.lintToken || v.draft !== dr) return;
      v.lint = result; drawLint();
    } catch { /* the save says what is wrong */ }
  }
  function drawLint() {
    const box = sceneUi.lint; if (!box) return;
    const l = v.lint;
    if (!l) { fill(box); return; }
    const says = l.steps.filter(x => x.t === 'say'), words = says.reduce((n, x) => n + (x.text.match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu) || []).length, 0);
    fill(box, l.errors.length
      ? h('ul', { class: 'vn-problem-list' }, ...l.errors.slice(0, 12).map(e => h('li', { class: 'error' }, icon('alert'),
        h('span', {}, h('button', { type: 'button', class: 'link-btn vn-scene-link', text: t('Line {n}', { n: e.line }), onclick: () => goLine(e.line) }), ` ${e.message}`))))
      : h('p', { class: 'vn-ok' }, icon('check'), h('span', { text: t('Readable script: {lines}, {words}, {commands}.', { lines: plural(says.length, t('{n} line or narration'), t('{n} lines and narrations')), words: plural(words, t('{n} word'), t('{n} words')), commands: plural(l.steps.length - says.length, t('{n} command'), t('{n} commands')) }) })));
  }
  function goLine(n) {
    const ta = sceneUi.script; if (!ta) return;
    const lines = ta.value.split('\n'), start = lines.slice(0, n - 1).reduce((a, l) => a + l.length + 1, 0);
    ta.focus(); ta.setSelectionRange(start, start + (lines[n - 1] || '').length);
    const line = parseFloat(getComputedStyle(ta).lineHeight) || 20; ta.scrollTop = Math.max(0, (n - 4) * line);
  }
  /** The exit's editor: its kind, then the fields of that kind (the scenes it leads to, the options, the conditions). */
  function drawExit() {
    const box = sceneUi.exit, dr = v.draft; if (!box || !dr) return;
    const e = dr.exit, changed = () => { const was = v.dirty; v.dirty = true; sceneUi.save.disabled = false; if (!was) drawSceneProblems(sceneBy(v.scene)); };
    const labels = new Set(data().scenes.map(s => s.label));
    const target = (obj, field, aria) => {
      const warn = h('span', { class: 'vn-target-warn' });
      const check = () => {
        const val = (obj[field] || '').trim();
        fill(warn, val && !labels.has(val) ? [h('span', { text: t('does not exist yet · ') }), h('button', { type: 'button', class: 'link-btn', onclick: () => createTarget(val) }, 'criar')] : []);
      };
      const el = h('input', { list: 'vn-labels', class: 'mono', spellcheck: 'false', 'aria-label': aria, placeholder: t('scene label'), oninput: ev => { obj[field] = ev.target.value; changed(); check(); } });
      el.value = obj[field] || ''; check();
      return h('span', { class: 'vn-target' }, el, warn);
    };
    const text = (obj, field, attributes) => { const el = h('input', { ...attributes, oninput: ev => { obj[field] = ev.target.value; changed(); } }); el.value = obj[field] || ''; return el; };
    const setType = type => {
      if (type === e.type) return;
      const to = targets(e)[0] || '';
      dr.exit = type === 'jump' ? { type, to } : type === 'choice' ? { type, prompt: '', options: [{ text: '', to, if: '', set: '', note: '' }, { text: '', to: '', if: '', set: '', note: '' }] }
        : type === 'branch' ? { type, cases: [{ if: '', to }], else: '' } : type === 'end' ? { type, title: dr.title || t('End'), kind: 'normal' } : { type: 'none' };
      changed(); drawExit();
    };
    const move = (list, i, j) => { if (j < 0 || j >= list.length) return; [list[i], list[j]] = [list[j], list[i]]; changed(); drawExit(); };
    const parts = [h('div', { class: 'segmented small vn-exit-types', role: 'group', 'aria-label': t('Type of exit') },
      ...EXIT_TYPES.map(([id, label]) => h('button', { type: 'button', 'aria-pressed': String(e.type === id), onclick: () => setType(id) }, label)))];
    if (e.type === 'none') parts.push(h('p', { class: 'hint', text: t('With no exit the game stops at this scene: the tree warns about it. Choose where it leads.') }));
    if (e.type === 'jump') parts.push(h('div', { class: 'vn-row' }, h('span', { class: 'field-label', text: t('Goes to') }), target(e, 'to', t('Next scene'))));
    if (e.type === 'end') parts.push(h('div', { class: 'vn-row' },
      h('label', { class: 'grow' }, t('Name of the ending'), text(e, 'title', { maxlength: 120 })),
      h('label', {}, t('Type'), h('select', { onchange: ev => { e.kind = ev.target.value; changed(); } }, ...ENDING_KINDS.map(([id, label]) => h('option', { value: id, text: label, selected: e.kind === id }))))));
    if (e.type === 'choice') {
      parts.push(h('label', {}, t('Question (optional, shown with the options)'), text(e, 'prompt', { maxlength: 300 })));
      parts.push(h('ol', { class: 'vn-options' }, ...e.options.map((o, i) => h('li', { class: 'vn-option' },
        h('div', { class: 'vn-row' },
          h('span', { class: 'vn-option-n', text: String(i + 1) }),
          text(o, 'text', { maxlength: 300, placeholder: t('What the player reads'), 'aria-label': t('Option {n}', { n: i + 1 }), class: 'grow' }),
          h('span', { class: 'vn-arrow-sign', text: '→' }), target(o, 'to', t('Destination of option {n}', { n: i + 1 })),
          h('button', { type: 'button', class: 'icon-btn tiny', title: t('Up'), 'aria-label': t('Up'), disabled: i === 0, onclick: () => move(e.options, i, i - 1) }, icon('to-top')),
          h('button', { type: 'button', class: 'icon-btn tiny', title: t('Down'), 'aria-label': t('Down'), disabled: i === e.options.length - 1, onclick: () => move(e.options, i, i + 1) }, icon('to-bottom')),
          h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove the option'), 'aria-label': t('Remove the option'), onclick: () => { e.options.splice(i, 1); changed(); drawExit(); } }, icon('close'))),
        h('div', { class: 'vn-row vn-option-more' },
          h('label', { class: 'grow' }, t('Only shows if (condition)'), text(o, 'if', { class: 'mono', spellcheck: 'false', placeholder: t('affinity_ana >= 2'), maxlength: 500 })),
          h('label', { class: 'grow' }, t('When chosen (separate with ;)'), text(o, 'set', { class: 'mono', spellcheck: 'false', placeholder: t('affinity_ana += 1; saw_letter = true'), maxlength: 600 })),
          h('label', { class: 'grow' }, t('Gameplay note'), text(o, 'note', { maxlength: 500, placeholder: t('Why this option exists') }))))),
        e.options.length < 9 ? h('li', {}, h('button', { type: 'button', class: 'btn ghost small', onclick: () => { e.options.push({ text: '', to: '', if: '', set: '', note: '' }); changed(); drawExit(); } }, icon('plus'), h('span', { text: t('Option') }))) : null));
    }
    if (e.type === 'branch') {
      parts.push(h('p', { class: 'hint', text: t('No choice by the player: the game goes on by the first case whose condition holds (affinity, flags), or by the “else”.') }));
      parts.push(h('ol', { class: 'vn-options' }, ...e.cases.map((c, i) => h('li', { class: 'vn-option' }, h('div', { class: 'vn-row' },
        h('span', { class: 'vn-option-n', text: i === 0 ? t('if') : t('or if') }),
        text(c, 'if', { class: 'mono grow', spellcheck: 'false', placeholder: t('ana_affinity >= 3 and saw_letter'), 'aria-label': t('Condition {n}', { n: i + 1 }), maxlength: 500 }),
        h('span', { class: 'vn-arrow-sign', text: '→' }), target(c, 'to', t('Destination of case {n}', { n: i + 1 })),
        h('button', { type: 'button', class: 'icon-btn tiny', title: t('Up'), 'aria-label': t('Up'), disabled: i === 0, onclick: () => move(e.cases, i, i - 1) }, icon('to-top')),
        h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove the case'), 'aria-label': t('Remove the case'), onclick: () => { e.cases.splice(i, 1); changed(); drawExit(); } }, icon('close'))))),
        e.cases.length < 9 ? h('li', {}, h('button', { type: 'button', class: 'btn ghost small', onclick: () => { e.cases.push({ if: '', to: '' }); changed(); drawExit(); } }, icon('plus'), h('span', { text: t('Case') }))) : null));
      parts.push(h('div', { class: 'vn-row' }, h('span', { class: 'field-label', text: t('Else') }), target(e, 'else', t('Destination when no case holds'))));
    }
    fill(box, ...parts);
  }
  async function createTarget(label) {
    if (v.dirty && !(await saveScene())) return;
    newScene({ label, chapter: v.draft?.chapter ?? undefined });
  }
  /** Saves the scene; true when it was saved. */
  async function saveScene() {
    const dr = v.draft, s = story(); if (!dr || v.saving) return false;
    v.saving = true; sceneUi.save.disabled = true;
    const exit = JSON.parse(JSON.stringify(dr.exit));
    if (exit.type === 'choice') exit.options = exit.options.map(o => ({ ...o, set: String(o.set || '').split(';').map(x => x.trim()).filter(Boolean) }));
    try {
      const { scene } = await api('vn-scene', { story: s.id, mode: 'save', scene: dr.id, rev: v.conflict ? undefined : dr.rev,
        fields: { title: dr.title, label: dr.label, chapter: dr.chapter || null, route: dr.route || null, summary: dr.summary, notes: dr.notes, script: dr.script, exit } });
      v.draft = draftOf(scene); v.dirty = false; v.conflict = false;
      if (sceneUi.banner) sceneUi.banner.hidden = true;
      await ctx.reload();
      toast(t('Scene saved.'));
      return true;
    } catch (e) {
      if (e.status === 409) { v.conflict = true; showBanner(e.message, true); }
      else toast(e.message, { tone: 'error', duration: 12000 });
      sceneUi.save.disabled = false;
      return false;
    } finally { v.saving = false; }
  }
  async function deleteScene() {
    const s = sceneBy(v.scene); if (!s) return;
    const leading = data().scenes.filter(x => targets(x.exit).includes(s.label)).map(x => x.label);
    const ok = await confirmAction({ title: t('Delete the scene “{title}”?', { title: s.title || s.label }), danger: true, confirm: t('Delete'),
      message: `${t('Its script and notes are deleted.')}${leading.length ? ` ${plural(leading.length, t('{n} scene leads to it ({scenes}): that exit is left pointing at a scene that does not exist, and the tree warns about it.'), t('{n} scenes lead to it ({scenes}): those exits are left pointing at a scene that does not exist, and the tree warns about it.')).replace('{scenes}', leading.slice(0, 5).join(', '))}` : ''}` });
    if (!ok) return;
    try { await api('vn-scene', { story: story().id, mode: 'delete', scene: s.id }); v.dirty = false; open('tree'); await ctx.reload(); toast(t('Scene deleted.')); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }

  // -----------------------------------------------------------------------------------------------------------------
  // The art: sprites (per character), backgrounds (per place) and CGs, what the script uses that doesn't exist yet
  // -----------------------------------------------------------------------------------------------------------------
  const artUi = { missing: null, sprites: null, backgrounds: null, cgs: null };
  function buildArt(body) {
    const forms = v.art.forms = artForms();
    artUi.missing = h('section', { class: 'vn-art-missing' });
    artUi.sprites = h('div', { class: 'vn-art-groups' });
    artUi.backgrounds = h('div', { class: 'vn-art-groups' });
    artUi.cgs = h('div', { class: 'vn-art-grid' });
    const settings = data().settings;
    fill(body,
      h('div', { class: 'vn-toolbar' },
        h('p', { class: 'hint grow', text: t('Sprites with a transparent background, {framing}, made from each character\'s sheet; each new expression and outfit is an edit of the base sprite, so they fit on screen. Backgrounds in {aspect}, with no people; the variations (night, rain) edit the base background.', { framing: FRAMINGS.find(f => f[0] === settings.framing)?.[1].toLowerCase(), aspect: settings.aspect }) }),
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => ctx.openCast() }, icon('user'), h('span', { text: t('Sheets and style') }))),
      artUi.missing,
      h('section', { class: 'vn-art-section' }, h('h3', { text: 'Sprites' }), forms.sprite, artUi.sprites),
      h('section', { class: 'vn-art-section' }, h('h3', { text: t('Backgrounds') }), forms.background, artUi.backgrounds),
      h('section', { class: 'vn-art-section' }, h('h3', { text: 'CGs' }), h('p', { class: 'hint', text: t('Full-screen illustrations of the special moments, with the sheets of whoever appears. In the script: @cg name.') }), forms.cg, artUi.cgs));
    updateArt();
  }
  /** The forms for a new picture: built once per visit to the view, so a poll doesn't lose what is typed. */
  function artForms() {
    const people = () => cast().filter(c => c.kind === 'character'), places = () => cast().filter(c => c.kind === 'place');
    const f = { sprite: null, background: null, cg: null };
    // Sprite
    const who = h('select', { 'aria-label': t('Character') }, ...people().map(c => h('option', { value: c.name, text: c.name })));
    const expression = h('input', { placeholder: t('neutral, happy, angry…'), maxlength: 40, 'aria-label': t('Expression') });
    const outfit = h('input', { placeholder: t('outfit (optional)'), maxlength: 40, 'aria-label': t('Outfit') });
    const spritePrompt = h('textarea', { rows: 2, maxlength: 4000, placeholder: t('Description (optional): pose, gesture, gaze. Empty, it comes from the sheet\'s note.'), 'aria-label': t('Description of the sprite') });
    const scratch = h('input', { type: 'checkbox' });
    f.sprite = people().length ? h('form', { class: 'vn-art-form', onsubmit: ev => { ev.preventDefault(); generate('sprite', { character: who.value, expression: expression.value || NEUTRAL, outfit: outfit.value, prompt: spritePrompt.value, from_scratch: scratch.checked }, () => { expression.value = ''; spritePrompt.value = ''; }); } },
      h('div', { class: 'vn-row' }, who, expression, outfit, h('label', { class: 'switch', title: t('Without editing the base sprite: a new sprite from the sheet') }, scratch, h('span', { class: 'switch-track' }), h('span', { text: t('From scratch') }))),
      h('div', { class: 'vn-row' }, spritePrompt, h('button', { type: 'submit', class: 'btn secondary small' }, icon('sparkle'), h('span', { text: t('Generate sprite') }))))
      : h('p', { class: 'hint' }, t('The sprites come from the character sheets. '), h('button', { type: 'button', class: 'link-btn', onclick: () => ctx.openCast() }, t('Create a sheet')), t(' first (with an image).'));
    // Background
    const place = h('input', { list: 'vn-places', maxlength: 80, placeholder: t('Place (sheet or name)'), 'aria-label': t('Place') });
    const variant = h('input', { maxlength: 40, placeholder: t('variation: day, night, rain…'), 'aria-label': t('Variation') });
    const bgPrompt = h('textarea', { rows: 2, maxlength: 4000, placeholder: t('Description (optional). Empty, it comes from the place sheet\'s note.'), 'aria-label': t('Description of the background') });
    const bgScratch = h('input', { type: 'checkbox' });
    f.background = h('form', { class: 'vn-art-form', onsubmit: ev => { ev.preventDefault(); if (!place.value.trim()) { place.focus(); return; } generate('background', { place: place.value, variant: variant.value, prompt: bgPrompt.value, from_scratch: bgScratch.checked }, () => { variant.value = ''; bgPrompt.value = ''; }); } },
      h('datalist', { id: 'vn-places' }, ...places().map(c => h('option', { value: c.name }))),
      h('div', { class: 'vn-row' }, place, variant, h('label', { class: 'switch', title: t('Without editing the place\'s base background') }, bgScratch, h('span', { class: 'switch-track' }), h('span', { text: t('From scratch') }))),
      h('div', { class: 'vn-row' }, bgPrompt, h('button', { type: 'submit', class: 'btn secondary small' }, icon('sparkle'), h('span', { text: t('Generate background') }))));
    // CG
    const cgName = h('input', { maxlength: 120, placeholder: t('Name (it becomes the name in the script: @cg first_kiss)'), 'aria-label': t('Name of the CG') });
    const cgPrompt = h('textarea', { rows: 2, maxlength: 4000, placeholder: t('The moment: the place, who is there, what they do, the light.'), 'aria-label': t('Description of the CG') });
    const cgPeople = h('div', { class: 'vn-checks', role: 'group', 'aria-label': t('Who appears') }, ...people().map(c => h('label', { class: 'vn-check' }, h('input', { type: 'checkbox', value: c.name }), h('span', { text: c.name }))));
    f.cg = h('form', { class: 'vn-art-form', onsubmit: ev => { ev.preventDefault(); if (!cgName.value.trim()) { cgName.focus(); return; } if (!cgPrompt.value.trim()) { cgPrompt.focus(); return; }
      generate('cg', { title: cgName.value, prompt: cgPrompt.value, characters: [...cgPeople.querySelectorAll('input:checked')].map(i => i.value) }, () => { cgName.value = ''; cgPrompt.value = ''; for (const i of cgPeople.querySelectorAll('input')) i.checked = false; }); } },
      h('div', { class: 'vn-row' }, cgName), people().length ? cgPeople : null,
      h('div', { class: 'vn-row' }, cgPrompt, h('button', { type: 'submit', class: 'btn secondary small' }, icon('sparkle'), h('span', { text: t('Generate CG') }))));
    return f;
  }
  function updateArt() {
    if (!artUi.sprites?.isConnected) return;
    const d = data(), art = d.art, a = d.analysis, people = cast().filter(c => c.kind === 'character');
    // What the script uses and doesn't exist yet
    const items = [
      ...a.missing.sprites.map(m => ({ kind: 'sprite', text: t('Sprite {character} ({attrs})', { character: m.character, attrs: m.attrs }), in: spriteInput(m), scenes: m.scenes, sheet: Boolean(findCast(cast(), m.character, 'character')) })),
      ...a.missing.backgrounds.map(m => ({ kind: 'background', text: t('Background {place}{variant}', { place: m.place, variant: m.variant ? ` (${m.variant})` : '' }), in: { place: m.place, variant: m.variant }, scenes: m.scenes, sheet: true })),
      ...a.missing.cgs.map(m => ({ kind: 'cg', text: `CG ${m.name}`, in: null, scenes: m.scenes, sheet: true })),
    ];
    const ready = items.filter(i => i.in && i.sheet), price = ctx.credit(ctx.writer(), d.settings.aspect).image;
    fill(artUi.missing, items.length || a.missing.audio.length ? [
      h('div', { class: 'vn-missing-head' }, h('h3', { text: t('The script uses and does not exist yet') }), h('span', { class: 'grow' }),
        ready.length ? h('button', { type: 'button', class: 'btn primary small', onclick: e => generateAll(ready, e.currentTarget) }, icon('sparkle'),
          h('span', { text: `${plural(ready.length, t('Generate {n} image'), t('Generate {n} images'))}${price ? ` · ~${formatUsd(price * ready.length)}` : ''}` })) : null,
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => ctx.prefillChat(t('Make the art the script uses that does not exist yet (sprites, backgrounds and CGs), following the sheets and the style. ')) }, icon('feather'), h('span', { text: t('Writer') }))),
      h('ul', { class: 'vn-missing' }, ...items.map(i => h('li', {},
        h('span', { class: 'grow' }, h('strong', { text: i.text }), h('small', { class: 'muted', text: ` · ${i.scenes.slice(0, 4).join(', ')}${i.scenes.length > 4 ? '…' : ''}` })),
        i.kind === 'cg' ? h('span', { class: 'muted', text: t('describe it in the CG form') })
          : !i.sheet ? h('span', { class: 'muted', text: t('no character sheet') })
          : h('button', { type: 'button', class: 'btn ghost small', onclick: e => { e.currentTarget.disabled = true; generate(i.kind, i.in); } }, icon('sparkle'), h('span', { text: t('Generate') })))),
        ...a.missing.audio.map(m => h('li', {}, h('span', { class: 'grow' }, h('strong', { text: `${m.kind === 'music' ? t('Music') : t('Sound')} ${m.name}` }), h('small', { class: 'muted', text: ` · ${m.scenes.slice(0, 4).join(', ')}` })),
          h('button', { type: 'button', class: 'btn ghost small', title: t('Put it in the list of music and sounds (you put the file in Ren\'Py)'), onclick: () => saveSettings({ audio: { set: [{ name: m.name, kind: m.kind }] } }) }, icon('plus'), h('span', { text: t('In the soundtrack') }))))),
    ] : [h('p', { class: 'vn-ok' }, icon('check'), h('span', { text: t('All the art the script uses already exists.') }))]);
    // Sprites by character
    fill(artUi.sprites, ...people.map(c => {
      const mine = art.sprites.filter(s => s.character === c.id), sheet = c.images.find(im => im.image)?.image;
      return h('div', { class: 'vn-art-group' },
        h('div', { class: 'vn-art-group-head' }, sheet ? h('img', { src: imageUrl(sheet, true), alt: '', class: 'vn-sheet-thumb' }) : null, h('strong', { text: c.name }),
          h('span', { class: 'muted', text: mine.length ? plural(mine.length, t('{n} sprite'), t('{n} sprites')) : sheet ? t('no sprites yet') : t('the sheet has no image yet') })),
        mine.length ? h('div', { class: 'vn-art-grid sprites' }, ...mine.map(s => artCard('sprite', s, [s.outfit, s.expression].filter(Boolean).join(' · ')))) : null);
    }), art.sprites.some(s => !people.some(c => c.id === s.character)) ? h('p', { class: 'hint', text: t('There are sprites of characters whose sheet was deleted.') }) : null);
    if (!people.length) fill(artUi.sprites);
    // Backgrounds by place
    const groups = new Map();
    for (const b of art.backgrounds) { const k = b.place || `name:${slug(b.name)}`; if (!groups.has(k)) groups.set(k, { name: b.name, list: [] }); groups.get(k).list.push(b); }
    fill(artUi.backgrounds, ...[...groups.values()].map(g => h('div', { class: 'vn-art-group' }, h('div', { class: 'vn-art-group-head' }, h('strong', { text: g.name }), h('span', { class: 'muted', text: plural(g.list.length, t('{n} variation'), t('{n} variations')) })),
      h('div', { class: 'vn-art-grid backgrounds' }, ...g.list.map(b => artCard('background', b, b.variant || 'base'))))));
    fill(artUi.cgs, ...art.cgs.map(c => artCard('cg', c, c.title || c.name)));
  }
  function spriteInput(m) {
    const c = findCast(cast(), m.character, 'character');
    const known = c ? data().art.sprites.filter(s => s.character === c.id && s.outfit).map(s => s.outfit) : [];
    return { character: m.character, ...splitAttrs(m.attrs, known) };
  }
  /** One picture of the art: the image (on a checkerboard, for the transparent sprites), its state while it is made, its actions. */
  function artCard(kind, entry, label) {
    const job = entry.job ? ctx.pending().get(entry.job) : null, failed = Boolean(job && !ACTIVE.has(job.status) && job.status !== 'completed');
    const making = Boolean(entry.job) && !failed;
    return h('figure', { class: `vn-art-card ${kind}${failed ? ' failed' : ''}` },
      h('div', { class: 'vn-art-pic' },
        entry.image ? h('button', { type: 'button', class: 'vn-art-open', title: t('Open the image'), onclick: () => openViewer(entry.image) }, h('img', { src: imageUrl(entry.image, true), alt: label, loading: 'lazy' })) : null,
        making ? h('span', { class: 'vn-art-state' }, h('span', { class: 'spinner small' }), h('span', { text: job?.status === 'running' ? t('Making…') : t('Queued…') })) : null,
        failed ? h('span', { class: 'vn-art-state failed' }, icon('alert'), h('span', { text: job.error || t('Did not come out.') })) : null),
      h('figcaption', {}, h('span', { class: 'vn-art-label', text: label }), entry.by === 'agent' ? h('span', { class: 'vn-by', title: t('Asked for by the writer'), text: 'E' }) : null),
      h('div', { class: 'vn-art-actions' },
        failed ? h('button', { type: 'button', class: 'link-btn', onclick: () => artAction({ mode: 'retry', job: entry.job }) }, t('Try again')) : null,
        failed ? h('button', { type: 'button', class: 'link-btn', onclick: () => artAction({ mode: 'dismiss', job: entry.job }) }, entry.image ? t('Keep the old one') : t('Give up')) : null,
        !making ? h('button', { type: 'button', class: 'icon-btn tiny', title: t('Make it again (same description)'), 'aria-label': t('Make it again'), onclick: () => remake(kind, entry) }, icon('redo')) : null,
        h('button', { type: 'button', class: 'icon-btn tiny', title: t('Use an image from the gallery'), 'aria-label': t('Use an image from the gallery'), onclick: () => fromGallery(kind, entry) }, icon('gallery')),
        h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove from the project (the image stays in the gallery)'), 'aria-label': t('Remove from the project'), onclick: () => removeArt(kind, entry) }, icon('trash'))));
  }
  function inputOf(kind, entry) {
    if (kind === 'sprite') { const c = cast().find(x => x.id === entry.character); return c ? { character: c.name, expression: entry.expression, outfit: entry.outfit } : null; }
    if (kind === 'background') return { place: entry.name, variant: entry.variant };
    return { name: entry.name, title: entry.title };
  }
  async function remake(kind, entry) {
    const input = inputOf(kind, entry); if (!input) return;
    const ok = await confirmAction({ title: t('Make it again?'), message: t('A new image with the same description. The current one stays until the new one is ready (and it stays in the gallery).'), confirm: t('Make it again') });
    if (ok) generate(kind, { ...input, prompt: entry.prompt || '' });
  }
  async function generate(kind, input, done = null) {
    try {
      const { jobs } = await api('vn-art', { story: story().id, mode: 'generate', kind, ...input });
      for (const job of jobs) trackJob(job);
      launchQueued(); done?.();
      await ctx.reload(); ctx.poll();
      return true;
    } catch (e) { toast(e.message, { tone: 'error', duration: 10000 }); updateArt(); return false; }
  }
  /** Everything missing, one request at a time: the base sprite of a character first; the rest of them wait for it. */
  async function generateAll(list, button) {
    const price = ctx.credit(ctx.writer(), data().settings.aspect).image;
    const ok = await confirmAction({ title: plural(list.length, t('Generate {n} image?'), t('Generate {n} images?')), confirm: t('Generate'),
      message: `${price ? `${t('About {cost} in all.', { cost: formatUsd(price * list.length) })} ` : ''}${t('The sprites of a character who has none yet wait for the base sprite to be ready: those are left for a second click, in a minute or two.')}` });
    if (!ok) return;
    button.disabled = true;
    let made = 0, waiting = 0, failed = 0;
    const based = new Set();
    for (const i of list) {
      if (i.kind === 'sprite') {
        const c = findCast(cast(), i.in.character, 'character');
        const has = c && data().art.sprites.some(s => s.character === c.id);
        if (c && !has) { if (based.has(c.id)) { waiting++; continue; } based.add(c.id); }
      }
      try { const { jobs } = await api('vn-art', { story: story().id, mode: 'generate', kind: i.kind, ...i.in }); for (const job of jobs) trackJob(job); made++; }
      catch (e) { if (/still being made|ainda está sendo feito/.test(e.message)) waiting++; else { failed++; if (failed === 1) toast(e.message, { tone: 'error', duration: 10000 }); } }
    }
    launchQueued();
    await ctx.reload(); ctx.poll();
    toast([plural(made, t('{n} image requested.'), t('{n} images requested.')), waiting ? plural(waiting, t('{n} waits for the base sprite or background: click again when it is ready.'), t('{n} wait for the base sprite or background: click again when it is ready.')) : '',
      failed ? plural(failed, t('{n} did not work.'), t('{n} did not work.', { ctx: 'many' })) : ''].filter(Boolean).join(' '), failed ? { tone: 'error' } : {});
  }
  async function artAction(body) {
    try {
      const r = await api('vn-art', { story: story().id, ...body });
      for (const job of r.jobs || []) trackJob(job);
      if (r.jobs?.length) launchQueued();
      await ctx.reload(); ctx.poll();
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }
  function fromGallery(kind, entry) {
    const chosen = [];
    ctx.pickImages(chosen, 1, async () => {
      if (!chosen.length) return;
      const input = inputOf(kind, entry); if (!input) return;
      try { await api('vn-art', { story: story().id, mode: 'put', kind, ...input, image: chosen[0].id }); await ctx.reload(); }
      catch (e) { toast(e.message, { tone: 'error' }); }
    }, kind === 'sprite' ? t('Image for the sprite (preferably with a transparent background)') : kind === 'background' ? t('Image for the background') : t('Image for the CG'));
  }
  async function removeArt(kind, entry) {
    const ok = await confirmAction({ title: t('Remove from the project?'), message: t('The image stays in the gallery. The script that uses it starts warning that it is missing.'), confirm: t('Remove', { ctx: 'take' }) });
    if (ok) artAction({ mode: 'remove', kind, id: entry.id });
  }

  // -----------------------------------------------------------------------------------------------------------------
  // Routes and endings; the variables, the audio and the game's settings; the gameplay notes
  // -----------------------------------------------------------------------------------------------------------------
  async function saveSettings(change) {
    try { await api('vn-settings', { story: story().id, change }); await ctx.reload(); return true; }
    catch (e) { toast(e.message, { tone: 'error', duration: 10000 }); update(); return false; }
  }
  function buildRoutes(body) {
    const d = data(), a = d.analysis, st = a.stats;
    const reached = a.endings.filter(e => e.reachable).length;
    const stat = (n, label) => h('div', { class: 'vn-stat' }, h('strong', { text: num(n) }), h('span', { text: label }));
    const name = h('input', { maxlength: 60, placeholder: t('New route (e.g.: Ana\'s route)'), 'aria-label': t('Name of the new route') });
    fill(body,
      h('div', { class: 'vn-stats' }, stat(st.scenes, 'cenas'), stat(st.words, 'palavras'), stat(st.lines, t('lines and narrations')), stat(st.choices, 'escolhas'), stat(st.endings, 'finais'),
        h('div', { class: 'vn-stat' }, h('strong', { text: `${reached}/${a.endings.length}` }), h('span', { text: t('reachable endings') }))),
      h('section', { class: 'vn-section' }, h('h3', { text: t('Endings') }),
        a.endings.length ? h('ul', { class: 'vn-endings' }, ...a.endings.map(e => h('li', { class: e.reachable ? '' : 'blocked' },
          h('span', { class: `vn-ending-kind ${e.kind}`, text: ENDING_LABEL[e.kind] || e.kind }),
          h('span', { class: 'grow' }, h('strong', { text: e.title }), h('small', { class: 'muted', text: ` · ${routeOf(e.route)?.name || t('no route')}` })),
          h('span', { class: e.reachable ? 'vn-ok' : 'vn-bad', text: e.reachable ? t('reachable') : problemsOf(e.scene).some(p => p.code === 'ending-blocked') ? t('blocked by the conditions') : t('no path gets here') }),
          h('button', { type: 'button', class: 'link-btn vn-scene-link', text: e.scene, onclick: () => open('scene', sceneBy(e.scene)?.id) })))) : h('p', { class: 'hint', text: t('No endings yet: in a scene, choose the “Ending” exit.') })),
      h('section', { class: 'vn-section' }, h('h3', { text: t('Routes') }),
        h('p', { class: 'hint', text: t('Each scene can belong to a route (Ana\'s, Bruno\'s, the common one). The color shows on the tree, and the writer knows which route each scene belongs to.') }),
        d.routes.length ? h('table', { class: 'vn-table' }, h('thead', {}, h('tr', {}, ...['', t('Route'), t('Scenes'), t('Words'), t('Endings'), t('Note'), ''].map(t => h('th', { scope: 'col', text: t })))),
          h('tbody', {}, ...d.routes.map(r => {
            const info = a.routes.find(x => x.id === r.id) || { scenes: 0, words: 0, endings: [] };
            const color = h('input', { type: 'color', value: r.color, 'aria-label': t('Color of the route {name}', { name: r.name }), onchange: e => saveSettings({ routes: { set: [{ name: r.id, color: e.target.value }] } }) });
            const nameInput = h('input', { value: r.name, maxlength: 60, 'aria-label': t('Name of the route'), onchange: e => { if (e.target.value.trim()) saveSettings({ routes: { set: [{ name: r.id, new_name: e.target.value.trim() }] } }); } });
            const note = h('input', { value: r.note || '', maxlength: 300, 'aria-label': t('Note about the route'), placeholder: t('what it is about'), onchange: e => saveSettings({ routes: { set: [{ name: r.id, note: e.target.value }] } }) });
            return h('tr', {}, h('td', {}, color), h('td', {}, nameInput), h('td', { text: num(info.scenes) }), h('td', { text: num(info.words) }), h('td', { text: info.endings.join(', ') || '—' }), h('td', {}, note),
              h('td', {}, h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Delete the route (the scenes are left with no route)'), 'aria-label': t('Delete the route {name}', { name: r.name }),
                onclick: async () => { if (await confirmAction({ title: t('Delete the route “{name}”?', { name: r.name }), message: t('Its scenes are left with no route; nothing in the script changes.'), confirm: t('Delete'), danger: true })) saveSettings({ routes: { remove: [r.id] } }); } }, icon('trash'))));
          }))) : null,
        h('form', { class: 'vn-row', onsubmit: async e => { e.preventDefault(); if (name.value.trim() && await saveSettings({ routes: { set: [{ name: name.value.trim() }] } })) name.value = ''; } },
          name, h('button', { type: 'submit', class: 'btn secondary small' }, icon('plus'), h('span', { text: t('Route') })))),
      h('section', { class: 'vn-section' }, h('h3', { text: t('Errors and warnings') }),
        a.problems.length ? h('ul', { class: 'vn-problem-list' }, ...a.problems.map(p => h('li', { class: p.level }, icon(p.level === 'info' ? 'check' : 'alert'),
          h('span', {}, p.scene ? h('button', { type: 'button', class: 'link-btn vn-scene-link', text: p.scene, onclick: () => open('scene', sceneBy(p.scene)?.id || null) }) : null, p.scene ? ' ' : null, p.text)))) : h('p', { class: 'vn-ok' }, icon('check'), h('span', { text: t('Nothing to fix.') }))));
  }
  function buildConfig(body) {
    const d = data(), s = d.settings;
    const segmented = (list, value, onpick, aria) => h('div', { class: 'segmented small', role: 'group', 'aria-label': aria },
      ...list.map(([id, label]) => h('button', { type: 'button', 'aria-pressed': String(value === id), onclick: () => { if (id !== value) onpick(id); } }, label)));
    const gameName = h('input', { value: s.name, maxlength: 120, placeholder: story().title || t('Name of the game'), onchange: e => saveSettings({ name: e.target.value }) });
    const startSelect = h('select', { onchange: e => saveSettings({ start: e.target.value }) }, h('option', { value: '', text: t('None', { ctx: 'feminine' }) }),
      ...d.scenes.map(x => h('option', { value: x.label, text: `${x.label} · ${x.title || t('Untitled')}`, selected: s.start === x.label })));
    const varRow = item => {
      const name = h('input', { value: item.name, class: 'mono', maxlength: 48, spellcheck: 'false', 'aria-label': t('Name of the variable'), onchange: e => saveSettings({ variables: { set: [{ name: item.name, new_name: e.target.value.trim() }] } }) });
      const type = h('select', { 'aria-label': t('Type'), onchange: e => saveSettings({ variables: { set: [{ name: item.name, type: e.target.value }] } }) }, ...VAR_TYPES.map(([id, label]) => h('option', { value: id, text: label, selected: item.type === id })));
      const initial = item.type === 'flag' ? h('select', { 'aria-label': t('Starts'), onchange: e => saveSettings({ variables: { set: [{ name: item.name, initial: e.target.value === 'true' }] } }) },
        h('option', { value: 'false', text: t('no'), selected: !item.initial }), h('option', { value: 'true', text: t('yes'), selected: Boolean(item.initial) }))
        : h('input', { value: String(item.initial ?? ''), type: item.type === 'number' ? 'number' : 'text', step: 'any', 'aria-label': t('Starting value'), onchange: e => saveSettings({ variables: { set: [{ name: item.name, initial: item.type === 'number' ? Number(e.target.value) || 0 : e.target.value }] } }) });
      const note = h('input', { value: item.note || '', maxlength: 300, 'aria-label': t('Note'), placeholder: t('what it is for'), onchange: e => saveSettings({ variables: { set: [{ name: item.name, note: e.target.value }] } }) });
      return h('tr', {}, h('td', {}, name), h('td', {}, type), h('td', {}, initial), h('td', {}, note),
        h('td', {}, h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Delete the variable'), 'aria-label': t('Delete {name}', { name: item.name }), onclick: () => saveSettings({ variables: { remove: [item.name] } }) }, icon('trash'))));
    };
    const newVar = h('input', { class: 'mono', maxlength: 48, spellcheck: 'false', placeholder: t('affinity_ana'), 'aria-label': t('Name of the new variable') });
    const newType = h('select', { 'aria-label': t('Type of the new variable') }, ...VAR_TYPES.map(([id, label]) => h('option', { value: id, text: label, selected: id === 'number' })));
    const audioRow = item => h('tr', {},
      h('td', {}, h('code', { text: item.name })),
      h('td', {}, h('select', { 'aria-label': t('Type'), onchange: e => saveSettings({ audio: { set: [{ name: item.name, kind: e.target.value }] } }) }, h('option', { value: 'music', text: t('Music'), selected: item.kind === 'music' }), h('option', { value: 'sound', text: t('Sound'), selected: item.kind === 'sound' }))),
      h('td', {}, h('input', { value: item.note || '', maxlength: 300, placeholder: t('what it is like (calm piano, a creaking door)'), 'aria-label': t('Description'), onchange: e => saveSettings({ audio: { set: [{ name: item.name, note: e.target.value }] } }) })),
      h('td', {}, h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove from the list'), 'aria-label': t('Remove {name}', { name: item.name }), onclick: () => saveSettings({ audio: { remove: [item.name] } }) }, icon('trash'))));
    const newAudio = h('input', { maxlength: 60, placeholder: t('cafe_theme'), 'aria-label': t('Name of the new track') });
    const newKind = h('select', { 'aria-label': t('Type of the new track') }, h('option', { value: 'music', text: t('Music') }), h('option', { value: 'sound', text: t('Sound') }));
    fill(body,
      h('section', { class: 'vn-section' }, h('h3', { text: t('The game') }),
        h('div', { class: 'vn-form-grid' },
          h('label', {}, t('Name of the game'), gameName),
          h('label', {}, t('Starting scene'), startSelect),
          h('div', { class: 'stack tight' }, h('span', { class: 'field-label', text: t('Screen format') }), segmented(ASPECTS.map(x => [x, x]), s.aspect, id => saveSettings({ aspect: id }), t('Screen format'))),
          h('div', { class: 'stack tight' }, h('span', { class: 'field-label', text: t('Framing of the sprites') }), segmented(FRAMINGS, s.framing, id => saveSettings({ framing: id }), t('Framing of the sprites'))))),
      h('section', { class: 'vn-section' }, h('h3', { text: t('Variables') }),
        h('p', { class: 'hint', text: t('Affinity, flags, counters: the choices change them (When chosen) and the conditions read them. They start with the starting value. Renaming here renames in every script and exit.') }),
        d.variables.length ? h('table', { class: 'vn-table' }, h('thead', {}, h('tr', {}, ...[t('Name'), t('Type'), t('Starts'), t('Note'), ''].map(t => h('th', { scope: 'col', text: t })))), h('tbody', {}, ...d.variables.map(varRow))) : null,
        h('form', { class: 'vn-row', onsubmit: async e => { e.preventDefault(); if (newVar.value.trim() && await saveSettings({ variables: { set: [{ name: newVar.value.trim(), type: newType.value, initial: newType.value === 'flag' ? false : newType.value === 'number' ? 0 : '' }] } })) newVar.value = ''; } },
          newVar, newType, h('button', { type: 'submit', class: 'btn secondary small' }, icon('plus'), h('span', { text: t('Variable') })))),
      h('section', { class: 'vn-section' }, h('h3', { text: t('Music and sounds') }),
        h('p', { class: 'hint', text: t('The names the script uses in @music and @sound. You put the files in Ren\'Py\'s game/audio folder (the exported zip says which).') }),
        d.audio.length ? h('table', { class: 'vn-table' }, h('thead', {}, h('tr', {}, ...[t('Name'), t('Type'), t('Description'), ''].map(t => h('th', { scope: 'col', text: t })))), h('tbody', {}, ...d.audio.map(audioRow))) : null,
        h('form', { class: 'vn-row', onsubmit: async e => { e.preventDefault(); if (newAudio.value.trim() && await saveSettings({ audio: { set: [{ name: newAudio.value.trim(), kind: newKind.value }] } })) newAudio.value = ''; } },
          newAudio, newKind, h('button', { type: 'submit', class: 'btn secondary small' }, icon('plus'), h('span', { text: t('Track') })))));
  }
  function buildGameplay(body) {
    const d = data(), g = d.gameplay;
    const text = h('textarea', { rows: 16, maxlength: 200000, class: 'vn-gameplay-text', 'aria-label': t('Gameplay notes'),
      placeholder: t('How the game works: the systems (affinity from 0 to 5, flags), what each route requires, the pace of the choices, ideas to test, comments on scenes…') });
    text.value = g.text || '';
    const status = h('p', { class: 'muted', text: g.at ? t('Revision {rev}{by}, {at}', { rev: g.rev, by: g.by ? `, ${by(g.by)}` : '', at: dateTime(g.at) }) : t('Still empty.', { ctx: 'notes' }) });
    const save = h('button', { type: 'submit', class: 'btn primary small', disabled: true }, icon('check'), h('span', { text: t('Save') }));
    text.addEventListener('input', () => { save.disabled = false; });
    text.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save.click(); } });
    const notes = [];
    for (const s of d.scenes) {
      if (s.notes) notes.push({ s, text: s.notes });
      if (s.exit.type === 'choice') for (const o of s.exit.options) if (o.note) notes.push({ s, text: `“${o.text}”: ${o.note}` });
    }
    fill(body,
      h('form', { class: 'vn-section', onsubmit: async e => {
        e.preventDefault(); save.disabled = true;
        try { await api('vn-gameplay', { story: story().id, text: text.value, rev: g.rev }); await ctx.reload(); toast(t('Notes saved. The writer sees them at the next message.')); }
        catch (err) { toast(err.message, { tone: 'error', duration: 12000 }); save.disabled = false; }
      } },
        h('div', { class: 'vn-missing-head' }, h('h3', { text: t('Gameplay notes') }), h('span', { class: 'grow' }), save),
        h('p', { class: 'hint', text: t('A document of the game, apart from the bible: the systems, the rules of the routes, the gameplay comments. The writer reads it and keeps it up to date.') }),
        text, status),
      notes.length ? h('section', { class: 'vn-section' }, h('h3', { text: t('Notes on the scenes and the choices') }),
        h('ul', { class: 'vn-scene-notes' }, ...notes.map(n => h('li', {}, h('button', { type: 'button', class: 'link-btn vn-scene-link', text: n.s.label, onclick: () => open('scene', n.s.id) }), h('span', { text: ` ${n.text}` }))))) : null);
  }

  // -----------------------------------------------------------------------------------------------------------------
  // The preview: the game played in the page, with the variables in view (and changeable, to test the conditions)
  // -----------------------------------------------------------------------------------------------------------------
  const playUi = { stage: null, side: null };
  function buildPlay(body) {
    playUi.stage = h('div', { class: 'vn-play-stage', tabindex: 0, 'aria-label': t('Preview: click, Space or Enter to go on; ← goes back'), onclick: e => { if (!e.target.closest('button')) advance(); } });
    playUi.side = h('aside', { class: 'vn-play-side' });
    fill(body, h('div', { class: 'vn-play-layout' }, h('div', { class: 'vn-play-main' }, playUi.stage, h('p', { class: 'hint vn-play-keys', text: t('Click, Space or Enter go on · ← or Backspace go back · locked options show struck through, with the condition') })), playUi.side));
    if (!v.play || v.play.story !== story().id) startPlay(); else drawPlay();
  }
  function stopPlay() { if (v.play?.timer) clearTimeout(v.play.timer); if (v.play) v.play.timer = null; }
  function playStale() { if (v.play && !v.play.stale) { v.play.stale = true; drawPlaySide(); } }
  function startPlayFrom(label) { v.play = null; v.startAt = label; open('play'); }
  async function startPlay(at = v.startAt || null) {
    v.startAt = null;
    const id = story().id;
    if (playUi.stage) fill(playUi.stage, h('div', { class: 'vn-play-loading' }, h('span', { class: 'spinner' })));
    try {
      const { vn } = await api('vn', undefined, { id, full: '1' });
      if (story()?.id !== id) return;
      const initial = Object.fromEntries(vn.variables.map(x => [x.name, x.initial]));
      v.play = { story: id, data: vn, byLabel: new Map(vn.scenes.map(s => [s.label, s])), vars: initial, scene: null, step: 0, view: blankView(), history: [], waiting: null, ended: null, stale: false, showLocked: true, notes: false, seen: new Set() };
      const first = at || vn.settings.start || vn.scenes[0]?.label;
      if (!first) { drawPlay(); return; }
      enterScene(first);
    } catch (e) { if (playUi.stage) fill(playUi.stage, h('p', { class: 'error', text: e.message })); }
  }
  const blankView = () => ({ bg: null, cg: null, shown: [], music: null, say: null, effect: null, sound: null, note: null });
  const clone = x => JSON.parse(JSON.stringify(x));
  function enterScene(label) {
    const p = v.play, s = p.byLabel.get(label);
    if (!s) { p.ended = { missing: label }; p.waiting = null; drawPlay(); return; }
    p.scene = label; p.step = 0; p.seen.add(label);
    run();
  }
  /** Plays the steps until one waits for the player (a line, a pause, the exit). */
  function run() {
    const p = v.play, s = p.byLabel.get(p.scene); stopPlay();
    p.view.effect = null; p.view.sound = null; p.view.note = null;
    while (p.step < s.steps.length) {
      const st = s.steps[p.step++];
      if (st.cond && !truthy(evaluate(st.cond, p.vars))) continue;
      const view = p.view;
      switch (st.t) {
        case 'note': if (p.notes) view.note = st.text; break;
        case 'bg': view.bg = { place: st.place, variant: st.variant, with: st.with }; view.cg = null; view.shown = []; break;
        case 'show': {
          const at = view.shown.findIndex(x => x.who.toLowerCase() === st.who.toLowerCase());
          if (at >= 0) view.shown[at] = { ...view.shown[at], attrs: st.attrs || view.shown[at].attrs, at: st.at || view.shown[at].at };
          else view.shown.push({ who: st.who, attrs: st.attrs, at: st.at });
          break;
        }
        case 'hide': view.shown = view.shown.filter(x => x.who.toLowerCase() !== st.who.toLowerCase()); break;
        case 'cg': view.cg = st.name; break;
        case 'music': view.music = st.track; break;
        case 'sound': view.sound = st.track; break;
        case 'set': p.vars = apply(st, p.vars); break;
        case 'effect': view.effect = st.name; break;
        case 'pause': p.waiting = { pause: st.seconds }; p.history.push(snapshot()); p.timer = setTimeout(() => { if (v.play === p && p.waiting?.pause) advance(); }, st.seconds * 1000); drawPlay(); return;
        case 'say': {
          if (st.who && st.attrs) { const at = view.shown.findIndex(x => x.who.toLowerCase() === st.who.toLowerCase()); if (at >= 0) view.shown[at] = { ...view.shown[at], attrs: st.attrs }; }
          view.say = { who: st.who, text: st.text };
          p.waiting = { say: true }; p.history.push(snapshot()); drawPlay(); return;
        }
      }
    }
    exitScene();
  }
  /** Where the preview stopped (taken once it waits, so going back here waits for the same click). */
  function snapshot() { const p = v.play; return { scene: p.scene, step: p.step, vars: clone(p.vars), view: clone(p.view), waiting: clone(p.waiting), ended: null }; }
  function exitScene() {
    const p = v.play, s = p.byLabel.get(p.scene), e = s.run || s.exit;
    p.view.say = null;
    if (e.type === 'jump') { enterScene(e.to); return; }
    if (e.type === 'branch') {
      const hit = e.cases.find(c => truthy(evaluate(c.cond, p.vars)));
      const to = hit ? hit.to : e.else;
      if (to) { enterScene(to); return; }
      p.ended = { stuck: t('No condition of the branch held and there is no “else”: the game stops here.') }; p.waiting = null; drawPlay(); return;
    }
    if (e.type === 'choice') { p.waiting = { choice: e }; p.history.push(snapshot()); drawPlay(); return; }
    if (e.type === 'end') { p.ended = { title: e.title, kind: e.kind }; p.waiting = null; drawPlay(); return; }
    p.ended = { stuck: t('This scene has no exit: the game stops here.') }; p.waiting = null; drawPlay();
  }
  function advance() {
    const p = v.play; if (!p || p.ended || p.waiting?.choice) return;
    if (p.waiting) { p.waiting = null; run(); }
  }
  function choose(o) {
    const p = v.play;
    for (const set of o.sets || []) p.vars = apply(set, p.vars);
    p.waiting = null; enterScene(o.to);
  }
  function back() {
    const p = v.play; if (!p || p.history.length < 2 && !(p.ended && p.history.length)) return;
    stopPlay();
    if (!p.ended) p.history.pop();
    const prev = p.history[p.history.length - 1]; if (!prev) return;
    Object.assign(p, { scene: prev.scene, step: prev.step, vars: clone(prev.vars), view: clone(prev.view), waiting: clone(prev.waiting), ended: null });
    drawPlay();
  }
  function drawPlay() {
    const p = v.play; if (!playUi.stage?.isConnected) return;
    const d = p?.data;
    if (!d) return;
    const aspect = (d.settings.aspect || '16:9').split(':');
    playUi.stage.style.aspectRatio = `${aspect[0]} / ${aspect[1]}`;
    const castList = cast(), art = d.art, view = p.view;
    const layers = [];
    // The background (or a card with the place's name when there is no picture yet)
    if (view.bg) {
      const place = findCast(castList, view.bg.place, 'place');
      const b = backgroundFor(art.backgrounds, place?.id || null, place?.name || view.bg.place, view.bg.variant);
      layers.push(b?.image ? h('img', { class: 'vn-layer bg', src: imageUrl(b.image), alt: '' })
        : h('div', { class: 'vn-layer bg missing' }, h('span', { text: `${view.bg.place}${view.bg.variant ? ` (${view.bg.variant})` : ''}` }), h('small', { text: t('background with no image yet') })));
    } else layers.push(h('div', { class: 'vn-layer bg blank' }));
    // The characters, at their places (the ones without a place spread out)
    const free = view.shown.filter(x => !x.at), slots = AUTO_SLOTS[Math.min(free.length, 3) - 1] || [];
    let n = 0;
    for (const x of view.shown) {
      const at = x.at || slots[n++] || 'center', c = findCast(castList, x.who, 'character');
      const sprite = c ? spriteFor(art.sprites, c.id, x.attrs) : null;
      const el = sprite?.image ? h('img', { class: `vn-sprite ${d.settings.framing}`, src: imageUrl(sprite.image), alt: `${x.who}${x.attrs ? ` (${x.attrs})` : ''}` })
        : h('div', { class: `vn-sprite missing ${d.settings.framing}` }, h('span', { text: x.who }), h('small', { text: x.attrs || 'base' }));
      el.style.left = `${POSITIONS[at] ?? 50}%`;
      layers.push(el);
    }
    if (view.cg) {
      const cg = art.cgs.find(c => c.name === view.cg);
      layers.push(cg?.image ? h('img', { class: 'vn-layer cg', src: imageUrl(cg.image), alt: '' }) : h('div', { class: 'vn-layer cg missing' }, h('span', { text: `CG ${view.cg}` }), h('small', { text: t('no image yet') })));
    }
    if (view.music !== null && view.music !== undefined) layers.push(h('span', { class: 'vn-music', title: t('Music playing (the preview does not play audio)') }, icon('volume'), h('span', { text: view.music })));
    if (view.sound) layers.push(h('span', { class: 'vn-sound' }, icon('volume'), h('span', { text: view.sound })));
    if (view.note) layers.push(h('span', { class: 'vn-note' }, h('span', { text: `# ${view.note}` })));
    if (view.say) layers.push(h('div', { class: 'vn-textbox' }, view.say.who ? h('span', { class: 'vn-speaker', text: view.say.who }) : null, h('p', { class: view.say.who ? '' : 'narration', text: view.say.text })));
    if (p.waiting?.pause) layers.push(h('span', { class: 'vn-pause', text: t('pause of {seconds} s', { seconds: number(p.waiting.pause) }) }));
    if (p.waiting?.choice) {
      const e = p.waiting.choice;
      layers.push(h('div', { class: 'vn-choices' }, e.prompt ? h('p', { class: 'vn-choice-prompt', text: e.prompt }) : null,
        ...e.options.map(o => {
          const open = !o.cond || truthy(evaluate(o.cond, p.vars));
          if (!open && !p.showLocked) return null;
          return h('button', { type: 'button', class: `vn-choice${open ? '' : ' locked'}`, disabled: !open, title: o.note || (open ? '' : t('Only shows if {condition}', { condition: o.if })), onclick: () => choose(o) },
            h('span', { text: o.text }), !open ? h('small', { text: t('if {condition}', { condition: o.if }) }) : null);
        })));
    }
    if (p.ended) layers.push(h('div', { class: `vn-end ${p.ended.kind || 'stuck'}` },
      p.ended.title ? [h('small', { text: t('Ending {kind}', { kind: ENDING_LABEL[p.ended.kind] || '' }).trim() }), h('strong', { text: p.ended.title })]
        : [h('strong', { text: p.ended.missing ? t('The scene “{label}” does not exist', { label: p.ended.missing }) : t('The game stopped') }), h('p', { text: p.ended.stuck || t('The exit leads to a scene that has not been created yet.') })],
      h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn secondary small', onclick: () => back() }, t('Back')), h('button', { type: 'button', class: 'btn primary small', onclick: () => startPlay() }, t('Play again')))));
    if (!p.scene && !p.ended) layers.push(h('div', { class: 'vn-end stuck' }, h('strong', { text: t('Nothing to play yet') }), h('p', { text: t('Create the first scene (or choose the starting scene in Variables and game).') })));
    fill(playUi.stage, ...layers);
    playUi.stage.classList.toggle('shake', view.effect === 'shake');
    playUi.stage.classList.toggle('flash', view.effect === 'flash');
    if (view.effect) setTimeout(() => playUi.stage?.classList.remove('shake', 'flash'), 600);
    drawPlaySide();
  }
  function drawPlaySide() {
    const p = v.play, side = playUi.side; if (!p || !side?.isConnected) return;
    const d = p.data, s = p.byLabel.get(p.scene);
    const jump = h('select', { 'aria-label': t('Go to the scene'), onchange: e => { if (e.target.value) { p.ended = null; p.waiting = null; p.view = blankView(); p.history = []; enterScene(e.target.value); } } },
      h('option', { value: '', text: t('Go to the scene…') }), ...d.scenes.map(x => h('option', { value: x.label, text: `${x.label} · ${x.title || ''}` })));
    const varInput = x => {
      const val = p.vars[x.name];
      const set = value => { p.vars = { ...p.vars, [x.name]: value }; if (p.waiting?.choice) drawPlay(); else drawPlaySide(); };
      if (x.type === 'flag') { const el = h('input', { type: 'checkbox', onchange: e => set(e.target.checked), 'aria-label': x.name }); el.checked = Boolean(val); return el; }
      return h('input', { type: x.type === 'number' ? 'number' : 'text', step: 'any', value: String(val ?? ''), 'aria-label': x.name, onchange: e => set(x.type === 'number' ? Number(e.target.value) || 0 : e.target.value) });
    };
    const extra = Object.keys(p.vars).filter(k => !d.variables.some(x => x.name === k));
    fill(side,
      p.stale ? h('div', { class: 'vn-banner' }, icon('alert'), h('span', { text: t('The project changed after the preview started.') }), h('button', { type: 'button', class: 'btn secondary small', onclick: () => startPlay() }, t('Restart'))) : null,
      h('div', { class: 'vn-play-group' }, h('div', { class: 'vn-play-now' }, h('span', { class: 'muted', text: t('Scene') }), s ? h('button', { type: 'button', class: 'link-btn vn-scene-link', text: s.label, onclick: () => open('scene', s.id) }) : h('span', { text: '—' }),
        s?.title ? h('span', { class: 'muted', text: ` · ${s.title}` }) : null),
      h('div', { class: 'row-end vn-play-buttons' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => back(), disabled: p.history.length < 2 && !p.ended }, icon('undo'), h('span', { text: t('Back') })),
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => startPlay() }, icon('rewind'), h('span', { text: t('From the start') }))),
      jump,
      h('label', { class: 'switch' }, (() => { const c = h('input', { type: 'checkbox', onchange: e => { p.showLocked = e.target.checked; drawPlay(); } }); c.checked = p.showLocked; return c; })(), h('span', { class: 'switch-track' }), h('span', { text: t('Show locked options') })),
      h('label', { class: 'switch' }, (() => { const c = h('input', { type: 'checkbox', onchange: e => { p.notes = e.target.checked; } }); c.checked = p.notes; return c; })(), h('span', { class: 'switch-track' }), h('span', { text: t('Show the notes (#)') }))),
      h('div', { class: 'vn-play-group' }, h('h4', { text: t('Variables') }),
      d.variables.length || extra.length ? h('div', { class: 'vn-play-vars' }, ...d.variables.map(x => h('label', { class: 'vn-play-var' }, h('code', { text: x.name }), varInput(x))),
        ...extra.map(k => h('div', { class: 'vn-play-var' }, h('code', { text: k }), h('span', { text: String(p.vars[k]) })))) : h('p', { class: 'hint', text: t('No variables.') }),
      h('p', { class: 'hint', text: t('Scenes seen in this playthrough: {n} of {total}.', { n: p.seen.size, total: d.scenes.length }) })));
  }

  // -----------------------------------------------------------------------------------------------------------------
  // The person's documents: files (.md, .txt, .docx) or pasted text, each to where it belongs
  // -----------------------------------------------------------------------------------------------------------------
  let importDialog = null;
  function guessTarget(name, text) {
    const n = `${name} ${String(text).slice(0, 200)}`.toLowerCase();
    if (/personag|character|ficha|\bchar\b/.test(n)) return 'character';
    if (/\blugar|cenári|cenari|local|location|place\b/.test(n)) return 'place';
    if (/cap[ií]tulo|chapter|\bcap\.?\s*\d|\bch\s*\d|epis[oó]dio/.test(n)) return 'chapter';
    if (/jogabil|gameplay|mec[aâ]nica|sistema/.test(n)) return 'gameplay';
    if (/planej|roteiro geral|outline|plano/.test(n)) return 'plan';
    return 'notes';
  }
  /** The text of a Word document: its paragraphs, in order (word/document.xml, read with the browser's own XML parser). */
  async function docxText(file) {
    const files = await ctx.unzip(file), xml = files.get('word/document.xml');
    if (!xml) throw new Error(t('{name} does not look like a Word .docx.', { name: file.name }));
    const doc = new DOMParser().parseFromString(new TextDecoder().decode(xml), 'application/xml');
    const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', out = [];
    for (const p of doc.getElementsByTagNameNS(W_NS, 'p')) {
      let line = '';
      for (const node of p.getElementsByTagNameNS(W_NS, '*')) {
        if (node.localName === 't') line += node.textContent;
        else if (node.localName === 'tab') line += '\t';
        else if (node.localName === 'br') line += '\n';
      }
      const style = p.getElementsByTagNameNS(W_NS, 'pStyle')[0]?.getAttributeNS(W_NS, 'val') || '';
      const level = /^(Heading|Ttulo|Titulo|Título)(\d)/i.exec(style)?.[2];
      out.push(level && line.trim() ? `${'#'.repeat(Math.min(3, Number(level)))} ${line.trim()}` : line);
    }
    return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
  }
  /** A document split at its top headings (# or ##): one part per heading, with the heading as its title. */
  function splitDoc(title, text) {
    const lines = text.split('\n'), top = lines.some(l => /^# \S/.test(l)) ? /^# (\S.*)$/ : /^## (\S.*)$/, parts = [];
    let cur = null;
    for (const l of lines) {
      const m = top.exec(l);
      if (m) { cur = { title: m[1].trim(), lines: [] }; parts.push(cur); continue; }
      if (!cur) { cur = { title, lines: [] }; parts.push(cur); }
      cur.lines.push(l);
    }
    return parts.map(p => ({ title: p.title, text: p.lines.join('\n').trim() })).filter(p => p.text);
  }
  function openImport() {
    if (!importDialog) {
      importDialog = h('dialog', { class: 'sheet wide vn-import', 'aria-labelledby': 'vn-import-title' });
      importDialog.addEventListener('click', e => { if (e.target !== importDialog) return; const r = importDialog.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) importDialog.close(); });
      document.body.append(importDialog);
    }
    v.importing = { docs: [] };
    drawImport();
    importDialog.showModal();
  }
  function drawImport() {
    const st = v.importing, list = h('ol', { class: 'vn-import-list' });
    const files = h('input', { type: 'file', multiple: true, accept: '.md,.markdown,.txt,.docx,text/plain,text/markdown,application/vnd.openxmlformats-officedocument.wordprocessingml.document', hidden: true,
      onchange: async e => { const chosen = [...e.target.files]; e.target.value = ''; await addFiles(chosen); } });
    const paste = h('textarea', { rows: 5, placeholder: t('Or paste here the text of a document (the design, a chapter\'s base, a character)…'), 'aria-label': t('Pasted text') });
    const split = h('input', { type: 'checkbox' }); split.checked = st.split ?? false;
    const addText = (title, text) => {
      const parts = split.checked ? splitDoc(title, text) : [{ title, text }];
      for (const p of parts) st.docs.push({ title: p.title, text: p.text, to: guessTarget(p.title, p.text) });
      drawList();
    };
    async function addFiles(chosen) {
      for (const file of chosen) {
        try {
          const text = /\.docx$/i.test(file.name) ? await docxText(file) : (await file.text()).replace(/\r\n?/g, '\n');
          addText(file.name.replace(/\.(md|markdown|txt|docx)$/i, ''), text);
        } catch (e) { toast(e.message || t('Could not read {name}.', { name: file.name }), { tone: 'error' }); }
      }
    }
    const drawList = () => {
      fill(list, ...st.docs.map((doc, i) => {
        const title = h('input', { value: doc.title, maxlength: 200, 'aria-label': t('Title'), oninput: e => { doc.title = e.target.value; } });
        const to = h('select', { 'aria-label': t('Where it goes'), onchange: e => { doc.to = e.target.value; } }, ...IMPORT_TARGETS.map(([id, label]) => h('option', { value: id, text: label, selected: doc.to === id })));
        return h('li', {}, h('div', { class: 'vn-row' }, title, to, h('span', { class: 'muted', text: `${num(ctx.wordCount(doc.text))} palavras` }),
          h('button', { type: 'button', class: 'icon-btn tiny danger', 'aria-label': t('Remove', { ctx: 'take' }), title: t('Remove', { ctx: 'take' }), onclick: () => { st.docs.splice(i, 1); drawList(); } }, icon('close'))),
          h('p', { class: 'vn-import-preview', text: doc.text.slice(0, 220) + (doc.text.length > 220 ? '…' : '') }));
      }));
      go.disabled = organize.disabled = !st.docs.length;
    };
    const go = h('button', { type: 'button', class: 'btn secondary small', onclick: () => send(false) }, h('span', { text: t('Import') }));
    const organize = h('button', { type: 'button', class: 'btn primary small', onclick: () => send(true), title: t('Imports and writes a message to the writer asking to organize everything into scenes, routes and variables (you check it and send it)') }, icon('feather'), h('span', { text: t('Import and ask the writer') }));
    async function send(ask) {
      if (!st.docs.length) return;
      go.disabled = organize.disabled = true;
      try {
        const counts = await api('vn-import', { story: story().id, items: st.docs.map(d => ({ to: d.to, title: d.title, text: d.text })) });
        importDialog.close();
        await ctx.reload();
        const said = [counts.notes ? t('{n} in the design document', { n: counts.notes }) : '', counts.chapters ? plural(counts.chapters, t('{n} chapter'), t('{n} chapters')) : '', counts.characters ? plural(counts.characters, t('{n} character sheet'), t('{n} character sheets')) : '',
          counts.places ? plural(counts.places, t('{n} place sheet'), t('{n} place sheets')) : '', counts.gameplay ? t('{n} in the gameplay notes', { n: counts.gameplay }) : '', counts.plan ? t('{n} in the plan', { n: counts.plan }) : ''].filter(Boolean).join(', ');
        toast(t('Imported: {what}.', { what: said }));
        if (ask) ctx.prefillChat(t('I imported my documents ({what}). Read everything and organize the project: the scene tree with the choices, the routes, the variables (affinity, flags), the endings and the gameplay notes, following what the documents say. Do not make up what they already decide; whatever is missing, ask me first.', { what: said }));
      } catch (e) { toast(e.message, { tone: 'error', duration: 12000 }); go.disabled = organize.disabled = false; }
    }
    fill(importDialog,
      h('div', { class: 'sheet-head' }, h('h2', { id: 'vn-import-title', text: t('Import documents') }), h('button', { type: 'button', class: 'icon-btn', 'aria-label': t('Close'), onclick: () => importDialog.close() }, icon('close'))),
      h('div', { class: 'stack' },
        h('p', { class: 'hint', text: t('The design document, the chapter bases, the character documents. Each one goes where you choose: the bible (design document), a chapter, a sheet (with the whole document kept in the bible), the gameplay notes or the plan. .md, .txt or .docx files.') }),
        h('div', { class: 'row-end vn-import-pick' },
          h('label', { class: 'switch', title: t('A file with several characters or chapters becomes one document per heading (# or ##)') }, split, h('span', { class: 'switch-track' }), h('span', { text: t('Split by headings') })),
          h('span', { class: 'grow' }),
          h('button', { type: 'button', class: 'btn secondary small', onclick: () => files.click() }, icon('upload'), h('span', { text: t('Choose files') })), files),
        paste,
        h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn ghost small', onclick: () => { if (paste.value.trim()) { addText(paste.value.trim().split('\n')[0].replace(/^#+\s*/, '').slice(0, 80) || t('Pasted text'), paste.value.trim()); paste.value = ''; } } }, icon('plus'), h('span', { text: t('Add the pasted text') }))),
        list,
        h('div', { class: 'row-end' }, go, organize)));
    split.addEventListener('change', () => { st.split = split.checked; });
    drawList();
  }

  // -----------------------------------------------------------------------------------------------------------------
  // The game for Ren'Py: the scripts from the server, the pictures from the gallery, in a zip made here
  // -----------------------------------------------------------------------------------------------------------------
  async function exportRenpy(say = () => {}) {
    const s = story(); if (!s) return;
    say(t('Building the Ren\'Py script…'));
    const { files, images } = await api('vn-export', undefined, { id: s.id });
    const enc = new TextEncoder(), out = Object.entries(files).map(([name, text]) => ({ name, bytes: enc.encode(text) }));
    const list = Object.entries(images);
    let missing = 0;
    for (const [i, [path, id]] of list.entries()) {
      say(t('Downloading the images… {n} of {total}', { n: i + 1, total: list.length }));
      try {
        const response = await fetch(imageUrl(id), { credentials: 'same-origin' });
        if (!response.ok) throw new Error(String(response.status));
        out.push({ name: path, bytes: new Uint8Array(await (await response.blob()).arrayBuffer()) });
      } catch { missing++; }
    }
    say(t('Building the zip…'));
    ctx.download(ctx.fileName(`${data()?.settings.name || s.title || 'visual novel'} renpy`, 'zip'), 'application/zip', ctx.zip(out));
    say('');
    if (missing) toast(plural(missing, t('{n} image did not come from the server and was left out of the zip.'), t('{n} images did not come from the server and were left out of the zip.')), { tone: 'error' });
  }

  // -----------------------------------------------------------------------------------------------------------------
  // Keys, the side's lines
  // -----------------------------------------------------------------------------------------------------------------
  function key(e) {
    if (story()?.kind !== 'vn' || document.querySelector('dialog[open]')) return false;
    if (v.view === 'scene' && (e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveScene(); return true; }
    if (v.view === 'play' && v.play) {
      const typing = e.target instanceof HTMLElement && (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable);
      if (typing || e.ctrlKey || e.metaKey || e.altKey) return false;
      if (e.key === ' ' || e.key === 'Enter') { if (e.target instanceof HTMLButtonElement && e.target.closest('.vn-choices, .vn-end')) return false; e.preventDefault(); advance(); return true; }
      if (e.key === 'ArrowLeft' || e.key === 'Backspace') { e.preventDefault(); back(); return true; }
      if (/^[1-9]$/.test(e.key) && v.play.waiting?.choice) {
        const o = v.play.waiting.choice.options.filter(x => !x.cond || truthy(evaluate(x.cond, v.play.vars)))[Number(e.key) - 1];
        if (o) { e.preventDefault(); choose(o); return true; }
      }
    }
    return false;
  }
  /** The project in a line: scenes, endings, words. */
  function summary() {
    const d = data(); if (!d) return '';
    const a = d.analysis.stats;
    return `${plural(a.scenes, t('{n} scene'), t('{n} scenes'))} · ${plural(a.endings, t('{n} ending'), t('{n} endings'))} · ${plural(a.words, t('{n} word'), t('{n} words'))}`;
  }
  function problemsLine() {
    const d = data(); if (!d) return null;
    const errors = d.analysis.problems.filter(p => p.level === 'error').length, warns = d.analysis.problems.filter(p => p.level === 'warn').length;
    return errors || warns ? { errors, warns, text: [errors ? plural(errors, t('{n} error'), t('{n} errors')) : '', warns ? plural(warns, t('{n} warning'), t('{n} warnings')) : ''].filter(Boolean).join(' · ') } : null;
  }
  function showProblems() { v.tree.problems = true; open('tree'); }

  return { render, refresh, open, reset, key, summary, problemsLine, showProblems, newScene, openImport, exportRenpy, bar,
    get view() { return v.view; }, get scene() { return v.scene; }, get dirty() { return v.dirty; } };
}
