// The reader: the text of a story on the whole screen, in the manner of an e-reader. A tap in the middle of the screen
// shows or hides the bars (the story and the chapter, the chapters, the text settings, the place in the chapter and the
// chapters around it); a tap near either side, a swipe, the arrow keys or the wheel turn the page. The settings (size,
// font, spacing, width of the text, alignment, theme, pages or scrolling, what the line at the foot shows) are kept with
// the account on the server (Vault::setReaderPrefs), so they follow it to any device. stories.js owns the story and the
// history; this file owns the layout and the controls. Text from a person or a model is only ever set as text: never
// through innerHTML.
import { t, number } from './i18n.js';

export const READER_SIZES = [80, 90, 100, 110, 120, 135, 150, 170, 200];
export const READER_DEFAULTS = Object.freeze({ size: 100, font: 'serif', spacing: 'normal', width: 'medium', align: 'left', theme: 'dark', mode: 'pages', spread: true, device: true, open: false, progress: true, remaining: true });
const CHOICES = { font: ['serif', 'sans'], spacing: ['tight', 'normal', 'wide'], width: ['narrow', 'medium', 'wide', 'full'], align: ['left', 'justify'], theme: ['dark', 'black', 'sepia', 'light'], mode: ['pages', 'scroll'] };
// progress and remaining: the line at the foot of the text shows the page (or the part read) and the time left.
const FLAGS = ['spread', 'device', 'open', 'progress', 'remaining'];
// The longest line of each width, in ems of the text (an em holds about two characters).
export const MEASURE = { narrow: 32, medium: 40, wide: 50, full: Infinity };
const WORDS_PER_MINUTE = 230;
const THEMES = [['dark', t('Dark', { ctx: 'theme' }), '#0e0e10', '#e6e3dc'], ['black', t('Black', { ctx: 'theme' }), '#000000', '#cfcac0'], ['sepia', t('Sepia', { ctx: 'theme' }), '#f5ecd9', '#4a3a2c'], ['light', t('Light', { ctx: 'theme' }), '#fbfaf8', '#222224']];

/** The settings as saved (the server, or nothing yet), with the defaults for what is missing or unknown. */
export function readerSettings(saved) {
  const out = { ...READER_DEFAULTS };
  if (!saved || typeof saved !== 'object') return out;
  if (Number.isFinite(saved.size)) out.size = READER_SIZES.reduce((best, s) => Math.abs(s - saved.size) < Math.abs(best - saved.size) ? s : best, READER_SIZES[0]);
  for (const [name, values] of Object.entries(CHOICES)) if (values.includes(saved[name])) out[name] = saved[name];
  for (const name of FLAGS) if (typeof saved[name] === 'boolean') out[name] = saved[name];
  return out;
}

/**
 * How the pages fill a screen: one or two columns, and the gap between them, which is also the margin at either side
 * (half of it on each). A column is never wider than the chosen width of the text. Two pages side by side on a wide
 * screen lying down (a tablet or a computer), unless that makes the lines much shorter than the chosen width.
 * @return {{columns: number, gap: number, column: number}} sizes in CSS pixels
 */
export function pageLayout({ width, height, font, prefs }) {
  const measure = MEASURE[prefs.width] * font;
  const margin = Math.max(20, Math.min(48, Math.round(width * 0.04)));
  if (prefs.spread && prefs.width !== 'full' && width >= 900 && width > height * 1.1) {
    const column = Math.min(measure, width / 2 - 2 * margin);
    if (column >= 0.6 * measure) { const gap = Math.round(width / 2 - column); return { columns: 2, gap, column: width / 2 - gap }; }
  }
  const gap = Math.round(width - Math.min(measure, width - 2 * margin));
  return { columns: 1, gap, column: width - gap };
}

/** What is left of the chapter, in reading time: `left` is the part not read yet (0 to 1). */
export function minutesLeft(words, left) {
  const part = Math.max(0, Math.min(1, Number(left) || 0)), minutes = (Number(words) || 0) * part / WORDS_PER_MINUTE;
  if (part <= 0.001) return t('End of the chapter');
  if (minutes < 0.75) return t('Less than 1 min left in the chapter');
  const m = Math.round(minutes);
  return m === 1 ? t('1 min left in the chapter') : t('{n} min left in the chapter', { n: m });
}

/** The boxes of an element on screen: one per column it runs through (a paragraph broken over two pages has two). */
function fragments(el) { return [...el.getClientRects()].filter(r => r.width > 0 && r.height > 0); }
const blocksOf = doc => [...doc.querySelectorAll('.prose > *')];

/**
 * Where the reading is: the first block of the text on screen and how much of it is behind (above the top of the
 * screen, or on the pages before). Null without text. The same place reads on scrolling text and on pages, so it moves
 * between them, and between devices.
 */
export function placeOf(doc, paged = false) {
  const blocks = blocksOf(doc), view = doc.getBoundingClientRect();
  if (!paged) {
    for (let i = 0; i < blocks.length; i++) {
      const r = blocks[i].getBoundingClientRect();
      if (r.bottom > view.top + 1) return { block: i, offset: r.top < view.top && r.height ? Math.min(1, (view.top - r.top) / r.height) : 0 };
    }
    return { block: Math.max(0, blocks.length - 1), offset: 1 };
  }
  for (let i = 0; i < blocks.length; i++) {
    const parts = fragments(blocks[i]);
    if (!parts.length) continue;
    const at = parts.findIndex(p => p.right > view.left + 1 && p.left < view.right - 1);
    if (at === -1) { if (parts[0].left >= view.right - 1) return { block: i, offset: 0 }; continue; }
    if (parts.length === 1) {
      // One box: a block on a single page, or a browser that gives one box for all the columns of a block.
      const p = parts[0];
      return { block: i, offset: p.left < view.left && p.width ? Math.min(1, (view.left - p.left) / p.width) : 0 };
    }
    const total = parts.reduce((sum, p) => sum + p.height, 0), before = parts.slice(0, at).reduce((sum, p) => sum + p.height, 0);
    return { block: i, offset: total ? before / total : 0 };
  }
  return { block: Math.max(0, blocks.length - 1), offset: 1 };
}

/**
 * Brings a place on screen: scrolls the text to it, or (with pages) returns the page it is on, counted from 0, for the
 * caller to show. Null when the block isn't there (yet).
 */
export function pageOrScroll(doc, place, { paged = false, step = 0, shift = 0 } = {}) {
  const b = blocksOf(doc)[place.block];
  if (!b) return null;
  const offset = Math.max(0, Math.min(1, Number(place.offset) || 0)), view = doc.getBoundingClientRect();
  if (!paged) {
    // The very start of the chapter shows its title too.
    if (place.block === 0 && offset === 0) { doc.scrollTop = 0; return doc.scrollTop; }
    const r = b.getBoundingClientRect();
    doc.scrollTop += r.top - view.top + offset * r.height;
    return doc.scrollTop;
  }
  const parts = fragments(b);
  if (!parts.length || !step) return null;
  let x;
  if (parts.length === 1) x = parts[0].left + offset * Math.max(0, parts[0].width - 2);
  else {
    // The box the place falls in; a hair past a boundary between two boxes is the later one (offsets are rounded).
    const total = parts.reduce((sum, p) => sum + p.height, 0), target = offset * total;
    let acc = 0, part = parts[parts.length - 1];
    for (const p of parts) { if (acc + p.height > target + 1) { part = p; break; } acc += p.height; }
    x = part.left + 1;
  }
  // shift: how far the pages are moved to the left now.
  return Math.max(0, Math.floor((x - view.left + shift) / step));
}

/**
 * The reader. host: what stories.js shares.
 *   root(), doc(): the story's element and the element of the text; story(), index(): the story and the chapter shown;
 *   go(i, at): shows chapter i at its 'start' or 'end'; exit(): leaves the reader (with the history);
 *   moved(): the place changed (stories.js saves it after a pause).
 */
export function createReader({ h, icon, api, toast, local, saveLocal, host }) {
  let prefs = { ...READER_DEFAULTS };
  // shift: how far the pages are moved to the left now, in pixels (page × step, until the next layout).
  // expect: where the reader itself last scrolled the text to (its scroll event isn't the person's).
  const rd = { on: false, chrome: false, panel: null, anchor: null, step: 0, page: 0, pages: 1, shift: 0, expect: null, frame: 0, anchorTimer: null,
    saveTimer: null, dirty: false, ownScreen: false, wheelAt: 0, swipe: null, ui: null, chromeAt: 0 };
  const paged = () => rd.on && prefs.mode === 'pages';
  const coarse = () => matchMedia('(pointer: coarse)').matches;
  const screenEl = () => document.fullscreenElement || document.webkitFullscreenElement || null;
  const canScreen = () => Boolean(document.fullscreenEnabled || document.webkitFullscreenEnabled);

  // ---------------------------------------------------------------------------------------------------------------
  // Settings
  // ---------------------------------------------------------------------------------------------------------------
  function setPrefs(saved) { prefs = readerSettings(saved); rd.dirty = false; clearTimeout(rd.saveTimer); apply(); }
  function change(patch) {
    const before = rd.on ? capture() : null;
    prefs = readerSettings({ ...prefs, ...patch });
    if ('device' in patch && rd.on) { if (prefs.device) takeScreen(); else leaveScreen(); }
    apply();
    if (before) show(before);
    renderPanel(); update();
    rd.dirty = true; clearTimeout(rd.saveTimer); rd.saveTimer = setTimeout(save, 700);
  }
  function save({ leaving = false } = {}) {
    clearTimeout(rd.saveTimer); rd.saveTimer = null;
    if (!rd.dirty) return;
    rd.dirty = false;
    api('reader-prefs', { reader: prefs }, {}, { keepalive: leaving }).catch(e => {
      rd.dirty = true;
      if (!leaving) toast(t('The reading preferences were not saved: {error}', { error: e.message }), { tone: 'error' });
    });
  }
  window.addEventListener('pagehide', () => save({ leaving: true }));
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') save({ leaving: true }); });

  /** The settings on the story's element. The text's size, font, spacing and alignment also hold outside the reader. */
  function apply() {
    const root = host.root();
    if (!root) return;
    root.style.setProperty('--read-scale', String(prefs.size / 100));
    Object.assign(root.dataset, { font: prefs.font, spacing: prefs.spacing, align: prefs.align, theme: prefs.theme });
    root.classList.toggle('reader', rd.on);
    root.classList.toggle('paged', paged());
    root.classList.toggle('chrome', rd.on && rd.chrome);
    if (rd.on) layout();
  }
  /** Sizes that depend on the screen and the text: the width of the lines, the columns of the pages. */
  function layout() {
    const root = host.root(), doc = host.doc();
    if (!root || !doc) return;
    const prose = doc.querySelector('.prose');
    const font = parseFloat(getComputedStyle(prose || doc).fontSize) || 18.5 * prefs.size / 100;
    const width = doc.clientWidth, height = doc.clientHeight;
    const measure = MEASURE[prefs.width] * font;
    root.style.setProperty('--r-measure', Number.isFinite(measure) ? `${Math.round(measure)}px` : 'none');
    if (!paged()) { rd.step = 0; rd.shift = 0; root.style.removeProperty('--r-shift'); return; }
    const pages = pageLayout({ width, height, font, prefs });
    root.style.setProperty('--r-cols', String(pages.columns));
    root.style.setProperty('--r-gap', `${pages.gap}px`);
    const flow = doc.querySelector('.doc-flow');
    if (flow) {
      const cs = getComputedStyle(flow);
      root.style.setProperty('--r-page-h', `${Math.max(120, flow.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom))}px`);
    }
    rd.step = width;
    rd.pages = countPages();
    setShift(Math.min(rd.page, rd.pages - 1));
  }

  // ---------------------------------------------------------------------------------------------------------------
  // Pages
  // ---------------------------------------------------------------------------------------------------------------
  function setShift(page) { rd.page = Math.max(0, page); rd.shift = rd.page * rd.step; host.root()?.style.setProperty('--r-shift', `${-rd.shift}px`); }
  function countPages() {
    const doc = host.doc(), flow = doc?.querySelector('.doc-flow');
    if (!flow || !rd.step) return 1;
    const view = doc.getBoundingClientRect();
    let right = 0;
    for (const el of flow.children) for (const p of fragments(el)) right = Math.max(right, p.right);
    if (!right) return 1;
    return Math.max(1, Math.floor((right - view.left + rd.shift - 1) / rd.step) + 1);
  }
  function setPage(n) {
    rd.pages = countPages();
    setShift(Math.max(0, Math.min(rd.pages - 1, n)));
    rd.anchor = capture(); update(); host.moved();
  }
  /** The height of a screen of text, less a couple of lines that stay in view (scrolling). */
  function screenful(doc) {
    const prose = doc.querySelector('.prose'), cs = prose && getComputedStyle(prose);
    const font = cs ? parseFloat(cs.fontSize) : 18;
    let line = cs ? parseFloat(cs.lineHeight) : NaN;
    if (!(line >= font)) line = font * 1.75;
    return Math.max(80, doc.clientHeight - 2.5 * line - 56);
  }
  function next() {
    const doc = host.doc(), s = host.story();
    if (!doc || !s) return;
    if (paged()) { if (rd.page < countPages() - 1) { setPage(rd.page + 1); return; } }
    else {
      const max = doc.scrollHeight - doc.clientHeight;
      if (doc.scrollTop < max - 2) { doc.scrollTop = Math.min(max, doc.scrollTop + screenful(doc)); return; }
    }
    if (host.index() < s.chapters.length - 1) host.go(host.index() + 1, 'start');
  }
  function prev() {
    const doc = host.doc();
    if (!doc) return;
    if (paged()) { if (rd.page > 0) { setPage(rd.page - 1); return; } }
    else if (doc.scrollTop > 2) { doc.scrollTop = Math.max(0, doc.scrollTop - screenful(doc)); return; }
    if (host.index() > 0) host.go(host.index() - 1, 'end');
  }
  function toEdge(end) {
    const doc = host.doc();
    if (!doc) return;
    if (paged()) setPage(end ? countPages() - 1 : 0);
    else doc.scrollTop = end ? doc.scrollHeight : 0;
  }

  // The place on screen, kept while the reader is open: after a change of size or settings, a turn of the screen or a
  // picture that loads, the same place is brought back.
  function capture() {
    const doc = host.doc(), c = host.story()?.chapters[host.index()];
    const at = doc && c ? placeOf(doc, paged()) : null;
    return at && { chapter: c.id, ...at };
  }
  function show(place) {
    const doc = host.doc(), c = host.story()?.chapters[host.index()];
    if (!doc || !place || !c || place.chapter !== c.id) return null;
    if (paged()) {
      rd.pages = countPages();
      const page = pageOrScroll(doc, place, { paged: true, step: rd.step, shift: rd.shift });
      if (page === null) return null;
      setShift(Math.min(page, rd.pages - 1));
      rd.anchor = place; update();
      return rd.page;
    }
    const top = pageOrScroll(doc, place);
    rd.anchor = place; rd.expect = top;
    return top;
  }
  function relayoutSoon() {
    if (!rd.on || rd.frame) return;
    rd.frame = requestAnimationFrame(() => {
      rd.frame = 0;
      if (!rd.on) return;
      layout();
      if (rd.anchor) show(rd.anchor);
      update();
    });
  }
  window.addEventListener('resize', relayoutSoon);

  // ---------------------------------------------------------------------------------------------------------------
  // Opening and leaving
  // ---------------------------------------------------------------------------------------------------------------
  /** place: where the reading is (from the panes, or saved). screen: false when no tap opened it (a reload). */
  function enter(place, { screen = true } = {}) {
    if (rd.on) return;
    rd.on = true; rd.panel = null; rd.page = 0; rd.anchor = null; rd.chrome = true;
    apply();
    if (place) show(place);
    rd.anchor = rd.anchor || capture();
    rd.chromeAt = position();
    if (screen) takeScreen();
    host.doc()?.focus({ preventScroll: true });
    update();
  }
  /** Leaves the reader; returns where the reading was, for the panes to show. */
  function exit() {
    if (!rd.on) return null;
    const place = capture() || rd.anchor;
    rd.on = false; rd.chrome = false; closePanel();
    apply(); leaveScreen();
    const root = host.root();
    for (const name of ['--r-shift', '--r-cols', '--r-gap', '--r-page-h', '--r-measure']) root?.style.removeProperty(name);
    return place;
  }
  /** The device's full screen too, where the browser allows it (not on an iPhone), and if the setting says so. */
  function takeScreen() {
    if (!prefs.device || !canScreen() || screenEl()) return;
    const el = document.documentElement, ask = el.requestFullscreen || el.webkitRequestFullscreen;
    try { const p = ask.call(el, { navigationUI: 'hide' }); rd.ownScreen = true; p?.catch?.(() => { rd.ownScreen = false; }); } catch { rd.ownScreen = false; }
  }
  function leaveScreen() {
    if (!rd.ownScreen) return;
    rd.ownScreen = false;
    if (screenEl() !== document.documentElement) return;
    try { (document.exitFullscreen || document.webkitExitFullscreen).call(document)?.catch?.(() => {}); } catch { /* already out */ }
  }

  /**
   * After stories.js drew the text (a chapter turned to, or the same one again, which the polling does every few
   * seconds): lays it out again and keeps the page. Scrolling text keeps its place by itself, and is left alone, as the
   * person may be scrolling.
   */
  function rendered({ at = null } = {}) {
    apply();
    if (!rd.on) return;
    const c = host.story()?.chapters[host.index()];
    // Another chapter starts at its beginning (or its end, going back); the same one keeps its place.
    if (at === 'start' || at === 'end' || !rd.anchor || rd.anchor.chapter !== c?.id) { rd.page = 0; layout(); toEdge(at === 'end'); rd.anchor = capture(); }
    else if (paged()) show(rd.anchor);
    rd.chromeAt = position();
    if (rd.panel === 'toc') renderPanel();
    update();
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The bars and the panels
  // ---------------------------------------------------------------------------------------------------------------
  function position() { const doc = host.doc(); return paged() ? rd.page * rd.step : doc ? doc.scrollTop : 0; }
  function setChrome(on) {
    if (!rd.on) return;
    if (!on && rd.chrome && !local().readerHint) {
      saveLocal({ readerHint: true });
      toast(coarse() ? t('Tap the middle of the screen to see the controls.') : t('Click the middle of the screen to see the controls.'));
    }
    rd.chrome = on; rd.chromeAt = position();
    if (!on) closePanel();
    host.root()?.classList.toggle('chrome', on);
    if (on) update();
  }
  function ui() {
    const ui = rd.ui = {};
    rd.panel = null;
    ui.title = h('strong'); ui.sub = h('small');
    ui.toc = h('button', { type: 'button', class: 'icon-btn', title: t('Chapters'), 'aria-label': t('Chapters'), 'aria-expanded': 'false', onclick: () => togglePanel('toc') }, icon('list'));
    ui.aa = h('button', { type: 'button', class: 'icon-btn reader-aa', title: t('Text and theme'), 'aria-label': t('Text and theme'), 'aria-expanded': 'false', onclick: () => togglePanel('type') }, h('span', { text: 'Aa', 'aria-hidden': 'true' }));
    ui.top = h('div', { class: 'reader-ui reader-bar reader-top' },
      h('button', { type: 'button', class: 'icon-btn', title: t('Exit full screen (Esc)'), 'aria-label': t('Exit full screen'), onclick: () => host.exit() }, icon('fullscreen-exit')),
      h('div', { class: 'reader-titles' }, ui.title, ui.sub), ui.toc, ui.aa);
    ui.scrub = h('input', { type: 'range', class: 'reader-scrub', min: 0, max: 1000, step: 1, value: 0, 'aria-label': t('Position in the chapter'), oninput: scrub });
    ui.where = h('span', { class: 'reader-where' });
    ui.prev = h('button', { type: 'button', class: 'btn ghost small', title: t('Previous chapter'), onclick: () => host.go(host.index() - 1, 'start') }, icon('chevron-left'), h('span', { text: t('Previous') }));
    ui.next = h('button', { type: 'button', class: 'btn ghost small', title: t('Next chapter'), onclick: () => host.go(host.index() + 1, 'start') }, h('span', { text: t('Next', { ctx: 'chapter' }) }), icon('chevron-right'));
    ui.bottom = h('div', { class: 'reader-ui reader-bar reader-bottom' }, ui.scrub, h('div', { class: 'reader-nav' }, ui.prev, ui.where, ui.next));
    ui.footLeft = h('span'); ui.footRight = h('span');
    ui.foot = h('div', { class: 'reader-ui reader-foot', 'aria-hidden': 'true' }, ui.footLeft, ui.footRight);
    ui.panel = h('div', { class: 'reader-ui reader-panel', hidden: true, role: 'dialog' });
    return [ui.top, ui.bottom, ui.foot, ui.panel];
  }
  function scrub(e) {
    const doc = host.doc(), v = Number(e.target.value);
    if (!doc) return;
    if (paged()) { setShift(Math.max(0, Math.min(countPages() - 1, v))); rd.anchor = capture(); update(); host.moved(); }
    else doc.scrollTop = v / 1000 * (doc.scrollHeight - doc.clientHeight);
    rd.chromeAt = position();
  }
  /**
   * The texts of the bars and of the line at the foot: the chapter, the page or the percentage, the time left. The foot
   * shows only what the settings ask for (nothing at all, with both off); the bars always say where the reading is.
   */
  function update() {
    const ui = rd.ui, s = host.story(), i = host.index(), c = s?.chapters[i], doc = host.doc();
    if (!ui || !rd.on || !c || !doc) return;
    ui.title.textContent = s.title || t('Untitled');
    ui.sub.textContent = t('Chapter {n}{title}', { n: i + 1, title: c.title ? ` · ${c.title}` : '' });
    ui.prev.disabled = i === 0; ui.next.disabled = i >= s.chapters.length - 1;
    let left, label;
    if (paged()) {
      const n = rd.pages;
      ui.scrub.max = String(Math.max(1, n - 1)); ui.scrub.value = String(rd.page); ui.scrub.disabled = n < 2;
      left = (n - rd.page) / n; label = t('Page {n} of {total}', { n: rd.page + 1, total: n });
    } else {
      const max = doc.scrollHeight - doc.clientHeight, f = max > 0 ? Math.min(1, doc.scrollTop / max) : 1;
      ui.scrub.max = '1000'; ui.scrub.value = String(Math.round(f * 1000)); ui.scrub.disabled = max <= 0;
      left = 1 - f; label = `${Math.round(f * 100)}%`;
    }
    ui.where.textContent = t('Chapter {n} of {total} · {where}', { n: i + 1, total: s.chapters.length, where: label.charAt(0).toLowerCase() + label.slice(1) });
    ui.footLeft.textContent = prefs.progress ? label : ''; ui.footRight.textContent = prefs.remaining ? minutesLeft(c.words, left) : '';
    ui.foot.hidden = !prefs.progress && !prefs.remaining;
  }

  function togglePanel(name) { if (rd.panel === name) closePanel(); else openPanel(name); }
  function openPanel(name) { rd.panel = name; renderPanel(); }
  function closePanel() {
    rd.panel = null;
    const ui = rd.ui;
    if (!ui) return;
    ui.panel.hidden = true; ui.panel.replaceChildren();
    ui.toc.setAttribute('aria-expanded', 'false'); ui.aa.setAttribute('aria-expanded', 'false');
  }
  function renderPanel() {
    const ui = rd.ui;
    if (!ui || !rd.panel) return;
    ui.toc.setAttribute('aria-expanded', String(rd.panel === 'toc')); ui.aa.setAttribute('aria-expanded', String(rd.panel === 'type'));
    ui.panel.hidden = false;
    ui.panel.setAttribute('aria-label', rd.panel === 'toc' ? t('Chapters') : t('Text and theme'));
    ui.panel.replaceChildren(...(rd.panel === 'toc' ? tocPanel() : typePanel()));
  }
  function tocPanel() {
    const s = host.story(), current = host.index();
    return [h('strong', { class: 'reader-panel-title', text: s.title || t('Untitled') }),
      h('ol', { class: 'reader-toc' }, ...s.chapters.map((c, i) => h('li', {},
        h('button', { type: 'button', 'aria-current': i === current ? 'true' : null, onclick: () => { closePanel(); if (i !== current) host.go(i, 'start'); } },
          h('span', { class: 'chapter-n', text: String(i + 1) }), h('span', { class: 'chapter-t', text: c.title || t('Untitled') }),
          h('span', { class: 'chapter-w', text: number(c.words) })))))];
  }
  function typePanel() {
    const field = (label, ...control) => h('div', { class: 'reader-field' }, h('span', { class: 'field-label', text: label }), ...control);
    const choice = (label, name, options) => field(label, h('div', { class: 'segmented small reader-seg', role: 'group', 'aria-label': label },
      ...options.map(([value, text]) => h('button', { type: 'button', 'aria-pressed': String(prefs[name] === value), onclick: () => change({ [name]: value }) }, text))));
    const toggle = (name, text) => h('label', { class: 'switch' },
      h('input', { type: 'checkbox', checked: prefs[name], onchange: e => change({ [name]: e.target.checked }) }), h('span', { class: 'switch-track' }), h('span', { text }));
    const at = READER_SIZES.indexOf(prefs.size);
    return [
      field(t('Text size'), h('div', { class: 'reader-size' },
        h('button', { type: 'button', class: 'btn secondary small', 'aria-label': t('Smaller text'), disabled: at <= 0, onclick: () => change({ size: READER_SIZES[at - 1] }) }, h('span', { class: 'reader-a small', text: 'A' })),
        h('output', { text: `${prefs.size}%` }),
        h('button', { type: 'button', class: 'btn secondary small', 'aria-label': t('Larger text'), disabled: at >= READER_SIZES.length - 1, onclick: () => change({ size: READER_SIZES[at + 1] }) }, h('span', { class: 'reader-a', text: 'A' })))),
      choice(t('Font'), 'font', [['serif', t('Serif')], ['sans', t('Sans serif')]]),
      choice(t('Line spacing'), 'spacing', [['tight', t('Tight')], ['normal', t('Normal')], ['wide', t('Wide', { ctx: 'spacing' })]]),
      choice(t('Text width'), 'width', [['narrow', t('Narrow')], ['medium', t('Medium')], ['wide', t('Wide', { ctx: 'width' })], ['full', t('Full', { ctx: 'width' })]]),
      choice(t('Alignment'), 'align', [['left', t('Left')], ['justify', t('Justified')]]),
      field(t('Theme'), h('div', { class: 'reader-themes', role: 'group', 'aria-label': t('Theme') }, ...THEMES.map(([value, text, bg, ink]) =>
        h('button', { type: 'button', class: 'reader-theme', 'aria-pressed': String(prefs.theme === value), vars: { '--swatch': bg, '--swatch-ink': ink }, onclick: () => change({ theme: value }) },
          h('b', { text: 'Aa', 'aria-hidden': 'true' }), h('span', { text }))))),
      choice(t('Reading'), 'mode', [['pages', t('Pages')], ['scroll', t('Scrolling')]]),
      prefs.mode === 'pages' ? toggle('spread', t('Two pages side by side when the screen is sideways')) : null,
      toggle('progress', prefs.mode === 'pages' ? t('Show the page at the foot') : t('Show how much has been read (%) at the foot')),
      toggle('remaining', t('Show the time left in the chapter')),
      canScreen() ? toggle('device', t('Hide the browser and system bars')) : null,
      toggle('open', t('Open stories already in full screen')),
      h('p', { class: 'hint', text: t('They are saved to your account and apply on all your devices.') }),
    ].filter(Boolean);
  }

  // ---------------------------------------------------------------------------------------------------------------
  // Taps, swipes, the wheel and the keys
  // ---------------------------------------------------------------------------------------------------------------
  const inDoc = e => rd.on && e.target instanceof Element && e.target.closest('#story-doc');
  function attach(root) {
    root.addEventListener('click', e => {
      if (!inDoc(e)) return;
      if (rd.panel) { closePanel(); return; }
      if (e.target.closest('a, button, input, select, textarea, summary, label, .figure-open')) return;
      if (String(getSelection?.() || '').trim()) return;
      const x = e.clientX / innerWidth;
      if (x < 0.25) { prev(); setChrome(false); }
      else if (x > 0.75) { next(); setChrome(false); }
      else setChrome(!rd.chrome);
    });
    root.addEventListener('touchstart', e => {
      rd.swipe = inDoc(e) && paged() && e.touches.length === 1 ? { x: e.touches[0].clientX, y: e.touches[0].clientY, at: Date.now() } : null;
    }, { passive: true });
    root.addEventListener('touchend', e => {
      const s = rd.swipe; rd.swipe = null;
      if (!s || !paged() || rd.panel) return;
      const t = e.changedTouches[0], dx = t.clientX - s.x, dy = t.clientY - s.y;
      if (Math.abs(dx) >= 40 && Math.abs(dx) > Math.abs(dy) * 1.4 && Date.now() - s.at < 900) { if (dx < 0) next(); else prev(); setChrome(false); }
    }, { passive: true });
    // A turn of the wheel (or a swipe on a touchpad, which sends many) turns one page.
    root.addEventListener('wheel', e => {
      if (!inDoc(e) || !paged() || e.ctrlKey || e.target.closest('.reader-panel')) return;
      e.preventDefault();
      const d = Math.abs(e.deltaY) >= Math.abs(e.deltaX) ? e.deltaY : e.deltaX, now = Date.now(), quiet = now - rd.wheelAt > 350;
      rd.wheelAt = now;
      if (!quiet || Math.abs(d) < 4) return;
      if (d > 0) next(); else prev();
      setChrome(false);
    }, { passive: false });
    // Scrolling the text (scrolling, not pages) hides the bars after a little way, and keeps the place.
    root.addEventListener('scroll', e => {
      if (!rd.on || paged() || e.target !== host.doc()) return;
      update();
      // Scrolling done by the reader (a place brought back after a change) neither hides the bars nor moves the place.
      if (rd.expect !== null && Math.abs(position() - rd.expect) < 1) { rd.chromeAt = position(); return; }
      rd.expect = null;
      if (rd.chrome && !rd.panel && Math.abs(position() - rd.chromeAt) > 48) setChrome(false);
      clearTimeout(rd.anchorTimer);
      rd.anchorTimer = setTimeout(() => { if (rd.on && !paged() && rd.expect === null) rd.anchor = capture(); }, 150);
    }, true);
    // A picture that loads moves the text after it: the place stays on screen.
    root.addEventListener('load', e => { if (rd.on && paged() && e.target instanceof HTMLImageElement) relayoutSoon(); }, true);
  }
  /** Keys in the reader. Returns true when the key was the reader's. */
  function key(e) {
    if (!rd.on || e.ctrlKey || e.metaKey || e.altKey) return false;
    const typing = e.target instanceof HTMLElement && (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable) && e.target.type !== 'range' && e.target.type !== 'checkbox';
    if (typing || document.querySelector('dialog[open]')) return false;
    if (e.key === 'Escape') { e.preventDefault(); if (rd.panel) closePanel(); else host.exit(); return true; }
    if (e.target instanceof HTMLElement && (e.target.closest('.reader-panel, .reader-scrub') || ((e.key === ' ' || e.key === 'Enter') && e.target.closest('button, a')))) return false;
    const pages = paged();
    const forward = e.key === 'ArrowRight' || e.key === 'PageDown' || (e.key === ' ' && !e.shiftKey) || (pages && e.key === 'ArrowDown');
    const back = e.key === 'ArrowLeft' || e.key === 'PageUp' || (e.key === ' ' && e.shiftKey) || (pages && e.key === 'ArrowUp');
    if (forward || back) { e.preventDefault(); if (forward) next(); else prev(); setChrome(false); return true; }
    if (pages && (e.key === 'Home' || e.key === 'End')) { e.preventDefault(); toEdge(e.key === 'End'); return true; }
    if (e.key === 't' || e.key === 'T') { e.preventDefault(); host.exit(); return true; }
    return false;
  }

  return {
    get on() { return rd.on; },
    paged, prefs: () => prefs, setPrefs, apply, attach, ui, enter, exit, rendered, key, show, capture,
    /** The pages' current page, for the place's restore (stories.js): what moves when the person turns a page. */
    position,
    /** The page a place is on, from the stories' own restore (pictures above it load after). */
    showPlace: place => show(place),
    save,
  };
}
