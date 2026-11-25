import { t, language, locale, number } from './i18n.js';
// The other modules load with this file's version, so a new upload never mixes old and new code behind a cache.
const assetVersion = new URL(import.meta.url).search;
const [{ snap16, frameKind, keptArea, validateFrame, frameTooLarge, resizeFrame, panFrame, sizeFrame, centerFrame, ratioFrame, hasRatio, resizeCrop, holdCrop, frameView, API_LIMITS, GPU_LIMITS }, { versionNumbers, buildTree, branches, forkDepth, validSize, editSize, aspectOf, upscaleSizes },
  { estimate, usageCost, formatUsd, PRICING_CHECKED, GEMINI_PRICING_CHECKED, isGemini, setAliases, UNKNOWN_IMAGE, balanceNow, formatBalance, planningCost, planningAverage, learnedScale }, { mp4Timing, snapFps, fpsLabel, frameAt, frameTime, frameCount, timecode, megabytes, COMMON_RATES },
  { justify, firstRowAt, monthMarks, railMarks }, { strips }, storyHelpers, { createStories }, readerModule, { createComics, zip }, packModule, { createVn }, { createGpu, aspectRatio }] =
  await Promise.all(['outpaint', 'lineage', 'pricing', 'video', 'library', 'clean', 'story', 'stories', 'reader', 'comics', 'pack', 'vn', 'gpu'].map(name => import(`./${name}.js${assetVersion}`)));

// ---------------------------------------------------------------------------
// DOM helpers and icons
// ---------------------------------------------------------------------------
const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const SVG = 'http://www.w3.org/2000/svg';

// 24 × 24 stroke icons drawn for this app.
const ICONS = {
  settings: '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2.25"/><circle cx="9" cy="17" r="2.25"/>',
  lock: '<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
  list: '<path d="M9 6.5h11M9 12h11M9 17.5h11"/><circle class="fill" cx="4.75" cy="6.5" r="1.1"/><circle class="fill" cx="4.75" cy="12" r="1.1"/><circle class="fill" cx="4.75" cy="17.5" r="1.1"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  close: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
  search: '<circle cx="11" cy="11" r="6.25"/><path d="m19.5 19.5-3.9-3.9"/>',
  star: '<path d="M12 3.8l2.5 5.1 5.6.8-4 3.9.9 5.6-5-2.6-5 2.6.9-5.6-4-3.9 5.6-.8z"/>',
  download: '<path d="M12 4.5v10.5M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>',
  upload: '<path d="M12 15V4.5M7.5 9 12 4.5 16.5 9M5 19.5h14"/>',
  trash: '<path d="M4.5 7h15M9.5 7V5.2c0-.7.5-1.2 1.2-1.2h2.6c.7 0 1.2.5 1.2 1.2V7M6.5 7l.8 11.6c.1 1 .9 1.9 2 1.9h5.4c1.1 0 1.9-.9 2-1.9L17.5 7M10 11v5.5M14 11v5.5"/>',
  brush: '<path d="M20 4.2c-.5-.5-1.4-.5-1.9 0L10.4 12l1.8 1.8 7.8-7.7c.5-.5.5-1.4 0-1.9z"/><path d="M9.6 13.2c-1.9-.3-3.6 1-3.8 3-.2 1.4-.9 2.4-2.3 2.8 3.6 1.4 7.8.4 8.1-3.7"/>',
  crop: '<path d="M6.5 2.5v13a2 2 0 0 0 2 2h13"/><path d="M2.5 6.5h13a2 2 0 0 1 2 2v13"/>',
  expand: '<path d="M4 9V5.5C4 4.7 4.7 4 5.5 4H9M15 4h3.5c.8 0 1.5.7 1.5 1.5V9M20 15v3.5c0 .8-.7 1.5-1.5 1.5H15M9 20H5.5C4.7 20 4 19.3 4 18.5V15"/><rect x="8.5" y="8.5" width="7" height="7" rx="1.2"/>',
  upscale: '<path d="M4 20l6.5-6.5M4 14.5V20h5.5"/><path d="M13 4h7v7"/><path d="M20 4l-6.5 6.5"/>',
  pencil: '<path d="M4.5 19.5h3.8L18.6 9.2a2.7 2.7 0 0 0-3.8-3.8L4.5 15.7z"/><path d="m13.5 6.7 3.8 3.8"/>',
  redo: '<path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3"/><path d="M19.5 4.5v4.5H15"/>',
  copy: '<rect x="8.5" y="8.5" width="11" height="11" rx="2"/><path d="M15.5 8.5V6.5a2 2 0 0 0-2-2h-7a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h2"/>',
  imagePlus: '<path d="M20 11.5V17a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17V7a2.5 2.5 0 0 1 2.5-2.5H12"/><path d="m4.5 16.5 4-4a1.6 1.6 0 0 1 2.2 0l5.3 5.3"/><circle cx="9" cy="9" r="1.4"/><path d="M18 3.5v5M15.5 6h5"/>',
  image: '<rect x="4" y="4.5" width="16" height="15" rx="2.5"/><circle cx="9.2" cy="9.7" r="1.6"/><path d="m4.5 17.5 4.2-4.2a1.6 1.6 0 0 1 2.2 0l1.6 1.6 2.7-2.7a1.6 1.6 0 0 1 2.2 0l2.6 2.6"/>',
  branch: '<circle cx="7" cy="5.5" r="2"/><circle cx="7" cy="18.5" r="2"/><circle cx="17" cy="8.5" r="2"/><path d="M7 7.5v9M17 10.5c0 3.6-3 4.6-8.4 6.6"/>',
  clock: '<circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/>',
  history: '<path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3"/><path d="M4.5 4.5v4.5H9"/><path d="M12 8.5V12l2.6 1.7"/>',
  back: '<path d="M19 12H5.5M11 6l-6 6 6 6"/>',
  chevronLeft: '<path d="m14.5 6-6 6 6 6"/>',
  chevronRight: '<path d="m9.5 6 6 6-6 6"/>',
  compare: '<rect x="4" y="4.5" width="16" height="15" rx="2.5"/><path d="M12 2.5v19"/><path d="M15 9.5h2M15 14.5h2" opacity=".6"/>',
  compose: '<path d="M11 4.5H6.5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V13"/><path d="M17.8 3.9a1.9 1.9 0 0 1 2.7 2.7l-7.2 7.2-3.5.8.8-3.5z"/>',
  alert: '<path d="M10.3 4.8 3.4 17.2A2 2 0 0 0 5.1 20h13.8a2 2 0 0 0 1.7-2.8L13.7 4.8a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4"/><circle class="fill" cx="12" cy="16.6" r="1"/>',
  undo: '<path d="M9 14.5 4.5 10 9 5.5"/><path d="M4.5 10H14a5.5 5.5 0 0 1 0 11h-3"/>',
  stack: '<rect x="7.5" y="3.5" width="13" height="13" rx="2.2"/><path d="M16.5 20.5H6a2.5 2.5 0 0 1-2.5-2.5V7.5"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  sliders: '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2.25"/><circle cx="9" cy="17" r="2.25"/>',
  user: '<circle cx="12" cy="8.5" r="3.6"/><path d="M5 19.5c1.2-3.4 3.8-5.2 7-5.2s5.8 1.8 7 5.2"/>',
  variations: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M6 16.5V8a2 2 0 0 1 2-2h8.5"/><path d="M3.5 13V5.5a2 2 0 0 1 2-2H13"/>',
  gallery: '<rect x="4" y="4" width="7" height="7" rx="1.6"/><rect x="13" y="4" width="7" height="7" rx="1.6"/><rect x="4" y="13" width="7" height="7" rx="1.6"/><rect x="13" y="13" width="7" height="7" rx="1.6"/>',
  video: '<rect x="3.5" y="6.5" width="12.5" height="11" rx="2.5"/><path d="m16 10.6 3.9-2.3a.7.7 0 0 1 1.1.6v6.2a.7.7 0 0 1-1.1.6L16 13.4"/>',
  play: '<path d="M8 6.2v11.6a1 1 0 0 0 1.5.86l9.3-5.8a1 1 0 0 0 0-1.72L9.5 5.34A1 1 0 0 0 8 6.2z"/>',
  pause: '<path d="M8.5 6v12M15.5 6v12"/>',
  stepBack: '<path d="M7 6.5v11"/><path d="M17.5 7.4v9.2a.8.8 0 0 1-1.25.66L10 12.66a.8.8 0 0 1 0-1.32l6.25-4.6a.8.8 0 0 1 1.25.66z"/>',
  stepForward: '<path d="M17 6.5v11"/><path d="M6.5 7.4v9.2a.8.8 0 0 0 1.25.66L14 12.66a.8.8 0 0 0 0-1.32L7.75 6.74a.8.8 0 0 0-1.25.66z"/>',
  rewind: '<path d="M11.5 7.5v9L5 12z"/><path d="M19.5 7.5v9L13 12z"/>',
  fastForward: '<path d="M4.5 7.5v9L11 12z"/><path d="M12.5 7.5v9L19 12z"/>',
  skipBack: '<path d="M4.5 6.5v11"/><path d="M12.5 7.5v9L7 12z"/><path d="M19.5 7.5v9L14 12z"/>',
  skipForward: '<path d="M19.5 6.5v11"/><path d="M11.5 7.5v9L17 12z"/><path d="M4.5 7.5v9L10 12z"/>',
  sparkle: '<path d="M11 3.5l1.6 4.4 4.4 1.6-4.4 1.6L11 15.5l-1.6-4.4L5 9.5l4.4-1.6z"/><path d="M17.5 14.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
  eraser: '<path d="M9 19.5 4.3 14.8a1.8 1.8 0 0 1 0-2.6l7.9-7.9a1.8 1.8 0 0 1 2.6 0l4.9 4.9a1.8 1.8 0 0 1 0 2.6L12 19.5z"/><path d="m8.3 8.2 7.5 7.5M9 19.5h11"/>',
  camera: '<path d="M4 8.7A1.7 1.7 0 0 1 5.7 7h2.1l1.5-2.1h5.4L16.2 7h2.1A1.7 1.7 0 0 1 20 8.7v8.6a1.7 1.7 0 0 1-1.7 1.7H5.7A1.7 1.7 0 0 1 4 17.3z"/><circle cx="12" cy="12.8" r="3.3"/>',
  volume: '<path d="M4.5 9.5h3l4.5-4v13l-4.5-4h-3z"/><path d="M15.5 9.2a4 4 0 0 1 0 5.6M18 6.7a7.5 7.5 0 0 1 0 10.6"/>',
  volumeOff: '<path d="M4.5 9.5h3l4.5-4v13l-4.5-4h-3z"/><path d="m16 9.5 5 5M21 9.5l-5 5"/>',
  chevronDown: '<path d="m6.5 9.5 5.5 5.5 5.5-5.5"/>',
  sort: '<path d="M8 19V5M4.5 8.5 8 5l3.5 3.5M16 5v14M12.5 15.5 16 19l3.5-3.5"/>',
  checkSquare: '<rect x="4" y="4" width="16" height="16" rx="4"/><path d="m8.5 12.3 2.4 2.4 4.7-5"/>',
  album: '<path d="M3.8 8.2a2.2 2.2 0 0 1 2.2-2.2h3.4l2 2.2H18a2.2 2.2 0 0 1 2.2 2.2v6.4A2.2 2.2 0 0 1 18 19H6a2.2 2.2 0 0 1-2.2-2.2z"/>',
  albumAdd: '<path d="M3.8 8.2a2.2 2.2 0 0 1 2.2-2.2h3.4l2 2.2H18a2.2 2.2 0 0 1 2.2 2.2v6.4A2.2 2.2 0 0 1 18 19H6a2.2 2.2 0 0 1-2.2-2.2z"/><path d="M12 11v5M9.5 13.5h5"/>',
  albumOut: '<path d="M3.8 8.2a2.2 2.2 0 0 1 2.2-2.2h3.4l2 2.2H18a2.2 2.2 0 0 1 2.2 2.2v6.4A2.2 2.2 0 0 1 18 19H6a2.2 2.2 0 0 1-2.2-2.2z"/><path d="M9.5 13.5h5"/>',
  toTop: '<path d="M5 4.5h14M12 20V9.5M7.5 14 12 9.5l4.5 4.5"/>',
  toBottom: '<path d="M5 19.5h14M12 4v10.5M7.5 10 12 14.5l4.5-4.5"/>',
  comic: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 11h16M11.5 4v7M13.5 11v9"/>',
  fullscreen: '<path d="M4.5 9V6a1.5 1.5 0 0 1 1.5-1.5h3M15 4.5h3A1.5 1.5 0 0 1 19.5 6v3M19.5 15v3a1.5 1.5 0 0 1-1.5 1.5h-3M9 19.5H6A1.5 1.5 0 0 1 4.5 18v-3"/>',
  fullscreenExit: '<path d="M9 4.5V7.5A1.5 1.5 0 0 1 7.5 9h-3M19.5 9h-3A1.5 1.5 0 0 1 15 7.5v-3M15 19.5v-3a1.5 1.5 0 0 1 1.5-1.5h3M4.5 15h3A1.5 1.5 0 0 1 9 16.5v3"/>',
  book: '<path d="M12 6.5c-1.8-1.4-4.3-2-7.5-2v13c3.2 0 5.7.6 7.5 2 1.8-1.4 4.3-2 7.5-2v-13c-3.2 0-5.7.6-7.5 2z"/><path d="M12 6.5v13"/>',
  feather: '<path d="M19.5 4.5c-6 0-11 4.5-11 11v4"/><path d="M8.5 15.5h5.5c3.2 0 5.5-4.6 5.5-11"/><path d="M4.5 19.5l4-4"/>',
  send: '<path d="M4.5 12 19.5 4.5 15 19.5l-3.2-6.3z"/><path d="m11.8 13.2 7.7-8.7"/>',
  stop: '<rect x="6.5" y="6.5" width="11" height="11" rx="2"/>',
  music: '<path d="M9 17.5V6l10-2v11.5"/><circle cx="6.5" cy="17.5" r="2.5"/><circle cx="16.5" cy="15.5" r="2.5"/>',
  eye: '<path d="M2.8 12s3.4-6.5 9.2-6.5 9.2 6.5 9.2 6.5-3.4 6.5-9.2 6.5S2.8 12 2.8 12z"/><circle cx="12" cy="12" r="2.8"/>',
  dialogue: '<path d="M4.5 6.5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4.5 3.5v-3.5h-.5a2 2 0 0 1-2-2z"/><path d="M8.5 9h7M8.5 12.2h4.5"/>',
  key: '<circle cx="8" cy="15.5" r="3.75"/><path d="M10.7 12.8 19.5 4M15.8 7.7l2.6 2.6M13.6 9.9l2 2"/>',
  coin: '<circle cx="12" cy="12" r="8"/><path d="M14.6 9.3c-.5-.9-1.5-1.4-2.6-1.4-1.5 0-2.6.8-2.6 2s1.1 1.7 2.6 2.1 2.8.9 2.8 2.2-1.2 2.1-2.8 2.1c-1.2 0-2.3-.5-2.8-1.5M12 6.4v1.5M12 16.6v1.5"/>',
  plug: '<path d="M9 3.5v4M15 3.5v4"/><path d="M6.5 7.5h11v3a5.5 5.5 0 0 1-11 0z"/><path d="M12 16v4.5"/>',
  users: '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19c.9-3 3-4.6 5.5-4.6s4.6 1.6 5.5 4.6"/><path d="M15.5 5.6a3.2 3.2 0 0 1 0 5.9M17.2 14.6c1.6.6 2.7 2 3.3 4.4"/>',
  server: '<rect x="4" y="4.5" width="16" height="6.5" rx="1.8"/><rect x="4" y="13" width="16" height="6.5" rx="1.8"/><circle class="fill" cx="7.5" cy="7.75" r="1"/><circle class="fill" cx="7.5" cy="16.25" r="1"/>',
  globe: '<circle cx="12" cy="12" r="8.25"/><path d="M3.75 12h16.5M12 3.75c2.3 2.4 3.4 5.1 3.4 8.25s-1.1 5.85-3.4 8.25c-2.3-2.4-3.4-5.1-3.4-8.25S9.7 6.15 12 3.75z"/>',
  shield: '<path d="M12 3.8 5 6.5v5.2c0 4.3 2.9 7.4 7 8.6 4.1-1.2 7-4.3 7-8.6V6.5z"/><path d="m9 12.2 2.1 2.1 4-4.2"/>',
  eyeOff: '<path d="M10.2 5.7c.6-.1 1.2-.2 1.8-.2 5.8 0 9.2 6.5 9.2 6.5a16 16 0 0 1-2.4 3.2M6.6 7.2C4.1 8.9 2.8 12 2.8 12s3.4 6.5 9.2 6.5c1.7 0 3.2-.5 4.5-1.3"/><path d="M9.9 9.9a2.8 2.8 0 0 0 4 4M4 4l16 16"/>',
};
const ICON_ALIASES = { 'image-plus': 'imagePlus', 'chevron-left': 'chevronLeft', 'chevron-right': 'chevronRight', 'step-back': 'stepBack', 'step-forward': 'stepForward',
  'fast-forward': 'fastForward', 'skip-back': 'skipBack', 'skip-forward': 'skipForward', 'chevron-down': 'chevronDown', 'check-square': 'checkSquare',
  'album-add': 'albumAdd', 'album-out': 'albumOut', 'to-top': 'toTop', 'to-bottom': 'toBottom', 'fullscreen-exit': 'fullscreenExit', 'eye-off': 'eyeOff' };
function icon(name) {
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('class', 'i');
  svg.innerHTML = ICONS[ICON_ALIASES[name] || name] || '';
  return svg;
}
$$('[data-icon]').forEach(el => el.prepend(icon(el.dataset.icon)));

/** Small element builder. Never pass user text through `html`. */
function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === undefined || value === null || value === false) continue;
    if (key === 'class') el.className = value;
    else if (key === 'text') el.textContent = value;
    else if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
    else if (key === 'vars') for (const [name, v] of Object.entries(value)) el.style.setProperty(name, v);
    else el.setAttribute(key, value === true ? '' : value);
  }
  for (const child of children.flat()) if (child !== null && child !== undefined && child !== false) el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  return el;
}

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------
const ACTIVE = new Set(['queued', 'running', 'cancelling']);
// The defaults are the "Alta" level of the simple mode (see PRESETS).
const DEFAULT_OPTIONS = { model: 'gpt-image-2.6-flare', size: '1024x1024', quality: 'high', complexity: 'high', format: 'png', background: 'auto', compression: 90, n: 1, moderation: 'low', thinking: 'medium', previews: 2, vary: true };
const QUALITY = [['auto', t('Automatic')], ['low', t('Low')], ['medium', t('Medium')], ['high', t('High')]];
const QUALITY_EXTENDED = [['xhigh', t('Extra high')], ['max', t('Maximum')]];
const COMPLEXITY = [['auto', t('Automatic')], ['low', t('Low')], ['medium', t('Medium')], ['high', t('High')]];
const THINKING = [['off', t('Off')], ['none', t('No reasoning')], ['low', t('Low', { ctx: 'effort' })], ['medium', t('Medium', { ctx: 'effort' })], ['high', t('High', { ctx: 'effort' })], ['xhigh', t('Extra high', { ctx: 'effort' })], ['max', t('Maximum', { ctx: 'effort' })]];
// Quality levels of the simple mode. Each one raises the image quality and complexity together with how much
// GPT-6 Sol reasons over the prompt before the image is made (prompt planning). The model is the one chosen in the
// advanced mode, GPT Image 2.6 Flare by default; a quality the model lacks falls back to high.
const PRESETS = [
  { id: 'fast', label: t('Fast'), quality: 'low', complexity: 'low', thinking: 'off' },
  { id: 'balanced', label: t('Balanced'), quality: 'medium', complexity: 'medium', thinking: 'low' },
  { id: 'high', label: t('High'), quality: 'high', complexity: 'high', thinking: 'medium' },
  { id: 'max', label: t('Maximum'), quality: 'max', complexity: 'high', thinking: 'high' },
];
const PLANNING_SKIPPED = { refused: t('refused by GPT-6 Sol, made with the prompt as written'), incomplete: t('not finished, made with the prompt as written'), failed: t('failed, made with the prompt as written') };
const OPERATION = { generate: 'Original', import: t('Imported'), edit: t('Edit'), expand: t('Expansion'), upscale: t('Upscale'), clean: t('Cleanup'), crop: t('Crop'), frame: t('Video frame'), video: t('Video') };
const EXPAND_PROMPT = 'Expand the image by filling only the transparent areas outside the original image. Preserve the elements, framing, details and colors of the original region. Continue the scene, lighting, perspective and textures naturally, with no borders, seams or repeated objects.';
// A marked area sent with nothing written: the area is made again as a correct version of itself. Names no kind of image.
const INPAINT_PROMPT = `Redo only the area marked by the mask, and nothing else. Generate that area again as a clean, correct and natural version of what it shows: the same subject and elements, in the same place, pose and size, fixed wherever they are malformed, distorted, garbled, smudged or damaged (for example hands and fingers, faces, eyes, teeth, lettering, warped objects, broken textures, seams and artifacts). It must blend seamlessly with the rest of the picture: the same style, light, colors, perspective, focus and texture, with no visible border. Do not add new objects, do not remove what the area shows and do not change its meaning. Everything outside the marked area stays exactly as it is.`;
// Cobrir: the area goes erased (transparent; Gemini sees it flat gray), so the model can't see what was there. With text,
// the area is drawn from it; without, it is filled with what surrounds it, as if what was there had never been.
const COVER_NOTE = 'The area marked by the mask was erased on purpose before sending: it is empty (transparent, or flat gray), so nothing of what was there should be guessed, kept or rebuilt. Draw that area anew following the request below, so that it blends seamlessly with the rest of the picture: the same style, light, colors, perspective, focus and texture, with no visible border. Everything outside the marked area stays exactly as it is.\n\nRequest: ';
const FILL_PROMPT = 'The area marked by the mask was erased on purpose before sending: it is empty (transparent, or flat gray). Fill it with what naturally belongs there, continuing the surroundings (background, surfaces, light, perspective and textures), so that it looks as if whatever was there had never been in the picture. Do not add new objects or people, and leave no outline, seam or placeholder. Everything outside the marked area stays exactly as it is.';
const UPSCALE_PROMPT = 'Recreate this same image at a higher resolution. Preserve exactly the composition, framing, proportions, colors, lighting, faces, text and every element in the same positions. Make the image clean and well defined, with smooth transitions, and remove noise, grain, specks, jagged edges and compression artifacts, without inventing new texture. Do not add, remove or reinterpret anything.';
const FORMAT_OF_MIME = { 'image/png': 'png', 'image/jpeg': 'jpeg', 'image/webp': 'webp' };
const PREFS_KEY = 'imagegen.prefs.v1';

const state = {
  generation: 0, csrf: '', initialized: false, unlocked: false, has_key: false, has_claude_key: false, has_gemini_key: false, user: null, mcpUrl: '', section: 'images',
  models: {}, sizes: {}, maxParallel: 4, sessionMinutes: 60, staleAfter: 180, skew: 0,
  prefs: loadPrefs(),
  ratio: '1:1', refs: [],
  jobs: new Map(), launching: new Set(), uploads: new Map(), maxVideoBytes: 0,
  gallery: { items: [], ids: new Map(), total: 0, hasMore: false, trash: false, album: null, origin: 'all', favorites: false, sort: 'newest', search: '', group: true, loading: false, version: 0, loaded: false },
  albums: null,
};
const viewer = { id: null, item: null, ready: Promise.resolve(), lineage: null, mode: 'view', action: null, versionsView: 'tree', refs: [], maskPainted: false, compare: false, token: 0, pushed: false, onlyImmersive: false, closeReason: null, expansion: null, upscale: null, frames: [] };
const jobView = { job: null }; // the request whose details are open, kept after it leaves state.jobs
const timers = {};
const tileCache = new Map();
const pendingNodes = new Map(); // job id → version-panel entry, updated in place
const thumbCache = new Map();
const fresh = new Set();

// Only generation options are stored in the browser; never prompts, images or keys.
function loadPrefs() { try { return JSON.parse(localStorage.getItem(PREFS_KEY)) || {}; } catch { return {}; } }
function savePrefs(patch) { state.prefs = { ...state.prefs, ...patch }; try { localStorage.setItem(PREFS_KEY, JSON.stringify(state.prefs)); } catch { /* storage may be blocked */ } }

const imageUrl = (id, thumb = false) => `index.php?action=image&id=${encodeURIComponent(id)}${thumb ? '&variant=thumb' : ''}`;
const previewUrl = (id, n) => `index.php?action=preview&id=${encodeURIComponent(id)}&n=${n}`;
function newId() { return [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join(''); }
const now = () => Date.now() / 1000 + state.skew;
/**
 * A count in a sentence: plural(n, t('{n} image'), t('{n} images')), with {n} as the language writes numbers. A sentence
 * without {n} gets the count in front, as the story modules still call it until they are translated.
 */
const plural = (n, one, many) => { const text = n === 1 ? one : many; return text.includes('{n}') ? text.replaceAll('{n}', number(n)) : `${n} ${text}`; };
/** Names in a sentence: "a, b and c". */
function listText(names) { return names.length > 1 ? t('{names} and {last}', { names: names.slice(0, -1).join(', '), last: names.at(-1) }) : names[0] || ''; }
function clock(seconds) { seconds = Math.max(0, Math.floor(seconds)); const m = Math.floor(seconds / 60), s = seconds % 60; return m >= 60 ? `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`; }
function dateTime(ts) { return new Date(ts * 1000).toLocaleString(locale, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
function timeOnly(ts) { return new Date(ts * 1000).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }); }
function dayLabel(ts) {
  const d = new Date(ts * 1000), today = new Date(); today.setHours(0, 0, 0, 0);
  const day = new Date(d); day.setHours(0, 0, 0, 0);
  const diff = Math.round((today - day) / 86400000);
  if (diff === 0) return t('Today');
  if (diff === 1) return t('Yesterday');
  return d.toLocaleDateString(locale, { day: 'numeric', month: 'long', ...(d.getFullYear() !== today.getFullYear() ? { year: 'numeric' } : {}) });
}
function snippet(text, max = 90) { const s = String(text || '').replace(/\s+/g, ' ').trim(); return s.length > max ? s.slice(0, max - 1) + '…' : s; }
function displayText(item) {
  if (item.label) return item.label;
  if (item.operation === 'expand') return t('Expansion');
  if (item.operation === 'upscale') return t('Upscale');
  // Names are numbers the generator gave, so they are never shown: the prompt says what the image is.
  return item.prompt || '';
}
function setError(selector, message = '') { $(selector).textContent = message; }

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------
class ApiError extends Error { constructor(message, status, transport = false) { super(message); this.status = status; this.transport = transport; } }
/** keepalive: the request goes on after the page closes (what it saves on the way out). */
async function api(action, data, params = {}, { keepalive = false } = {}) {
  const generation = state.generation;
  const query = new URLSearchParams({ action, ...params });
  let response;
  try {
    response = await fetch(`index.php?${query}`, { method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', keepalive,
      headers: data === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf }, body: data === undefined ? undefined : JSON.stringify(data) });
  } catch { throw new ApiError(t('No connection to the server.'), 0, true); }
  let result;
  try { result = await response.json(); }
  catch { throw new ApiError(response.ok ? t('The server answered in an unexpected way.') : t('The server answered with error {status}. Check PHP\'s memory and time limits.', { status: response.status }), response.status, true); }
  // A long request may already have sent status 200 before failing: errors can arrive in the body.
  if (!response.ok || result.error) {
    if (response.status === 401 && !['login', 'state', 'setup'].includes(action)) lockUI();
    throw new ApiError(result.error || t('Could not finish.'), response.status);
  }
  if (generation !== state.generation && !['state', 'login', 'setup', 'logout'].includes(action)) throw new ApiError(t('The session was locked.'), 401);
  if (result.csrf) state.csrf = result.csrf;
  if (state.unlocked && !['state', 'jobs', 'run'].includes(action)) resetIdle();
  return result;
}

// ---------------------------------------------------------------------------
// Toast and confirmation
// ---------------------------------------------------------------------------
const toastEl = $('#toast');
function toast(message, { action, tone, duration } = {}) {
  $('#toast-text').textContent = message;
  const button = $('#toast-action');
  button.hidden = !action;
  if (action) { button.textContent = action.label; button.onclick = () => { hideToast(); action.run(); }; }
  toastEl.dataset.tone = tone || '';
  if (toastEl.showPopover) { try { toastEl.hidePopover(); } catch { /* not open */ } }
  placeToast();
  // Popovers live in the top layer, so the toast stays visible above open dialogs.
  if (toastEl.showPopover) toastEl.showPopover(); else toastEl.classList.add('shown');
  toastShown = true;
  clearTimeout(timers.toast); timers.toast = setTimeout(hideToast, duration ?? (action ? 8000 : 4500));
}
let toastShown = false;
/**
 * A window opened as modal makes everything outside it inert, a notice included: its button could not be pressed while
 * the viewer was open. So the notice lives inside the window in front (windows that open over others come later in the
 * page), or in the page when there is none.
 */
function placeToast() {
  const host = $$('dialog[open]').pop() || document.body;
  if (toastEl.parentElement !== host) host.append(toastEl);
}
function hideToast() { toastShown = false; clearTimeout(timers.toast); if (toastEl.hidePopover) { try { toastEl.hidePopover(); } catch { /* closed */ } } toastEl.classList.remove('shown'); }
// The window a notice was in has closed: the notice goes on where it can still be seen.
document.addEventListener('close', e => {
  if (!toastShown || !(e.target instanceof HTMLDialogElement) || !e.target.contains(toastEl)) return;
  placeToast();
  if (toastEl.showPopover) { try { toastEl.showPopover(); } catch { /* already shown */ } }
}, true);

function confirmAction({ title, message, confirm, danger = false }) {
  const dialog = $('#confirm-dialog');
  $('#confirm-title').textContent = title; $('#confirm-message').textContent = message || '';
  $('#confirm-message').hidden = !message;
  const yes = $('#confirm-yes'); yes.textContent = confirm; yes.className = `btn ${danger ? 'danger' : 'primary'}`;
  return new Promise(resolve => {
    const done = value => { dialog.close(); resolve(value); };
    yes.onclick = () => done(true); $('#confirm-no').onclick = () => done(false);
    dialog.oncancel = () => resolve(false);
    dialog.showModal(); yes.focus();
  });
}

$$('[data-close]').forEach(b => b.addEventListener('click', () => document.getElementById(b.dataset.close).close()));

// The GPU worker (gpu.js): the window that asks for a video, the GPU's state, the LoRAs and the GPU requests in the feed.
const gpuUi = createGpu({ api, h, icon, toast, confirmAction, trackJob: job => { trackJob(job); schedulePoll(1500); }, newId, $, $$, dateTime, imageUrl, showSettings: pane => showSettings(pane),
  pickImages: (list, max, done, title) => openPicker({ textarea: '#video-prompt', list: () => list, offset: 0, max, base: () => null, render: done, title, mentions: false }),
  pickVideos: (list, max, done, title) => openPicker({ textarea: '#video-prompt', list: () => list, offset: 0, max, base: () => null, render: done, title, mentions: false, kind: 'video' }),
  keys: () => ({ claude: !!state.has_claude_key, openai: !!state.has_key, gemini: !!state.has_gemini_key }), formatUsd,
  activeJobs: () => [...state.jobs.values()].filter(j => ACTIVE.has(j.status)), prefs: () => state.prefs, savePrefs, changed: () => scheduleRender(),
  // The account's LoRAs changed (Settings › GPU): the pickers of the composer and the edit panel follow.
  lorasChanged: () => { if (state.unlocked) renderLoraFields(); } });

// Stories (stories.js): the writer's section, with what it shares of this page.
const stories = createStories({ api, h, icon, toast, confirmAction, imageUrl, openViewer, trackJob, launchQueued, formatUsd, dateTime, plural, newId, story: storyHelpers, reader: readerModule, createComics, createVn, $,
  openHistory: story => openHistory({ story }),
  // The album of a story, its comic or a visual novel, open in the library (showAlbum is declared further down).
  openAlbum: id => showAlbum(id),
  // A story opened from the other section (the history, an address) takes the section of its kind with it.
  kindChanged: kind => { const section = kind === 'vn' ? 'vn' : 'stories'; if (state.section !== section) setSection(section, { remember: false }); },
  models: () => state.models, prefs: () => state.prefs, savePrefs, hasKey: () => state.has_key, hasClaudeKey: () => state.has_claude_key, hasGeminiKey: () => state.has_gemini_key,
  // Gallery images for a message to the writer (or for a story's cover and character sheets): the gallery picker,
  // filling `list` in place.
  pickImages: (list, max, done, title = t('Images for the writer')) => openPicker({ textarea: '#chat-input', list: () => list, offset: 0, max, base: () => null, render: done, title, mentions: false }),
  askName,
  imageSize: storyImageSize,
  // A whole story in a zip and back (pack.js), with the zip of comics.js and pictures fit for the import route.
  pack: packModule, zip, importableImage, blobData: blob => blobData(blob), // blobData is declared further down
  // What the writer is told of the credit for pictures: the balance of the service that makes them (OpenAI, or Google
  // for Nano Banana) as the top bar estimates it (null when it is off), what one picture costs with the story's image
  // settings (a landscape picture, as illustrations are by default), and whose balance it is, for the page's own lines.
  credit: (writer, aspect = '3:2') => {
    const payer = imagePayer(writer.image_model), now = accountNow(payer);
    const size = storyImageSize(writer, aspect);
    const cost = size ? estimate({ model: writer.image_model, quality: writer.image_quality, size, previews: 1 }) : null;
    return { balance: now ? now.balance : null, image: cost ? cost.perImage : null, of: BALANCES[payer].of };
  },
  // The story open changed, or its writer: the balances at the top follow it. A step of the writer was paid: they come again.
  inView: () => renderBalanceChips(), spent: () => refreshBalance() });
/**
 * The size a new picture of a story is made at (Writer::sizeFor): the usual one for its aspect ratio, or 1.5 or 2 times
 * it as Ampliar computes them. Models with fixed sizes always make the usual one.
 */
function storyImageSize(writer, aspect = '3:2') {
  const model = state.models?.[writer.image_model];
  if (!model) return null;
  if (!model.flex) return aspect === '1:1' ? '1024x1024' : ['2:3', '3:4', '9:16', '4:5'].includes(aspect) ? '1024x1536' : '1536x1024';
  const base = state.sizes?.[aspect], scale = String(writer.image_scale || '1');
  if (!base || scale === '1') return base || null;
  const [width, height] = base.split('x').map(Number), sizes = upscaleSizes(width, height);
  const pick = sizes.find(s => s.label === (scale === '1.5' ? `${number(1.5)}×` : '2×')) || sizes.find(s => s.label === t('Maximum'));
  return pick ? `${pick.width}x${pick.height}` : base;
}
$$('dialog.sheet').forEach(d => d.addEventListener('click', e => {
  if (e.target !== d) return;
  const r = d.getBoundingClientRect();
  if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close();
}));

// ---------------------------------------------------------------------------
// Unlock, setup and lock
// ---------------------------------------------------------------------------
async function init() {
  try {
    const data = await api('state');
    Object.assign(state, { initialized: data.initialized, unlocked: false, has_key: data.has_key, has_claude_key: Boolean(data.has_claude_key), has_gemini_key: Boolean(data.has_gemini_key), models: data.models, sizes: data.sizes, user: data.user, mcpUrl: data.mcp_url || '',
      maxParallel: data.max_parallel || 4, sessionMinutes: data.session_minutes || 60, staleAfter: data.stale_after || 180, maxVideoBytes: data.max_video_bytes || 0 });
    setAliases(data.models); state.modelsFetched = data.models_fetched || 0; state.modelsFresh = data.models_fresh || 1800;
    buildOptionLists(); stories.setWriters(data.writers || null); stories.setReaderPrefs(data.reader || null); stories.setStoryPrefs(data.story_prefs || null);
    $('#gate-title').textContent = data.initialized ? t('Sign in') : t('Initial setup');
    $('#gate-description').hidden = data.initialized;
    $('#gate-description').textContent = t('Enter the installation code from config.php and create the first user, who will be the administrator. There is no password recovery: keep it safe.');
    $('#install-fields').hidden = data.initialized; $('#confirm-wrap').hidden = data.initialized;
    $('#setup-hint').textContent = data.setup_ready ? '' : t('Set setup_token in config.php to at least 24 random characters.');
    $('#setup-hint').hidden = data.setup_ready;
    $('#auth-submit').disabled = !data.initialized && !data.setup_ready;
    $('#auth-submit').textContent = data.initialized ? t('Sign in') : t('Create user');
    $('#auth-name').required = !data.initialized;
    $('#auth-password').autocomplete = data.initialized ? 'current-password' : 'new-password';
    if (data.unlocked) await enter();
    else ($('#auth-name').value ? $('#auth-password') : $('#auth-name')).focus();
  } catch (e) {
    $('#auth-error').textContent = e.message; $('#auth-submit').textContent = t('Reload'); $('#auth-submit').disabled = false;
    $('#auth-submit').onclick = () => location.reload();
  }
}
$('#auth-form').onsubmit = async e => {
  e.preventDefault(); setError('#auth-error');
  const password = $('#auth-password').value;
  if (!state.initialized && password !== $('#auth-confirm').value) { setError('#auth-error', t('The passwords do not match.')); return; }
  $('#auth-submit').disabled = true;
  try { await api(state.initialized ? 'login' : 'setup', { name: $('#auth-name').value.trim(), password, token: $('#setup-token').value, website: $('#auth-website').value }); $('#auth-form').reset(); await init(); }
  catch (err) { setError('#auth-error', err.message); }
  finally { $('#auth-submit').disabled = false; }
};
function resetIdle() { clearTimeout(timers.idle); timers.idle = setTimeout(() => { lockUI(); toast(t('Session locked after inactivity.')); }, state.sessionMinutes * 60000); }

async function enter() {
  state.unlocked = true; resetIdle();
  $('#gate').hidden = true; $('#application').hidden = false;
  $('#account-name').textContent = state.user?.name || ''; $('#account-open').title = t('Signed in as {name}', { name: state.user?.name || '' });
  // The models the account's keys listed last time, before the saved options are put back (one may be among them).
  await loadModels();
  migratePrefs(); setOptionsMode(state.prefs.mode === 'advanced' ? 'advanced' : 'simple', { save: false });
  applyComposerPrefs(); renderCleanBefore();
  Object.assign(state.gallery, { group: state.prefs.group !== false, sort: SORT_LABEL[state.prefs.sort] ? state.prefs.sort : 'newest', trash: false, album: null, origin: 'all', favorites: false });
  renderToolbar();
  viewer.versionsView = state.prefs.versions === 'time' ? 'time' : 'tree';
  applyColumns();
  await Promise.all([loadGallery(), loadFeedJobs(), refreshBalance()]);
  gpuUi.refresh().then(() => gpuUi.schedule());
  if (state.user?.admin) checkExposure();
  if (!state.has_key) showSettings();
  // Fresh lists from the APIs when the kept ones are old, without holding the page.
  if (Date.now() / 1000 - (state.modelsFetched || 0) >= (state.modelsFresh || 1800)) loadModels({ refresh: true });
  const match = location.hash.match(/^#v\/([a-f0-9]{32})$/);
  if (match) openViewer(match[1], { history: 'none' });
  else if (writingHash(location.hash)) openStoriesAt(location.hash);
}

/**
 * Whether the app's internal folders answer on the web, which happens on servers that ignore .htaccess (nginx): app/ holds
 * a small text file that .htaccess hides. Local development servers (php -S) never follow .htaccess, so they are left out.
 */
async function checkExposure() {
  if (['localhost', '127.0.0.1', '[::1]'].includes(location.hostname)) return;
  let exposed = false;
  try {
    const response = await fetch('app/web-check.txt', { cache: 'no-store', credentials: 'omit' });
    exposed = response.ok && (await response.text()).trim() === 'atelier-internal-folder';
  } catch { /* unreachable: nothing to warn about */ }
  $('#exposed-warning').hidden = !exposed;
  if (exposed) toast(t('The app\'s internal folders are open to the web. See the warning in Settings.'), { tone: 'error', duration: 20000, action: { label: t('View'), run: showSettings } });
}

function lockUI() {
  state.generation++; state.unlocked = false;
  stories.reset(); setSection('images', { remember: false });
  for (const name of Object.keys(timers)) { clearTimeout(timers[name]); clearInterval(timers[name]); }
  $$('dialog[open]').forEach(d => { viewer.closeReason = 'lock'; d.close(); });
  if (location.hash) history.replaceState(null, '', location.pathname + location.search);
  hideToast();
  state.jobs.clear(); state.launching.clear(); state.refs = []; fresh.clear(); leaving.clear(); timers.leave = null;
  // Uploads stop with the session; the server removes their unfinished files a day later.
  for (const upload of state.uploads.values()) { upload.cancelled = true; upload.controller?.abort(); URL.revokeObjectURL(upload.src); }
  state.uploads.clear(); stopVideo(); viewer.frames = [];
  Object.assign(state.gallery, { items: [], ids: new Map(), total: 0, hasMore: false, loaded: false, search: '', trash: false, album: null, origin: 'all', favorites: false });
  state.albums = null; captions.clear(); captionQueue.clear(); pendingThumbs.clear(); dayLabels.clear();
  Object.assign(layout, { entries: [], result: null, marks: [], heads: new Map(), special: 0 });
  if (drag.active || drag.pending) dragCleanup();
  setSelecting(false); timeline.el.hidden = true; timeline.shown = false;
  $$('[popover]:not(#toast)').forEach(menu => { try { menu.hidePopover(); } catch { /* closed */ } });
  Object.assign(viewer, { id: null, item: null, lineage: null, refs: [], maskPainted: false, compare: false, expansion: null, mode: 'view' });
  closeMentionMenu();
  Object.assign(picker, { target: null, selected: [], known: new Map(), items: [], mention: null });
  stopAllHoverVideos();
  for (const cache of [tileCache, thumbCache, pendingNodes, pickerTiles]) { cache.forEach(el => $$('img', el).concat(el.tagName === 'IMG' ? [el] : []).forEach(i => i.removeAttribute('src'))); cache.clear(); }
  dayHeads.clear(); feedEl.style.height = '';
  $$('img[src*="action="]').forEach(i => i.removeAttribute('src'));
  ['#feed', '#scope-menu', '#view-menu', '#album-list', '.timeline-marks', '#reference-list', '#edit-refs', '#versions-list', '#frames-list', '#video-details', '#requests-list', '#history-foot', '#info-details', '#picker-grid', '#picker-empty'].forEach(s => $(s).replaceChildren());
  for (const canvas of [$('#mask-canvas'), $('#expand-canvas')]) { canvas.width = 0; canvas.height = 0; }
  for (const s of ['#prompt', '#edit-prompt', '#vary-lines', '#edit-vary-lines', '#detail-prompt', '#search', '#picker-search', '#album-name', '#name-input']) $(s).value = '';
  $('#info-prompt').textContent = ''; $('#info-typed').textContent = ''; $('#info-planned').textContent = '';
  for (const s of ['#auth-form', '#key-form', '#claude-key-form', '#gemini-key-form', '#password-form', '#rename-form', '#user-form']) $(s).reset();
  for (const s of ['#mcp-output', '#user-created', '#users-list']) $(s).replaceChildren();
  state.user = null; $('#account-name').textContent = ''; $('#exposed-warning').hidden = true;
  balance.info = null; for (const chip of $$('.balance-chip')) chip.hidden = true;
  for (const s of ['#balance-form', '#claude-balance-form', '#gemini-balance-form']) $(s).reset();
  $('#application').hidden = true; $('#gate').hidden = false; $('#activity').hidden = true; document.title = 'Atelier';
  $('#gate-title').textContent = t('Sign in');
}
// ---------------------------------------------------------------------------
// Sections: images and stories
// ---------------------------------------------------------------------------
// The stories have addresses of their own (#historias, #historias/<story>, #historias/<story>/ler in the reader), and
// going into them makes entries in the browser's history: going back from the reader leads to the story, from a story to
// the list of stories, and from there to the images.
const STORIES_HASH = /^#historias(?:\/([a-f0-9]{32})(\/ler)?)?$/;
// The visual novels (a story of kind vn, in a section of their own) have #vn and #vn/<project>.
const VN_HASH = /^#vn(?:\/([a-f0-9]{32}))?$/;
const WRITING = { stories: { hash: STORIES_HASH, base: '#historias', kind: 'story' }, vn: { hash: VN_HASH, base: '#vn', kind: 'vn' } };
const writingHash = hash => STORIES_HASH.test(hash) || VN_HASH.test(hash);
function setSection(section, { remember = true } = {}) {
  const from = state.section;
  state.section = section;
  for (const b of $$('.section-switch button')) b.setAttribute('aria-pressed', String(b.dataset.section === section));
  $('.workspace').hidden = section !== 'images';
  document.body.dataset.section = section;
  const w = WRITING[section];
  if (w) stories.show(w.kind); else stories.hide();
  if (remember && !location.hash.startsWith('#v/')) {
    const base = location.pathname + location.search, inWriting = writingHash(location.hash);
    if (w && !w.hash.test(location.hash)) {
      // A story or project left open opens again, over the list. From the other writing section the entries pile on
      // (back returns to it) and count from its own, so the images button still walks back to the images.
      const before = inWriting && WRITING[from] && history.state?.atelier ? Number(history.state.depth) || 0 : 0;
      history.pushState({ atelier: 'stories', depth: before + 1 }, '', `${base}${w.base}`);
      const open = stories.address();
      if (open !== w.base) history.pushState({ atelier: 'story', depth: before + 2 }, '', base + open);
    }
    else if (section === 'images' && inWriting) history.replaceState(null, '', base);
  }
  if (section === 'images' && state.unlocked) scheduleRender();
  renderBalanceChips();
}
$$('.section-switch button').forEach(b => b.addEventListener('click', () => {
  // Back to the images from the stories: the entries the stories made are walked back, so the back button doesn't
  // lead into them again.
  const depth = history.state?.atelier ? Number(history.state.depth) || 0 : 0;
  if (b.dataset.section === 'images' && WRITING[state.section] && depth > 0 && writingHash(location.hash)) { history.go(-depth); return; }
  if (b.dataset.section !== state.section) setSection(b.dataset.section);
}));
/**
 * Opened at an address in the stories (a reload, a link): the entries a person would have walked through are put under
 * it, so going back leads to the list of stories and then to the images. A reload finds them already there.
 */
function openStoriesAt(hash) {
  const section = VN_HASH.test(hash) ? 'vn' : 'stories', w = WRITING[section];
  const [, id = null, reading] = hash.match(w.hash) || [], base = location.pathname + location.search;
  if (!history.state?.atelier) {
    history.replaceState(null, '', base);
    history.pushState({ atelier: 'stories', depth: 1 }, '', `${base}${w.base}`);
    if (id) history.pushState({ atelier: 'story', depth: 2 }, '', `${base}${w.base}/${id}`);
    if (id && reading) history.pushState({ atelier: 'reader', depth: 3 }, '', `${base}${w.base}/${id}/ler`);
  }
  setSection(section, { remember: false });
  stories.route(id, Boolean(reading));
}

$('#lock').onclick = async () => { try { await api('logout', {}); } catch { /* lock locally anyway */ } lockUI(); await init(); };
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

// ---------------------------------------------------------------------------
// Option lists shared by the composer, edits and expansions
// ---------------------------------------------------------------------------
function fillSelect(el, entries, value) {
  el.replaceChildren(...entries.map(([v, label]) => { const o = h('option', { value: v }, label); return o; }));
  if (entries.some(([v]) => v === value)) el.value = value;
}
function qualities(model) {
  const m = state.models[model];
  // Nano Banana: the quality is how much it thinks before drawing (Pro always thinks a lot: only Alta).
  if (m?.provider === 'gemini') return QUALITY.filter(([v]) => v in (m.qualities || {}));
  // A model on the account's GPU: the quality is how many megapixels it draws.
  if (m?.provider === 'gpu') return QUALITY.filter(([v]) => v in (m.qualities || {})).map(([v, label]) => [v, t('{quality} · {n} MP', { quality: label, n: number(m.qualities[v]) })]);
  return m?.extended ? [...QUALITY, ...QUALITY_EXTENDED] : QUALITY;
}
const isGpu = model => state.models?.[model]?.provider === 'gpu';
/** The sizes a model generates: the image APIs' or, for a model on the account's GPU, its own (outpaint.js). */
const limitsOf = model => isGpu(model) ? GPU_LIMITS : API_LIMITS;
/** Whether a request for this model needs the OpenAI key (the GPU's models need the worker token, which the server checks). */
const needsKey = model => !state.has_key && !isGpu(model);
/** What a model doesn't have, turned off in a panel's fields: Nano Banana and the GPU's models make no transparent background and no previews. */
function syncProviderFields(model, { background = null, previews = null, moderation = null } = {}) {
  const gemini = model?.provider === 'gemini', gpu = model?.provider === 'gpu', off = gemini || gpu;
  const transparent = background?.querySelector('option[value=transparent]');
  if (transparent) transparent.disabled = off;
  if (off && background?.value === 'transparent') background.value = 'auto';
  for (const el of [previews, moderation]) if (el) { el.disabled = off; el.title = gemini ? t('Nano Banana does not have this option') : gpu ? t('{model} does not have this option', { model: model.label }) : ''; }
}
/** Prompt planning: the effort of GPT-6 Sol or Gemini, or for a model on the GPU MiniMax's expander, on or off. */
const GPU_THINKING = [['off', t('Off')], ['medium', t('MiniMax\'s expander')]];
function syncThinking(select, model) {
  const gpu = isGpu(model), previous = select.value;
  if ((select.dataset.gpu === 'true') !== gpu) { fillSelect(select, gpu ? GPU_THINKING : THINKING, previous); select.dataset.gpu = String(gpu); }
  if (gpu && previous !== 'off') select.value = 'medium';
}
function modelEntries(filter = () => true) { return Object.entries(state.models).filter(([, m]) => filter(m)).map(([id, m]) => [id, m.label]); }
function syncModelFields(modelSelect, qualitySelect, complexitySelect, fallbackQuality = 'high') {
  const model = state.models[modelSelect.value];
  const previous = qualitySelect.value || fallbackQuality;
  fillSelect(qualitySelect, qualities(modelSelect.value), previous);
  if (qualitySelect.value !== previous) qualitySelect.value = qualities(modelSelect.value).some(([v]) => v === fallbackQuality) ? fallbackQuality : 'high';
  if (complexitySelect) { complexitySelect.disabled = !model?.complexity; complexitySelect.title = model?.complexity ? '' : t('This model has no complexity control'); }
}
// ---------------------------------------------------------------------------
// The models of the account's keys (the server's Catalog): the lists kept from last time, fresh ones from Anthropic,
// OpenAI and Google when those are old or a key changed. A newer model the app has no prices for comes with "(preço
// estimado)" and is priced like the model it follows.
// ---------------------------------------------------------------------------
async function loadModels({ refresh = false, force = false } = {}) {
  try { applyModels(await api('models', { refresh, force })); }
  catch (err) { console.warn(t('Model list:'), err.message); }
}
function applyModels(data) {
  if (!data?.models) return;
  state.models = data.models; state.modelsFetched = data.fetched || 0; setAliases(data.models);
  // Each list keeps what was chosen, or the saved choice when it only now appears.
  for (const [selector, saved] of [['#model', state.prefs.composer?.model], ['#edit-model', state.prefs.edit?.model ?? state.prefs.composer?.model]]) {
    const el = $(selector), current = el.value;
    fillSelect(el, modelEntries(), state.models[saved] ? saved : state.models[current] ? current : DEFAULT_OPTIONS.model);
  }
  stories.setWriters(data.writers || null);
  if (state.unlocked) { updateComposerModel(); updateComposerCost(); }
}
function buildOptionLists() {
  fillSelect($('#model'), modelEntries(), DEFAULT_OPTIONS.model);
  fillSelect($('#edit-model'), modelEntries(), DEFAULT_OPTIONS.model);
  fillSelect($('#quantity'), Array.from({ length: 10 }, (_, i) => [String(i + 1), `${i + 1}×`]), '1');
  fillSelect($('#edit-quantity'), Array.from({ length: 10 }, (_, i) => [String(i + 1), `${i + 1}×`]), '1');
  for (const s of ['#complexity', '#edit-complexity']) fillSelect($(s), COMPLEXITY, 'high');
  for (const s of ['#thinking', '#edit-thinking']) fillSelect($(s), THINKING, 'off');
  for (const [panel, p] of Object.entries(QUALITY_PANELS)) $(p.presets).replaceChildren(...PRESETS.map((preset, index) => {
    // Four bars, lit up to the level.
    const svg = document.createElementNS(SVG, 'svg'); svg.setAttribute('viewBox', '0 0 20 14'); svg.setAttribute('aria-hidden', 'true');
    for (let i = 0; i < PRESETS.length; i++) {
      const bar = document.createElementNS(SVG, 'rect'), height = 4 + 3 * i;
      Object.entries({ x: 1 + 5 * i, y: 14 - height, width: 3, height, rx: 1 }).forEach(([k, v]) => bar.setAttribute(k, v));
      if (i <= index) bar.setAttribute('class', 'on');
      svg.append(bar);
    }
    return h('button', { type: 'button', class: 'preset', 'data-preset': preset.id, 'aria-pressed': 'false', onclick: () => choosePreset(panel, preset) }, svg, h('span', {}, preset.label));
  }));
  const ratios = $('#ratios'); ratios.replaceChildren();
  for (const ratio of [...Object.keys(state.sizes), 'auto', 'custom']) {
    const button = h('button', { type: 'button', class: 'ratio' + (ratio === 'custom' ? ' wide' : ''), 'data-ratio': ratio, 'aria-pressed': 'false',
      title: state.sizes[ratio] ? state.sizes[ratio].replace('x', ' × ') : ratio === 'auto' ? t('The model chooses') : t('Width and height in pixels') });
    if (state.sizes[ratio]) {
      const [w, hh] = ratio.split(':').map(Number), max = Math.max(w, hh), sw = 16 * w / max, sh = 16 * hh / max;
      const svg = document.createElementNS(SVG, 'svg'); svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
      const rect = document.createElementNS(SVG, 'rect');
      Object.entries({ x: (24 - sw) / 2, y: (24 - sh) / 2, width: sw, height: sh, rx: 2 }).forEach(([k, v]) => rect.setAttribute(k, v));
      svg.append(rect); button.append(svg);
    }
    button.append(h('span', {}, ratio === 'auto' ? 'Auto' : ratio === 'custom' ? t('Custom') : ratio));
    button.onclick = () => { setRatio(ratio); persistComposer(); };
    ratios.append(button);
  }
}

// ---------------------------------------------------------------------------
// Simple and advanced modes
// ---------------------------------------------------------------------------
// The simple mode shows only the quality levels; the advanced mode shows model, quality, complexity and prompt
// planning one by one. Both edit the same fields: a level just fills them in, so switching modes never loses a choice,
// and fields that match no level show as a custom setting.
const QUALITY_PANELS = {
  composer: { model: '#model', quality: '#quality', complexity: '#complexity', thinking: '#thinking', presets: '#presets', summary: '#preset-summary', apply: () => { updateGenerateLabel(); persistComposer(); } },
  edit: { model: '#edit-model', quality: '#edit-quality', complexity: '#edit-complexity', thinking: '#edit-thinking', presets: '#edit-presets', summary: '#edit-preset-summary', apply: () => persistEdit() },
};
function presetValues(preset, model) {
  const thinking = isGpu(model) && preset.thinking !== 'off' ? 'medium' : preset.thinking;
  return { quality: qualities(model).some(([v]) => v === preset.quality) ? preset.quality : 'high', complexity: preset.complexity, thinking };
}
/** The level these options correspond to, or null when they were set by hand. */
function presetOf(o) {
  const complexity = state.models[o.model]?.complexity;
  return PRESETS.find(preset => {
    const v = presetValues(preset, o.model);
    return v.quality === o.quality && v.thinking === o.thinking && (!complexity || v.complexity === o.complexity);
  }) || null;
}
function describeOptions(o) {
  const model = state.models[o.model];
  const name = (list, value) => (list.find(([v]) => v === value)?.[1] || value).toLowerCase();
  const parts = [model?.label || o.model, t('{level} quality', { level: name([...QUALITY, ...QUALITY_EXTENDED], o.quality) })];
  if (model?.complexity) parts.push(t('{level} complexity', { level: name(COMPLEXITY, o.complexity) }));
  parts.push(o.thinking === 'off' ? t('no prompt planning') : model?.provider === 'gpu' ? t('prompt planned by MiniMax\'s expander') : o.thinking === 'none' ? t('prompt planning without reasoning') : t('prompt planning with {effort} effort', { effort: name(THINKING, o.thinking) }));
  return parts.join(', ') + '.';
}
function panelValues(panel) {
  const p = QUALITY_PANELS[panel];
  return { model: $(p.model).value, quality: $(p.quality).value, complexity: $(p.complexity).value, thinking: $(p.thinking).value };
}
function renderPresets(panel) {
  const p = QUALITY_PANELS[panel], o = panelValues(panel), current = presetOf(o);
  for (const button of $$('.preset', $(p.presets))) {
    const preset = PRESETS.find(x => x.id === button.dataset.preset);
    button.setAttribute('aria-pressed', String(preset === current));
    button.title = describeOptions({ model: o.model, ...presetValues(preset, o.model) });
  }
  $(p.summary).textContent = (current ? '' : t('Custom in advanced mode: ')) + describeOptions(o);
}
function choosePreset(panel, preset) {
  const p = QUALITY_PANELS[panel], v = presetValues(preset, $(p.model).value);
  $(p.quality).value = v.quality; $(p.complexity).value = v.complexity; syncThinking($(p.thinking), $(p.model).value); $(p.thinking).value = v.thinking;
  p.apply();
}
function setOptionsMode(mode, { save = true } = {}) {
  document.body.dataset.mode = mode;
  $$('.mode-toggle').forEach(input => { input.checked = mode === 'advanced'; });
  $$('.quality-title').forEach(el => { el.textContent = mode === 'advanced' ? t('Model and quality') : t('Quality'); });
  if (save) savePrefs({ mode });
}
$$('.mode-toggle').forEach(input => input.addEventListener('change', () => setOptionsMode(input.checked ? 'advanced' : 'simple')));
/** The first visit after the simple mode arrived starts there, on Alta, unless the saved options already match a level. */
function migratePrefs() {
  if (state.prefs.mode) return;
  const patch = { mode: 'simple' }, high = PRESETS.find(p => p.id === 'high');
  for (const name of ['composer', 'edit']) {
    const saved = state.prefs[name]; if (!saved) continue;
    const o = { ...DEFAULT_OPTIONS, ...(state.prefs.composer || {}), ...(name === 'edit' ? saved : {}) };
    if (!state.models[o.model]) o.model = DEFAULT_OPTIONS.model;
    if (!presetOf(o)) patch[name] = { ...saved, ...presetValues(high, o.model) };
  }
  savePrefs(patch);
}

// ---------------------------------------------------------------------------
// Composer
// ---------------------------------------------------------------------------
function setRatio(ratio) {
  state.ratio = ratio;
  $$('.ratio').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.ratio === ratio)));
  $('#custom-size').hidden = ratio !== 'custom';
}
function updateComposerModel() {
  const model = state.models[$('#model').value]; if (!model) return;
  syncModelFields($('#model'), $('#quality'), $('#complexity'));
  syncProviderFields(model, { background: $('#background'), previews: $('#previews'), moderation: $('#moderation') });
  syncThinking($('#thinking'), $('#model').value);
  $('#thinking-hint').textContent = model.provider === 'gpu'
    ? t('MiniMax\'s prompt expander (from ComfyUI\'s official templates) rewrites the prompt on the GPU before the picture, and chooses its shape when the size is Auto. No cost; it downloads 7 GB more the first time in a session. If it fails, the picture is made with the prompt as written.')
    : t('Rewrites the prompt before generating: with GPT-6 Sol for the GPT Image models, with Gemini 3.8 Flash for Nano Banana. Extra cost. If it refuses, the image is made with the prompt as written.');
  renderLoraFields();
  const fixed = ['1:1', '2:3', '3:2', 'auto'];
  $$('.ratio').forEach(b => { b.disabled = !model.flex && !fixed.includes(b.dataset.ratio); });
  if (!model.flex && !fixed.includes(state.ratio)) setRatio('1:1');
  $('#size-note').textContent = model.provider === 'gemini'
    ? t('{model}: comes out at 1K, 2K or 4K depending on the size; an aspect ratio it does not have is cropped from the result. {note}', { model: model.label, note: model.note || '' })
    : model.provider === 'gpu'
    ? t('{model}: the quality sets the megapixels and the size the shape (Custom: any size up to 12,000 px per side). With Auto, the prompt expander chooses the shape, or it follows the first reference when it is off. {note}', { model: model.label, note: model.note || '' })
    : model.flex
    ? t('Custom: multiples of 16, aspect ratio up to 3:1 and up to 3,840 px per side. Above 2560 × 1440 the result is experimental.')
    : t('This model only accepts 1:1, 2:3, 3:2 and automatic.');
}
function updateFormatFields() {
  const format = $('#format').value;
  $('#compression-wrap').hidden = format === 'png';
  if (format === 'jpeg' && $('#background').value === 'transparent') $('#background').value = 'opaque';
}
// Several images from one request (the number next to the button): varied by the app, which plans one direction per
// image from the request; one line per image, written by the owner; or all from the same request. The lines are prompt
// text, so they go with the request and are never kept in the browser; only the choice between varied and alike is.
const VARY_HINTS = {
  auto: (n, edit) => edit ? t('Before generating, the app writes a different direction for each of the {n} images from your request (pose, scene, angle, light), keeping what you asked for and the image recognizable. One planning call per batch.', { n })
    : t('Before generating, the app writes a different direction for each of the {n} images from your request (pose, scene, angle, light), keeping what you asked for. One planning call per batch.', { n }),
  lines: () => t('One line per image, from 2 to 10, in the box right below the request. Each line completes the request, and the number of images follows the number of lines.'),
  same: () => t('All with the same request. Since the API has no seed, they tend to come out similar.'),
};
// The lines have their own box right under the prompt box; how the images vary is chosen under the buttons. In the edit
// panel all of it belongs to a plain edit: with Ampliar, Fotorrealista and the like chosen (`off`), it steps aside.
const varyForms = {
  composer: { box: '#vary-box', lines: '#vary-lines', quantity: '#quantity', open: '#vary-open', mode: 'auto', off: () => false, changed: () => { updateGenerateLabel(); persistComposer(); } },
  edit: { box: '#edit-vary-box', lines: '#edit-vary-lines', quantity: '#edit-quantity', open: '#edit-vary-open', mode: 'auto', off: () => !!viewer.action, changed: () => persistEdit() },
};
function varyLines(form) {
  return $(varyForms[form].lines).value.split('\n').map(line => line.replace(/\s+/g, ' ').trim()).filter(Boolean);
}
function checkLines(lines) {
  if (lines.length < 2) return t('Write at least 2 lines, one per image.');
  if (lines.length > 10) return t('There are {lines} lines: the maximum is 10 images per request.', { lines: lines.length });
  return '';
}
function renderVary(form) {
  const f = varyForms[form], box = $(f.box), quantity = $(f.quantity), off = f.off(), lines = f.mode === 'lines' && !off;
  if (lines) quantity.value = String(Math.max(2, Math.min(10, varyLines(form).length)));
  const n = Number(quantity.value) || 1;
  box.hidden = off || n < 2;
  quantity.disabled = lines;
  quantity.title = lines ? t('The number of images follows the number of lines') : t('Number of images');
  for (const button of $$('[data-vary]', box)) button.setAttribute('aria-pressed', String(button.dataset.vary === f.mode));
  $('[data-vary-hint]', box).textContent = VARY_HINTS[f.mode](n, form === 'edit');
  $(f.lines).closest('.lines-box').hidden = !lines;
  $(f.open).setAttribute('aria-pressed', String(n > 1 && !off));
}
for (const [form, f] of Object.entries(varyForms)) {
  const box = $(f.box), textarea = $(f.lines);
  for (const button of $$('[data-vary]', box)) button.onclick = () => {
    f.mode = button.dataset.vary; f.changed();
    if (f.mode === 'lines') { autoGrow(textarea); textarea.focus(); }
  };
  let count = 0;
  textarea.addEventListener('input', () => {
    autoGrow(textarea);
    const now = varyLines(form).length; if (now !== count) { count = now; f.changed(); }
  });
  // The Variações chip: turns several images on (4, varied by the app) or back to one.
  $(f.open).onclick = () => {
    const quantity = $(f.quantity);
    if (Number(quantity.value) > 1) { if (f.mode === 'lines') f.mode = 'auto'; quantity.value = '1'; }
    else { quantity.value = '4'; if (f.mode === 'same') f.mode = 'auto'; }
    f.changed();
    if (!box.hidden) box.scrollIntoView({ block: 'nearest' });
  };
}
function updateGenerateLabel() {
  renderVary('composer');
  const n = Number($('#quantity').value) || 1;
  $('#generate-label').textContent = n > 1 ? t('Generate {n}', { n }) : t('Generate');
  const length = $('#prompt').value.length;
  $('#prompt-count').textContent = length > 2000 ? `${number(length)} / ${number(32000)}` : '';
  updateComposerCost();
}

// API balance. OpenAI has no API for the prepaid balance, so the app follows it from the amount entered in the settings:
// the server keeps the tokens of every request since then, and the balance is priced here (assets/pricing.js). It also
// brings the tokens of the latest planning calls, for the estimates.
const balance = { info: null };
// Cost estimates (assets/pricing.js). Prompt planning is billed apart: it enters by what the account's latest planning
// calls cost on average, and is only mentioned while there are too few of them to go by.
const refSize = ref => ref.width ? [ref.width, ref.height] : UNKNOWN_IMAGE;
/** The planning calls of a request: one per image at the effort chosen, and one for the directions of a varied batch. */
const planningCalls = (o, varied) => [...(o.thinking !== 'off' ? [o.thinking] : []), ...(varied ? ['variation'] : [])];
function showCost(selector, spec) {
  const el = $(selector);
  if (spec && isGpu(spec.model)) {
    el.hidden = false;
    el.textContent = t('No API cost: made on your GPU (Colab).');
    el.title = t('The GPU\'s time is paid to whoever rents it, such as Colab\'s compute units, while the notebook is on. Varied batches plan their directions with the OpenAI or Gemini key.');
    return;
  }
  const result = spec && estimate(spec);
  el.hidden = !result; if (!result) return;
  const n = spec.n || 1, calls = spec.planning || [];
  // The averages are of GPT-6 Sol's planning: Nano Banana's (Gemini 3.8 Flash) is only mentioned.
  const gemini = isGemini(spec.model);
  const costs = calls.map(call => { const each = gemini ? null : planningAverage(balance.info?.planning, call); return each === null ? null : each * (call === 'variation' ? 1 : n); });
  const planned = calls.length && costs.every(cost => cost !== null) ? costs.reduce((sum, cost) => sum + cost, 0) : null;
  const total = formatUsd(result.total + (planned || 0)), images = n > 1 ? ' ' + t('({n} images)', { n }) : '';
  el.textContent = planned !== null ? t('Estimated cost: ≈ {total}{images}, with planning (≈ {planning})', { total, images, planning: formatUsd(planned) })
    : calls.length ? t('Estimated cost: ≈ {total}{images} + planning', { total, images }) : t('Estimated cost: ≈ {total}{images}', { total, images });
  el.title = [gemini ? t('Calculated with Google\'s prices from {date} and the typical token use for this model, quality and size.', { date: GEMINI_PRICING_CHECKED })
    : t('Calculated with OpenAI\'s prices from {date} and the typical token use for this model, quality and size.', { date: PRICING_CHECKED }),
  gemini ? t('Paid with the Gemini key, outside the OpenAI balance.') : '', t('References and the edited image count as input.'),
  planned !== null ? t('Planning is counted at the average of your latest requests.') : '', t('The real cost of each image appears in its details.')].filter(Boolean).join(' ');
}
function updateComposerCost() {
  if (!state.models) return;
  // The balance at the top is the one of the model chosen.
  if (!WRITING[state.section]) renderBalanceChips();
  const o = composerOptions();
  showCost('#generate-cost', { model: o.model, quality: o.quality, size: o.size, n: o.n, previews: o.previews, planning: planningCalls(o, varyForms.composer.mode === 'auto' && o.n > 1),
    inputs: state.refs.map(refSize), promptLength: $('#prompt').value.length });
}
function composerSize() {
  if (state.ratio === 'custom') return `${$('#width').value}x${$('#height').value}`;
  return state.ratio === 'auto' ? 'auto' : state.sizes[state.ratio];
}
function composerOptions() {
  const model = $('#model').value;
  return { model, size: composerSize(), quality: $('#quality').value, complexity: $('#complexity').value, format: $('#format').value,
    background: $('#background').value, compression: Number($('#compression').value), n: Number($('#quantity').value), moderation: $('#moderation').value,
    thinking: $('#thinking').value, previews: Number($('#previews').value), vary: varyForms.composer.mode !== 'same',
    ...(isGpu(model) ? { loras: chosenLoras('composer') } : {}) };
}
// LoRAs (gpu.js): for a model on the account's GPU, the composer and the edit panel each keep the ones chosen, with
// their strengths, as [{ id, strength }].
const loraChoice = { composer: [], edit: [] };
const loraFields = { composer: { box: '#loras', model: '#model', save: () => persistComposer() }, edit: { box: '#edit-loras', model: '#edit-model', save: () => persistEdit() } };
const loraCopy = list => (Array.isArray(list) ? list : []).filter(l => typeof l?.id === 'string').map(l => ({ id: l.id, strength: Number(l.strength ?? 1) })).slice(0, 4);
/** The LoRAs chosen in a panel that are still in the account's list. */
function chosenLoras(panel) {
  const known = gpuUi.loraList();
  return loraChoice[panel].filter(c => !known || known.some(l => l.id === c.id));
}
function renderLoraFields() {
  for (const [panel, f] of Object.entries(loraFields)) {
    const box = $(f.box), model = state.models[$(f.model).value], show = model?.provider === 'gpu';
    box.hidden = !show;
    if (!show) continue;
    f.render ||= gpuUi.loraPicker(box, { get: () => loraChoice[panel], set: list => { loraChoice[panel] = loraCopy(list); f.render(); f.save(); }, max: model.max_loras || 4 });
    f.render();
  }
}
function applyOptions(options) {
  const o = { ...DEFAULT_OPTIONS, ...(options || {}) };
  if (state.models[o.model]) $('#model').value = o.model;
  updateComposerModel();
  const set = (selector, value) => { const el = $(selector); if ([...el.options].some(opt => opt.value === String(value))) el.value = String(value); };
  set('#quality', o.quality); set('#complexity', o.complexity); set('#format', o.format); set('#background', o.background);
  set('#quantity', o.n); set('#moderation', o.moderation); syncThinking($('#thinking'), $('#model').value); set('#thinking', o.thinking); set('#previews', o.previews);
  loraChoice.composer = loraCopy(o.loras);
  $('#compression').value = o.compression; $('#compression-value').textContent = o.compression;
  varyForms.composer.mode = o.vary === false ? 'same' : 'auto';
  const ratio = Object.keys(state.sizes).find(k => state.sizes[k] === o.size);
  if (ratio) setRatio(ratio);
  else if (o.size === 'auto') setRatio('auto');
  else if (/^\d+x\d+$/.test(o.size || '')) { const [w, hh] = o.size.split('x'); $('#width').value = w; $('#height').value = hh; setRatio('custom'); }
  updateComposerModel(); updateFormatFields(); updateGenerateLabel(); renderPresets('composer');
}
function persistComposer() {
  updateComposerCost(); renderPresets('composer');
  savePrefs({ composer: { ...composerOptions(), ratio: state.ratio, width: Number($('#width').value), height: Number($('#height').value) } });
}
function applyComposerPrefs() {
  const saved = state.prefs.composer;
  if (saved?.ratio === 'custom') { $('#width').value = saved.width || 1536; $('#height').value = saved.height || 1024; }
  applyOptions(saved ? { ...saved, size: saved.ratio === 'custom' ? `${saved.width}x${saved.height}` : saved.ratio === 'auto' ? 'auto' : state.sizes[saved.ratio] || saved.size } : DEFAULT_OPTIONS);
}
$('#model').onchange = () => { updateComposerModel(); persistComposer(); };
$('#format').onchange = () => { updateFormatFields(); persistComposer(); };
$('#background').onchange = () => { if ($('#background').value === 'transparent' && $('#format').value === 'jpeg') { $('#format').value = 'png'; updateFormatFields(); } persistComposer(); };
$('#compression').oninput = () => { $('#compression-value').textContent = $('#compression').value; };
$('#compression').onchange = persistComposer;
for (const s of ['#quality', '#complexity', '#quantity', '#moderation', '#thinking', '#previews', '#width', '#height']) $(s).addEventListener('change', () => { updateGenerateLabel(); persistComposer(); });
$('#prompt').addEventListener('input', () => { autoGrow($('#prompt')); updateGenerateLabel(); });
$('#restore-defaults').onclick = () => { applyOptions(DEFAULT_OPTIONS); persistComposer(); toast(t('Default options restored.')); };
$('#reset-composer').onclick = () => { $('#prompt').value = ''; state.refs = []; renderRefs(); autoGrow($('#prompt')); setError('#generate-error'); updateGenerateLabel(); $('#prompt').focus(); };
function autoGrow(textarea) { textarea.style.height = 'auto'; textarea.style.height = `${Math.min(textarea.scrollHeight + 2, window.innerHeight * .5)}px`; }

// References (composer and edits share the same reader)
async function readImages(files, list, max) {
  const accepted = [];
  for (const file of files) {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) { toast(t('Use PNG, JPEG or WebP images.')); continue; }
    if (file.size > 14 * 1024 * 1024) { toast(t('{name} is over 14 MB.', { name: file.name || t('Image') })); continue; }
    if (list.length + accepted.length >= max) { toast(t('Use up to {max} references.', { max })); break; }
    const data = await new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(r.result); r.onerror = reject; r.readAsDataURL(file); });
    const probe = new Image(); probe.src = data;
    const size = await probe.decode().then(() => ({ width: probe.naturalWidth, height: probe.naturalHeight }), () => ({}));
    accepted.push({ data, name: file.name || t('Pasted image'), ...size });
  }
  list.push(...accepted);
  return accepted.length;
}
function refsPayload(list) { return list.map(r => r.id ? { id: r.id } : { data: r.data }); }

// The two prompts that take references. "@2" in a prompt names its second image; in an edit, @1 is the image being
// edited and the extra references start at @2. The server tells the model which attached image each number is.
const REF_TARGETS = {
  composer: { textarea: '#prompt', container: '#reference-list', fileInput: '#file-input', list: () => state.refs, offset: 0, max: 16, base: () => null, render: () => renderRefs(),
    placeholder: [t('Describe the image'), t('Describe the image. Cite the references with @1, @2…')] },
  edit: { textarea: '#edit-prompt', container: '#edit-refs', fileInput: '#edit-file', list: () => viewer.refs, offset: 1, max: 15, base: () => viewer.item, render: () => renderEditRefs(),
    placeholder: [t('What should change?'), t('What should change? This image is @1; the references, @2 onward')] },
};
const refNumber = (target, index) => index + 1 + target.offset;
const refName = ref => ref.name || (ref.id ? t('Image from the gallery') : t('Image from the computer'));
function renderRefList(target) {
  const list = target.list(), base = target.base();
  const chip = (src, number, name, remove) => {
    const img = h('img', { alt: '' }); img.src = src;
    return h('div', { class: 'ref' + (remove ? '' : ' base') },
      h('button', { type: 'button', class: 'ref-cite', title: t('@{number}: {name}. Click to cite it in the prompt.', { number, name }), 'aria-label': t('Cite @{number} ({name}) in the prompt', { number, name }), onclick: () => insertMention(target, [number]) },
        img, h('span', { class: 'ref-tag', 'aria-hidden': 'true' }, `@${number}`)),
      remove ? h('button', { type: 'button', class: 'icon-btn ref-remove', 'aria-label': t('Remove reference @{number}', { number }), title: t('Remove'), onclick: remove }, icon('close')) : '');
  };
  $(target.container).replaceChildren(
    ...(base && list.length ? [chip(imageUrl(base.id, true), 1, t('this image'), null)] : []),
    ...list.map((ref, index) => chip(ref.id ? imageUrl(ref.id, true) : ref.data, refNumber(target, index), refName(ref), () => removeRef(target, index))));
  $(target.textarea).placeholder = target.placeholder[list.length ? 1 : 0];
}
function removeRef(target, index) {
  const before = [...target.list()];
  target.list().splice(index, 1);
  renumberMentions(target, before);
  target.render();
}
function renderRefs() { renderRefList(REF_TARGETS.composer); updateComposerCost(); }
$('#file-input').onchange = async e => { await addRefFiles(REF_TARGETS.composer, [...e.target.files]); e.target.value = ''; };
$('#file-open').onclick = () => $('#file-input').click();
function acceptDrops(zone, onFiles) {
  zone.addEventListener('dragover', e => { if (!e.dataTransfer?.types.includes('Files')) return; e.preventDefault(); e.stopPropagation(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => { if (!e.dataTransfer?.files.length) return; e.preventDefault(); e.stopPropagation(); zone.classList.remove('dragover'); onFiles([...e.dataTransfer.files]); });
}
function acceptPaste(textarea, onFiles) {
  textarea.addEventListener('paste', e => { const files = [...(e.clipboardData?.files || [])].filter(f => f.type.startsWith('image/')); if (files.length) { e.preventDefault(); onFiles(files); } });
}
// Files from the device. Chosen from the @ menu, the new images are cited where the @ was typed.
async function addRefFiles(target, files) {
  const list = target.list(), before = list.length;
  try { await readImages(files, list, target.max); } catch { toast(t('Could not open the image.')); }
  target.render();
  const pending = target.pending; target.pending = null;
  const textarea = $(target.textarea);
  if (pending && list.length > before && textarea.value.slice(pending.start, pending.end) === pending.text) {
    insertMention(target, list.slice(before).map((_, i) => refNumber(target, before + i)), [pending.start, pending.end]);
  }
}
const addComposerRefs = files => addRefFiles(REF_TARGETS.composer, files);
acceptDrops($('#drop-area'), addComposerRefs); acceptPaste($('#prompt'), addComposerRefs);
$('#gallery-refs').onclick = () => openPicker(REF_TARGETS.composer);
// A video from the composer: its prompt, and its references as the first frame or the people and things to keep.
// The composer's references go as the video's references.
$('#video-open').onclick = () => gpuUi.open({ prompt: $('#prompt').value, pictures: state.refs.slice(0, 9).map(r => r.id ? { id: r.id, width: r.width, height: r.height } : { data: r.data, src: r.data, width: r.width, height: r.height }).filter(f => f.id || f.data) });

// ---------------------------------------------------------------------------
// Mentions: @1, @2… in a prompt. Typing @ opens a menu of the references; removing one renumbers the others.
// ---------------------------------------------------------------------------
const MENTION = /(?<![\p{L}\p{N}_@])@([0-9]{1,2}|\?)(?![\p{L}\p{N}_])/gu;
const fold = text => String(text || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
function renumberMentions(target, before) {
  const textarea = $(target.textarea), after = target.list(), value = textarea.value;
  const next = value.replace(MENTION, (whole, n) => {
    const old = n === '?' ? -1 : Number(n) - 1 - target.offset;
    if (old < 0 || old >= before.length) return whole;
    const index = after.indexOf(before[old]);
    // A mention of a removed image is kept visible, so the person decides what it should say.
    return index < 0 ? '@?' : `@${refNumber(target, index)}`;
  });
  if (next === value) return;
  const caret = textarea.selectionStart + next.length - value.length;
  textarea.value = next; textarea.setSelectionRange(Math.max(0, caret), Math.max(0, caret));
  textarea.dispatchEvent(new Event('input'));
}
function insertMention(target, numbers, range) {
  const textarea = $(target.textarea);
  const [start, end] = range || [textarea.selectionStart, textarea.selectionEnd];
  const before = textarea.value.slice(0, start), after = textarea.value.slice(end);
  const text = (/[\p{L}\p{N}_@]$/u.test(before) ? ' ' : '') + numbers.map(n => `@${n}`).join(' ') + (!after || /^[\p{L}\p{N}_@]/u.test(after) ? ' ' : '');
  textarea.value = before + text + after;
  textarea.focus();
  textarea.setSelectionRange(start + text.length, start + text.length);
  textarea.dispatchEvent(new Event('input'));
}
function mentionError(target, text) {
  const total = target.list().length + target.offset;
  for (const [, n] of text.matchAll(MENTION)) {
    if (n === '?') return t('The prompt has a mention without an image (@?), left from a removed reference. Cite another image or delete the mention.');
    const number = Number(n);
    if (number >= 1 && number <= total) continue;
    if (!total) return t('The prompt cites @{number}, but there are no reference images. Add the images or delete the mention.', { number });
    return t('There is no image @{number}. {range}', { number, range: total === 1 ? t('The only image is @1.') : t('The images go from @1 to @{total}.', { total }) });
  }
  return '';
}

const mentionMenu = { target: null, start: 0, end: 0, text: '', options: [], active: 0 };
function mentionQuery(textarea) {
  if (textarea.selectionStart !== textarea.selectionEnd) return null;
  const caret = textarea.selectionStart;
  const match = /(?:^|[^\p{L}\p{N}_@])@([\p{L}\p{N}_-]{0,24})$/u.exec(textarea.value.slice(0, caret));
  return match ? { start: caret - match[1].length - 1, end: caret, query: match[1] } : null;
}
function mentionOptions(target, query) {
  const base = target.base(), list = target.list();
  const entries = [
    ...(base ? [{ number: 1, name: t('This image'), src: imageUrl(base.id, true) }] : []),
    ...list.map((ref, i) => ({ number: refNumber(target, i), name: refName(ref), src: ref.id ? imageUrl(ref.id, true) : ref.data })),
  ];
  if (!query) return [...entries, { action: 'gallery', name: t('Choose from the gallery…') }, { action: 'file', name: t('Upload from the computer…') }];
  const q = fold(query);
  return /^[0-9]+$/.test(q) ? entries.filter(e => String(e.number).startsWith(q)) : entries.filter(e => fold(e.name).includes(q));
}
// Where the caret is drawn, measured on a hidden copy of the text that wraps the same way.
function caretPoint(textarea) {
  const style = getComputedStyle(textarea);
  const copy = h('div', { 'aria-hidden': 'true' });
  for (const name of ['boxSizing', 'width', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
    'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing', 'lineHeight', 'wordSpacing', 'textTransform', 'tabSize']) copy.style[name] = style[name];
  Object.assign(copy.style, { position: 'fixed', left: '0', top: '0', visibility: 'hidden', whiteSpace: 'pre-wrap', overflowWrap: 'break-word', borderStyle: 'solid', height: 'auto' });
  copy.textContent = textarea.value.slice(0, textarea.selectionStart);
  const marker = h('span', {}, '\u200b'); copy.append(marker);
  textarea.parentNode.append(copy);
  const box = textarea.getBoundingClientRect();
  const point = { x: box.left + marker.offsetLeft - textarea.scrollLeft, top: box.top + marker.offsetTop - textarea.scrollTop, height: marker.offsetHeight || parseFloat(style.lineHeight) || 20 };
  copy.remove();
  return point;
}
function closeMentionMenu() {
  const target = mentionMenu.target; if (!target) return;
  mentionMenu.target = null;
  target.menu.hidden = true; target.menu.replaceChildren();
  $(target.textarea).removeAttribute('aria-activedescendant');
  $(target.textarea).setAttribute('aria-expanded', 'false');
}
function updateMentionMenu(target) {
  const textarea = $(target.textarea);
  const found = document.activeElement === textarea && state.unlocked ? mentionQuery(textarea) : null;
  if (!found || target.dismissed === found.start) { if (!found) target.dismissed = null; if (mentionMenu.target === target) closeMentionMenu(); return; }
  const options = mentionOptions(target, found.query);
  if (!options.length) { if (mentionMenu.target === target) closeMentionMenu(); return; }
  const same = mentionMenu.target === target && mentionMenu.start === found.start;
  if (mentionMenu.target && mentionMenu.target !== target) closeMentionMenu();
  Object.assign(mentionMenu, { target, start: found.start, end: found.end, text: '@' + found.query, options, active: same ? Math.min(mentionMenu.active, options.length - 1) : 0 });
  const menu = target.menu;
  menu.replaceChildren(...options.map((option, index) => {
    const art = option.action ? h('span', { class: 'mention-art' }, icon(option.action === 'gallery' ? 'gallery' : 'upload')) : h('img', { alt: '', src: option.src });
    return h('button', { type: 'button', role: 'option', id: `${menu.id}-${index}`, class: 'mention-option' + (option.action ? ' action' : ''), tabindex: '-1',
      onclick: () => chooseMention(index) }, art, option.action ? '' : h('strong', {}, `@${option.number}`), h('span', {}, option.name));
  }));
  menu.hidden = false;
  textarea.setAttribute('aria-expanded', 'true');
  highlightMention();
  // Below the caret, or above it when there is no room; always inside the window.
  const point = caretPoint(textarea), width = menu.offsetWidth, height = menu.offsetHeight;
  const below = point.top + point.height + 6;
  menu.style.left = `${Math.max(8, Math.min(point.x - 14, innerWidth - width - 8))}px`;
  menu.style.top = `${below + height > innerHeight - 8 && point.top - height - 6 > 8 ? point.top - height - 6 : Math.min(below, innerHeight - height - 8)}px`;
}
function highlightMention() {
  const { target, active } = mentionMenu; if (!target) return;
  $$('.mention-option', target.menu).forEach((el, i) => el.setAttribute('aria-selected', String(i === active)));
  const current = target.menu.children[active];
  if (current) { current.scrollIntoView({ block: 'nearest' }); $(target.textarea).setAttribute('aria-activedescendant', current.id); }
}
function chooseMention(index) {
  const { target, options, start, end, text } = mentionMenu; const option = options[index];
  if (!target || !option) return;
  closeMentionMenu();
  if (option.number) insertMention(target, [option.number], [start, end]);
  else if (option.action === 'gallery') openPicker(target, { mention: { start, end, text } });
  else { target.pending = { start, end, text }; $(target.fileInput).click(); }
}
for (const target of Object.values(REF_TARGETS)) {
  const textarea = $(target.textarea);
  target.menu = h('div', { class: 'mention-menu', role: 'listbox', id: `${textarea.id}-mentions`, 'aria-label': t('Cite an image'), hidden: true });
  target.menu.addEventListener('mousedown', e => e.preventDefault()); // keeps the caret in the prompt
  textarea.closest('.prompt-box').append(target.menu);
  textarea.setAttribute('aria-autocomplete', 'list'); textarea.setAttribute('aria-controls', target.menu.id); textarea.setAttribute('aria-expanded', 'false');
  textarea.addEventListener('input', () => updateMentionMenu(target));
  textarea.addEventListener('click', () => updateMentionMenu(target));
  textarea.addEventListener('keyup', e => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) updateMentionMenu(target); });
  textarea.addEventListener('blur', () => { if (mentionMenu.target === target) closeMentionMenu(); });
  textarea.addEventListener('keydown', e => {
    if (mentionMenu.target !== target) return;
    if (e.ctrlKey || e.metaKey || e.altKey) { closeMentionMenu(); return; }
    const count = mentionMenu.options.length;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); mentionMenu.active = (mentionMenu.active + (e.key === 'ArrowDown' ? 1 : count - 1)) % count; highlightMention(); }
    else if ((e.key === 'Enter' || e.key === 'Tab') && !e.shiftKey) { e.preventDefault(); chooseMention(mentionMenu.active); }
    // Escape closes only the menu, not the viewer around it.
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); target.dismissed = mentionMenu.start; closeMentionMenu(); }
  });
  $(target.fileInput).addEventListener('cancel', () => { target.pending = null; });
}
// The menu follows the caret when the prompt itself scrolls or the window changes size; other scrolling closes it.
document.addEventListener('scroll', e => {
  const target = mentionMenu.target; if (!target || e.target === target.menu) return;
  if (e.target === $(target.textarea)) updateMentionMenu(target); else closeMentionMenu();
}, true);
window.addEventListener('resize', () => { if (mentionMenu.target) updateMentionMenu(mentionMenu.target); });

// ---------------------------------------------------------------------------
// Gallery picker: choose several library images as references at once
// ---------------------------------------------------------------------------
const pickerDialog = $('#picker');
const picker = { target: null, selected: [], known: new Map(), items: [], filter: 'all', search: '', group: true, hasMore: false, loading: false, version: 0, mention: null };
const pickerTiles = new Map();
function openPicker(target, { mention = null } = {}) {
  if (!state.unlocked || pickerDialog.open) return;
  const ids = target.list().filter(r => r.id);
  Object.assign(picker, { target, mention, selected: [...new Set(ids.map(r => r.id))], known: new Map(ids.map(r => [r.id, r])), items: [], filter: 'all', search: '', group: state.gallery.group && target.kind !== 'video', hasMore: false });
  $('#picker-search').value = ''; $('#picker-group').checked = picker.group;
  $$('[data-picker-filter]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.pickerFilter === 'all')));
  $('#picker-title').textContent = target.title || (target.offset ? t('References for the edit') : t('References from the gallery'));
  $('#picker-grid').replaceChildren(); $('#picker-empty').hidden = true;
  pickerDialog.showModal();
  updatePicker();
  loadPicker().catch(e => toast(e.message, { tone: 'error' }));
}
async function loadPicker({ more = false } = {}) {
  const version = ++picker.version;
  picker.loading = true; $('#picker-loading').hidden = false;
  try {
    const data = await api('gallery', undefined, { q: picker.search, favorite: picker.filter === 'favorites' ? '1' : '0', trash: '0', kind: picker.target.kind || 'image',
      offset: String(more ? picker.items.length : 0), limit: '60', group: picker.group ? '1' : '0' });
    if (version !== picker.version || !pickerDialog.open) return;
    picker.items = more ? [...picker.items, ...data.items] : data.items; picker.hasMore = data.has_more;
    for (const item of data.items) picker.known.set(item.id, item);
    renderPicker();
  } finally {
    if (version === picker.version) { picker.loading = false; $('#picker-loading').hidden = true; }
  }
  requestAnimationFrame(morePicker);
}
function morePicker() {
  const body = $('#picker-body');
  if (pickerDialog.open && picker.hasMore && !picker.loading && body.scrollHeight - body.scrollTop - body.clientHeight < 600) loadPicker({ more: true }).catch(e => toast(e.message, { tone: 'error' }));
}
$('#picker-body').addEventListener('scroll', morePicker, { passive: true });
function pickerTile(item) {
  let tile = pickerTiles.get(item.id);
  if (!tile) {
    const img = h('img', { alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' }); img.src = imageUrl(item.id, true);
    tile = h('button', { type: 'button', class: 'pick', 'data-id': item.id, 'aria-pressed': 'false', onclick: () => togglePick(item.id) },
      img, h('span', { class: 'tile-caption', 'aria-hidden': 'true' }), h('span', { class: 'pick-mark', 'aria-hidden': 'true' }),
      isVideo(item) ? h('span', { class: 'pick-duration', 'aria-hidden': 'true' }, icon('play'), clock(Math.round(item.duration || 0))) : '');
    pickerTiles.set(item.id, tile);
  }
  tile.style.setProperty('--ar', (item.width / item.height).toFixed(4));
  const text = displayText(item);
  tile.dataset.label = snippet(text, 60) || (isVideo(item) ? t('Video') : t('Image'));
  $('.tile-caption', tile).textContent = snippet(text, 140);
  return tile;
}
function renderPicker() {
  syncChildren($('#picker-grid'), picker.items.map(pickerTile));
  const empty = !picker.items.length;
  $('#picker-empty').hidden = !empty;
  if (empty) {
    const [title, detail] = picker.search ? [t('Nothing found'), t('No prompt contains “{search}”.', { search: snippet(picker.search, 40) })]
      : picker.filter === 'favorites' ? [t('No favorites'), ''] : picker.target.kind === 'video' ? [t('No videos yet'), t('Import or drag in a video (MP4, MOV or WebM), or make one.')]
      : [t('The gallery is empty'), t('Generate or import images to use them as references.')];
    $('#picker-empty').replaceChildren(icon('image'), h('strong', {}, title), detail ? h('span', {}, detail) : '');
  }
  updatePicker();
}
// The references the prompt will have: the ones it had (minus those unselected here), then the new ones in the order picked.
function pickerResult() {
  const chosen = new Set(picker.selected), seen = new Set();
  const kept = picker.target.list().filter(r => { if (!r.id) return true; if (!chosen.has(r.id) || seen.has(r.id)) return false; seen.add(r.id); return true; });
  const added = picker.selected.filter(id => !seen.has(id)).map(id => {
    const item = picker.known.get(id) || {};
    return { id, name: snippet(displayText(item), 60) || (isVideo(item) ? t('Video') : t('Image from the gallery')), width: item.width, height: item.height, ...(isVideo(item) ? { duration: item.duration || 0 } : {}) };
  });
  return { list: [...kept, ...added], added };
}
const pickerRoom = () => picker.target.max - picker.target.list().filter(r => !r.id).length;
function updatePicker() {
  const target = picker.target; if (!target) return;
  const { list } = pickerResult(), numbers = new Map(), baseId = target.base()?.id;
  list.forEach((ref, i) => { if (ref.id && !numbers.has(ref.id)) numbers.set(ref.id, refNumber(target, i)); });
  for (const [id, tile] of pickerTiles) {
    if (!tile.isConnected) continue;
    const base = id === baseId, number = base ? 1 : numbers.get(id);
    tile.disabled = base; tile.classList.toggle('base', base);
    tile.setAttribute('aria-pressed', String(!base && !!number));
    // Images for the story writer have no @ in a prompt: just the order they go in.
    const mark = target.mentions === false ? String(number) : `@${number}`;
    $('.pick-mark', tile).textContent = number ? mark : '';
    const state_ = base ? t('image being edited, @1') : number ? (target.mentions === false ? `selecionada, ${number}ª` : t('selected as @{number}', { number })) : '';
    tile.title = state_ ? state_[0].toUpperCase() + state_.slice(1) : '';
    tile.setAttribute('aria-label', state_ ? `${tile.dataset.label} (${state_})` : tile.dataset.label);
  }
  const count = picker.selected.length, cited = picker.selected.map(id => numbers.get(id)).sort((a, b) => a - b).map(n => `@${n}`);
  if (target.kind === 'video') {
    $('#picker-status').textContent = !count ? t('Select one or more videos (up to {max}).', { max: pickerRoom() }) : plural(count, t('{n} video selected.'), t('{n} videos selected.'));
    $('#picker-done').textContent = count ? plural(count, t('Use {n} video'), t('Use {n} videos')) : t('Done');
    return;
  }
  $('#picker-status').textContent = !count ? t('Select one or more images (up to {max}).', { max: pickerRoom() })
    : target.mentions === false ? plural(count, t('{n} image selected.'), t('{n} images selected.'))
    : plural(count, t('{n} image selected. In the prompt: {cited}.', { cited: listText(cited) }), t('{n} images selected. In the prompt: {cited}.', { cited: listText(cited) }));
  $('#picker-done').textContent = count ? plural(count, t('Use {n} image'), t('Use {n} images')) : t('Done');
}
function togglePick(id) {
  const index = picker.selected.indexOf(id);
  if (index >= 0) picker.selected.splice(index, 1);
  else if (picker.selected.length >= pickerRoom()) { toast(picker.target.kind === 'video' ? t('Up to {max} videos.', { max: picker.target.max }) : picker.target.mentions === false ? t('Up to {max} images.', { max: picker.target.max }) : picker.target.offset ? t('Use up to {max} references besides the edited image.', { max: picker.target.max }) : t('Use up to {max} references.', { max: picker.target.max })); return; }
  else picker.selected.push(id);
  updatePicker();
}
function confirmPicker() {
  const target = picker.target; if (!target) return;
  const list = target.list(), before = [...list], { list: next, added } = pickerResult(), mention = picker.mention;
  list.splice(0, list.length, ...next);
  picker.mention = null; pickerDialog.close();
  if (target.mentions !== false) renumberMentions(target, before);
  target.render();
  const textarea = $(target.textarea);
  if (mention && added.length && textarea.value.slice(mention.start, mention.end) === mention.text) insertMention(target, added.map(ref => refNumber(target, list.indexOf(ref))), [mention.start, mention.end]);
  else if (mention) textarea.focus();
}
$('#picker-done').onclick = confirmPicker;
$('#picker-cancel').onclick = () => pickerDialog.close();
pickerDialog.addEventListener('close', () => {
  picker.version++;
  if (picker.mention && picker.target) { const textarea = $(picker.target.textarea); picker.mention = null; textarea.focus(); }
});
pickerDialog.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); confirmPicker(); } });
$('#picker-search').addEventListener('input', () => { clearTimeout(timers.pickerSearch); timers.pickerSearch = setTimeout(() => { picker.search = $('#picker-search').value.trim(); loadPicker().catch(e => toast(e.message, { tone: 'error' })); }, 250); });
$$('[data-picker-filter]').forEach(button => button.onclick = () => {
  picker.filter = button.dataset.pickerFilter;
  $$('[data-picker-filter]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
  loadPicker().catch(e => toast(e.message, { tone: 'error' }));
});
$('#picker-group').onchange = () => { picker.group = $('#picker-group').checked; loadPicker().catch(e => toast(e.message, { tone: 'error' })); };

$('#generate-form').onsubmit = async e => {
  e.preventDefault(); setError('#generate-error');
  if (needsKey($('#model').value)) { showSettings(); return; }
  const prompt = $('#prompt').value.trim();
  if (!prompt) { setError('#generate-error', t('Write what you want to generate.')); $('#prompt').focus(); return; }
  const unmatched = mentionError(REF_TARGETS.composer, prompt);
  if (unmatched) { setError('#generate-error', unmatched); $('#prompt').focus(); return; }
  const lines = varyForms.composer.mode === 'lines' ? varyLines('composer') : null;
  const linesError = lines && checkLines(lines);
  if (linesError) { setError('#generate-error', linesError); $('#vary-lines').focus(); return; }
  const options = composerOptions();
  if (state.ratio === 'custom' && !validSize(Number($('#width').value), Number($('#height').value), limitsOf(options.model))) {
    setError('#generate-error', isGpu(options.model) ? t('Invalid custom size: use whole pixels, from 64 to 12,000 per side, up to 40 megapixels and an aspect ratio up to 5:1.')
      : t('Invalid custom size: use multiples of 16, an aspect ratio up to 3:1, up to 3,840 px per side and between 0.66 and 8.3 megapixels.')); return;
  }
  const button = $('#generate'); button.disabled = true;
  try {
    await submitJob({ prompt, options, references: refsPayload(state.refs), ...(lines ? { lines } : {}) });
    persistComposer();
    button.classList.add('sent'); $('#generate-label').textContent = t('Sent');
    setTimeout(() => { button.classList.remove('sent'); updateGenerateLabel(); }, 900);
  } catch (err) { setError('#generate-error', err.message); }
  finally { button.disabled = false; }
};

// ---------------------------------------------------------------------------
// Requests: create, launch, poll. Several run in parallel; none blocks the UI.
// ---------------------------------------------------------------------------
// A request for several images comes back as one job per image, so each runs, fails or is retried on its own.
function startJobs({ job, jobs = [job] }) {
  jobs.forEach(trackJob); launchQueued(); schedulePoll(1500);
  return jobs[0];
}
async function submitJob(spec) {
  return startJobs(await api('create', { id: newId(), ...spec }));
}
async function retryJob(job, n) {
  const created = await api('retry', { id: newId(), from: job.id, ...(n ? { n } : {}) });
  if (!ACTIVE.has(job.status)) state.jobs.delete(job.id);
  return startJobs(created);
}
// Where the server can't check a request's process, it goes by silence alone and may mark as interrupted a request whose
// process was only quiet. That request runs again when its process speaks, and ends as it ends: it is still asked about
// for a while, also after it was dismissed.
const revivable = new Map(); // request id → time (ms) until which it is asked about
const REVIVE_FOR = 20 * 60000;
function trackJob(job) {
  const previous = state.jobs.get(job.id);
  if (revivable.has(job.id) && job.status !== 'uncertain') {
    revivable.delete(job.id);
    if (!previous && job.status === 'completed') { revived(job); return; }
  }
  // Still interrupted and already dismissed: it stays out of the gallery.
  if (!previous && revivable.has(job.id)) return;
  // A late answer about a request that already ended and left the screen must not bring it back.
  if (!previous && (job.status === 'completed' || job.status === 'cancelled')) return;
  const merged = { ...previous, ...job };
  state.jobs.set(job.id, merged);
  if (jobView.job?.id === job.id) jobView.job = merged;
  if (previous && ACTIVE.has(previous.status) && job.status === 'uncertain' && !job.watched) revivable.set(job.id, Date.now() + REVIVE_FOR);
  if (previous && (ACTIVE.has(previous.status) || previous.status === 'uncertain') && previous.status !== job.status && !ACTIVE.has(job.status)) onJobEnded(merged);
  scheduleRender();
}
/** A request that was marked as interrupted and dismissed, and then finished after all. */
function revived(job) {
  job.image_ids?.forEach(id => fresh.add(id));
  refreshAfterJobs();
  toast(t('A request that seemed interrupted has finished. The image is in the gallery.'), job.image_ids?.length ? { action: { label: t('View'), run: () => openViewer(job.image_ids[0], { history: 'replace' }) } } : {});
}
function launch(id) {
  if (state.launching.has(id)) return;
  state.launching.add(id);
  const job = state.jobs.get(id); if (job) delete job.launchError;
  api('run', { id })
    .then(({ job: result }) => trackJob(result))
    .catch(err => {
      // A dropped connection says nothing about the request: polling tells what happened on the server.
      if (!state.unlocked || err.transport || err.status === 401) return;
      const current = state.jobs.get(id);
      if (current && current.status === 'queued') { current.launchError = err.message; scheduleRender(); }
      else toast(err.message, { tone: 'error' });
    })
    .finally(() => { state.launching.delete(id); schedulePoll(300); });
}
function launchQueued() {
  // Video requests wait for the GPU worker, which takes them from the server itself.
  const jobs = [...state.jobs.values()].filter(j => !j.runner);
  const busy = jobs.filter(j => j.status === 'running' || j.status === 'cancelling' || (j.status === 'queued' && state.launching.has(j.id))).length;
  let free = state.maxParallel - busy;
  for (const job of jobs.filter(j => j.status === 'queued' && !state.launching.has(j.id) && !j.launchError).sort((a, b) => a.created - b.created)) {
    if (free-- <= 0) break;
    launch(job.id);
  }
}
function schedulePoll(delay) {
  if (!state.unlocked) return;
  const due = Date.now() + delay;
  if (timers.poll && timers.pollDue <= due) return;
  clearTimeout(timers.poll); timers.pollDue = due;
  timers.poll = setTimeout(poll, delay);
}
async function poll() {
  timers.poll = null;
  if (!state.unlocked) return;
  for (const [id, until] of revivable) if (until < Date.now()) revivable.delete(id);
  const tracked = [...new Set([...[...state.jobs.values()].filter(j => ACTIVE.has(j.status)).map(j => j.id), ...revivable.keys()])];
  if (!tracked.length) return;
  const generation = state.generation;
  try {
    const { jobs, now: serverNow } = await api('jobs', undefined, { scope: 'active', ids: tracked.join(',') });
    if (generation !== state.generation) return;
    state.skew = serverNow - Date.now() / 1000;
    for (const job of jobs) trackJob(job);
    launchQueued();
  } catch { /* retried below */ }
  if ([...state.jobs.values()].some(j => ACTIVE.has(j.status))) schedulePoll(document.hidden ? 10000 : 2000);
  else if (revivable.size) schedulePoll(15000);
}
document.addEventListener('visibilitychange', () => { if (!document.hidden) schedulePoll(0); });
async function loadFeedJobs() {
  const { jobs, now: serverNow } = await api('jobs', undefined, { scope: 'feed' });
  state.skew = serverNow - Date.now() / 1000;
  for (const job of jobs) state.jobs.set(job.id, job);
  scheduleRender(); launchQueued(); schedulePoll(2000);
}
let refreshPending = null;
function refreshAfterJobs() {
  // Several requests may finish together: refresh once.
  if (refreshPending) return refreshPending;
  refreshPending = new Promise(resolve => setTimeout(resolve, 250)).then(() => Promise.all([loadGallery(), refreshBalance()])).catch(() => {}).finally(() => { refreshPending = null; });
  return refreshPending;
}
const NO_CREDIT = t('No credit at OpenAI. Top up the API key\'s account to generate again.');
const BILLING_URL = 'https://platform.openai.com/settings/organization/billing/overview';
const NO_GEMINI_CREDIT = t('No credit at Gemini. Check the key\'s billing and limits in Google AI Studio to generate with Nano Banana again.');
const GEMINI_BILLING_URL = 'https://aistudio.google.com/usage';
async function onJobEnded(job) {
  const related = viewer.lineage && job.parent_id && viewer.lineage.nodes.some(n => n.id === job.parent_id);
  if (job.status === 'completed' || job.image_ids?.length) {
    job.image_ids.forEach(id => fresh.add(id));
    // The gallery and the version tree are refreshed before the request leaves the screen, so nothing blinks out.
    await Promise.all([refreshAfterJobs(), related ? refreshLineage() : null]);
    if (job.status === 'completed') state.jobs.delete(job.id);
    scheduleRender();
    if (related) {
      // Follow the result only when the person is still on the version that was edited, not writing a new instruction and
      // without an area painted on it (the area stays after sending, to send again).
      // The instruction just sent stays in the box; typing after sending means a new one is being written.
      const busy = viewer.editTyped || viewer.maskPainted || $('dialog.sheet[open]');
      if (viewer.id === job.parent_id && viewer.mode === 'view' && !busy && job.image_ids.length) openViewer(job.image_ids[0], { history: 'replace' });
      else toast(t('{operation} ready.', { operation: OPERATION[job.operation] || t('Edit') }), { action: { label: t('View'), run: () => openViewer(job.image_ids[0], { history: 'replace' }) } });
    } else if ($('#viewer').open && job.image_ids.length) {
      toast(t('Image ready.'), { action: { label: t('View'), run: () => openViewer(job.image_ids[0], { history: 'replace' }) } });
    }
  } else if (job.status === 'cancelled') {
    state.jobs.delete(job.id); scheduleRender(); if (related) renderVersions();
    refreshBalance();
  } else {
    if (related) renderVersions();
    // A request that ended without an image may have paid for its planning.
    refreshBalance();
    // No credit left at OpenAI: said wherever the person is and for longer, with the way to top up, since every request
    // after it would fail the same way. The request itself says the same in the gallery and in its details.
    if (job.error_kind === 'credit') toast(NO_CREDIT, { tone: 'error', duration: 20000, action: { label: t('Reload'), run: () => window.open(BILLING_URL, '_blank', 'noopener') } });
    else if (job.error_kind === 'gemini-credit') toast(NO_GEMINI_CREDIT, { tone: 'error', duration: 20000, action: { label: t('Open'), run: () => window.open(GEMINI_BILLING_URL, '_blank', 'noopener') } });
    else if ($('#viewer').open) toast(job.status === 'failed' ? t('A request failed.') : t('A request was left unconfirmed.'), { tone: 'error', action: { label: t('Details'), run: () => openJobDetails(job.id, job) } });
  }
  updateActivity();
}
async function cancelJob(job) {
  if (job.status === 'running') {
    const ok = await confirmAction({ title: t('Cancel the generation?'), message: t('The app stops waiting for the API. If the API is already processing it, the request may still be charged.'), confirm: t('Cancel generation'), danger: true });
    if (!ok) return;
  }
  try { const { job: updated } = await api('cancel', { id: job.id }); trackJob(updated); schedulePoll(500); }
  catch (e) { toast(e.message, { tone: 'error' }); }
}
async function dismissJob(job, { quiet = false } = {}) {
  try { await api('dismiss', { id: job.id }); state.jobs.delete(job.id); scheduleRender(); }
  catch (e) { if (!quiet) toast(e.message, { tone: 'error' }); }
}
// A request that ended without a result leaves the gallery by itself. It counts about ten seconds while it is in front
// of the person, then it is dismissed, as its × does at once; it stays in Pedidos, where it can be repeated. The count
// waits while the page is in the background, while the request is out of sight (scrolled away, in another view of the
// library, behind a window) and while the pointer or the request's details are on it.
const LEAVE_AFTER = 10000, LEAVE_STEP = 100;
const leaving = new Map(); // request id → milliseconds left in front of the person; 0 while the dismissal is on its way
const canHover = matchMedia('(hover: hover)');
const unsuccessful = job => (job.status === 'failed' || job.status === 'uncertain') && !job.launchError;
/** The × of an unsuccessful request. The ring around it runs out with the request's time on the screen. */
function dismissButton(job, size) {
  return h('button', { type: 'button', class: `icon-btn ${size} leave`, 'data-leaves': job.id, 'aria-label': DISMISS_LABEL, title: DISMISS_TITLE,
    onclick: () => dismissJob(state.jobs.get(job.id) || job) }, icon('close'));
}
const DISMISS_LABEL = t('Dismiss request'), DISMISS_TITLE = t('Dismiss. The request also leaves the gallery by itself in a few seconds, and stays in Requests.');
/** In front of the person, on the screen: a card of the gallery with no window over it, or an entry among the versions of the open image. */
function failureShown(id) {
  if (document.hidden) return false;
  const onScreen = el => { if (!el?.isConnected) return false; const box = el.getBoundingClientRect(); return box.width > 0 && box.bottom > 0 && box.top < innerHeight; };
  const open = $$('dialog[open]');
  if (!open.length) return onScreen(tileCache.get(`job:${id}:0`));
  return open.length === 1 && open[0] === viewerDialog && !viewerDialog.classList.contains('immersive') && onScreen(pendingNodes.get(id));
}
function failureHeld(id) {
  if (jobDialog.open && jobView.job?.id === id) return true;
  return canHover.matches && [tileCache.get(`job:${id}:0`), pendingNodes.get(id)].some(el => el?.isConnected && el.matches(':hover'));
}
function watchFailures() {
  for (const job of state.jobs.values()) if (unsuccessful(job) && !leaving.has(job.id)) leaving.set(job.id, LEAVE_AFTER);
  for (const id of [...leaving.keys()]) { const job = state.jobs.get(id); if (!job || !unsuccessful(job)) leaving.delete(id); }
  if (leaving.size && !timers.leave) timers.leave = setInterval(countLeaving, LEAVE_STEP);
  if (!leaving.size && timers.leave) { clearInterval(timers.leave); timers.leave = null; }
  for (const el of $$('[data-leaves]')) el.style.setProperty('--left', ((leaving.get(el.dataset.leaves) ?? LEAVE_AFTER) / LEAVE_AFTER).toFixed(3));
}
function countLeaving() {
  for (const [id, left] of [...leaving]) {
    if (!left || !failureShown(id) || failureHeld(id)) continue;
    const job = state.jobs.get(id); if (!job) continue;
    leaving.set(id, Math.max(0, left - LEAVE_STEP));
    if (left > LEAVE_STEP) continue;
    // A dismissal that does not go through (the connection dropped) is tried again after another count.
    dismissJob(job, { quiet: true }).then(() => { if (state.jobs.has(id) && leaving.has(id)) leaving.set(id, LEAVE_AFTER); watchFailures(); });
  }
  watchFailures();
}
/**
 * A video request in the window again: its prompt, options and what it came from in the library. Pictures from the
 * device and sounds stay with the request, so they are not copied.
 */
function reuseVideo(job) {
  const size = id => ({ id, width: itemById(id)?.width, height: itemById(id)?.height, duration: itemById(id)?.duration });
  const inputs = job.inputs || [], o = job.options || {};
  const source = inputs.find(i => i.kind === 'video' && i.role === 'source');
  gpuUi.open({ prompt: job.prompt, options: o, op: o.op || null,
    pictures: inputs.filter(i => i.kind === 'image' && i.role === 'ref' && i.id).map(i => size(i.id)),
    keys: inputs.filter(i => i.kind === 'image' && i.role === 'key' && i.id).map(i => ({ ...size(i.id), at: i.at })),
    videos: inputs.filter(i => i.kind === 'video' && i.role === 'ref').map(i => ({ ...size(i.id), duration: i.duration })),
    source: source ? { ...size(source.id), duration: source.duration } : null });
  if (inputs.some(i => i.kind === 'audio' || (i.kind === 'image' && !i.id))) toast(t('Prompt and options copied. The sounds and the pictures from the device were not copied.'));
}
function useJobPrompt(job) {
  closeViewer();
  if (job.kind === 'video') { reuseVideo(job); return; }
  if (state.section !== 'images') setSection('images');
  applyOptions(job.options); persistComposer();
  $('#prompt').value = job.prompt; autoGrow($('#prompt')); updateGenerateLabel();
  if (job.reference_count) toast(t('Prompt and options copied. The references were not copied.'));
  $('#prompt').focus();
}

function phaseText(job) {
  if (job.launchError) return t('Did not start');
  if (job.runner === 'gpu') return gpuUi.phase(job);
  if (job.status === 'queued') {
    const ahead = [...state.jobs.values()].filter(j => j.status === 'queued' && j.created < job.created).length;
    return ahead ? t('Queued · #{n}', { n: ahead + 1 }) : t('Queued');
  }
  if (job.status === 'cancelling') return t('Cancelling…');
  if (job.status === 'completed') return t('Ready');
  if (job.previews) return t('Preview {previews} of {total}', { previews: job.previews, total: job.options?.previews || job.previews });
  return { starting: t('Starting'), varying: t('Planning variations'), planning: t('Planning the prompt'), cleaning: t('Cleaning'), generating: t('Generating'), saving: t('Saving') }[job.phase] || (job.operation === 'clean' ? t('Cleaning') : t('Generating'));
}
function silenceOf(job) { return job.status === 'running' || job.status === 'cancelling' ? now() - job.updated : 0; }

function updateActivity() {
  const active = [...state.jobs.values()].filter(j => ACTIVE.has(j.status));
  const running = active.filter(j => j.status !== 'queued').length, queued = active.length - running;
  $('#activity').hidden = !active.length;
  $('#activity-text').textContent = [running && `${running} gerando`, queued && t('{queued} queued', { queued })].filter(Boolean).join(' · ');
  document.title = active.length ? `(${active.length}) Atelier` : 'Atelier';
  if (active.length && !timers.tick) timers.tick = setInterval(tick, 1000);
  if (!active.length && timers.tick) { clearInterval(timers.tick); timers.tick = null; }
}
function tick() {
  for (const el of $$('[data-elapsed]')) { const job = state.jobs.get(el.dataset.elapsed); if (job) el.textContent = clock(now() - job.created); }
  for (const el of $$('[data-signal]')) {
    const job = state.jobs.get(el.dataset.signal); const silent = job ? silenceOf(job) : 0;
    // Where the server checks the request's process, it is interrupted only if that process stops, however long it takes.
    // The check comes after 20 s of silence, at the next poll: a request still running then has its process alive.
    el.hidden = silent < (job?.watched ? 25 : 20);
    el.textContent = job?.watched
      ? t('No news from the server for {silent}, but the request\'s process is still running.', { silent: clock(silent) })
      : t('No signal from the server for {silent}. If it goes past {staleAfter}, the request is marked as interrupted.', { silent: clock(silent), staleAfter: clock(state.staleAfter) });
  }
}

// ---------------------------------------------------------------------------
// Library. The whole view arrives at once as compact rows (id, date, size, flags), is laid out in justified rows
// (grouped by day when sorted by date) and only the rows around the screen are in the page, so thousands of items
// scroll smoothly. Titles and prompts load as tiles come into view. A timeline on the right jumps anywhere.
// ---------------------------------------------------------------------------
const SORTS = [['newest', t('Newest')], ['oldest', t('Oldest')], ['custom', t('Custom order')], ['largest', t('Highest resolution')], ['smallest', t('Lowest resolution')],
  ['wide', t('Landscape first')], ['tall', t('Portrait first')], ['versions', t('Most versions')]];
const SORT_LABEL = Object.fromEntries(SORTS);
const datedSort = () => ['newest', 'oldest'].includes(state.gallery.sort);
/** Versions and stacks are grouped in the library view; favorites, albums and the trash list every item on its own. */
const groupable = () => { const g = state.gallery; return !g.trash && !g.favorites && !g.album; };
const grouped = () => state.gallery.group && groupable();
const feedEl = $('#feed');
const captions = new Map(); // id → title, prompt, label, operation, model; kept until the session locks
const layout = { entries: [], result: null, metrics: null, marks: [], heads: new Map(), special: 0 };
const dayHeads = new Map(); // day label → heading element
const DAY_FORMAT = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' });
const DAY_YEAR_FORMAT = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' });
const MONTH_FORMAT = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' });
const dayLabels = new Map();

function scheduleRender() {
  if (timers.render) return;
  timers.render = requestAnimationFrame(() => { timers.render = null; renderFeed(); updateActivity(); if ($('#viewer').open) renderVersions(); renderJobDetails(); watchFailures(); });
}

const dayKey = d => d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate();
function dayText(key, created) {
  const today = new Date(); const todayKey = dayKey(today);
  if (key === todayKey) return t('Today');
  today.setDate(today.getDate() - 1);
  if (key === dayKey(today)) return t('Yesterday');
  let label = dayLabels.get(key);
  if (!label) {
    const d = new Date(created * 1000);
    label = (d.getFullYear() === new Date().getFullYear() ? DAY_FORMAT : DAY_YEAR_FORMAT).format(d);
    dayLabels.set(key, label);
  }
  return label;
}
/**
 * A library row from the server: [id, created, width, height, flags, versions, duration]. Flags: 1 favorite, 2 video,
 * 4 imported, 8 video frame, 16 in a stack, 32 hidden. Versions: how many items its pile (versions or stack) has.
 */
function rowItem([id, created, width, height, flags, versions, duration]) {
  const video = !!(flags & 2);
  const item = { id, created, width, height, favorite: !!(flags & 1), kind: video ? 'video' : 'image', versions, duration, stacked: !!(flags & 16), hidden: !!(flags & 32),
    origin: video ? 'video' : flags & 4 ? 'imported' : 'generated', operation: flags & 8 ? 'frame' : video ? 'video' : flags & 4 ? 'import' : null,
    day: dayKey(new Date(created * 1000)), prompt: '', label: null };
  const caption = captions.get(id);
  return caption ? Object.assign(item, caption) : item;
}
const itemById = id => { const i = state.gallery.ids.get(id); return i === undefined ? null : state.gallery.items[i]; };

async function loadGallery() {
  const g = state.gallery; const version = ++g.version;
  g.loading = true; if (!g.loaded) $('#feed-loading').hidden = false;
  try {
    const data = await api('library', undefined, { q: g.search, favorite: g.favorites ? '1' : '0', trash: g.trash ? '1' : '0', origin: g.origin === 'all' ? '' : g.origin,
      album: g.album?.id || '', group: grouped() ? '1' : '0', sort: g.sort });
    if (version !== g.version) return;
    g.items = data.items.map(rowItem); g.ids = new Map(g.items.map((item, i) => [item.id, i]));
    g.total = data.total; g.loaded = true;
    for (const id of selection.ids) if (!g.ids.has(id)) selection.ids.delete(id);
    renderFeed();
  } finally {
    if (version === g.version) { g.loading = false; $('#feed-loading').hidden = true; }
  }
}

// Puts exactly these nodes in this order, touching only what changed. Re-inserting an element restarts its
// CSS animations and reloads nothing, but it still makes every tile fade in again: that was the flicker.
function syncChildren(parent, nodes) {
  const keep = new Set(nodes);
  for (const child of [...parent.childNodes]) if (!keep.has(child)) child.remove();
  nodes.forEach((node, i) => { const at = parent.childNodes[i]; if (at !== node) parent.insertBefore(node, at || null); });
}
function setText(el, text) { if (el.textContent !== text) el.textContent = text; }
// Shows a newer preview only once it is decoded: the previous one stays on screen until then.
function setPreview(container, src) {
  if (container.dataset.preview === src) return;
  container.dataset.preview = src;
  const current = $('img.preview', container);
  const img = h('img', { class: 'preview', alt: '' });
  if (!current) {
    img.onload = () => img.classList.add('loaded');
    img.src = src; container.prepend(img); return;
  }
  img.src = src;
  img.decode().then(() => {
    if (container.dataset.preview !== src || !current.isConnected) return;
    img.classList.add('loaded'); current.replaceWith(img);
  }, () => {});
}

/** Requests and uploads in progress show first, in a section of their own (or under "Hoje" with the newest first). */
function feedEntries() {
  const g = state.gallery, entries = [];
  const plain = !g.trash && !g.album && !g.favorites && !g.search;
  const pending = g.sort === 'newest' ? t('Today') : t('In progress');
  pruneUploads();
  if (plain && ['all', 'video'].includes(g.origin)) {
    for (const upload of [...state.uploads.values()].sort((a, b) => b.created - a.created)) { const tile = uploadTile(upload); if (tile) entries.push({ el: tile, group: pending }); }
  }
  // A request shows where its images go: in the library, or in the album that keeps them out of it (a story's).
  const here = plain ? job => !job.apart : g.album && !g.trash && !g.favorites && !g.search ? job => job.album === g.album.id : null;
  if (here && ['all', 'generated'].includes(g.origin)) {
    for (const job of [...state.jobs.values()].sort((a, b) => b.created - a.created)) if (here(job)) for (const tile of jobTiles(job)) entries.push({ el: tile, group: pending });
  }
  layout.special = entries.length;
  const dated = datedSort();
  for (const item of g.items) entries.push({ item, group: dated ? dayText(item.day, item.created) : null });
  return entries;
}
function feedMetrics() {
  const narrow = innerWidth <= 560, medium = innerWidth <= 920;
  return { width: feedEl.clientWidth, rowHeight: narrow ? 120 : medium ? 150 : 230, gap: medium ? 4 : 6, sectionGap: medium ? 16 : 24, header: 30, headerGap: medium ? 8 : 12 };
}
function relayout() {
  const m = feedMetrics();
  // Request cards need room for their text.
  const tiles = layout.entries.map(e => ({ group: e.group, aspect: e.item ? e.item.width / e.item.height : Math.max(e.el.aspect || 1, 180 / m.rowHeight) }));
  const result = justify(tiles, m);
  layout.metrics = m; layout.result = result;
  layout.heads = new Map();
  for (const head of result.headers) layout.heads.set(head.index, head);
  feedEl.style.height = `${Math.ceil(result.height)}px`;
  layout.marks = datedSort() ? monthMarks(layout.entries.map(e => e.item || null), result) : [];
}
const stickyBottom = () => $('.feed-toolbar').getBoundingClientRect().bottom;
/** The first item at the top of the screen, to keep it in place when tiles are added or removed above it. */
function scrollAnchor() {
  const result = layout.result; if (!result) return null;
  const top = feedEl.getBoundingClientRect().top, sticky = stickyBottom();
  if (top >= sticky) return null;
  const rows = result.rows, row = rows[firstRowAt(rows, sticky - top)];
  if (!row) return null;
  for (let i = row.first; i <= row.last; i++) if (layout.entries[i]?.item) return { id: layout.entries[i].item.id, y: result.tiles[i].y };
  return null;
}
function restoreAnchor(anchor) {
  if (!anchor) return;
  const index = state.gallery.ids.get(anchor.id); if (index === undefined) return;
  const box = layout.result.tiles[layout.special + index];
  if (box && Math.abs(box.y - anchor.y) > 0.5) window.scrollBy(0, box.y - anchor.y);
}

function renderFeed() {
  if (!state.unlocked) return;
  const anchor = scrollAnchor();
  layout.entries = feedEntries();
  relayout();
  restoreAnchor(anchor);
  const g = state.gallery;
  const empty = !layout.entries.length && g.loaded;
  $('#feed-empty').hidden = !empty;
  if (empty) {
    const content = g.search ? [t('Nothing found'), t('No prompt contains “{search}”.', { search: snippet(g.search, 40) })]
      : g.trash ? [t('The trash is empty'), '']
        : g.album?.kind ? [t('Empty album'), g.album.kind === 'comic' ? t('The pages and sheets of the comic adaptation come here as they are drawn.')
          : g.album.kind === 'vn' ? t('The sprites, backgrounds, CGs, covers and sheets of the visual novel come here as they are made.')
            : t('The covers, illustrations and sheets of the story come here as they are made.')]
        : g.album ? [t('Empty album'), t('Select items from the library and use “Add to album”. Importing with the album open also adds to it.')]
          : g.origin === 'hidden' ? [t('Nothing hidden'), t('Use “Hide” on an image, or on a selection of several items, to take it out of the library without deleting it. It stays only here, and the reference picker still shows it.')]
          : g.favorites ? [t('No favorites'), t('Use an image\'s star to keep it here.')]
            : g.origin === 'video' ? [t('No videos yet'), t('Import or drag in a video (MP4, MOV or WebM) to view it frame by frame and save frames as images.')]
              : g.origin === 'imported' ? [t('Nothing imported yet'), t('Import, drag in or paste (Ctrl+V) images from the computer.')]
                : g.origin === 'generated' ? [t('No images generated yet'), t('Write a prompt on the side to generate.')]
                  : [t('No images yet'), t('Write a prompt on the side, or import, drag in or paste (Ctrl+V) an image to edit. Videos can be imported too.')];
    $('#feed-empty').replaceChildren(icon(g.trash ? 'trash' : g.origin === 'hidden' ? 'eyeOff' : g.album ? 'album' : g.origin === 'video' ? 'video' : 'image'), h('strong', {}, content[0]), content[1] ? h('span', {}, content[1]) : '');
  }
  $('#gallery-count').textContent = !g.loaded ? '' : g.search ? plural(g.total, t('{n} result'), t('{n} results')) : g.trash ? (g.total ? t('{total} in the trash', { total: number(g.total) }) : '') : plural(g.total, t('{n} item'), t('{n} items'));
  renderTimeline();
  paintFeed();
}

// Drawing: only the rows near the screen are in the page. Tiles are kept and reused as they scroll in and out.
let painting = false;
const pendingThumbs = new Set();
function requestPaint() { if (!painting) { painting = true; requestAnimationFrame(() => { painting = false; paintFeed(); }); } }
function place(el, box) {
  const x = Math.round(box.x), y = Math.round(box.y), w = Math.round(box.x + box.w) - x, hh = Math.round(box.h);
  const key = `${x},${y},${w},${hh}`;
  if (el.box === key) return;
  el.box = key;
  el.style.transform = `translate(${x}px, ${y}px)`; el.style.width = `${w}px`; el.style.height = `${hh}px`;
}
function paintFeed() {
  const result = layout.result; if (!result || !state.unlocked) return;
  const top = -feedEl.getBoundingClientRect().top, margin = innerHeight * 0.75;
  const nodes = [], fast = timeline.dragging;
  for (let r = firstRowAt(result.rows, top - margin); r < result.rows.length && result.rows[r].top <= top + innerHeight + margin; r++) {
    const row = result.rows[r];
    for (let i = row.first; i <= row.last; i++) {
      const head = layout.heads.get(i);
      if (head) { const el = dayHead(head.group); place(el, { x: head.x, y: head.y, w: head.width, h: layout.metrics.header }); nodes.push(el); }
      const entry = layout.entries[i];
      const el = entry.el || itemTile(entry.item);
      place(el, result.tiles[i]);
      nodes.push(el);
    }
  }
  syncChildren(feedEl, nodes);
  const wanted = [];
  for (const el of nodes) {
    if (!el.item) continue;
    const img = el.thumb;
    if (img && !img.getAttribute('src')) { if (fast) pendingThumbs.add(el); else img.src = imageUrl(el.item.id, true); }
    if (!captions.has(el.item.id)) wanted.push(el.item.id);
    if (fresh.has(el.item.id)) { fresh.delete(el.item.id); el.classList.add('fresh'); setTimeout(() => el.classList.remove('fresh'), 3000); }
    // Least recently shown tiles go first when the cache is trimmed.
    tileCache.delete(el.key); tileCache.set(el.key, el);
  }
  if (wanted.length) queueCaptions(wanted);
  if (!fast && pendingThumbs.size) flushThumbs();
  trimTiles();
  paintTimeline();
}
function flushThumbs() {
  for (const el of pendingThumbs) if (el.isConnected && !el.thumb.getAttribute('src')) el.thumb.src = imageUrl(el.item.id, true);
  pendingThumbs.clear();
}
/** Keeps the tile cache bounded: tiles far from the screen are dropped (their thumbnails load again if they come back). */
function trimTiles() {
  if (tileCache.size <= 1500) return;
  for (const [key, el] of tileCache) {
    if (tileCache.size <= 1200) break;
    if (el.isConnected || el.classList.contains('job')) continue;
    el.thumb?.removeAttribute('src'); tileCache.delete(key);
  }
}
addEventListener('scroll', () => { requestPaint(); timelineScrolled(); }, { passive: true });
addEventListener('resize', () => { if (state.unlocked) { renderTimeline(); requestPaint(); } });
new ResizeObserver(() => { if (state.unlocked && layout.metrics && feedEl.clientWidth !== layout.metrics.width) renderFeed(); }).observe(feedEl);

function dayHead(label) {
  let el = dayHeads.get(label);
  if (!el) {
    const check = h('button', { type: 'button', class: 'day-check', 'aria-label': t('Select {label}', { label }), title: t('Select the day') }, icon('check'));
    check.onclick = () => selectGroup(label);
    el = h('div', { class: 'day-head' }, check, h('span', {}, label));
    dayHeads.set(label, el);
  }
  return el;
}

// Tiles
function itemTile(item) {
  const trash = state.gallery.trash, key = trash ? `trash:${item.id}` : item.id;
  let tile = tileCache.get(key);
  if (!tile) {
    const img = h('img', { alt: '', decoding: 'async', draggable: 'false' });
    img.onload = () => tile.classList.add('loaded');
    const open = h('button', { type: 'button', class: 'tile-open' }, img);
    const check = h('button', { type: 'button', class: 'tile-check', 'aria-label': t('Select'), 'aria-pressed': 'false' }, icon('check'));
    // No caption on hover: the picture alone, with its prompt kept for screen readers.
    tile = h('div', { class: 'tile', 'data-id': item.id }, open, h('span', { class: 'tile-badge' }), check);
    tile.key = key; tile.thumb = img;
    open.addEventListener('click', e => tileClick(tile, e));
    check.addEventListener('click', e => { e.stopPropagation(); toggleSelected(tile.item.id, { range: e.shiftKey }); });
    if (trash) {
      tile.classList.add('trashed');
      const restore = h('button', { type: 'button', class: 'btn small' }, icon('undo'), t('Restore'));
      const purge = h('button', { type: 'button', class: 'btn small' }, icon('trash'), t('Delete'));
      restore.onclick = () => restoreItems([tile.item.id]);
      purge.onclick = () => purgeItems([tile.item.id]);
      tile.append(h('div', { class: 'tile-actions' }, restore, purge));
    } else {
      const star = h('button', { type: 'button', class: 'tile-star' }, icon('star'));
      star.onclick = () => toggleFavorite(tile.item);
      tile.append(star);
      tile.addEventListener('pointerdown', e => dragStart(tile, e));
      tile.addEventListener('pointerenter', e => hoverVideo(tile, e));
      tile.addEventListener('pointerleave', () => stopHoverVideo(tile));
    }
    tileCache.set(key, tile);
  }
  if (tile.item !== item) { tile.item = item; updateTile(tile); }
  const selected = selection.ids.has(item.id);
  if (tile.classList.contains('selected') !== selected) { tile.classList.toggle('selected', selected); $('.tile-check', tile).setAttribute('aria-pressed', String(selected)); }
  return tile;
}
function updateTile(tile) {
  const item = tile.item, text = displayText(item);
  const name = snippet(text, 60) || (item.kind === 'video' ? t('video') : t('image'));
  $('.tile-open', tile).setAttribute('aria-label', t('Open {name}', { name }));
  $('.tile-check', tile).setAttribute('aria-label', t('Select {name}', { name }));
  const star = $('.tile-star', tile);
  if (star) { star.setAttribute('aria-pressed', String(item.favorite)); star.setAttribute('aria-label', item.favorite ? t('Remove from favorites') : t('Favorite')); }
  const badge = $('.tile-badge', tile);
  // Videos show their length; edited images, how many versions they have; a stack, how many items it holds.
  const stack = item.stacked && item.versions > 1;
  const badgeKey = `${item.kind === 'video' ? `video:${item.duration}` : ''}:${item.versions}:${stack}`;
  if (badge.dataset.versions !== badgeKey) {
    badge.dataset.versions = badgeKey;
    const part = (...children) => h('span', { class: 'badge-part' }, ...children);
    const parts = [], titles = [];
    if (stack) { parts.push(part(icon('stack'), String(item.versions))); titles.push(t('Stack of {versions} items', { versions: item.versions })); }
    if (item.kind === 'video') { parts.push(part(icon('play'), clock(item.duration))); titles.push(t('Video')); }
    else if (!stack && item.versions > 1) { parts.push(part(icon('branch'), String(item.versions))); titles.push(t('{versions} versions', { versions: item.versions })); }
    badge.replaceChildren(...parts);
    badge.title = titles.join(' · ');
  }
  tile.classList.toggle('video', item.kind === 'video');
}
function tileClick(tile, e) {
  if (drag.suppressClick) { drag.suppressClick = false; return; }
  const id = tile.item.id;
  if (selection.active || e.ctrlKey || e.metaKey || (e.shiftKey && selection.anchor)) { toggleSelected(id, { range: e.shiftKey }); return; }
  if (!state.gallery.trash) openViewer(id);
}

// Captions: titles and prompts of the tiles on screen, a hundred at a time.
const captionQueue = new Set();
function queueCaptions(ids) {
  ids.forEach(id => captionQueue.add(id));
  if (!timers.captions) timers.captions = setTimeout(loadCaptions, 120);
}
async function loadCaptions() {
  timers.captions = null;
  const ids = [...captionQueue].slice(0, 100);
  ids.forEach(id => captionQueue.delete(id));
  if (!ids.length || !state.unlocked) return;
  try {
    const { items } = await api('captions', undefined, { ids: ids.join(',') });
    for (const caption of items) {
      const data = { prompt: caption.prompt, label: caption.label, operation: caption.operation, model: caption.model };
      captions.set(caption.id, data);
      const item = itemById(caption.id);
      if (item) Object.assign(item, data);
      for (const key of [caption.id, `trash:${caption.id}`]) { const tile = tileCache.get(key); if (tile?.item) { Object.assign(tile.item, data); updateTile(tile); } }
    }
    // Ids that came back empty (gone meanwhile) are not asked again.
    for (const id of ids) if (!captions.has(id)) captions.set(id, {});
  } catch { /* captions are optional */ }
  if (captionQueue.size) timers.captions = setTimeout(loadCaptions, 60);
}

/** Plays a video tile muted, in a loop, while the pointer rests on it. */
function hoverVideo(tile, e) {
  if (e.pointerType !== 'mouse' || tile.item?.kind !== 'video' || selection.active || drag.active) return;
  clearTimeout(tile.hoverTimer);
  tile.hoverTimer = setTimeout(() => {
    if (!tile.matches(':hover') || $('video', tile)) return;
    const video = h('video', { class: 'hover-play', muted: true, loop: true, playsinline: true, preload: 'auto', disablepictureinpicture: true });
    video.muted = true; video.src = videoUrl(tile.item.id);
    video.addEventListener('playing', () => video.classList.add('playing'), { once: true });
    $('.tile-open', tile).append(video);
    video.play().catch(() => {});
  }, 350);
}
function stopHoverVideo(tile) {
  clearTimeout(tile.hoverTimer);
  const video = $('video', tile);
  if (video) { video.pause(); video.removeAttribute('src'); video.load(); video.remove(); }
}

function parentAspect(job) {
  const parent = job.parent_id && (itemById(job.parent_id) || viewer.lineage?.nodes.find(n => n.id === job.parent_id));
  return parent ? parent.width / parent.height : 1;
}
/** The shape a request's tile takes: the image size it asks for, or a video's aspect (its first frame's when it has one). */
function jobAspect(job) {
  if (job.kind === 'video') {
    // The shape of the video it comes from, else of its earliest pinned picture (as the worker takes it), else the one asked.
    const inputs = job.inputs || [], at = i => i.at === 'end' ? Infinity : Number(i.at || 0);
    const from = inputs.find(i => i.role === 'source') || inputs.filter(i => i.kind === 'image' && i.role === 'key').sort((a, b) => at(a) - at(b))[0];
    const shape = from?.id ? itemById(from.id) : null;
    return shape?.width && shape?.height ? shape.width / shape.height : aspectRatio(job.options?.aspect);
  }
  return aspectOf(job.options?.size, parentAspect(job));
}
function jobTiles(job) {
  // A finished request keeps its place until the gallery shows its images, so the feed never empties in between.
  if (job.status === 'completed' && (!job.image_ids?.length || job.image_ids.some(id => state.gallery.ids.has(id)))) return [];
  // A request that failed or could not start is one card, however many images it asked for.
  const count = (!ACTIVE.has(job.status) && job.status !== 'completed') || job.launchError ? 1 : Math.min(job.options?.n || 1, 10);
  const aspect = Math.min(3, Math.max(1 / 3, jobAspect(job)));
  const tiles = [];
  for (let k = 0; k < count; k++) {
    const key = `job:${job.id}:${k}`;
    let tile = tileCache.get(key);
    if (!tile) {
      tile = h('div', { class: 'tile job', 'data-job': job.id });
      // The whole card opens the request's details; the button under the card's text does the same from the keyboard.
      tile.opener = h('button', { type: 'button', class: 'tile-open job-open' });
      tile.addEventListener('click', e => { if (!e.target.closest('button:not(.job-open), a') && !selectingIn(tile)) openJobDetails(job.id); });
      tile.key = key; tileCache.set(key, tile);
    }
    tile.aspect = aspect;
    renderJobTile(tile, job, k);
    tiles.push(tile);
  }
  return tiles;
}

function renderJobTile(tile, job, index) {
  const state_ = job.launchError ? 'stuck' : job.status;
  const problem = ['failed', 'uncertain', 'stuck'].includes(state_);
  for (const name of ['failed', 'uncertain', 'stuck']) tile.classList.toggle(name, state_ === name);
  // Event handlers read the latest copy: polling replaces the job object.
  const latest = () => state.jobs.get(job.id) || job;
  const structure = JSON.stringify(problem ? [state_, job.error, job.launchError, job.kind] : ['active', index, job.parent_id, job.operation, job.label, job.prompt, job.kind]);
  if (tile.dataset.structure !== structure) {
    tile.dataset.structure = structure;
    tile.opener.setAttribute('aria-label', t('View request details: {request}', { request: snippet(job.label || job.prompt, 60) }));
    const body = h('div', { class: `job-body${problem ? ' problem' : ''}` });
    if (problem) {
      const title = state_ === 'failed' ? t('Failed') : state_ === 'uncertain' ? t('Interrupted') : t('Did not start');
      const message = state_ === 'stuck' ? t('The server refused to start the request: {launchError}', { launchError: job.launchError }) : (job.error || t('Could not confirm the result.'));
      const actions = state_ === 'stuck'
        ? [h('button', { type: 'button', class: 'btn secondary', onclick: () => launch(job.id) }, t('Try to start')), h('button', { type: 'button', class: 'btn ghost', onclick: () => cancelJob(latest()) }, t('Cancel'))]
        : [h('button', { type: 'button', class: 'btn secondary', onclick: () => retryJob(latest()).catch(e => toast(e.message, { tone: 'error' })) }, icon('redo'), t('Repeat')),
          h('button', { type: 'button', class: 'btn ghost optional', onclick: () => useJobPrompt(latest()) }, t('Use prompt'))];
      // The same × that cancels a request while it runs dismisses it once it has ended without a result.
      body.append(h('div', { class: 'job-status' }, icon('alert'), h('span', { class: 'phase' }, title), state_ === 'stuck' ? '' : dismissButton(job, 'small')), h('p', { class: 'job-error' }, message),
        h('p', { class: 'job-prompt', title: job.prompt }, snippet(job.label || job.prompt, 120)), h('div', { class: 'job-actions' }, actions));
      delete tile.dataset.preview;
      tile.replaceChildren(tile.opener, body);
    } else {
      const cancel = h('button', { type: 'button', class: 'icon-btn small', 'aria-label': t('Cancel request'), onclick: () => cancelJob(latest()) }, icon('close'));
      const status = h('div', { class: 'job-status' }, h('span', { class: 'spinner small' }), h('span', { class: 'phase' }),
        h('span', { class: 'job-time', 'data-elapsed': job.id }, clock(now() - job.created)), cancel);
      const kind = job.kind === 'video' ? h('span', { class: 'job-kind' }, job.parent_ids?.length ? thumbOf(job.parent_ids[0], 'parent') : icon('video'), t('Video'))
        : job.parent_id ? h('span', { class: 'job-kind' }, thumbOf(job.parent_id, 'parent'), OPERATION[job.operation] || t('Edit')) : '';
      const footer = h('div', { class: 'job-footer' }, index === 0 ? h('p', { class: 'job-signal', 'data-signal': job.id, hidden: true }) : '', kind, h('p', { class: 'job-prompt', title: job.prompt }, snippet(job.label || job.prompt, 160)));
      body.append(status, footer);
      tile.replaceChildren(...$$(':scope > img.preview', tile), tile.opener, body);
    }
    tick();
  }
  tile.classList.toggle('has-preview', !problem && !!(index === 0 && job.previews));
  if (problem) return;
  $('.job-status .spinner', tile).hidden = job.status === 'queued' || job.status === 'completed';
  setText($('.job-status .phase', tile), phaseText(job));
  const cancel = $('.job-status .icon-btn', tile);
  cancel.hidden = !ACTIVE.has(job.status) || job.status === 'cancelling';
  cancel.title = job.status === 'running' ? t('Cancel generation') : t('Remove from queue');
  if (index === 0 && job.previews) setPreview(tile, previewUrl(job.id, job.previews));
}

function thumbOf(id, scope) {
  const img = h('img', { alt: '', loading: 'lazy' }); img.src = imageUrl(id, true); img.dataset.scope = scope; return img;
}
/** True while the person is selecting text inside `el`: finishing a selection must not count as a click. */
function selectingIn(el) { const s = getSelection(); return !!s && !s.isCollapsed && el.contains(s.anchorNode); }

// ---------------------------------------------------------------------------
// Request details: prompt, options and progress of a request, open while it runs (with or without previews)
// ---------------------------------------------------------------------------
const jobDialog = $('#job-dialog');
const JOB_TITLE = { generate: t('New image'), edit: t('Edit'), expand: t('Expansion'), upscale: t('Upscale'), video: t('Video') };
const JOB_ENDED = { completed: t('Ready', { ctx: 'image' }), failed: t('Failed'), uncertain: t('Interrupted'), cancelled: t('Cancelled') };
/** `copy` opens the details of a request that may have left the screen already. */
function openJobDetails(id, copy = null) {
  const job = state.jobs.get(id) || copy; if (!job) return;
  jobView.job = job;
  renderJobDetails();
  if (!jobDialog.open) jobDialog.showModal();
}
/** What a video request asked for (gpu.js), then its seed, the GPU that made it and when it was asked. */
function videoRows(job) {
  const o = job.options || {}, made = job.made || {}, rows = gpuUi.rows(job);
  if (made.seed !== undefined || o.seed !== null && o.seed !== undefined) rows.push([t('Seed'), String(made.seed ?? o.seed)]);
  if (made.gpu) rows.push([t('GPU'), made.seconds ? t('{gpu}, in {time}', { gpu: made.gpu, time: clock(made.seconds) }) : made.gpu]);
  rows.push([t('Request'), dateTime(job.created)]);
  return rows;
}
/** How a picture was made on the GPU: its LoRAs, its seed and the GPU that made it, in how long. */
function gpuPictureRows(o, made = {}) {
  const rows = [];
  if (o.loras?.length) rows.push(['LoRAs', o.loras.map(l => `${l.name || t('LoRA no longer in the list')} (${number(Math.round(Number(l.strength ?? 1) * 100) / 100)})`).join(', ')]);
  const seed = made?.seed ?? o.seed;
  if (seed !== null && seed !== undefined) rows.push([t('Seed'), String(seed)]);
  if (made?.gpu) rows.push([t('GPU'), made.seconds ? t('{gpu}, in {time}', { gpu: made.gpu, time: clock(made.seconds) }) : made.gpu]);
  return rows;
}
/** Rebuilds a part only when what it shows changed, so text being selected or copied is never replaced. */
function rebuild(el, key, build) {
  const shape = JSON.stringify(key);
  if (el.dataset.shape === shape) return;
  el.dataset.shape = shape; el.replaceChildren(...build());
}
function renderJobDetails() {
  if (!jobView.job) return;
  const job = state.jobs.get(jobView.job.id) || jobView.job; jobView.job = job;
  const latest = () => state.jobs.get(job.id) || jobView.job;
  const op = job.kind === 'video' ? 'video' : JOB_TITLE[job.operation] ? job.operation : 'generate';
  const o = job.options || {};
  const active = ACTIVE.has(job.status) && !job.launchError;
  const problem = !!job.launchError || ['failed', 'uncertain'].includes(job.status);
  const done = job.status === 'completed' && job.image_ids?.length > 0;
  // Expansions and upscales send a fixed instruction: the label says what was asked, the instruction stays one click away.
  const fixed = op === 'expand' || op === 'upscale';

  setText($('#job-title'), JOB_TITLE[op]);
  $('#job-spinner').hidden = !active || job.status === 'queued';
  const phase = $('#job-phase');
  setText(phase, active ? phaseText(job) : job.launchError ? t('Did not start') : JOB_ENDED[job.status] || job.status);
  phase.className = active ? 'phase' : `status ${job.launchError ? 'uncertain' : job.status}`;
  const elapsed = $('#job-elapsed');
  if (active) { elapsed.dataset.elapsed = job.id; setText(elapsed, clock(now() - job.created)); }
  else { delete elapsed.dataset.elapsed; setText(elapsed, job.status === 'completed' ? `levou ${clock(job.updated - job.created)}` : ''); }
  const signal = $('#job-signal');
  if (active) signal.dataset.signal = job.id; else { delete signal.dataset.signal; signal.hidden = true; }
  const error = job.launchError ? t('The server refused to start the request: {launchError}', { launchError: job.launchError })
    : problem || job.status === 'cancelled' ? (job.error || t('Could not confirm the result.')) : '';
  $('#job-error').hidden = !error; setText($('#job-error'), error);

  const typed = typedText(job);
  setText($('#job-prompt-heading'), op === 'edit' ? (typed === null ? t('Requested change') : t('Full prompt')) : fixed ? JOB_TITLE[op] : 'Prompt');
  setText($('#job-prompt'), fixed ? job.label || JOB_TITLE[op] : job.prompt);
  $('#job-sent-wrap').hidden = !fixed; setText($('#job-sent'), fixed ? job.prompt : '');
  renderTyped('#job-typed-wrap', '#job-typed', typed);
  // A planned direction exists once the batch is planned; a fixed one is listed with the details instead.
  const direction = job.variation?.planned || job.variation?.written ? job.variation.label : '';
  $('#job-variation-wrap').hidden = !direction; setText($('#job-variation'), direction || '');

  // The picture: the result, else the latest preview, else the image being changed, dimmed.
  const visual = $('#job-visual');
  visual.style.setProperty('--ar', Math.min(3, Math.max(1 / 3, jobAspect(job))).toFixed(4));
  // A video shows, until it is made, the picture it starts on or the video it comes from.
  const videoStart = op === 'video' ? (job.inputs || []).find(i => i.id && (i.role === 'source' || i.kind === 'image' && i.role === 'key' && i.at === 0)) : null;
  const firstFrame = videoStart?.id || null;
  const src = done ? imageUrl(job.image_ids[0], true) : job.previews ? previewUrl(job.id, job.previews) : job.parent_id ? imageUrl(job.parent_id, true) : firstFrame ? imageUrl(firstFrame, true) : null;
  if (src) setPreview(visual, src);
  else if (visual.dataset.preview) { delete visual.dataset.preview; $$('img.preview', visual).forEach(i => i.remove()); }
  const source = !!src && !done && !job.previews;
  visual.classList.toggle('source', source);
  visual.classList.toggle('busy', active);
  $('.spinner', visual).hidden = !active || !!job.previews;
  const label = $('#job-visual-label');
  label.hidden = !source; setText(label, op === 'edit' ? t('Edited image') : op === 'video' ? (videoStart?.role === 'source' ? t('Original video') : t('First frame')) : t('Source image'));

  // Gallery images used as references (the image being changed is the picture above).
  const refs = fixed ? [] : (job.parent_ids || []).filter(id => id !== job.parent_id && id !== firstFrame);
  $('#job-refs').hidden = !refs.length;
  rebuild($('#job-refs'), refs, () => refs.map(id => thumbOf(id, 'job')));

  const model = state.models[o.model];
  const extra = Math.max(0, (job.reference_count || 0) - (job.parent_id ? 1 : 0));
  if (op === 'video') {
    rebuild($('#job-details'), [o, job.made, job.created], () => videoRows(job).flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]));
  } else {
  const rows = [[t('Model'), model?.label || o.model || ''], [t('Quality'), [...QUALITY, ...QUALITY_EXTENDED].find(([v]) => v === o.quality)?.[1] || o.quality || '']];
  if (model?.complexity && o.complexity) rows.push([t('Complexity'), COMPLEXITY.find(([v]) => v === o.complexity)?.[1] || o.complexity]);
  rows.push([t('Size'), o.size === 'auto' || !o.size ? t('Automatic', { ctx: 'size' }) : String(o.size).replace('x', ' × ')]);
  if (o.format) rows.push([t('Format'), String(o.format).toUpperCase()]);
  if (o.thinking && o.thinking !== 'off' && !fixed) rows.push([t('Prompt planning'), job.runner === 'gpu' ? t('MiniMax\'s expander') : THINKING.find(([v]) => v === o.thinking)?.[1] || o.thinking]);
  if (job.variation) rows.push([t('Variation'), t('{index} of {count}{label}', { index: job.variation.index, count: job.variation.count, label: !job.variation.planned && !job.variation.written && job.variation.label ? `: ${job.variation.label}` : '' })]);
  if (job.runner === 'gpu') rows.push(...gpuPictureRows(o, job.made));
  else rows.push([t('Previews'), o.previews ? String(o.previews) : t('Off', { ctx: 'previews' })]);
  if (extra && !fixed) rows.push([job.parent_id ? t('Extra references') : t('References'), String(extra)]);
  if (job.has_mask && op === 'edit') rows.push([t('Area'), t('Only the marked area')]);
  if (job.cleaned) rows.push([t('Before sending'), cleanedNote(job.cleaned)]);
  rows.push([t('Request'), dateTime(job.created)]);
  rebuild($('#job-details'), rows, () => rows.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]));
  }

  const actions = [];
  if (done) actions.push(['view', 'btn primary small', op === 'video' ? 'video' : 'image', op === 'video' ? t('View video') : t('View image'), () => { jobDialog.close(); openViewer(job.image_ids[0], { history: viewerDialog.open ? 'replace' : 'push' }); }]);
  if (!fixed && op === 'edit' && job.parent_id) actions.push(['edit', 'btn secondary small', 'pencil', t('Use in the edit'), () => useInEdit(latest())]);
  else if (!fixed) actions.push(['reuse', 'btn secondary small', 'compose', t('Use prompt'), () => { const current = latest(); jobDialog.close(); useJobPrompt(current); }]);
  if (job.launchError) actions.push(['launch', 'btn secondary small', 'redo', t('Try to start'), () => launch(job.id)]);
  if (problem && !job.launchError) {
    actions.push(['retry', 'btn secondary small', 'redo', t('Repeat'), async () => { try { await retryJob(latest()); jobDialog.close(); } catch (e) { toast(e.message, { tone: 'error' }); } }]);
    actions.push(['dismiss', 'btn ghost small', null, t('Dismiss'), async () => { const current = latest(); jobDialog.close(); await dismissJob(current); }]);
  }
  if (ACTIVE.has(job.status) && job.status !== 'cancelling') actions.push(['cancel', 'btn ghost small', 'close', job.status === 'running' ? t('Cancel generation') : t('Remove from queue'), () => cancelJob(latest())]);
  rebuild($('#job-actions'), actions.map(a => a[0] + a[3]), () => actions.map(([name, cls, glyph, text, run]) =>
    h('button', { type: 'button', class: cls, 'data-action': name, onclick: run }, glyph ? icon(glyph) : '', text)));
}
async function useInEdit(job) {
  jobDialog.close();
  if (viewer.id !== job.parent_id) await openViewer(job.parent_id, { history: viewerDialog.open ? 'replace' : 'push' });
  if (viewer.item?.id !== job.parent_id) return;
  if (viewer.action) setAction(null);
  const field = $('#edit-prompt');
  field.value = job.prompt; field.dispatchEvent(new Event('input', { bubbles: true })); field.focus();
  if (job.reference_count > 1) toast(t('Change copied. The extra references were not copied.'));
}
$('#job-typed-copy').onclick = () => copyTyped(jobView.job);
$('#job-copy').onclick = async () => {
  try { await navigator.clipboard.writeText(jobView.job?.prompt || ''); toast(t('Prompt copied.')); }
  catch { toast(t('Could not copy.'), { tone: 'error' }); }
};
jobDialog.addEventListener('close', () => {
  // Nothing of the request stays on the page after the dialog closes.
  jobView.job = null;
  const visual = $('#job-visual'); delete visual.dataset.preview; $$('img', visual).forEach(i => { i.removeAttribute('src'); i.remove(); });
  for (const s of ['#job-prompt', '#job-typed', '#job-sent', '#job-variation', '#job-error']) $(s).textContent = '';
  for (const s of ['#job-refs', '#job-details', '#job-actions']) { $(s).replaceChildren(); delete $(s).dataset.shape; }
});
async function toggleFavorite(item) {
  try {
    await api('favorite', { id: item.id }); item.favorite = !item.favorite;
    const listed = itemById(item.id);
    if (listed && listed !== item) listed.favorite = item.favorite;
    if (viewer.item?.id === item.id) { viewer.item.favorite = item.favorite; renderViewerHeader(); }
    if (state.gallery.favorites) await loadGallery();
    else { const tile = tileCache.get(item.id); if (tile?.item) updateTile(tile); }
  } catch (e) { toast(e.message, { tone: 'error' }); }
}

// ---------------------------------------------------------------------------
// Selection: the check on a tile (or Ctrl + click, or a long press) starts it; Shift + click selects a range.
// It works on the whole view, also on tiles far from the screen ("Selecionar tudo", a whole day).
// ---------------------------------------------------------------------------
const selection = { active: false, ids: new Set(), anchor: null };
function setSelecting(on) {
  selection.active = on;
  if (!on) { selection.ids.clear(); selection.anchor = null; }
  $('#feed-view').classList.toggle('selecting', on);
  renderSelection();
}
function toggleSelected(id, { range = false } = {}) {
  const g = state.gallery;
  if (!selection.active) setSelecting(true);
  if (range && selection.anchor && g.ids.has(selection.anchor) && g.ids.has(id)) {
    const [a, b] = [g.ids.get(selection.anchor), g.ids.get(id)].sort((x, y) => x - y);
    for (let i = a; i <= b; i++) selection.ids.add(g.items[i].id);
  } else if (selection.ids.has(id)) selection.ids.delete(id);
  else selection.ids.add(id);
  selection.anchor = id;
  renderSelection();
}
/** Selects every item of a day, or clears them when all are selected already. */
function selectGroup(label) {
  const ids = state.gallery.items.filter(item => dayText(item.day, item.created) === label).map(item => item.id);
  if (!selection.active) setSelecting(true);
  const all = ids.every(id => selection.ids.has(id));
  ids.forEach(id => all ? selection.ids.delete(id) : selection.ids.add(id));
  renderSelection();
}
function selectAll() {
  const g = state.gallery, all = g.items.length && g.items.every(item => selection.ids.has(item.id));
  if (all) selection.ids.clear(); else g.items.forEach(item => selection.ids.add(item.id));
  renderSelection();
}
/** Selected ids in the order they appear. */
const selectedIds = () => state.gallery.items.filter(item => selection.ids.has(item.id)).map(item => item.id);
function renderSelection() {
  const g = state.gallery, n = selection.ids.size;
  $('#selection-bar').hidden = !selection.active;
  setText($('#selection-count'), n ? plural(n, t('{n} item selected'), t('{n} items selected')) : t('Select items'));
  const all = g.items.length && n === g.items.length;
  setText($('#select-all'), all ? t('Clear selection') : t('Select all ({items})', { items: number(g.items.length) }));
  const show = (op, visible) => { $(`[data-bulk="${op}"]`).hidden = !visible; };
  show('favorite', !g.trash); show('album', !g.trash); show('album-remove', !g.trash && !!g.album);
  // Ocultar takes items out of the library; in the Ocultas filter the same button brings them back.
  const hiddenView = g.origin === 'hidden', hide = $('[data-bulk="hide"]');
  show('hide', !g.trash);
  if (hide.dataset.mode !== String(hiddenView)) {
    hide.dataset.mode = String(hiddenView);
    hide.replaceChildren(icon(hiddenView ? 'eye' : 'eyeOff'), h('span', {}, hiddenView ? t('Show') : t('Hide')));
    hide.title = hiddenView ? t('Back to the library') : t('Take out of the library without deleting. They stay in the Hidden filter.');
  }
  show('stack', !g.trash); show('unstack', !g.trash && [...selection.ids].some(id => itemById(id)?.stacked));
  show('top', !g.trash && g.sort === 'custom'); show('bottom', !g.trash && g.sort === 'custom');
  show('trash', !g.trash); show('restore', g.trash); show('purge', g.trash);
  const favorites = n && [...selection.ids].every(id => itemById(id)?.favorite);
  setText($('[data-bulk="favorite"] span:last-child'), favorites ? t('Unfavorite') : t('Favorite'));
  $$('[data-bulk]').forEach(b => { b.disabled = !n; });
  $('[data-bulk="stack"]').disabled = n < 2;
  for (const el of feedEl.children) {
    if (!el.item) continue;
    const selected = selection.ids.has(el.item.id);
    if (el.classList.contains('selected') !== selected) { el.classList.toggle('selected', selected); $('.tile-check', el).setAttribute('aria-pressed', String(selected)); }
  }
}
$('#select-mode').onclick = () => setSelecting(!selection.active);
$('#selection-close').onclick = () => setSelecting(false);
$('#select-all').onclick = selectAll;
$$('[data-bulk]').forEach(button => button.addEventListener('click', () => bulkAction(button.dataset.bulk).catch(e => toast(e.message, { tone: 'error' }))));

async function bulkAction(op) {
  const ids = selectedIds(), g = state.gallery;
  if (!ids.length) return;
  if (op === 'album') { openAlbumDialog(ids); return; }
  if (op === 'purge') { await purgeItems(ids); return; }
  if (op === 'restore') { await restoreItems(ids); return; }
  if (op === 'favorite') {
    const off = ids.every(id => itemById(id)?.favorite);
    await api('bulk', { op: off ? 'unfavorite' : 'favorite', ids });
    ids.forEach(id => { const item = itemById(id); if (item) item.favorite = !off; const tile = tileCache.get(id); if (tile?.item) { tile.item.favorite = !off; updateTile(tile); } });
    toast(off ? plural(ids.length, t('{n} item removed from favorites.'), t('{n} items removed from favorites.')) : plural(ids.length, t('{n} item added to favorites.'), t('{n} items added to favorites.')));
    if (g.favorites) await loadGallery(); else renderSelection();
    return;
  }
  if (op === 'trash') {
    await api('bulk', { op: 'trash', ids });
    setSelecting(false); await loadGallery();
    toast(plural(ids.length, t('{n} item moved to the trash.'), t('{n} items moved to the trash.')), { action: { label: t('Undo'), run: async () => { await api('bulk', { op: 'restore', ids }); await loadGallery(); } } });
    return;
  }
  if (op === 'hide') {
    // Grouped, a tile stands for an image with its versions (or a stack): all of it goes, or comes back, together.
    const show = g.origin === 'hidden', whole = grouped(), n = ids.length;
    await api('hide', { ids, hidden: !show, whole });
    setSelecting(false); await loadGallery();
    toast(show ? plural(n, t('{n} item back in the library.'), t('{n} items back in the library.')) : plural(n, t('{n} item hidden. It stays in the Hidden filter (the crossed-out eye).'), t('{n} items hidden. They stay in the Hidden filter (the crossed-out eye).')),
      { action: { label: t('Undo'), run: async () => { await api('hide', { ids, hidden: show, whole }); await loadGallery(); } } });
    return;
  }
  if (op === 'stack') {
    // Undoing takes out what joined: the whole stack when it is new, or what was added to the one stack chosen.
    const before = ids.filter(id => itemById(id)?.stacked), added = ids.filter(id => !before.includes(id));
    const { count } = await api('stack', { ids });
    setSelecting(false); await loadGallery();
    const undo = !before.length ? () => api('unstack', { ids, whole: true }) : before.length === 1 ? () => api('unstack', { ids: added, whole: false }) : null;
    toast(plural(count, t('Stack with {n} item.'), t('Stack with {n} items.')) + (grouped() ? '' : ' ' + t('With versions grouped, the library shows it as a single card.')),
      undo ? { action: { label: t('Undo'), run: async () => { await undo(); await loadGallery(); } } } : {});
    return;
  }
  if (op === 'unstack') {
    const stacked = ids.filter(id => itemById(id)?.stacked);
    if (!stacked.length) return;
    await api('unstack', { ids: stacked, whole: true });
    setSelecting(false); await loadGallery();
    toast(stacked.length === 1 ? t('Stack undone. Each item has its own card again.') : t('Stacks undone. Each item has its own card again.'));
    return;
  }
  if (op === 'album-remove') {
    const album = g.album;
    await api('bulk', { op: 'album-remove', ids, album: album.id });
    setSelecting(false); await loadGallery(); refreshAlbums();
    toast(plural(ids.length, t('{n} item removed from {album}.', { album: album.name }), t('{n} items removed from {album}.', { album: album.name })), { action: { label: t('Undo'), run: async () => { await api('bulk', { op: 'album-add', ids, album: album.id }); await loadGallery(); refreshAlbums(); } } });
    return;
  }
  if (op === 'top' || op === 'bottom') {
    const chosen = new Set(ids);
    const before = op === 'top' ? g.items.find(item => !chosen.has(item.id))?.id ?? null : null;
    await moveItems(ids, before);
    setSelecting(false);
  }
}
async function restoreItems(ids) {
  await api('bulk', { op: 'restore', ids });
  if (selection.active) setSelecting(false);
  await loadGallery();
  toast(plural(ids.length, t('{n} item restored.'), t('{n} items restored.')));
}
async function purgeItems(ids) {
  const one = ids.length === 1;
  if (!await confirmAction({ title: one ? t('Delete permanently?') : t('Delete {ids} items permanently?', { ids: number(ids.length) }),
    message: t('{deleted} from the server. This cannot be undone. Versions edited from them stay in the gallery.', { deleted: one ? t('The item and its encrypted files are deleted') : t('The items and their encrypted files are deleted') }),
    confirm: t('Delete'), danger: true })) return;
  try {
    const { count } = await api('bulk', { op: 'purge', ids });
    ids.forEach(id => tileCache.delete(`trash:${id}`));
    if (selection.active) setSelecting(false);
    await loadGallery();
    toast(plural(count, t('{n} item deleted.'), t('{n} items deleted.')));
  } catch (e) { toast(e.message, { tone: 'error' }); }
}
/** Moves items in the custom order to right before `before` (null: the end), and shows it at once. */
async function moveItems(ids, before) {
  const g = state.gallery, moving = new Set(ids);
  const items = g.items.filter(item => !moving.has(item.id)), moved = ids.map(itemById).filter(Boolean);
  const at = before === null ? items.length : items.findIndex(item => item.id === before);
  items.splice(at < 0 ? items.length : at, 0, ...moved);
  g.items = items; g.ids = new Map(items.map((item, i) => [item.id, i]));
  renderFeed();
  try { await api('reorder', { ids, before }); }
  catch (e) { toast(e.message, { tone: 'error' }); }
  await loadGallery();
}

// Long press on a touch screen starts the selection.
feedEl.addEventListener('pointerdown', e => {
  if (e.pointerType === 'mouse') return;
  const tile = e.target.closest('.tile[data-id]'); if (!tile?.item) return;
  const start = { x: e.clientX, y: e.clientY };
  clearTimeout(timers.longPress);
  timers.longPress = setTimeout(() => { drag.suppressClick = true; toggleSelected(tile.item.id); navigator.vibrate?.(15); }, 450);
  const cancel = ev => { if (ev.type !== 'pointermove' || Math.hypot(ev.clientX - start.x, ev.clientY - start.y) > 10) { clearTimeout(timers.longPress); off(); } };
  const off = () => ['pointermove', 'pointerup', 'pointercancel'].forEach(type => removeEventListener(type, cancel));
  ['pointermove', 'pointerup', 'pointercancel'].forEach(type => addEventListener(type, cancel, { passive: true }));
});
feedEl.addEventListener('contextmenu', e => { if (e.target.closest('.tile[data-id]') && (selection.active || timers.longPress)) e.preventDefault(); });

// ---------------------------------------------------------------------------
// Custom order: in "Ordem personalizada", tiles are dragged into place with the mouse; selected tiles move together.
// ---------------------------------------------------------------------------
const drag = { active: false, pending: null, ids: [], ghost: null, marker: null, before: undefined, suppressClick: false, frame: 0, pointer: null };
function dragStart(tile, e) {
  if (e.button !== 0 || e.pointerType !== 'mouse' || state.gallery.sort !== 'custom' || state.gallery.trash || e.target.closest('.tile-star, .tile-check')) return;
  drag.pending = { tile, x: e.clientX, y: e.clientY, id: e.pointerId };
  addEventListener('pointermove', dragMove);
  addEventListener('pointerup', dragEnd);
  addEventListener('pointercancel', dragCancel);
}
function dragMove(e) {
  drag.pointer = { x: e.clientX, y: e.clientY };
  if (!drag.active) {
    const p = drag.pending; if (!p || Math.hypot(e.clientX - p.x, e.clientY - p.y) < 6) return;
    const id = p.tile.item.id;
    drag.ids = selection.ids.has(id) ? selectedIds() : [id];
    drag.active = true; stopHoverVideo(p.tile);
    document.body.classList.add('reordering');
    drag.ghost = h('div', { class: 'drag-ghost' }, h('img', { alt: '', src: imageUrl(id, true) }), drag.ids.length > 1 ? h('span', { class: 'drag-count' }, String(drag.ids.length)) : '');
    drag.marker = h('div', { class: 'drop-marker' });
    document.body.append(drag.ghost); feedEl.append(drag.marker);
    for (const el of feedEl.children) if (el.item && drag.ids.includes(el.item.id)) el.classList.add('dragging');
    autoScroll();
  }
  drag.ghost.style.transform = `translate(${e.clientX}px, ${e.clientY}px)`;
  dropTarget(e.clientX, e.clientY);
}
/** Where the dragged tiles would land: before the tile under the pointer's left half, or after it. */
function dropTarget(clientX, clientY) {
  const result = layout.result, rect = feedEl.getBoundingClientRect();
  const x = clientX - rect.left, y = clientY - rect.top, rows = result.rows;
  let r = firstRowAt(rows, y - layout.metrics.gap);
  if (r >= rows.length) r = rows.length - 1;
  const row = rows[r]; if (!row) return;
  let target = null; // tile index the block goes before; null: after the row's last tile
  for (let i = row.first; i <= row.last; i++) {
    if (!layout.entries[i].item) continue;
    const box = result.tiles[i];
    if (x < box.x + box.w / 2) { target = i; break; }
  }
  const moving = new Set(drag.ids);
  // The anchor is the first item from the target on that isn't being moved.
  let index = target ?? row.last + 1;
  while (index < layout.entries.length && (!layout.entries[index].item || moving.has(layout.entries[index].item.id))) index++;
  drag.before = index < layout.entries.length ? layout.entries[index].item.id : null;
  const box = target !== null ? result.tiles[target] : result.tiles[row.last];
  const markerX = target !== null ? box.x - layout.metrics.gap / 2 : box.x + box.w + layout.metrics.gap / 2;
  drag.marker.style.transform = `translate(${Math.round(markerX - 1.5)}px, ${Math.round(box.y)}px)`;
  drag.marker.style.height = `${Math.round(box.h)}px`;
}
function autoScroll() {
  cancelAnimationFrame(drag.frame);
  if (!drag.active) return;
  const p = drag.pointer, edge = 70;
  if (p) {
    const top = stickyBottom();
    const speed = p.y < top + edge ? -(top + edge - p.y) : p.y > innerHeight - edge ? p.y - (innerHeight - edge) : 0;
    if (speed) { window.scrollBy(0, speed * 0.4); dropTarget(p.x, p.y); }
  }
  drag.frame = requestAnimationFrame(autoScroll);
}
function dragCleanup() {
  removeEventListener('pointermove', dragMove); removeEventListener('pointerup', dragEnd); removeEventListener('pointercancel', dragCancel);
  cancelAnimationFrame(drag.frame);
  drag.ghost?.remove(); drag.marker?.remove();
  for (const el of feedEl.querySelectorAll('.dragging')) el.classList.remove('dragging');
  document.body.classList.remove('reordering');
  Object.assign(drag, { active: false, pending: null, ghost: null, marker: null, pointer: null });
}
function dragEnd() {
  const was = drag.active, ids = drag.ids, before = drag.before;
  dragCleanup();
  if (!was) return;
  drag.suppressClick = true; setTimeout(() => { drag.suppressClick = false; }, 0);
  if (before !== undefined) moveItems(ids, before).catch(e => toast(e.message, { tone: 'error' }));
}
function dragCancel() { dragCleanup(); }

// ---------------------------------------------------------------------------
// Timeline: a rail on the right that maps the whole view. Dragging it scrolls anywhere at once; years and months mark
// where they start (in a date order), and the bubble says what is at that point in the others.
// ---------------------------------------------------------------------------
const timeline = { el: $('#timeline'), dragging: false, shown: false };
const clampNumber = (v, min, max) => Math.min(max, Math.max(min, v));
function scrollRange() {
  const sticky = stickyBottom(), feedTop = feedEl.getBoundingClientRect().top + scrollY;
  const min = Math.max(0, feedTop - sticky - 8), max = Math.max(min, document.documentElement.scrollHeight - innerHeight);
  return { min, max, feedTop, sticky };
}
const contentScroll = (y, r) => clampNumber(r.feedTop + y - r.sticky - 8, r.min, r.max);
const MONTH_LABELS = new Map();
function monthText(created) {
  const d = new Date(created * 1000), key = d.getFullYear() * 12 + d.getMonth();
  if (!MONTH_LABELS.has(key)) MONTH_LABELS.set(key, MONTH_FORMAT.format(d));
  return MONTH_LABELS.get(key);
}
function shapeText(item) {
  const r = item.width / item.height;
  return Math.abs(r - 1) < 0.02 ? t('Square') : r > 1 ? t('Landscape {ratio}:1', { ratio: number(r, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }) : t('Portrait 1:{ratio}', { ratio: number(1 / r, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) });
}
/** What the view shows at a content position, in the terms of its order. */
function labelAt(y) {
  const result = layout.result; if (!result?.rows.length) return '';
  const row = result.rows[Math.min(firstRowAt(result.rows, y), result.rows.length - 1)];
  for (let i = row.first; i <= row.last; i++) {
    const item = layout.entries[i].item; if (!item) continue;
    switch (state.gallery.sort) {
      case 'largest': case 'smallest': return `${number(item.width)} × ${number(item.height)}`;
      case 'wide': case 'tall': return shapeText(item);
      case 'versions': return plural(item.versions, t('{n} version'), t('{n} versions'));
      case 'custom': return t('{position} of {total}', { position: number(i - layout.special + 1), total: number(state.gallery.items.length) });
      default: return monthText(item.created);
    }
  }
  return t('In progress');
}
function renderTimeline() {
  const el = timeline.el, result = layout.result;
  const show = !!result && state.unlocked && state.gallery.items.length > 12 && result.height > innerHeight * 1.8;
  timeline.shown = show; el.hidden = !show;
  if (!show) return;
  el.style.top = `${Math.round(stickyBottom() + 6)}px`;
  const height = el.clientHeight, r = scrollRange(), span = r.max - r.min || 1;
  const marks = railMarks(layout.marks, y => (contentScroll(y, r) - r.min) / span * height, { labelGap: 20, dotGap: 6 });
  $('.timeline-marks', el).replaceChildren(...marks.map(mark => {
    const node = mark.label ? h('span', { class: 'timeline-year' }, String(mark.year)) : h('span', { class: 'timeline-dot' });
    node.style.top = `${mark.position}px`; return node;
  }));
  el.setAttribute('aria-valuetext', '');
  paintTimeline();
}
function paintTimeline() {
  if (!timeline.shown) return;
  const el = timeline.el, r = scrollRange(), f = r.max > r.min ? clampNumber((scrollY - r.min) / (r.max - r.min), 0, 1) : 0;
  const height = el.clientHeight;
  $('.timeline-thumb', el).style.transform = `translateY(${Math.round(clampNumber(f * height, 9, height - 9))}px)`;
  const label = labelAt(scrollY - r.feedTop + r.sticky + 8);
  setText($('.timeline-thumb .timeline-bubble', el), label);
  el.setAttribute('aria-valuenow', String(Math.round(f * 100))); el.setAttribute('aria-valuetext', label);
}
function timelineScrolled() {
  if (!timeline.shown) return;
  timeline.el.classList.add('scrolling');
  clearTimeout(timers.timelineIdle);
  timers.timelineIdle = setTimeout(() => timeline.el.classList.remove('scrolling'), 900);
}
function scrubTo(clientY) {
  const rect = timeline.el.getBoundingClientRect(), r = scrollRange();
  const f = clampNumber((clientY - rect.top) / rect.height, 0, 1);
  window.scrollTo(0, r.min + f * (r.max - r.min));
}
function hoverRail(clientY) {
  const el = timeline.el, rect = el.getBoundingClientRect(), r = scrollRange();
  const y = clampNumber(clientY - rect.top, 0, rect.height), f = y / rect.height;
  const content = r.min + f * (r.max - r.min) - r.feedTop + r.sticky + 8;
  const hover = $('.timeline-hover', el);
  hover.style.transform = `translateY(${Math.round(y)}px)`;
  setText($('.timeline-bubble', hover), labelAt(content));
}
timeline.el.addEventListener('pointerdown', e => {
  if (e.button !== 0) return;
  e.preventDefault();
  timeline.el.setPointerCapture(e.pointerId);
  timeline.dragging = true; timeline.el.classList.add('dragging');
  scrubTo(e.clientY); hoverRail(e.clientY);
});
timeline.el.addEventListener('pointermove', e => { hoverRail(e.clientY); if (timeline.dragging) scrubTo(e.clientY); });
const endScrub = () => {
  if (!timeline.dragging) return;
  timeline.dragging = false; timeline.el.classList.remove('dragging');
  requestPaint();
};
timeline.el.addEventListener('pointerup', endScrub);
timeline.el.addEventListener('pointercancel', endScrub);
timeline.el.addEventListener('lostpointercapture', endScrub);
timeline.el.addEventListener('keydown', e => {
  const r = scrollRange(), page = innerHeight * 0.9, step = (r.max - r.min) / 20;
  const to = { ArrowUp: scrollY - page, ArrowDown: scrollY + page, PageUp: scrollY - step, PageDown: scrollY + step, Home: r.min, End: r.max }[e.key];
  if (to === undefined) return;
  e.preventDefault(); window.scrollTo(0, clampNumber(to, r.min, r.max));
});

// ---------------------------------------------------------------------------
// Toolbar: where (library, an album, the trash), what (origin, favorites, search) and in which order
// ---------------------------------------------------------------------------
function resetFeedView() {
  stopAllHoverVideos();
  feedEl.replaceChildren();
  window.scrollTo(0, Math.min(scrollY, scrollRange().min));
  const g = state.gallery;
  g.items = []; g.ids = new Map(); g.loaded = false;
  if (selection.active) setSelecting(false);
  renderToolbar(); renderFeed();
  return loadGallery().catch(e => toast(e.message, { tone: 'error' }));
}
function stopAllHoverVideos() { for (const el of feedEl.querySelectorAll('.tile.video')) stopHoverVideo(el); }
function renderToolbar() {
  const g = state.gallery;
  $$('[data-origin]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.origin === g.origin)));
  $('#origin-filter').hidden = g.trash;
  $('#favorites-only').setAttribute('aria-pressed', String(g.favorites));
  $('#favorites-only').hidden = g.trash;
  $('#scope-button').replaceChildren(icon(g.trash ? 'trash' : g.album ? ALBUM_ICON[g.album.kind] || 'album' : 'gallery'), h('span', { id: 'scope-label' }, g.trash ? t('Trash') : g.album ? g.album.name : t('Library')), icon('chevronDown'));
  $('#scope-button').classList.toggle('active', g.trash || !!g.album);
  setText($('#sort-label'), SORT_LABEL[g.sort]);
  $('#view-button').title = grouped() ? t('Order: {sort}, versions and stacks grouped', { sort: SORT_LABEL[g.sort] }) : t('Order: {sort}', { sort: SORT_LABEL[g.sort] });
}
$$('[data-origin]').forEach(button => button.onclick = () => { if (state.gallery.origin === button.dataset.origin) return; state.gallery.origin = button.dataset.origin; resetFeedView(); });
$('#favorites-only').onclick = () => { state.gallery.favorites = !state.gallery.favorites; resetFeedView(); };
$('#search').addEventListener('input', () => { clearTimeout(timers.search); timers.search = setTimeout(() => { state.gallery.search = $('#search').value.trim(); loadGallery().catch(e => toast(e.message)); }, 250); });

// Menus (popovers): the scope menu lists albums; the view menu holds the order and the grouping of versions.
function openMenu(menu, button, items) {
  if (menu.matches(':popover-open')) { menu.hidePopover(); return; }
  menu.replaceChildren(...items);
  menu.showPopover();
  const r = button.getBoundingClientRect();
  const width = menu.offsetWidth, height = menu.offsetHeight;
  menu.style.left = `${Math.max(8, Math.min(r.left, innerWidth - width - 8))}px`;
  menu.style.top = `${r.bottom + 6 + height > innerHeight - 8 ? Math.max(8, r.top - height - 6) : r.bottom + 6}px`;
  button.setAttribute('aria-expanded', 'true');
  (menu.querySelector('[aria-checked="true"]') || menu.querySelector('button'))?.focus();
}
for (const [menu, button] of [[$('#scope-menu'), $('#scope-button')], [$('#view-menu'), $('#view-button')]]) {
  menu.addEventListener('toggle', e => { if (e.newState === 'closed') button.setAttribute('aria-expanded', 'false'); });
  menu.addEventListener('keydown', e => {
    const items = $$('button:not([disabled])', menu), at = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); items[(at + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length]?.focus(); }
  });
}
/** note: [icon, what it means], a small mark before the count (an album kept out of the library). */
function menuItem(label, { checked, onclick, iconName, thumb, thumbIcon = 'album', count, danger, note, role = 'menuitem' } = {}) {
  const art = thumb !== undefined ? (thumb ? h('img', { class: 'menu-thumb', alt: '', src: imageUrl(thumb, true) }) : h('span', { class: 'menu-thumb blank' }, icon(thumbIcon)))
    : iconName ? icon(iconName) : h('span', { class: 'menu-check' }, icon('check'));
  const button = h('button', { type: 'button', class: `menu-item${danger ? ' danger' : ''}`, role: checked === undefined || role !== 'menuitem' ? role : 'menuitemradio', 'aria-checked': checked === undefined ? null : String(checked) },
    art, h('span', { class: 'menu-label' }, label), note ? h('span', { class: 'menu-note', title: note[1], 'aria-label': note[1] }, icon(note[0])) : '',
    count !== undefined ? h('span', { class: 'menu-count' }, number(count)) : '');
  button.onclick = () => { button.closest('[popover]').hidePopover(); onclick(); };
  return button;
}
const menuTitle = text => h('div', { class: 'menu-title', role: 'presentation' }, text);
const menuSeparator = () => h('div', { class: 'menu-sep', role: 'separator' });
function openScope(view) {
  const g = state.gallery;
  Object.assign(g, { trash: false, album: null, ...view });
  if (g.trash) { g.origin = 'all'; g.favorites = false; }
  resetFeedView();
}
$('#scope-button').onclick = async () => {
  const g = state.gallery, button = $('#scope-button'), menu = $('#scope-menu');
  if (menu.matches(':popover-open')) { menu.hidePopover(); return; }
  if (!state.albums) await refreshAlbums().catch(() => {});
  const albums = state.albums || [];
  // The person's own albums, then the albums the stories, comics and visual novels made (each with all its pictures,
  // which stay out of the library). A mark tells an album that does otherwise than its kind usually does.
  const item = album => menuItem(album.name, { checked: g.album?.id === album.id, thumb: album.cover, thumbIcon: ALBUM_ICON[album.kind] || 'album', count: album.count,
    note: !album.kind && !album.library ? ['eyeOff', t('Its items don\'t show in the library')] : album.kind && album.library ? ['eye', t('Its items show in the library too')] : null,
    onclick: () => openScope({ album }) });
  const made = ALBUM_KINDS.flatMap(([kind, title]) => { const list = albums.filter(a => a.kind === kind); return list.length ? [menuSeparator(), menuTitle(title), ...list.map(item)] : []; });
  openMenu(menu, button, [
    menuItem(t('Library'), { checked: !g.trash && !g.album, onclick: () => openScope({}) }),
    menuSeparator(), menuTitle(t('Albums')),
    ...albums.filter(a => !a.kind).map(item),
    menuItem(t('New album…'), { iconName: 'plus', onclick: () => createAlbum() }),
    ...made,
    ...(g.album ? [menuSeparator(),
      menuItem(t('Show in the library'), { role: 'menuitemcheckbox', checked: g.album.library, onclick: () => setAlbumLibrary(g.album, !g.album.library) }),
      ...(g.album.story ? [menuItem(g.album.kind === 'vn' ? t('Open the visual novel') : t('Open the story'), { iconName: ALBUM_ICON[g.album.kind], onclick: () => openStoryFromHistory(g.album.story) })] : []),
      menuItem(t('Rename album…'), { iconName: 'pencil', onclick: () => renameAlbum(g.album) }),
      menuItem(t('Delete album…'), { iconName: 'trash', danger: true, onclick: () => deleteAlbum(g.album) })] : []),
    menuSeparator(),
    menuItem(t('Trash'), { checked: g.trash, onclick: () => openScope({ trash: true }) }),
  ]);
};
$('#view-button').onclick = () => {
  const g = state.gallery;
  openMenu($('#view-menu'), $('#view-button'), [
    menuTitle(t('Sort by')),
    ...SORTS.map(([id, label]) => menuItem(label, { checked: g.sort === id, onclick: () => { if (g.sort === id) return; g.sort = id; savePrefs({ sort: id }); resetFeedView(); } })),
    // Favorites and albums show every version picked, so grouping doesn't apply there.
    ...(groupable() ? [menuSeparator(),
      menuItem(t('Group versions and stacks'), { role: 'menuitemcheckbox', checked: g.group, onclick: () => { g.group = !g.group; savePrefs({ group: g.group }); resetFeedView(); } })] : []),
  ]);
};

// ---------------------------------------------------------------------------
// Albums
// ---------------------------------------------------------------------------
/** The albums the stories made, by kind, in the order the menus list them, and the icon of each kind. */
const ALBUM_KINDS = [['story', t('Stories')], ['comic', t('Comics and manga')], ['vn', t('Visual novels')]];
const ALBUM_ICON = { story: 'book', comic: 'comic', vn: 'dialogue' };
/** Whether an album's items show in the library too, or only in the album. */
async function setAlbumLibrary(album, library) {
  try {
    await api('album-library', { id: album.id, library });
    await refreshAlbums();
    toast(library ? t('The items of {album} show in the library too.', { album: album.name }) : t('The items of {album} now show only in the album, not in the library.', { album: album.name }));
  } catch (e) { toast(e.message, { tone: 'error' }); }
}
/** An album opened from a story, its comic or a visual novel: back to the images, with the album open. */
async function showAlbum(id) {
  const albums = await refreshAlbums().catch(() => state.albums || []);
  const album = albums.find(a => a.id === id);
  if (!album) { toast(t('The album is no longer there. The next picture made for the story makes it again.'), { tone: 'error' }); return; }
  // As the Images button does: the entries the stories made are walked back.
  const depth = history.state?.atelier ? Number(history.state.depth) || 0 : 0;
  if (WRITING[state.section] && depth > 0 && writingHash(location.hash)) history.go(-depth); else if (state.section !== 'images') setSection('images');
  openScope({ album });
}
async function refreshAlbums() {
  const { albums } = await api('albums');
  state.albums = albums;
  const current = state.gallery.album && albums.find(a => a.id === state.gallery.album.id);
  if (current) { state.gallery.album = current; renderToolbar(); }
  return albums;
}
/** Asks for a name in a small dialog. Resolves to the trimmed name, or null when cancelled. */
/** A name asked in a small window: what it is for (message), the field's label and how long it can be. */
function askName({ title, value = '', confirm = t('Save'), label = t('Name'), message = '', maxLength = 80 }) {
  const dialog = $('#name-dialog');
  $('#name-title').textContent = title; $('#name-ok').textContent = confirm;
  $('#name-message').textContent = message; $('#name-message').hidden = !message; $('#name-label').textContent = label;
  $('#name-input').maxLength = maxLength; $('#name-input').value = value; setError('#name-error');
  return new Promise(resolve => {
    let done = false;
    const finish = result => { if (done) return; done = true; resolve(result); };
    $('#name-form').onsubmit = e => { e.preventDefault(); const name = $('#name-input').value.trim(); if (!name) return; finish(name); dialog.close(); };
    $('#name-cancel').onclick = () => { dialog.close(); };
    dialog.onclose = () => finish(null);
    dialog.showModal(); $('#name-input').select();
  });
}
async function createAlbum(ids = []) {
  const name = await askName({ title: t('New album'), confirm: t('Create') });
  if (!name) return null;
  try {
    const { album } = await api('album-create', { name, ids });
    await refreshAlbums();
    if (!ids.length) openScope({ album });
    return album;
  } catch (e) { toast(e.message, { tone: 'error' }); return null; }
}
async function renameAlbum(album) {
  const name = await askName({ title: t('Rename album'), value: album.name });
  if (!name || name === album.name) return;
  try { await api('album-rename', { id: album.id, name }); await refreshAlbums(); renderToolbar(); }
  catch (e) { toast(e.message, { tone: 'error' }); }
}
async function deleteAlbum(album) {
  const message = album.library ? t('Only the album is deleted. Its images and videos stay in the library.')
    : album.kind ? t('Only the album is deleted. Its images and videos go back to the library. The next picture made for the story makes a new album, with only what comes next.')
      : t('Only the album is deleted. Its images and videos go back to the library.');
  if (!await confirmAction({ title: t('Delete the album {name}?', { name: album.name }), message, confirm: t('Delete album'), danger: true })) return;
  try { await api('album-delete', { id: album.id }); await refreshAlbums(); openScope({}); toast(t('Album deleted.')); }
  catch (e) { toast(e.message, { tone: 'error' }); }
}
/** The list of albums to add items to, with a field to create a new one. */
async function openAlbumDialog(ids) {
  const dialog = $('#album-dialog');
  $('#album-summary').textContent = ids.length === 1 ? t('Choose the album.') : t('Choose the album for {ids} items.', { ids: number(ids.length) });
  $('#album-name').value = '';
  const list = $('#album-list');
  list.replaceChildren(h('span', { class: 'spinner' }));
  dialog.showModal();
  const add = async album => {
    try {
      const { count } = await api('bulk', { op: 'album-add', ids, album: album.id });
      dialog.close();
      if (selection.active) setSelecting(false);
      await refreshAlbums();
      // Into an album that keeps its items out of the library, they leave the library.
      const away = !album.library && count > 0;
      toast(count ? (away ? plural(count, t('{n} item moved to {album}: it now shows only there.', { album: album.name }), t('{n} items moved to {album}: they now show only there.', { album: album.name }))
        : plural(count, t('{n} item added to {album}.', { album: album.name }), t('{n} items added to {album}.', { album: album.name })))
        : ids.length === 1 ? t('It was already in {album}.', { album: album.name }) : t('They were already in {album}.', { album: album.name }),
        { action: state.gallery.album?.id === album.id ? undefined : { label: t('Open'), run: () => openScope({ album: state.albums.find(a => a.id === album.id) || album }) } });
      if (state.gallery.album?.id === album.id || away) loadGallery();
    } catch (e) { toast(e.message, { tone: 'error' }); }
  };
  $('#album-new').onsubmit = async e => {
    e.preventDefault();
    const name = $('#album-name').value.trim(); if (!name) { $('#album-name').focus(); return; }
    try { const { album } = await api('album-create', { name, ids }); dialog.close(); if (selection.active) setSelecting(false); await refreshAlbums();
      toast(plural(album.count, t('{n} item added to the new album {album}.', { album: album.name }), t('{n} items added to the new album {album}.', { album: album.name })), { action: { label: t('Open'), run: () => openScope({ album: state.albums.find(a => a.id === album.id) || album }) } }); }
    catch (err) { toast(err.message, { tone: 'error' }); }
  };
  try {
    const albums = await refreshAlbums();
    const option = album => h('button', { type: 'button', class: 'album-option', onclick: () => add(album) },
      album.cover ? h('img', { alt: '', src: imageUrl(album.cover, true) }) : h('span', { class: 'album-cover' }, icon(ALBUM_ICON[album.kind] || 'album')),
      h('span', { class: 'album-name' }, album.name),
      h('span', { class: 'muted album-count' },
        album.library ? null : h('span', { class: 'album-apart', title: t('Its items don\'t show in the library'), 'aria-label': t('Its items don\'t show in the library') }, icon('eyeOff')),
        plural(album.count, t('{n} item'), t('{n} items'))));
    // The person's own albums first, then the stories', each kind under its name.
    const made = ALBUM_KINDS.flatMap(([kind, title]) => { const of = albums.filter(a => a.kind === kind); return of.length ? [h('h3', { class: 'album-group', text: title }), ...of.map(option)] : []; });
    list.replaceChildren(...(albums.length ? [...albums.filter(a => !a.kind).map(option), ...made]
      : [h('p', { class: 'hint' }, t('No albums yet. Give a name below to create the first one.'))]));
    (albums.length ? $('.album-option', list) : $('#album-name')).focus();
  } catch (e) { list.replaceChildren(h('p', { class: 'error' }, e.message)); }
}

// Keyboard in the library: Esc leaves the selection, Delete moves it to the trash, Ctrl+A selects the whole view.
document.addEventListener('keydown', e => {
  if (!state.unlocked || $('dialog[open]') || $('[popover]:popover-open:not(#toast)')) return;
  const typing = e.target instanceof HTMLElement && (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable);
  if (typing) return;
  if (e.key === 'Escape' && drag.active) { dragCancel(); return; }
  if (!selection.active) return;
  if (e.key === 'Escape') { e.preventDefault(); setSelecting(false); }
  else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') { e.preventDefault(); selectAll(); }
  else if ((e.key === 'Delete' || e.key === 'Backspace') && selection.ids.size) { e.preventDefault(); bulkAction(state.gallery.trash ? 'purge' : 'trash').catch(err => toast(err.message, { tone: 'error' })); }
});

// Import from the device: the image joins the gallery as the start of a new lineage.
async function importFiles(files, { pasted = false } = {}) {
  const videos = files.filter(isVideoFile);
  const images = files.filter(f => !isVideoFile(f) && ['image/png', 'image/jpeg', 'image/webp'].includes(f.type));
  if (!images.length && !videos.length) { toast(t('Use PNG, JPEG or WebP images, or MP4, MOV or WebM videos.')); return; }
  // A single video opens right away from the computer, frame by frame, while it uploads to the gallery.
  const g = state.gallery;
  if (videos.length && (g.trash || g.album || g.favorites || g.search || !['all', 'video'].includes(g.origin))) showImports();
  videos.forEach((file, i) => importVideo(file, { open: i === 0 && videos.length === 1 && !images.length }));
  if (!images.length) return;
  // Imported with an album open, the images join that album; otherwise the library shows them as they arrive.
  const album = g.album && !g.trash ? g.album : null;
  if (!album && (g.trash || g.favorites || g.search || !['all', 'imported'].includes(g.origin))) showImports();
  const imported = [], failed = [], many = images.length > 1;
  let refreshed = performance.now();
  toast(many ? t('Importing {images} images…', { images: number(images.length) }) : t('Importing…'));
  for (const [i, file] of images.entries()) {
    const name = pasted ? t('Pasted image') : file.name;
    if (file.size > 14 * 1024 * 1024) failed.push(t('{name} is over 14 MB.', { name }));
    else {
      try {
        const data = await new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(r.result); r.onerror = reject; r.readAsDataURL(file); });
        const { image } = await api('import', { data, name });
        imported.push(image); fresh.add(image.id);
      } catch (e) { failed.push(`${name}: ${e.message}`); }
    }
    if (!many || i === images.length - 1) continue;
    toast(t('Importing {n} of {images}…', { n: number(i + 1), images: number(images.length) }));
    // A long import shows its images every few seconds instead of only at the end.
    if (!album && performance.now() - refreshed > 3000) { refreshed = performance.now(); loadGallery().catch(() => {}); }
  }
  const problem = failed.length > 1 ? t('{count} files were not imported ({first})', { count: failed.length, first: failed[0] }) : failed[0] || '';
  if (!imported.length) { if (problem) toast(problem, { tone: 'error' }); else hideToast(); return; }
  if (album) { try { await api('bulk', { op: 'album-add', ids: imported.map(i => i.id), album: album.id }); refreshAlbums().catch(() => {}); } catch (e) { toast(e.message, { tone: 'error' }); } }
  await loadGallery();
  if (imported.length === 1) { openViewer(imported[0].id); if (problem) toast(problem, { tone: 'error' }); }
  else toast((album ? t('{n} images imported to {album}.', { n: number(imported.length), album: album.name }) : t('{n} images imported.', { n: number(imported.length) }))
    + (problem ? ' ' + problem.replace(/\.?$/, '.') : ''), problem ? { tone: 'error' } : {});
}
/** Back to the whole library, where new imports show first in the date order. */
function showImports() {
  const g = state.gallery;
  Object.assign(g, { trash: false, album: null, favorites: false, origin: 'all', search: '' });
  $('#search').value = '';
  return resetFeedView();
}
$('#import-input').onchange = e => { importFiles([...e.target.files]); e.target.value = ''; };
{
  const feed = $('#feed-view'); let depth = 0;
  feed.addEventListener('dragenter', e => { if (!e.dataTransfer?.types.includes('Files')) return; depth++; $('#drop-overlay').hidden = false; });
  feed.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (!depth) $('#drop-overlay').hidden = true; });
  feed.addEventListener('dragover', e => { if (e.dataTransfer?.types.includes('Files')) e.preventDefault(); });
  feed.addEventListener('drop', e => { depth = 0; $('#drop-overlay').hidden = true; if (!e.dataTransfer?.files.length) return; e.preventDefault(); importFiles([...e.dataTransfer.files]); });
}
// Ctrl+V with an image imports it, like dropping it on the gallery. Pasted into a prompt it becomes a reference instead
// (those fields handle the event first). With the viewer or another window open, nothing is imported.
document.addEventListener('paste', e => {
  if (e.defaultPrevented || !state.unlocked || $('dialog[open]')) return;
  const files = [...(e.clipboardData?.files || [])].filter(f => f.type.startsWith('image/'));
  if (!files.length) return;
  e.preventDefault();
  importFiles(files, { pasted: true });
});

// ---------------------------------------------------------------------------
// Viewer: one image, its versions and direct editing
// ---------------------------------------------------------------------------
const viewerDialog = $('#viewer');
/** Opens an image or a video. `at` (seconds) is where a video starts, for a frame opened back in its video. */
/**
 * immersive: only to look at the picture on the whole screen (the cover in the story's window): the viewer opens in full
 * screen, and leaving full screen closes it, back to where it was opened.
 */
async function openViewer(id, { history: mode = 'push', at = null, immersive = false } = {}) {
  if (!state.unlocked) return;
  const opening = !viewerDialog.open;
  if (opening) { stopAllHoverVideos(); viewerDialog.showModal(); viewer.pushed = false; viewer.onlyImmersive = false; }
  // Asked for in the same tap that opened it: the browser gives the whole screen only then.
  if (opening && immersive) { setImmersive(true); viewer.onlyImmersive = true; }
  leaveStageMode();
  const changed = viewer.id !== id;
  viewer.id = id;
  if (mode === 'push' && opening) { history.pushState({ viewer: true }, '', `#v/${id}`); viewer.pushed = true; }
  else if (mode !== 'none') history.replaceState(history.state, '', `#v/${id}`);
  const token = ++viewer.token;
  // Settles once the image in the address is the one in viewer.item (or failed to load): an edit sent before waits for it.
  let loaded; viewer.ready = new Promise(resolve => { loaded = resolve; });
  $('#stage-loading').hidden = false;
  try {
    const needLineage = !viewer.lineage || !viewer.lineage.nodes.some(n => n.id === id);
    const [detail, lineage] = await Promise.all([api('detail', undefined, { id }), needLineage ? api('lineage', undefined, { id }) : null]);
    if (token !== viewer.token) return;
    viewer.item = detail.image; loaded();
    if (lineage) viewer.lineage = lineage;
    if (changed) { viewer.compare = false; clearMask(); resetZoom(); }
    pruneUploads();
    if (isVideo(viewer.item)) {
      viewer.frames = [];
      renderViewer(); loadFrames(); focusPlayer();
      await loadVideo(viewer.item, { at });
    } else {
      stopVideo();
      renderViewer();
      await showStageImage(imageUrl(id), token);
    }
  } catch (e) {
    if (token !== viewer.token) return;
    toast(e.message, { tone: 'error' });
    if (opening) closeViewer();
  } finally { loaded(); if (token === viewer.token) $('#stage-loading').hidden = true; }
}
function closeViewer() { if (viewerDialog.open) viewerDialog.close(); }
/** Another image is about to show: Ampliar, Expandir and the brush were about the one before. Fotorrealista, Melhorar and Limpar stay chosen. */
function leaveStageMode() {
  if (viewer.action === 'expand' || viewer.action === 'crop' || viewer.action === 'upscale') setAction(null);
  else if (viewer.mode !== 'view') setMode('view');
}
viewerDialog.addEventListener('cancel', e => {
  if (viewerDialog.classList.contains('immersive')) { e.preventDefault(); setImmersive(false); }
  else if (viewer.action) { e.preventDefault(); setAction(null); }
  else if (viewer.mode !== 'view') { e.preventDefault(); setMode('view'); }
});
viewerDialog.addEventListener('close', () => {
  const reason = viewer.closeReason; viewer.closeReason = null;
  setAction(null);
  stopCleaner(); cleaner.cached = null;
  setImmersive(false);
  setMode('view');
  viewer.token++; viewer.id = null; viewer.item = null; viewer.lineage = null; viewer.compare = false; clearMask(); resetZoom(); holdSpace(false);
  stopVideo(); viewer.frames = []; delete viewerDialog.dataset.kind; pruneUploads(); scheduleRender();
  $('#stage-image').removeAttribute('src'); $('#versions-list').replaceChildren(); $('#frames-list').replaceChildren();
  if (reason !== 'pop' && reason !== 'lock' && location.hash.startsWith('#v/')) {
    // A viewer opened in place of the address (not on top of it) gives back the address of what is under it.
    if (viewer.pushed) history.back(); else history.replaceState(history.state, '', location.pathname + location.search + (WRITING[state.section] ? stories.address() : ''));
  }
  viewer.pushed = false;
});
window.addEventListener('popstate', () => {
  const match = location.hash.match(/^#v\/([a-f0-9]{32})$/);
  if (match && state.unlocked) openViewer(match[1], { history: 'none' });
  else if (viewerDialog.open) { viewer.closeReason = 'pop'; viewerDialog.close(); }
  if (match || !state.unlocked) return;
  // Back and forward through the stories: a story, the list, or out to the images.
  const vnPlace = location.hash.match(VN_HASH), place = vnPlace || location.hash.match(STORIES_HASH);
  if (place) { const section = vnPlace ? 'vn' : 'stories'; if (state.section !== section) setSection(section, { remember: false }); stories.route(place[1] || null, Boolean(place[2])); }
  else if (WRITING[state.section]) setSection('images', { remember: false });
});
$('#viewer-close').onclick = closeViewer;

async function refreshLineage() {
  if (!viewer.id) return;
  try { const lineage = await api('lineage', undefined, { id: viewer.id }); viewer.lineage = lineage; renderVersions(); renderViewerHeader(); }
  catch { /* the image may have been moved to the trash */ }
}
function currentNumbers() { return viewer.lineage ? versionNumbers(viewer.lineage.nodes) : new Map(); }
function nodeOf(id) { return viewer.lineage?.nodes.find(n => n.id === id); }

function renderViewer() {
  const video = isVideo(viewer.item);
  viewerDialog.dataset.kind = video ? 'video' : 'image';
  $('#versions-aside').setAttribute('aria-label', video ? t('Saved frames') : t('Versions'));
  renderViewerHeader();
  if (video) { renderVideoPanel(); renderFrames(); renderPlayer(); renderStack(); renderCover(); return; }
  renderEditPanel();
  renderInfo();
  renderVersions();
}
function renderViewerHeader() {
  const item = viewer.item; if (!item) return;
  const numbers = currentNumbers(), count = viewer.lineage?.nodes.filter(n => !n.deleted).length || 1;
  $('#viewer-version').textContent = count > 1 ? t('{prefix}{n} of {total}', { prefix: viewer.lineage.stack ? '' : 'v', n: numbers.get(item.id), total: viewer.lineage.nodes.length }) : '';
  const fav = $('#viewer-favorite'); fav.setAttribute('aria-pressed', String(item.favorite)); fav.setAttribute('aria-label', item.favorite ? t('Remove from favorites') : t('Favorite'));
  // A video still uploading lives only in this browser: it can't be favorited, renamed, downloaded or trashed yet.
  const local = !!item.local;
  for (const s of ['#viewer-favorite', '#viewer-album', '#viewer-hide', '#viewer-download', '#viewer-trash']) $(s).hidden = local;
  const hide = $('#viewer-hide'), hidden = !!item.hidden;
  if (hide.dataset.on !== String(hidden)) { hide.dataset.on = String(hidden); hide.replaceChildren(icon(hidden ? 'eye' : 'eyeOff')); }
  hide.setAttribute('aria-label', hidden ? t('Show in the library') : t('Hide from the library'));
  hide.title = hidden ? t('Hidden: it is in the Hidden filter. Click to bring it back to the library.') : t('Hide from the library (it stays in the Hidden filter)');
  $('#viewer-download').href = local ? '' : isVideo(item) ? videoUrl(item.id, true) : imageUrl(item.id) + '&download=1';
  $('#viewer-download').title = isVideo(item) ? t('Download the video') : t('Download');
  const index = galleryIndex();
  $('#viewer-prev').disabled = index <= 0;
  $('#viewer-next').disabled = index < 0 || (index >= state.gallery.items.length - 1 && !state.gallery.hasMore);
  const parent = nodeOf(item.id)?.parent;
  $('#compare').hidden = !parent;
  $('#compare').setAttribute('aria-pressed', String(viewer.compare));
  $('#compare-text').textContent = parent ? t('Compare with v{parent}', { parent: numbers.get(parent) }) : t('Compare');
  $('#stage-meta').textContent = `${item.width} × ${item.height}`;
}
function galleryIndex() {
  const ids = state.gallery.ids; if (!viewer.item) return -1;
  if (ids.has(viewer.item.id)) return ids.get(viewer.item.id);
  // With versions grouped, the gallery shows another version of the same image.
  for (const node of viewer.lineage?.nodes || []) if (ids.has(node.id)) return ids.get(node.id);
  return -1;
}
async function navigate(step) {
  let index = galleryIndex(); if (index < 0) return;
  const target = state.gallery.items[index + step];
  if (target) openViewer(target.id, { history: 'replace' });
}
/** ↑ and ↓: the previous or next version in the order the versions panel shows (tree or chronological), or for a video the next item of its stack. */
function navigateVersion(step) {
  const nodes = $$(`${isVideo(viewer.item) ? '#stack-list' : '#versions-list'} button.vnode[data-id]`), at = nodes.findIndex(el => el.dataset.id === viewer.id);
  const target = at < 0 ? null : nodes[at + step];
  if (target) openViewer(target.dataset.id, { history: 'replace' });
}

// Full screen: only the picture, with the same keys. The bar, the panels and the video controls come back while the
// pointer is near their edge of the screen (or with a tap, on touch screens), and hide again at the next key.
function setImmersive(on) {
  const was = viewerDialog.classList.contains('immersive');
  if (on && !viewerDialog.open) return;
  viewerDialog.classList.toggle('immersive', on);
  // A viewer opened only to look on the whole screen closes with it.
  if (!on && was && viewer.onlyImmersive) { viewer.onlyImmersive = false; closeViewer(); }
  if (!on) { hideChrome(); clearTimeout(timers.cursor); viewerDialog.classList.remove('cursor-idle'); }
  const button = $('#viewer-fullscreen');
  button.setAttribute('aria-pressed', String(on));
  button.setAttribute('aria-label', on ? t('Exit full screen') : t('Full screen'));
  button.title = on ? t('Exit full screen (T or Esc)') : t('Full screen (T)');
  button.replaceChildren(icon(on ? 'fullscreenExit' : 'fullscreen'));
  if (on && !was) {
    // Where the page can't take the whole screen (iPhone), the viewer still hides everything around the picture.
    // The shell takes the screen, not the page: a full-screen page would be drawn over the open dialog.
    $('.viewer-shell').requestFullscreen?.({ navigationUI: 'hide' })?.catch(() => {});
    if (viewerDialog.contains(document.activeElement) && document.activeElement !== viewerDialog) document.activeElement.blur();
    idleCursor();
  } else if (!on && document.fullscreenElement) document.exitFullscreen().catch(() => {});
  if (on !== was) requestAnimationFrame(() => { if (viewerDialog.open) fitStage(); });
}
const CHROME_EDGES = { top: '.viewer-bar', left: '.inspector', right: '.versions', bottom: '.video-bar, .stage-bar' };
function hideChrome() { for (const edge in CHROME_EDGES) viewerDialog.classList.remove(`show-${edge}`); }
function idleCursor() {
  clearTimeout(timers.cursor);
  viewerDialog.classList.remove('cursor-idle');
  timers.cursor = setTimeout(() => {
    const shown = Object.keys(CHROME_EDGES).some(edge => viewerDialog.classList.contains(`show-${edge}`));
    if (viewerDialog.classList.contains('immersive') && !shown) viewerDialog.classList.add('cursor-idle');
  }, 2000);
}
viewerDialog.addEventListener('pointermove', e => {
  if (!viewerDialog.classList.contains('immersive') || e.pointerType !== 'mouse') return;
  idleCursor();
  const near = 64, over = selector => $$(selector, viewerDialog).some(el => {
    const r = el.getBoundingClientRect();
    return r.width > 0 && e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
  });
  const at = { top: e.clientY < near, left: e.clientX < near, right: e.clientX > innerWidth - near, bottom: e.clientY > innerHeight - near };
  for (const edge in CHROME_EDGES) {
    const shown = viewerDialog.classList.contains(`show-${edge}`);
    const keep = shown && (over(CHROME_EDGES[edge]) || $$(CHROME_EDGES[edge], viewerDialog).some(el => el.matches(':focus-within')));
    viewerDialog.classList.toggle(`show-${edge}`, at[edge] || keep);
  }
});
document.documentElement.addEventListener('pointerleave', () => { if (viewerDialog.classList.contains('immersive')) hideChrome(); });
// On a touch screen a tap on the picture shows or hides the bar and the video controls.
$('#stage').addEventListener('click', e => {
  if (!viewerDialog.classList.contains('immersive') || !matchMedia('(pointer: coarse)').matches || e.target.closest('button, input, select, a')) return;
  const show = !viewerDialog.classList.contains('show-top');
  viewerDialog.classList.toggle('show-top', show); viewerDialog.classList.toggle('show-bottom', show);
});
// Full screen on a touch screen: swiping sideways moves through the gallery like ← and →, and swiping up or down moves
// through the versions like ↓ and ↑ (up brings the next one, as in a feed). Not while zoomed in, where a drag pans.
const swipe = { id: null, x: 0, y: 0, time: 0 };
$('#stage').addEventListener('pointerdown', e => {
  const ok = e.pointerType === 'touch' && viewerDialog.classList.contains('immersive') && viewer.mode === 'view' && zoom.scale === 1
    && zoom.pointers.size <= 1 && !e.target.closest('button, input, select, a, .stage-bar, .video-bar');
  swipe.id = ok ? e.pointerId : null; swipe.x = e.clientX; swipe.y = e.clientY; swipe.time = performance.now();
});
$('#stage').addEventListener('pointerup', e => {
  if (e.pointerId !== swipe.id) return;
  swipe.id = null;
  const dx = e.clientX - swipe.x, dy = e.clientY - swipe.y;
  if (zoom.scale !== 1 || performance.now() - swipe.time > 900) return;
  if (Math.abs(dx) >= 50 && Math.abs(dx) > Math.abs(dy) * 1.5) navigate(dx < 0 ? 1 : -1);
  else if (Math.abs(dy) >= 50 && Math.abs(dy) > Math.abs(dx) * 1.5) navigateVersion(dy < 0 ? 1 : -1);
});
// A second finger makes it a pinch, not a swipe.
$('#stage').addEventListener('pointerdown', e => { if (swipe.id !== null && e.pointerId !== swipe.id) swipe.id = null; }, { capture: true });
$('#stage').addEventListener('pointercancel', () => { swipe.id = null; });
document.addEventListener('fullscreenchange', () => {
  // Esc while the browser has the whole screen leaves full screen without reaching the page.
  if (!document.fullscreenElement && viewerDialog.classList.contains('immersive')) setImmersive(false);
});
$('#viewer-fullscreen').onclick = () => setImmersive(!viewerDialog.classList.contains('immersive'));
$('#viewer-prev').onclick = () => navigate(-1);
$('#viewer-next').onclick = () => navigate(1);
// The bar acts on the item in the address only once it has loaded: right after moving to another version the previous
// one is still on screen for a moment, and a click then must not favorite or trash it.
const shownItem = () => viewer.item && viewer.item.id === viewer.id && !viewer.item.local ? viewer.item : null;
$('#viewer-favorite').onclick = () => { const item = shownItem(); if (item) toggleFavorite(itemById(item.id) || item); };
$('#viewer-album').onclick = () => { const item = shownItem(); if (item) openAlbumDialog([item.id]); };
// Ocultar: the item leaves this view of the library (or, in Ocultas, goes back to it), so the viewer goes on to the next
// item of the view, as a mail app does after archiving. Grouped, the whole image with its versions (or stack) goes.
$('#viewer-hide').onclick = async () => {
  const item = shownItem(); if (!item) return;
  const hide = !item.hidden, whole = grouped(), g = state.gallery, index = galleryIndex();
  const neighbors = index < 0 ? [] : [g.items[index + 1]?.id, g.items[index - 1]?.id].filter(Boolean);
  try {
    await api('hide', { ids: [item.id], hidden: hide, whole });
    const listed = g.ids.has(item.id);
    item.hidden = hide;
    await loadGallery();
    const gone = listed ? !g.ids.has(item.id) : index >= 0 && galleryIndex() < 0;
    const next = gone ? neighbors.find(id => g.ids.has(id)) : null;
    if (next) openViewer(next, { history: 'replace' }); else { await refreshLineage(); renderViewerHeader(); }
    const undo = { label: t('Undo'), run: async () => { await api('hide', { ids: [item.id], hidden: !hide, whole }); await loadGallery(); if (viewerDialog.open) { if (viewer.item?.id === item.id) viewer.item.hidden = !hide; refreshLineage(); } } };
    toast(hide ? t('Hidden. It stays in the Hidden filter (the crossed-out eye).') : t('Back in the library.'), { action: undo });
  } catch (e) { toast(e.message, { tone: 'error' }); }
};
$('#viewer-trash').onclick = async () => {
  const item = shownItem(); if (!item) return;
  try {
    await api('trash', { id: item.id });
    const next = nodeOf(item.id)?.parent || viewer.lineage?.nodes.find(n => n.id !== item.id && !n.deleted)?.id;
    viewer.lineage = null;
    if (next) openViewer(next, { history: 'replace' }); else closeViewer();
    await loadGallery();
    toast(t('Moved to the trash.'), { action: { label: t('Undo'), run: async () => { await api('restore', { id: item.id }); await loadGallery(); if (viewerDialog.open) refreshLineage(); } } });
  } catch (e) { toast(e.message, { tone: 'error' }); }
};

// Stage image, fitted with JS so the mask canvas matches the drawn pixels exactly.
let stageImage = $('#stage-image');
async function showStageImage(url, token) {
  const next = new Image(); next.alt = viewer.item ? snippet(displayText(viewer.item), 120) : ''; next.id = 'stage-image'; next.draggable = false; next.src = url;
  try { await next.decode(); } catch { if (token === viewer.token) toast(t('Could not load the image.'), { tone: 'error' }); return; }
  if (token !== viewer.token) return;
  stageImage.replaceWith(next); stageImage = next;
  $('#stage-frame').classList.toggle('checker', viewer.item?.mime !== 'image/jpeg');
  fitStage();
}
/** Pixel size of what the stage shows: the image, or the video's frames. */
function mediaSize() {
  if (isVideo(viewer.item)) return [stageVideo.videoWidth || viewer.item.width || 0, stageVideo.videoHeight || viewer.item.height || 0];
  return [stageImage.naturalWidth, stageImage.naturalHeight];
}
// Where the viewer stacks the picture over the panels and scrolls (the same condition is in style.css): phones, and tablets held upright.
const STACKED = '(max-width: 920px), (orientation: portrait) and (max-width: 1180px)';
// A phone: the picture goes from one edge of the screen to the other and is as tall as its proportions make it, so
// what comes under it moves up for a wide picture and down for a tall one. Nothing of the picture is cut off.
const PHONE = '(max-width: 699.98px), (max-height: 540px)';
const STRIP = 46, BRUSH_STRIP = 56; // under the picture on a phone: its size, Comparar and Ajustar; over them, the brush's bar
let stageUnder = 0; // the height of what the stage holds under the picture, where the picture never goes
function fitStage() {
  const stage = $('#stage'), frame = $('#stage-frame');
  const [mediaWidth, mediaHeight] = mediaSize();
  if (!mediaWidth || !mediaHeight) return;
  const immersive = viewerDialog.classList.contains('immersive');
  const stacked = matchMedia(STACKED).matches && viewer.mode !== 'expand' && !immersive;
  const phone = stacked && matchMedia(PHONE).matches;
  // A video keeps its controls below the picture; the picture is centered in the space above them.
  const bar = isVideo(viewer.item) && !immersive ? $('#video-bar').offsetHeight : 0;
  const under = bar || (phone ? STRIP + (viewer.mode === 'mask' ? BRUSH_STRIP : 0) : 0);
  stageUnder = under;
  stage.style.paddingBottom = under ? `${under}px` : '';
  stage.style.setProperty('--under', `${under}px`);
  stage.classList.toggle('edge', phone); stage.dataset.mode = viewer.mode;
  const room = immersive ? 0 : phone ? under : bar ? bar + 40 : 104;
  if (phone) {
    // Held upright, the picture always takes the whole width, however tall that makes it: the page scrolls. With the
    // phone lying down, or while an area is painted, the picture stays within the screen, to be seen whole.
    const whole = matchMedia('(orientation: portrait)').matches && viewer.mode !== 'mask';
    const limit = whole ? Infinity : Math.max(160, window.innerHeight - $('.viewer-bar').offsetHeight - under);
    stage.style.setProperty('--stage-h', `${Math.ceil(Math.min(stage.clientWidth * mediaHeight / mediaWidth, limit)) + under}px`);
  } else if (stacked) {
    // The viewer scrolls: size the stage to the picture instead of a fixed share of the screen.
    const fit = (stage.clientWidth - 48) * mediaHeight / mediaWidth + room;
    stage.style.setProperty('--stage-h', `${Math.round(Math.max(260 + bar, Math.min(window.innerHeight * 0.62 + bar, fit)))}px`);
  } else stage.style.removeProperty('--stage-h');
  const width = stage.clientWidth - (immersive || phone ? 0 : 48), height = stage.clientHeight - room;
  // On a phone even a small picture takes the whole width; elsewhere it is enlarged up to twice its size (four times in full screen).
  const scale = Math.max(0.01, Math.min(width / mediaWidth, height / mediaHeight, phone ? Infinity : immersive ? 4 : 2));
  frame.style.width = `${Math.round(mediaWidth * scale)}px`;
  frame.style.height = `${Math.round(mediaHeight * scale)}px`;
  applyZoom();
}
// Zoom and pan. The wheel zooms around the pointer; dragging moves the picture once it is larger than the stage;
// a double-click switches between fitted and close up. While painting a mask, Space or the middle button pans.
// The mask canvas lives in the same frame, so the brush keeps lining up with the pixels at any zoom.
const zoom = { scale: 1, x: 0, y: 0, panning: false, space: false, pointers: new Map(), pinch: null };
const stageEl = $('#stage'), stageFrame = $('#stage-frame');
function fitScale() { const [width] = mediaSize(); return width && stageFrame.offsetWidth ? stageFrame.offsetWidth / width : 1; }
/** The middle of the space the picture has: the stage without what it holds under the picture. */
function stageCenter() { const box = stageEl.getBoundingClientRect(); return { x: box.left + box.width / 2, y: box.top + (box.height - stageUnder) / 2 }; }
function maxZoom() { return Math.max(4, 4 / fitScale()); } // up to four screen pixels per image pixel
function applyZoom({ animate = false } = {}) {
  // The picture can move only as far as it is larger than the stage, so it never leaves the screen.
  const limit = (size, room) => Math.max(0, (size * zoom.scale - room) / 2);
  const lx = limit(stageFrame.offsetWidth, stageEl.clientWidth), ly = limit(stageFrame.offsetHeight, stageEl.clientHeight - stageUnder);
  zoom.x = Math.min(lx, Math.max(-lx, zoom.x)); zoom.y = Math.min(ly, Math.max(-ly, zoom.y));
  stageFrame.classList.toggle('easing', animate);
  stageFrame.style.transform = zoom.scale === 1 ? '' : `translate(${zoom.x}px, ${zoom.y}px) scale(${zoom.scale})`;
  stageFrame.classList.toggle('zoomed', zoom.scale > 1);
  $('#zoom-reset').hidden = zoom.scale === 1;
  $('#zoom-text').textContent = `${Math.round(fitScale() * zoom.scale * 100)}%`;
}
function zoomAt(scale, clientX, clientY, options) {
  const next = Math.min(maxZoom(), Math.max(1, scale));
  const center = stageCenter();
  // The point under the pointer stays where it is.
  const px = clientX - center.x, py = clientY - center.y;
  zoom.x = px - (px - zoom.x) * next / zoom.scale; zoom.y = py - (py - zoom.y) * next / zoom.scale;
  zoom.scale = next;
  if (next === 1) { zoom.x = 0; zoom.y = 0; }
  applyZoom(options);
}
function zoomBy(factor) { const center = stageCenter(); zoomAt(zoom.scale * factor, center.x, center.y, { animate: true }); }
function resetZoom(options) { zoom.scale = 1; zoom.x = 0; zoom.y = 0; applyZoom(options); }
const zoomable = e => viewer.mode !== 'expand' && mediaSize()[0] && !e.target.closest('.stage-bar, .brush-bar, .video-bar');
stageEl.addEventListener('wheel', e => {
  if (!zoomable(e)) return;
  e.preventDefault();
  const delta = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
  // Trackpad pinches arrive as wheel events with Ctrl and small steps.
  zoomAt(zoom.scale * Math.exp(-delta * (e.ctrlKey ? 0.01 : 0.0015)), e.clientX, e.clientY);
}, { passive: false });
stageEl.addEventListener('pointerdown', e => {
  if (!zoomable(e)) return;
  zoom.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
  const pinch = zoom.pointers.size === 2;
  const pan = pinch || e.button === 1 || zoom.space || (viewer.mode !== 'mask' && e.button === 0 && zoom.scale > 1);
  if (!pan) return; // a mask stroke goes on to the canvas
  e.preventDefault(); e.stopPropagation();
  drawing = false; lastPoint = null;
  try { stageEl.setPointerCapture(e.pointerId); } catch { /* the pointer is already gone */ }
  zoom.panning = true; zoom.pinch = null; stageFrame.classList.add('panning');
  hideBrush();
}, { capture: true });
stageEl.addEventListener('pointermove', e => {
  const previous = zoom.pointers.get(e.pointerId); if (!previous) return;
  zoom.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
  if (!zoom.panning) return;
  e.stopPropagation();
  if (zoom.pointers.size >= 2) {
    const [a, b] = [...zoom.pointers.values()];
    const pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y), x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    if (zoom.pinch) { zoom.x += pinch.x - zoom.pinch.x; zoom.y += pinch.y - zoom.pinch.y; zoomAt(zoom.scale * pinch.distance / zoom.pinch.distance, pinch.x, pinch.y); }
    zoom.pinch = pinch;
  } else {
    zoom.x += e.clientX - previous.x; zoom.y += e.clientY - previous.y; applyZoom();
  }
}, { capture: true });
const releasePointer = e => {
  zoom.pointers.delete(e.pointerId); zoom.pinch = null;
  if (!zoom.pointers.size && zoom.panning) { zoom.panning = false; stageFrame.classList.remove('panning'); }
};
stageEl.addEventListener('pointerup', releasePointer, { capture: true });
stageEl.addEventListener('pointercancel', releasePointer, { capture: true });
stageEl.addEventListener('auxclick', e => { if (e.button === 1) e.preventDefault(); });
// On a phone one finger on the picture scrolls the page, since the picture takes the whole width (touch-action in
// style.css). Two fingers are a pinch on the picture, never a scroll of the page.
stageEl.addEventListener('touchmove', e => { if (e.touches.length > 1 && e.cancelable) e.preventDefault(); }, { passive: false });
stageEl.addEventListener('dblclick', e => {
  if (viewer.mode !== 'view' || !zoomable(e)) return;
  if (zoom.scale > 1) { resetZoom({ animate: true }); return; }
  // Close up at the real pixel size when that is a real step up, otherwise twice the fitted size.
  const actual = 1 / fitScale();
  zoomAt(actual >= 1.5 ? actual : 2, e.clientX, e.clientY, { animate: true });
});
stageFrame.addEventListener('transitionend', () => stageFrame.classList.remove('easing'));
$('#zoom-reset').onclick = () => resetZoom({ animate: true });
function holdSpace(down) { zoom.space = down; stageFrame.classList.toggle('grab-ready', down); }
window.addEventListener('blur', () => holdSpace(false));
document.addEventListener('keyup', e => { if (e.key === ' ') holdSpace(false); });

new ResizeObserver(() => { if (!viewerDialog.open) return; fitStage(); if (viewer.mode === 'expand' && !expansionDrag) fitExpansion(false); }).observe($('#stage'));
$('#compare').onclick = async () => {
  const item = viewer.item; const parent = nodeOf(item?.id)?.parent; if (!parent) return;
  viewer.compare = !viewer.compare;
  const token = viewer.token;
  $('#compare-label').hidden = !viewer.compare;
  $('#compare-label').textContent = `v${currentNumbers().get(parent)}`;
  $('#mask-canvas').hidden = viewer.compare || (!viewer.maskPainted && viewer.mode !== 'mask');
  renderViewerHeader();
  await showStageImage(imageUrl(viewer.compare ? parent : item.id), token);
};

// Versions panel
$$('[data-versions]').forEach(button => button.onclick = () => {
  viewer.versionsView = button.dataset.versions; savePrefs({ versions: viewer.versionsView }); renderVersions();
});
function versionThumb(id) {
  let img = thumbCache.get(id);
  if (!img) { img = h('img', { alt: '', loading: 'lazy', draggable: 'false' }); img.src = imageUrl(id, true); thumbCache.set(id, img); }
  return img;
}
function versionNode(node, numbers) {
  const current = node.id === viewer.id;
  const op = OPERATION[node.operation] || t('Version');
  const parentNumber = node.parent ? numbers.get(node.parent) : null;
  const sub = displayText(node);
  const button = h(node.deleted ? 'div' : 'button', { type: node.deleted ? null : 'button', class: `vnode${current ? ' current' : ''}${node.deleted ? ' trashed' : ''}`, 'aria-current': current ? 'true' : null,
    title: node.deleted ? t('In the trash') : `${parentNumber ? t('{operation} of v{parent}', { operation: op, parent: parentNumber }) : op} · ${dateTime(node.created)}` },
  h('span', { class: 'vthumb' }, versionThumb(node.id)),
  h('span', { class: 'vtext' },
    h('span', { class: 'vtitle' }, h('span', { class: 'vnum' }, `v${numbers.get(node.id)}`), h('span', { class: 'vop' }, node.deleted ? t('In the trash') : op),
      node.cover ? h('span', { class: 'vcover', title: t('This is the version that appears in the gallery') }, t('Cover')) : '',
      node.hidden && !node.deleted ? h('span', { class: 'vhidden', title: t('Hidden: out of the library, in the Hidden filter'), 'aria-label': t('Hidden') }, icon('eyeOff')) : ''),
    h('span', { class: 'vsub' }, snippet(sub, 60) || timeOnly(node.created))));
  if (!node.deleted) { button.dataset.id = node.id; button.onclick = () => { if (node.id !== viewer.id) openViewer(node.id, { history: 'replace' }); }; }
  return button;
}
function pendingNode(job) {
  let el = pendingNodes.get(job.id);
  if (!el) {
    el = h('div', { class: 'vnode pending', title: job.prompt },
      h('button', { type: 'button', class: 'vnode-open', 'aria-label': t('View request details: {request}', { request: snippet(job.label || job.prompt, 60) }) }),
      h('span', { class: 'vthumb' }, h('span', { class: 'spinner small' })),
      h('span', { class: 'vtext' },
        h('span', { class: 'vtitle' }, h('span', { class: 'vop' }), h('span', { class: 'vfrom', 'data-elapsed': job.id }, clock(now() - job.created))),
        h('span', { class: 'vsub' }, snippet(job.label || job.prompt, 50))),
      h('button', { type: 'button', class: 'icon-btn tiny', 'data-leaves': job.id, 'aria-label': t('Cancel'), title: t('Cancel'),
        onclick: () => { const current = state.jobs.get(job.id) || job; (unsuccessful(current) ? dismissJob : cancelJob)(current); } }, icon('close')));
    el.addEventListener('click', e => { if (!e.target.closest('.icon-btn')) openJobDetails(job.id); });
    pendingNodes.set(job.id, el);
  }
  const ended = !ACTIVE.has(job.status);
  setText($('.vop', el), ended ? ({ failed: t('Failed'), completed: t('Ready') }[job.status] || t('Interrupted')) : phaseText(job));
  $('.vfrom', el).hidden = ended;
  $('.spinner', el).hidden = ended;
  const close = $('.icon-btn', el), failed = unsuccessful(job);
  close.hidden = !failed && (ended || job.status === 'cancelling');
  close.classList.toggle('leave', failed);
  close.title = failed ? DISMISS_TITLE : t('Cancel'); close.setAttribute('aria-label', failed ? DISMISS_LABEL : t('Cancel'));
  if (job.previews) setPreview($('.vthumb', el), previewUrl(job.id, job.previews));
  return el;
}
/** The cover: the version that stands for the image and its edits in the gallery. Without one, the newest version does. */
function renderCover() {
  const bar = $('#cover-bar'), item = viewer.item, live = viewer.lineage?.nodes.filter(n => !n.deleted) || [], stack = !!viewer.lineage?.stack;
  bar.hidden = !item || !!item.local || (isVideo(item) && !stack) || live.length < 2 || !live.some(n => n.id === item.id);
  if (bar.hidden) return;
  const numbers = currentNumbers(), cover = live.find(n => n.cover);
  const newest = live.reduce((a, b) => (b.created > a.created ? b : a));
  const mine = cover?.id === item.id, name = n => `v${numbers.get(n.id)}`;
  setText($('#cover-note'), mine ? (stack ? t('This item is the stack\'s cover: it is the one that appears in the gallery.') : t('This version is the cover: it is the one that appears in the gallery.'))
    : cover ? t('The cover in the gallery is {cover}.', { cover: name(cover) }) : stack ? t('The gallery shows the newest item of the stack (v{n}).', { n: numbers.get(newest.id) }) : t('The gallery shows the newest version (v{n}).', { n: numbers.get(newest.id) }));
  const button = $('#cover-toggle');
  button.dataset.on = mine ? '0' : '1';
  button.replaceChildren(icon(mine ? 'undo' : 'image'), mine ? (stack ? t('Back to the newest item') : t('Back to the newest version')) : stack ? t('Use as the stack\'s cover') : t('Use this one as the cover'));
}
/**
 * A stack made by hand: the versions panel lists all its items (each image a tree of its own) under "Pilha", and a
 * video shows them above its frames. The bar under the list takes this image (with its versions) out, or undoes it.
 */
function renderStack() {
  const lineage = viewer.lineage, item = viewer.item, video = isVideo(item);
  const stack = !!lineage?.stack && !!item && !item.local;
  const live = stack ? lineage.nodes.filter(n => !n.deleted) : [];
  setText($('#versions-title'), stack ? t('Stack') : t('Versions'));
  $('#versions-title').title = stack ? t('↑ and ↓ go through the stack\'s items in the order of this list') : t('↑ and ↓ go through the versions in the order of this list');
  $('#stack-head').hidden = $('#stack-list').hidden = !(stack && video);
  $('#stack-bar').hidden = !stack;
  if (!stack) { $('#stack-list').replaceChildren(); delete $('#stack-list').dataset.shape; return; }
  const images = new Set(live.map(n => n.root)).size;
  const items = plural(live.length, t('{n} item'), t('{n} items'));
  setText($('#stack-note'), images < live.length
    ? t('A stack you put together: {items}, from {images} with their versions. In the library, a single card.', { items, images: plural(images, t('{n} image'), t('{n} images')) })
    : t('A stack you put together: {items}. In the library, a single card.', { items }));
  $('#stack-leave').title = video ? t('This video leaves the stack') : t('This image leaves the stack with all its versions');
  if (video) {
    setText($('#stack-count'), number(live.length));
    const list = $('#stack-list'), shape = JSON.stringify([viewer.id, live]);
    if (list.dataset.shape !== shape) {
      list.dataset.shape = shape;
      const numbers = currentNumbers();
      list.replaceChildren(h('div', { class: 'timeline' }, [...live].sort((a, b) => a.created - b.created).map(node => versionNode(node, numbers))));
    }
  }
}
$('#stack-leave').onclick = async () => {
  const item = shownItem(); if (!item) return;
  const root = nodeOf(item.id)?.root, other = viewer.lineage?.nodes.find(n => !n.deleted && n.root !== root);
  try {
    await api('unstack', { ids: [item.id], whole: false });
    await Promise.all([refreshLineage(), loadGallery()]);
    toast(isVideo(item) ? t('The video left the stack.') : t('The image left the stack, with its versions.'), other ? { action: { label: t('Undo'), run: async () => {
      await api('stack', { ids: [item.id, other.id] }); await Promise.all([refreshLineage(), loadGallery()]); } } } : {});
  } catch (e) { toast(e.message, { tone: 'error' }); }
};
$('#stack-undo').onclick = async () => {
  const item = shownItem(); if (!item || !viewer.lineage?.stack) return;
  // One item of each image is enough to make the same stack again.
  const each = [...new Map(viewer.lineage.nodes.filter(n => !n.deleted).map(n => [n.root, n.id])).values()];
  try {
    await api('unstack', { ids: [item.id], whole: true });
    await Promise.all([refreshLineage(), loadGallery()]);
    toast(t('Stack undone. Each item has its own card again.'), { action: { label: t('Undo'), run: async () => {
      await api('stack', { ids: each }); await Promise.all([refreshLineage(), loadGallery()]); } } });
  } catch (e) { toast(e.message, { tone: 'error' }); }
};
$('#cover-toggle').onclick = async () => {
  const item = viewer.item, button = $('#cover-toggle'); if (!item) return;
  const on = button.dataset.on === '1'; button.disabled = true;
  try {
    const stack = !!viewer.lineage?.stack;
    await api('cover', { id: item.id, cover: on });
    await Promise.all([refreshLineage(), loadGallery()]);
    toast(on ? (stack ? t('This item is now the stack\'s cover in the gallery.') : t('This version is now the cover in the gallery.')) : stack ? t('The gallery shows the stack\'s newest item again.') : t('The gallery shows the newest version again.'));
  } catch (e) { toast(e.message, { tone: 'error' }); }
  finally { button.disabled = false; }
};
function renderVersions() {
  const list = $('#versions-list');
  $$('[data-versions]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.versions === viewer.versionsView)));
  const lineage = viewer.lineage; renderStack(); renderCover();
  if (!lineage) { list.replaceChildren(); return; }
  const ids = new Set(lineage.nodes.map(n => n.id));
  // A finished edit stays listed until the refreshed tree has its version.
  const pending = [...state.jobs.values()].filter(j => j.parent_id && ids.has(j.parent_id) && j.status !== 'cancelled' && !(j.status === 'completed' && (!j.image_ids?.length || j.image_ids.some(id => ids.has(id)))));
  for (const id of pendingNodes.keys()) if (!state.jobs.has(id)) pendingNodes.delete(id);
  pending.forEach(pendingNode);
  // Progress updates the pending entries in place; the list is rebuilt only when versions come or go.
  const shape = JSON.stringify([viewer.versionsView, viewer.id, lineage.nodes, pending.map(j => j.id)]);
  if (list.firstChild && list.dataset.shape === shape) return;
  list.dataset.shape = shape;
  const numbers = versionNumbers(lineage.nodes);
  if (viewer.versionsView === 'time') {
    const entries = [...lineage.nodes.map(node => ({ node, at: node.created })), ...pending.map(job => ({ job, at: job.created }))].sort((a, b) => a.at - b.at);
    list.replaceChildren(h('div', { class: 'timeline' }, entries.map(e => {
      if (e.job) return pendingNode(e.job);
      const el = versionNode(e.node, numbers);
      const parent = e.node.parent ? numbers.get(e.node.parent) : null;
      if (parent) el.querySelector('.vop').textContent = t('{operation} of v{parent}', { operation: OPERATION[e.node.operation] || t('Version'), parent });
      el.querySelector('.vsub').textContent = [dateTime(e.node.created), snippet(displayText(e.node), 40)].filter(Boolean).join(' · ');
      return el;
    })));
  } else {
    const { roots, children } = buildTree(lineage.nodes, pending);
    const renderBranch = chain => h('ol', { class: 'branch' }, chain.map(({ entry, forks }) => {
      const li = h('li', { class: `vitem${forks.length ? ' has-forks' : ''}` }, entry.job ? pendingNode(entry.job) : versionNode(entry.node, numbers));
      if (forks.length) li.append(h('div', { class: 'forks' }, forks.map(fork => h('div', { class: 'fork' }, renderBranch(fork)))));
      return li;
    }));
    const chains = roots.map(root => branches(root, children));
    list.replaceChildren(h('div', { class: 'tree', 'data-depth': String(Math.max(0, ...chains.map(forkDepth))) }, chains.map(renderBranch)));
    fitTree();
  }
  revealCurrentVersion();
}
/** Brings the current version into view inside its list only: the page itself stays where it is. */
function revealCurrentVersion() {
  const list = $('#versions-list'), node = $('.vnode.current', list); if (!node) return;
  const box = list.getBoundingClientRect(), r = node.getBoundingClientRect();
  if (r.top < box.top) list.scrollTop -= box.top - r.top + 6;
  else if (r.bottom > box.bottom) list.scrollTop += r.bottom - box.bottom + 6;
}
/**
 * How far each level of forks steps in: 40px while the column has room, less the deeper the tree is, so that the
 * deepest version still has the width of its picture and a few words. No version is ever pushed out of the column,
 * however many levels there are and however narrow the column is dragged.
 */
function fitTree() {
  const list = $('#versions-list'), tree = $('.tree', list); if (!tree) return;
  const depth = Number(tree.dataset.depth) || 0, room = list.clientWidth - 20 - TREE_NODE_MIN;
  tree.style.setProperty('--step', `${depth ? Math.max(0, Math.min(40, Math.floor(room / depth))) : 40}px`);
}
const TREE_NODE_MIN = 156;
new ResizeObserver(fitTree).observe($('#versions-list'));

// ---------------------------------------------------------------------------
// Column widths: the edge of a column can be dragged
// ---------------------------------------------------------------------------
// The new-image column and the edit column of the viewer share one width, so the prompt box is in the same place and
// has the same size on both screens; the versions column has its own. They are layout options, kept in the browser
// with the others. A double click on the edge goes back to the default; the arrow keys move a focused edge.
const COLUMNS = { side: { prop: '--side-w', min: 280, max: 640 }, versions: { prop: '--versions-w', min: 180, max: 560 } };
function applyColumns() {
  for (const [name, c] of Object.entries(COLUMNS)) {
    const width = Number(state.prefs.columns?.[name]);
    if (width) document.documentElement.style.setProperty(c.prop, `${Math.max(c.min, Math.min(c.max, width))}px`);
    else document.documentElement.style.removeProperty(c.prop);
  }
}
for (const handle of $$('.col-handle')) {
  const name = handle.dataset.resize, c = COLUMNS[name];
  // The versions column is on the right: it grows as its edge goes left.
  const direction = name === 'versions' ? -1 : 1;
  const shown = () => Math.round($(handle.dataset.target).getBoundingClientRect().width);
  const set = width => document.documentElement.style.setProperty(c.prop, `${Math.round(Math.max(c.min, Math.min(c.max, width)))}px`);
  // What is kept is the width the column really has: the screen may not have room for all that was asked.
  const keep = () => savePrefs({ columns: { ...state.prefs.columns, [name]: shown() } });
  const reset = () => { const { [name]: _, ...rest } = state.prefs.columns || {}; savePrefs({ columns: rest }); applyColumns(); };
  let drag = null;
  handle.onpointerdown = e => {
    if (e.button) return;
    e.preventDefault(); e.stopPropagation(); handle.setPointerCapture(e.pointerId);
    drag = { x: e.clientX, width: shown() }; handle.classList.add('dragging'); document.body.classList.add('resizing');
  };
  handle.onpointermove = e => { if (drag) set(drag.width + direction * (e.clientX - drag.x)); };
  handle.onpointerup = handle.onpointercancel = () => {
    if (!drag) return;
    drag = null; handle.classList.remove('dragging'); document.body.classList.remove('resizing'); keep();
  };
  handle.ondblclick = reset;
  handle.onkeydown = e => {
    if (e.key === 'Home') { e.preventDefault(); reset(); return; }
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    e.preventDefault(); e.stopPropagation();
    set(shown() + direction * (e.key === 'ArrowRight' ? 16 : -16)); keep();
  };
}

/**
 * What the person wrote in the box for a request whose prompt the app put together with a ready-made instruction
 * (Fotorrealista, Melhorar, Ampliar, Expandir): their own text, to copy apart from the whole prompt. '' when nothing was
 * written; null when the prompt is the text as written (a generation, a plain edit). Requests keep it since 21/11/2026;
 * for earlier ones it is read back from the prompt, where the app put it in a fixed place.
 */
function typedText(x) {
  if (typeof x?.typed === 'string') return x.typed;
  const prompt = x?.original_prompt || x?.prompt || '';
  // Fotorrealista and Melhorar (and Ampliar as Fotorrealista): the notes open the prompt (withNotes).
  const notes = prompt.match(/^The user's own request for this image\. It has priority over everything else in this prompt:\n([\s\S]*?)\n\nCarry that request out fully/);
  if (notes) return notes[1];
  // The marker in English, or in Portuguese for requests made before the instructions went to the model in English.
  const after = (...markers) => { for (const marker of markers) { const at = prompt.indexOf(marker); if (at >= 0) return prompt.slice(at + marker.length); } return ''; };
  if (x?.operation === 'upscale') return after('Requested improvements: ', 'Melhorias pedidas: ');
  if (x?.operation === 'expand') return after('Instructions for the new areas: ', 'Instruções para as novas áreas: ');
  // The first evening of Fotorrealista and Melhorar, the notes went at the end.
  if (/^(Fotorrealista|Melhorada)\b/.test(x?.label || '')) return after('\n\nObservações: ');
  return null;
}
/** Shows what the person wrote, with its own Copiar, when the prompt shown is more than that. */
function renderTyped(wrap, text, typed) {
  $(wrap).hidden = !typed;
  setText($(text), typed || '');
}
async function copyTyped(x) {
  try { await navigator.clipboard.writeText(typedText(x) || ''); toast(t('Your text was copied.')); } catch { toast(t('Could not copy.'), { tone: 'error' }); }
}

// Info panel
function renderInfo() {
  const item = viewer.item; if (!item) return;
  // Frames saved from a video are imports too: no prompt, and a way back to the video.
  const frame = item.operation === 'frame' ? item.source || {} : null;
  const imported = item.operation === 'import' || !!frame;
  // A version made on the device (Limpar, or a crop) has no prompt either: it says what was done.
  const cleaned = item.operation === 'clean', cropped = item.operation === 'crop';
  // Fotorrealista and Melhorar show their whole prompt here; what the person wrote shows above it, to copy on its own.
  const typed = typedText(item);
  $('#prompt-heading').textContent = item.operation === 'edit' ? (typed === null ? t('Requested change') : t('Full prompt')) : item.operation === 'expand' ? t('Expansion') : item.operation === 'upscale' ? t('Upscale') : cleaned ? t('Cleanup') : cropped ? t('Crop') : frame ? t('Source') : 'Prompt';
  const text = item.operation === 'expand' ? (item.label || t('Automatic expansion')) : item.operation === 'upscale' ? (item.label || t('Upscale'))
    : cleaned ? (item.made_on === 'server' ? t('{label}: specks and fine lines removed on the server, without using the API.', { label: item.label || t('Cleanup') })
      : t('{label}: specks and fine lines removed on this device, without using the API.', { label: item.label || t('Cleanup') }))
    : cropped ? (item.made_on === 'server' ? t('{label}: made on the server, without using the API. The version it came from is still saved.', { label: item.label || t('Crop') })
      : t('{label}: made on this device, without using the API. The version it came from is still saved.', { label: item.label || t('Crop') })) : item.prompt;
  $('#info-prompt').textContent = text || (frame ? (frame.video_id ? t('Frame {n} ({time}) saved from a video.', { n: number((frame.frame ?? 0) + 1), time: timecode(frame.time) }) : t('Frame {n} ({time}) saved from a video that is not in the gallery.', { n: number((frame.frame ?? 0) + 1), time: timecode(frame.time) })) : imported ? t('Image imported from the computer.') : '');
  $('#open-video').hidden = !frame?.video_id;
  $('#info-prompt').classList.toggle('empty', !text);
  $('#prompt-editor').hidden = true; $('#info-prompt').hidden = false;
  $('#prompt-edit').hidden = imported || cleaned || cropped; $('#prompt-copy').hidden = !item.prompt;
  renderTyped('#typed-wrap', '#info-typed', typed);
  $('#planned-wrap').hidden = !item.planned_prompt; $('#info-planned').textContent = item.planned_prompt || '';
  // What went to the image API, with what the server added (the fidelity guard, the Limpar notice); not repeated when
  // it is the prompt above as it is.
  const sent = item.sent_prompt && item.sent_prompt !== item.prompt ? item.sent_prompt : '';
  $('#sent-wrap').hidden = !sent; $('#info-sent').textContent = sent;
  const direction = item.variation?.planned || item.variation?.written ? item.variation.label : '';
  $('#variation-wrap').hidden = !direction; $('#info-variation').textContent = direction;
  // Expansions and upscales store a fixed instruction, not a prompt worth reusing.
  $('#reuse').hidden = imported || !item.prompt || item.operation === 'expand' || item.operation === 'upscale'; $('#regenerate').hidden = !item.job_id;
  const o = item.options || {}; const model = state.models[o.model];
  const numbers = currentNumbers(); const parent = nodeOf(item.id)?.parent;
  const rows = [
    [t('Type'), parent ? t('{operation} of v{parent}', { operation: OPERATION[item.operation] || t('Image'), parent: numbers.get(parent) }) : OPERATION[item.operation] || t('Image')],
    [t('Size'), `${item.width} × ${item.height}`],
    [t('Created'), dateTime(item.created)],
  ];
  if (frame) rows.push([t('Position in the video'), `frame ${((frame.frame ?? 0) + 1).toLocaleString(locale)} · ${timecode(frame.time)}`]);
  if (!imported && item.options) {
    rows.push([t('Model'), model?.label || o.model], [t('Quality'), [...QUALITY, ...QUALITY_EXTENDED].find(([v]) => v === o.quality)?.[1] || o.quality]);
    if (model?.complexity) rows.push([t('Complexity'), COMPLEXITY.find(([v]) => v === o.complexity)?.[1] || o.complexity]);
    rows.push([t('Format'), String(o.format || '').toUpperCase()]);
    const gpu = model?.provider === 'gpu' || !!item.made?.gpu;
    if (o.thinking && o.thinking !== 'off') rows.push([t('Planning'), gpu ? (item.planning_skipped ? t('MiniMax\'s expander failed, made with the prompt as written') : t('MiniMax\'s expander'))
      : `${THINKING.find(([v]) => v === o.thinking)?.[1] || o.thinking}${PLANNING_SKIPPED[item.planning_skipped] ? `: ${PLANNING_SKIPPED[item.planning_skipped]}` : ''}`]);
    if (gpu) rows.push(...gpuPictureRows(o, item.made));
    if (item.variation) rows.push([t('Variation'), t('{index} of {count}{label}', { index: item.variation.index, count: item.variation.count, label: item.variation.planned || item.variation.written ? '' : `: ${item.variation.label}` })]);
    if (item.cleaned) rows.push([t('Before sending'), cleanedNote(item.cleaned)]);
    const tokens = (item.usage?.total_tokens || 0) + (item.planning_usage?.total_tokens || 0) + (item.variation_usage?.total_tokens || 0);
    if (tokens) rows.push(['Tokens', tokens.toLocaleString(locale)]);
    // The usage covers the whole request, which may have produced several images.
    const cost = usageCost(o.model, item.usage), planned = planningCost(item), images = o.n > 1 ? ' ' + t('({n} images)', { n: o.n }) : '';
    if (cost !== null) rows.push([o.n > 1 ? t('Request cost') : t('Cost'), planned !== null ? t('{total}{images}, with {planning} of planning', { total: formatUsd(cost + planned), images, planning: formatUsd(planned) })
      : item.planning_usage || item.variation_usage ? t('{total}{images} + planning', { total: formatUsd(cost), images }) : formatUsd(cost) + images]);
  }
  $('#info-details').replaceChildren(...rows.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]));
}
$('#prompt-copy').onclick = async () => { try { await navigator.clipboard.writeText(viewer.item?.prompt || ''); toast(t('Prompt copied.')); } catch { toast(t('Could not copy.'), { tone: 'error' }); } };
$('#typed-copy').onclick = () => copyTyped(viewer.item);
$('#prompt-edit').onclick = () => { $('#detail-prompt').value = viewer.item?.prompt || ''; $('#prompt-editor').hidden = false; $('#info-prompt').hidden = true; $('#detail-prompt').focus(); };
$('#prompt-cancel').onclick = () => { $('#prompt-editor').hidden = true; $('#info-prompt').hidden = false; };
$('#prompt-save').onclick = async () => {
  const item = viewer.item; const prompt = $('#detail-prompt').value.trim();
  try { await api('metadata', { id: item.id, title: item.title || '', prompt }); item.prompt = prompt; renderInfo(); refreshAfterJobs(); toast(t('Text saved. The original request is still recorded.')); }
  catch (e) { toast(e.message, { tone: 'error' }); }
};
$('#reuse').onclick = () => { const item = viewer.item; if (item) useJobPrompt({ prompt: item.prompt, options: { ...item.options, n: Number($('#quantity').value) || 1 }, reference_count: 0 }); };
$('#regenerate').onclick = async () => {
  const item = viewer.item; if (!item?.job_id) return;
  try { await retryJob({ id: item.job_id, status: 'completed' }, 1); toast(t('Generating another version with the same request.')); }
  catch (e) { toast(e.message, { tone: 'error' }); }
};
/** Adds a library image to the new-image prompt as a reference and goes there. */
function addReference(item) {
  const existing = state.refs.findIndex(r => r.id === item.id);
  if (existing < 0 && state.refs.length >= 16) { toast(t('Use up to 16 references.')); return; }
  if (existing < 0) { state.refs.push({ id: item.id, name: snippet(displayText(item), 60) || t('Image from the gallery'), width: item.width, height: item.height }); renderRefs(); }
  closeViewer(); $('#prompt').focus();
  toast(existing < 0 ? t('Added to the references as @{n}.', { n: state.refs.length }) : t('Already in the references as @{n}.', { n: existing + 1 }));
}
$('#as-reference').onclick = () => { if (viewer.item) addReference(viewer.item); };
$('#animate-open').onclick = () => { const item = viewer.item; if (item) gpuUi.open({ prompt: $('#edit-prompt').value, keys: [{ at: 0, id: item.id, width: item.width, height: item.height }] }); };
$('#open-video').onclick = () => { const source = viewer.item?.source; if (source?.video_id) openViewer(source.video_id, { history: 'replace', at: source.time }); };

// Edit panel. One box of text and one button serve every request about the image: a plain edit, or one of the
// ready-made requests chosen in the grid under the box (viewer.action: Ampliar, Expandir, Fotorrealista, Melhorar,
// Limpar). What the text means follows the request, and so do the options shown and the label of the button.
function editPrefs() { return { ...DEFAULT_OPTIONS, ...(state.prefs.composer || {}), ...(state.prefs.edit || {}) }; }
function renderEditPanel() {
  const prefs = editPrefs();
  const model = state.models[prefs.model] ? prefs.model : DEFAULT_OPTIONS.model;
  $('#edit-model').value = model;
  syncModelFields($('#edit-model'), $('#edit-quality'), $('#edit-complexity'), prefs.quality);
  syncProviderFields(state.models[$('#edit-model').value], { previews: $('#edit-previews') });
  $('#edit-quality').value = qualities(model).some(([v]) => v === prefs.quality) ? prefs.quality : 'high';
  $('#edit-quantity').value = String(Math.min(10, prefs.editN || 1));
  $('#edit-complexity').value = prefs.complexity; $('#edit-previews').value = String(prefs.previews);
  syncThinking($('#edit-thinking'), model); $('#edit-thinking').value = prefs.thinking; if (!$('#edit-thinking').value) $('#edit-thinking').value = 'off';
  loraChoice.edit = loraCopy(prefs.loras); renderLoraFields();
  renderCleanBefore();
  // Lines being written stay when another version opens, like the text in the box.
  if (varyForms.edit.mode !== 'lines') varyForms.edit.mode = prefs.vary === false ? 'same' : 'auto';
  renderPresets('edit'); setError('#edit-error');
  renderAction(); renderEditRefs();
}
/** The text of the box as it goes along with a ready-made request: one line, or nothing. */
function boxNotes() { return $('#edit-prompt').value.replace(/\s+/g, ' ').trim(); }
function updateEditCost() {
  const item = viewer.item; if (!item) return;
  const action = viewer.action;
  if (action === 'upscale') { updateUpscaleCost(); return; }
  if (action === 'expand' || action === 'crop') { updateExpandCost(); return; }
  if (action === 'clean') { const note = $('#edit-cost'); note.hidden = false; note.textContent = t('No cost: the cleanup runs on this device.'); note.title = ''; return; }
  const o = editOptions(item);
  // A ready-made request goes as written (no planning) and takes only the image itself.
  if (action) showCost('#edit-cost', { model: o.model, quality: o.quality, size: o.size, n: o.n, previews: o.previews, planning: [],
    inputs: [[item.width, item.height]], promptLength: withNotes(ACTIONS[action].prompt, boxNotes()).length });
  else {
    const redo = viewer.maskPainted && !$('#edit-prompt').value.trim(), cover = viewer.maskPainted && brushCovers();
    showCost('#edit-cost', { model: o.model, quality: o.quality, size: o.size, n: o.n, previews: o.previews, planning: redo ? [] : planningCalls(o, varyForms.edit.mode === 'auto' && o.n > 1),
      inputs: [[item.width, item.height], ...viewer.refs.map(refSize)], promptLength: redo ? (cover ? FILL_PROMPT : INPAINT_PROMPT).length : (cover ? COVER_NOTE.length : 0) + $('#edit-prompt').value.length });
  }
}
function persistEdit() {
  renderVary('edit'); updateEditCost(); renderPresets('edit');
  savePrefs({ edit: { model: $('#edit-model').value, quality: $('#edit-quality').value, editN: Number($('#edit-quantity').value), complexity: $('#edit-complexity').value,
    previews: Number($('#edit-previews').value), thinking: $('#edit-thinking').value, vary: varyForms.edit.mode !== 'same', loras: loraChoice.edit } });
}
$('#edit-model').onchange = () => {
  syncModelFields($('#edit-model'), $('#edit-quality'), $('#edit-complexity')); syncProviderFields(state.models[$('#edit-model').value], { previews: $('#edit-previews') });
  syncThinking($('#edit-thinking'), $('#edit-model').value); renderLoraFields(); persistEdit();
};
for (const s of ['#edit-quality', '#edit-quantity', '#edit-complexity', '#edit-previews', '#edit-thinking']) $(s).addEventListener('change', persistEdit);
function renderEditRefs() { renderRefList(REF_TARGETS.edit); renderBox(); updateEditCost(); }
// References only go with a plain edit: adding one goes back to it.
const addEditRefs = files => { if (viewer.action) setAction(null); return addRefFiles(REF_TARGETS.edit, files); };
$('#edit-file').onchange = async e => { await addEditRefs([...e.target.files]); e.target.value = ''; };
$('#edit-file-open').onclick = () => $('#edit-file').click();
$('#edit-gallery-refs').onclick = () => openPicker(REF_TARGETS.edit);
acceptDrops($('#edit-drop'), addEditRefs); acceptPaste($('#edit-prompt'), addEditRefs);
$('#edit-prompt').addEventListener('input', () => { autoGrow($('#edit-prompt')); renderBox(); updateEditCost(); });
$('#edit-clear').onclick = () => { const box = $('#edit-prompt'); box.value = ''; box.dispatchEvent(new Event('input', { bubbles: true })); if (!box.disabled) box.focus(); };
/**
 * The options of a request about an image: its own (format, background…), with what the panel chose. The seed and the
 * LoRAs of the image are not carried over: a model on the GPU takes the edit panel's LoRAs, and keeps the shape of the
 * image being changed (Auto).
 */
function sourceOptions(item) {
  const { seed, loras, engine, ...base } = { ...DEFAULT_OPTIONS, ...(item.options || {}) };
  return base;
}
function editOptions(item) {
  const model = $('#edit-model').value, gpu = isGpu(model);
  return { ...sourceOptions(item), model, quality: $('#edit-quality').value, n: Number($('#edit-quantity').value), complexity: $('#edit-complexity').value,
    previews: Number($('#edit-previews').value), thinking: $('#edit-thinking').value, vary: varyForms.edit.mode !== 'same',
    size: gpu ? 'auto' : editSize(item.width, item.height, state.models[model]?.flex), ...(gpu ? { loras: chosenLoras('edit') } : {}) };
}
$('#edit-clean').onchange = () => setCleanBefore($('#edit-clean').value);
/**
 * The one button of the panel: sends whatever is chosen. Everything stays as it was, to send again or adjust: the text
 * in the box, the request chosen in the grid (Fotorrealista, Melhorar, Ampliar and its size, the frame of Expandir or of
 * a crop, Limpar) and a painted area.
 */
$('#edit-form').onsubmit = async e => {
  e.preventDefault(); setError('#edit-error');
  // Sent right after opening an image or moving to another version: the request waits for it, so it is about the image
  // in the address. Before, it went nowhere (no image yet) or to the image shown before.
  // Another image may start loading meanwhile (a click in the versions): the wait is for the last one.
  for (let ready = null; viewer.item?.id !== viewer.id && ready !== viewer.ready;) { ready = viewer.ready; await ready; }
  const item = viewer.item, action = viewer.action; if (!item || item.id !== viewer.id) return;
  if ($('#edit-submit').disabled) return;
  if (action === 'clean') { await submitClean($('#edit-submit')); return; }
  // A crop is made on this device, like Limpar: it needs no API key. The GPU's models need the worker token instead.
  const using = action === 'expand' ? $('#expand-model').value : action === 'upscale' ? $('#upscale-model').value : $('#edit-model').value;
  if (needsKey(using) && !(action === 'crop' || (action === 'expand' && expansionKind() === 'crop'))) { showSettings(); return; }
  if (action) {
    const sent = action === 'upscale' ? await submitUpscale() : action === 'expand' ? await submitExpansion() : action === 'crop' ? await submitCrop(true) : await submitPreset(action);
    // Sent: the request stays chosen. The result of Fotorrealista or Melhorar opens when it is ready, and they stay chosen
    // there; Ampliar and Expandir are about the image on the stage, so they stay on it and a message offers the result.
    if (sent) { viewer.editTyped = false; renderVersions(); }
    return;
  }
  const prompt = $('#edit-prompt').value.trim();
  // With an area painted the text is optional: nothing written means "make this area again".
  const redo = !prompt && viewer.maskPainted;
  if (!prompt && !redo) { setError('#edit-error', t('Describe what should change, or paint an area to redo only that area.')); $('#edit-prompt').focus(); return; }
  const unmatched = mentionError(REF_TARGETS.edit, prompt);
  if (unmatched) { setError('#edit-error', unmatched); $('#edit-prompt').focus(); return; }
  const lines = varyForms.edit.mode === 'lines' && !redo ? varyLines('edit') : null;
  const linesError = lines && checkLines(lines);
  if (linesError) { setError('#edit-error', linesError); $('#edit-vary-lines').focus(); return; }
  const button = $('#edit-submit'); button.disabled = true;
  try {
    const mask = viewer.maskPainted ? buildMask() : null, cover = !!mask && brushCovers();
    // The ready-made instruction goes as written, like Fotorrealista and Melhorar: no planning, every image the same request.
    const ready = { options: { ...editOptions(item), thinking: 'off', vary: false } };
    const request = redo ? (cover ? { ...ready, prompt: FILL_PROMPT, label: t('Covered area filled') } : { ...ready, prompt: INPAINT_PROMPT, label: t('Area redone') })
      : cover ? { prompt: COVER_NOTE + prompt, typed: prompt, label: t('Covered area: {text}', { text: prompt.replace(/\s+/g, ' ') }), options: editOptions(item) }
      : { prompt, options: editOptions(item) };
    // With an area marked the image goes as it is, never cleaned first: what is outside the area comes back as it was
    // sent, so a cleaned image would come back soft all over. A covered area goes erased (coveredReference).
    const source = cover ? await coveredReference(item) : mask ? { id: item.id } : await sourceReference(item);
    await submitJob({ ...request, references: [source, ...refsPayload(viewer.refs)], mask, parent_id: item.id, operation: 'edit', ...(lines ? { lines } : {}) });
    viewer.editTyped = false;
    persistEdit();
    // The request now shows up in the version tree. A painted area stays, to send again; the result is offered by a
    // message instead of opening, which would take the area away.
    renderVersions();
  } catch (err) { setError('#edit-error', err.message); }
  finally { button.disabled = false; }
};

// Typing in the box after sending means a new request is on the way: the result then waits instead of opening.
$('#edit-prompt').addEventListener('input', () => { viewer.editTyped = true; });

// Fotorrealista: turns the image into a real-looking photo. Written from OpenAI's image prompting guide: name the one
// thing that changes, list everything that stays, ask for a real photograph in terms of real light, materials and
// optics, and rule out the retouched or rendered look. The image is the description of the scene, not a surface to
// copy: told to keep every detail as it is, the model repaints the same render with matte skin. It keeps the forms
// (anatomy above all) and photographs them again, with light that comes from the scene's own sources. It also cleans
// noise, artifacts and broken textures, which it tells apart from real detail so the cleanup doesn't smooth the picture.
// It names the camera and the lens (by framing) and asks for a frame straight out of the camera, correctly exposed, with
// only the faint, even grain of a camera at low ISO (never dots: the damage it cleans is dots), and it describes how a
// camera records hair, which is where the result looked least like a photo.
// Each person gets one skin: a face whose tone, shine or texture differs from the body's (as if pasted onto another
// body) is brought to match, with the face as the reference, since the face carries who the person is. Melhorar does
// the same (ENHANCE_STEPS).
// Nothing in it is about one kind of image: no place, light or subject is named.
const PHOTOREAL_PROMPT = `Turn this image into an ultra-realistic photograph: photograph this exact scene again with a real camera, with real people in a real place. The result is a straight-out-of-camera photo: a single unedited frame, exactly as the camera recorded it, with no filters, retouching or post-processing. Nobody should be able to tell it apart from a real photograph.

Use the attached image as the description of the scene (who and what is in it, where everything is, every shape, the colors and where the light comes from), not as a surface to copy. Its shading style, its highlights and the finish of its skin and materials come from a painter or a renderer: do not trace them, and remove every flaw that is not part of the scene. The forms that the shading describes (every muscle, fold, vein and contour) stay exactly as they are; only the way they are lit and finished becomes photographic.

Preserve exactly, without exceptions:
- Composition, framing, crop, camera angle and perspective.
- Every person's identity: face, facial features and their proportions, expression, gaze, age, skin tone, hair (style, length and color), body shape, build, proportions, pose and gestures.
- Anatomy, exactly as in the original: body and limb proportions, the length and thickness of every limb, hands and fingers, the musculature (the size, shape, position and definition of every muscle), bone structure, body fat, curves and silhouette. Do not slim, bulk up, idealize, simplify or correct the anatomy; render the same body with real skin over it.
- Clothing, accessories, objects, background, setting, and the position and size of every element.
- The color palette, the time of day, and the direction, color and mood of the light.
- Every element of the scene, however small. Do not add, remove, move or redesign anything (other than the flaws listed below), and do not add text, logos or watermarks.
- Where an object or material is stylized beyond what is physically possible, render the closest real-world equivalent that keeps its shape, colors and placement. People keep their anatomy as it is.

Clean it completely:
- Remove all of the original's noise and grain, color speckles, compression and JPEG artifacts, scattered light or dark dots, enlarged pore-like pits, crinkled or crepey texture, webs of fine lines, flakes or white specks in the hair, blockiness, banding, halos, color fringing, smudges, unwanted blur and moiré. Do not copy that damage from the original: render every surface fresh and clean.
- Fix strange or broken textures: warped, melted, smeared, repeating or garbled patterns become the clean, coherent real-world material they stand for.
- Noise, artifacts and glitches are not details: remove them. Real details (eyes, hair strands, fabric weave, small objects, edges) stay clear and intact; do not blur them away.

Make it read as a real photo:
- Real light: it comes from the sources the scene has (the sun, the sky, a window, lamps, a screen), from the same direction and in the same color as in the original, and it behaves like real light. It falls off with distance, casts shadows as hard or as soft as that source would, leaves contact shadows where things touch, picks up color from nearby surfaces, and leaves the sides turned away from it darker. No flat, even, sourceless light.
- Real materials: natural, healthy skin that looks clean, freshly washed and dry, with a matte finish: no oil, sweat or wet shine, only a faint, soft sheen where the light hits the highest points (unless the user's request asks for wet, sweaty or oiled skin), lit from within like living skin rather than a painted or plastic shell, with the natural variation of tone a real body has, following the same muscles and contours, in the same skin tone as the original, never darker; real fabric weave, seams and folds; real metal, wood, glass, stone, water and foliage.
- Skin texture that fits the distance: pores show only when the camera is close enough for real pores to be seen (a close-up of a face or of a detail), and then as fine, natural pores. From a normal or wider distance (half body, full body, a whole scene) the skin reads smooth and even, without pores, as it does in a real photo taken from that far. Never add pores to fill the resolution.
- One skin for each person: their face, neck, shoulders, arms, hands, torso and legs show the same skin tone, undertone, finish and quality, lit by the same light, with only the small variations a real body has (a little more color on the cheeks, lips, knuckles and knees). Where the original's face and body don't match (a different tone, more shine or oil on one of them, a different texture or level of quality, so that the face looks pasted onto another body), make them match: the whole person takes the skin tone of their face, with one even matte finish and the same quality everywhere. This is not a change to the person: their skin tone is the one their face has.
- Real hair, as a camera records it: a mass made of many individual strands of slightly different thickness and direction, falling and clumping the way real hair does under its own weight, with fine baby hairs at the hairline and a few loose strands at the outer edges; the color varies a little from strand to strand and from root to tip; light catches single strands as soft, broken highlights that follow the curve of the hair, and thin hair against the light lets some of it through. Keep the same style, length, volume and color as the original. Never a smooth, painted, sculpted or plastic mass, a helmet-like sheen, or strands drawn as regular lines.
- Camera: a professional full-frame camera with a prime lens that fits the framing, such as an 85mm for a close portrait, a 50mm for half or full body and a 35mm for a wider scene, at a moderate aperture (around f/2.8 to f/4 for people, f/8 for a wide scene), at low ISO, focused on the eyes when there is a face, with the camera's natural color and contrast. If the user's request asks for a phone photo or another camera, use that instead.
- Exposure: a correctly exposed photograph, as a good photographer sets it for the main subject: bright, clean highlights that keep their detail, shadows that are dark but still show what is in them, and the full tonal range and contrast a real camera records, instead of the flat, even tones or the painted contrast of an illustration. The light keeps its direction, color and mood; only the way the camera records it changes.
- Real optics: natural lens perspective, a depth of field that fits the framing (a close portrait has a softer background, a wide scene stays in focus), believable white balance, and a clean image with smooth tonal transitions. The only grain is the very faint, fine and even grain a real camera leaves at low ISO: the same over the whole frame and noticeable only when zoomed in, never dots, specks, blotches or a texture on the skin.
- An unretouched, true-to-life look, like a photo someone actually took and nobody edited: someone looking at it should believe it came straight from the camera.

Avoid: painterly or illustrated traces, outlines, cel shading, a 3D-render, CGI or video-game look, flat or sourceless lighting, flat or painted contrast, noise, heavy or blotchy grain, compression artifacts, dots or pits on the skin, pores at a distance where they could not be seen, webs of fine lines, specks in the hair, painted, sculpted or plastic-looking hair, oily, wet or sweaty-looking skin, darker skin, a face whose tone, shine or texture differs from the body's, waxy or smeared textures, airbrushed or plastic skin, beauty retouching, HDR, oversharpening, cinematic color grading, and any change to the anatomy, the musculature, the people or the layout.`;
// Melhorar: cleans the image and brings it to its best quality without changing its style or content. The
// same cleanup can go along with an upscale (an option in the Ampliar panel).
const ENHANCE_STEPS = `- Do not reproduce the damage in the original: noise and grain, scattered light or dark dots and speckles, enlarged pore-like pits, crinkled or crepey texture, webs of fine lines, flakes or white specks in the hair, color speckles, compression and JPEG artifacts, blockiness, banding, halos, color fringing, smudges and moiré. Render those surfaces fresh and clean instead of tracing them.
- Fix strange or broken textures: warped, melted, smeared, repeating or garbled patterns become the clean, coherent material they stand for.
- Skin: smooth, even and natural, clean, freshly washed and dry, with a matte finish (only a faint, soft sheen where the light hits the highest points, unless the user's request asks for wet, sweaty or oiled skin) and texture that fits the distance: pores only when the view is close enough for real pores to show (a close-up of a face or of a detail), and then fine and natural; from farther away the skin reads smooth and even, without pores; the same skin tone as the original, never darker; no oily, wet or sweaty shine. Moles, freckles, scars, veins, muscle definition and every real feature stay as they are.
- One skin for each person: the face, neck, body and limbs show the same skin tone, undertone, finish and quality, with only the small variations a real body has (a little more color on the cheeks, lips, knuckles and knees). Where the original's face and body don't match (a different tone, more shine or oil on one of them, a different texture or level of quality, so that the face looks pasted onto another body), make them match: the whole person takes the skin tone of their face, with one even matte finish and the same quality everywhere. This is not a change to the person: their skin tone is the one their face has.
- Hair: clean, natural strands with soft highlights, free of specks and flakes.
- A clean, well-focused image with smooth tonal transitions and gradients, accurate exposure and color balance. Real details (eyes, hair strands, fabric, edges, small objects) stay clear and intact; do not blur them away.

Avoid: changing the style, adding texture or "detail" that is not part of the scene, pores at a distance where they could not be seen, oily or glossy skin, darker skin, a face whose tone, shine or texture differs from the body's, airbrushed or plastic skin, beauty retouching (no slimming, no reshaping of faces or bodies, no added makeup), oversharpening, an HDR look, heavy color grading, and any change to the people or the layout.`;
const ENHANCE_PROMPT = `Restore and enhance this image without changing what it shows: recreate the same picture from scratch as a clean, new render at its best quality. Use the attached image as the reference for what to show (the people, content, composition, colors and light), not as a surface to copy: its surface may be damaged by noise and artifacts, and that damage must not carry over.

Keep exactly, without exceptions:
- The style and medium (a photo stays a photo, an illustration stays an illustration), composition, framing, crop and perspective.
- Every person's identity: face, facial features and their proportions, expression, gaze, age, skin tone (the same color, not darker), hair, body shape, build, anatomy and musculature, pose and gestures.
- Clothing, accessories, objects, background, colors, lighting, and the position and size of every element.
- Every real detail, however small. Do not add, remove, move or redesign anything, and do not add text, logos or watermarks.

Clean and improve:
${ENHANCE_STEPS}`;
/**
 * What the person writes in the panel is the point of that request. Added once after the ready-made instruction, with
 * its long list of things to keep, it was ignored (a change to a body lost to "keep the anatomy exactly"). So it goes
 * first, stated as having priority over the rest, and once more at the end, and the instruction's own "without
 * exceptions" and "any change to the people" make room for it.
 */
function withNotes(prompt, notes) {
  if (!notes) return prompt;
  prompt = prompt
    .replace(/\b(Preserve|Keep) exactly, without exceptions:/, "$1 exactly, apart from what the user's request above changes:")
    .replace(/and any change to ((?:the anatomy, the musculature, )?the people or the layout)\.$/, "and any change to $1 other than what the user's request asks for.");
  return `The user's own request for this image. It has priority over everything else in this prompt:\n${notes}\n\n`
    + 'Carry that request out fully, so that it is clearly visible in the result, not subtle. Where it changes something that the instructions below say to keep '
    + '(the anatomy, a part of a body, a face, clothes, colors, light or anything else), the request wins for that part, and "keep" below means the image as it is after that change. '
    + 'Everything the request does not mention follows the instructions below.\n\n'
    + `${prompt}\n\nReminder: the user's request comes first and must be clearly visible in the result:\n${notes}`;
}
/**
 * Sends Fotorrealista or Melhorar for the current image, with the edit panel's model and quality. The text of the box
 * goes along as notes that have priority over the ready-made instruction. True once the request is sent.
 */
async function submitPreset(name) {
  const item = viewer.item, p = ACTIONS[name], notes = boxNotes(), button = $('#edit-submit');
  button.disabled = true;
  try {
    // The prompt is complete as it is: no planning step rewrites it, and every image of the request follows it.
    await submitJob({ prompt: withNotes(p.prompt, notes), typed: notes, label: p.label + (notes ? `: ${notes}` : ''), options: { ...editOptions(item), thinking: 'off', vary: false },
      references: [await sourceReference(item)], parent_id: item.id, operation: 'edit' });
    return true;
  } catch (err) { setError('#edit-error', err.message); return false; }
  finally { button.disabled = false; }
}
// ---------------------------------------------------------------------------
// Limpar: dots and the fine web of lines removed on the device (assets/clean.js, run in a worker)
// ---------------------------------------------------------------------------
const CLEAN_LEVELS = { leve: t('light'), media: t('medium'), forte: t('strong') };
const cleaner = { stop: null, cached: null };
/**
 * "Limpar antes de enviar": the level the image is cleaned at before an edit sends it, or 'off'. Remembered for each
 * account: on the same browser one may clean every edit while another (cards with text, say) sends images as they are.
 */
function cleanBefore() { const v = state.prefs.clean?.by?.[state.user?.id]; return v === 'off' || CLEAN_LEVELS[v] ? v : 'media'; }
/** What the details of a request, and of its images, say when the image being changed went through Limpar. */
function cleanedNote(level) {
  return t('Cleanup: {level}. The prompt said the blur was intentional and asked for the focus back.', { level: CLEAN_LEVELS[level] || CLEAN_LEVELS.media });
}
/** The level the Limpar button was last used at. */
function cleanLevel() { const v = state.prefs.clean?.level; return CLEAN_LEVELS[v] ? v : 'media'; }
function stopCleaner() { cleaner.stop?.(); }
/**
 * Cleans the pixels in workers, off the main thread: the image is cut into horizontal strips, one per worker (up to
 * four), each with the rows around it that the filter looks at. One run at a time: starting another, or leaving the
 * image, ends the one before.
 */
function runCleaner(pixels, level, onProgress) {
  stopCleaner();
  const { width, height, data } = pixels, row = width * 4;
  const parts = strips(height, Math.min(4, navigator.hardwareConcurrency || 2));
  const out = new Uint8ClampedArray(data.length), done = parts.map(() => 0), workers = [];
  return new Promise((resolve, reject) => {
    let left = parts.length;
    const end = () => { workers.forEach(w => w.terminate()); if (cleaner.stop === stop) cleaner.stop = null; };
    const stop = cleaner.stop = () => { end(); reject(Object.assign(new Error(t('Cleanup interrupted.')), { name: 'AbortError' })); };
    const fail = message => { end(); reject(new Error(message)); };
    parts.forEach((part, i) => {
      const worker = new Worker(new URL(`./clean-worker.js${assetVersion}`, import.meta.url), { type: 'module' });
      workers.push(worker);
      worker.onmessage = e => {
        if (e.data.progress !== undefined) { done[i] = e.data.progress; onProgress?.(done.reduce((a, b) => a + b, 0) / parts.length); return; }
        if (e.data.error) { fail(e.data.error); return; }
        // Only the strip's own rows are kept; the rows around it were there for the filter to look at.
        const skip = (part.from - part.top) * row;
        out.set(new Uint8ClampedArray(e.data.pixels).subarray(skip, skip + (part.to - part.from) * row), part.from * row);
        if (--left === 0) { end(); resolve(new ImageData(out, width, height)); }
      };
      worker.onerror = () => fail(t('Could not clean the image on this device.'));
      const strip = data.slice(part.top * row, part.bottom * row);
      worker.postMessage({ pixels: strip.buffer, width, height: part.bottom - part.top, level }, [strip.buffer]);
    });
  });
}
/** What one cleaned image may weigh: a request carries it as data, and a Limpar version is imported. */
const CLEANED_LIMIT = 13 * 1024 * 1024;
/**
 * A cleaned image as a PNG, or, when the PNG passes CLEANED_LIMIT (a large image: the PNG of the browser is about 2 bytes
 * a pixel, so from around 6 megapixels), as a lossless WebP (quality 1 in Chromium) and last as a JPEG at 0.97, whose
 * changes are far below what the cleanup itself does. Before, such an image was sent as it was, without cleaning and
 * without a word.
 */
async function cleanedBlob(canvas) {
  const png = await canvasBlob(canvas, 'image/png');
  if (png && png.size <= CLEANED_LIMIT) return png;
  const webp = await canvasBlob(canvas, 'image/webp', 1);
  if (webp?.type === 'image/webp' && webp.size <= CLEANED_LIMIT) return webp;
  return await canvasBlob(canvas, 'image/jpeg', 0.97) || png;
}
/** A picture for the import route, which takes 14 MB at most: as it is, or drawn again as cleanedBlob keeps a large one. */
async function importableImage(blob) {
  if (blob.size <= 14 * 1024 * 1024) return blob;
  const bitmap = await createImageBitmap(blob);
  const canvas = document.createElement('canvas'); canvas.width = bitmap.width; canvas.height = bitmap.height;
  canvas.getContext('2d').drawImage(bitmap, 0, 0); bitmap.close?.();
  const smaller = await cleanedBlob(canvas);
  if (!smaller || smaller.size > 14 * 1024 * 1024) throw new Error(t('The image is over 14 MB.'));
  return smaller;
}
/** An image of the library cleaned at a level (cleanedBlob). The last one is kept in memory while the page is open, never stored. */
async function cleanedImage(item, level, onProgress) {
  const hit = cleaner.cached;
  if (hit && hit.id === item.id && hit.level === level) return hit;
  const response = await fetch(imageUrl(item.id));
  if (!response.ok) throw new Error(t('Could not load the image to clean it.'));
  const bitmap = await createImageBitmap(await response.blob());
  const canvas = document.createElement('canvas'); canvas.width = bitmap.width; canvas.height = bitmap.height;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(bitmap, 0, 0); bitmap.close?.();
  ctx.putImageData(await runCleaner(ctx.getImageData(0, 0, canvas.width, canvas.height), level, onProgress), 0, 0);
  const blob = await cleanedBlob(canvas);
  if (!blob) throw new Error(t('Could not prepare the cleaned image.'));
  return cleaner.cached = { id: item.id, level, blob, width: canvas.width, height: canvas.height };
}
/**
 * The image an edit starts from, as the request sends it. With "Limpar antes de enviar" on it is cleaned on the device
 * first, so the edit doesn't copy the dots and lines of the image it starts from; the image in the library is not
 * changed. The request says the image was cleaned and at which level, and the server opens the prompt by telling the
 * model that the blur was put there on purpose and must be undone (OpenAI::CLEANED). Where the cleanup can't run (an
 * old browser, too little memory) the image goes as it is, and a message says so. Not for an edit with an area marked:
 * there the model is asked to leave the rest of the image untouched, and it would hand the softness back.
 */
async function sourceReference(item) {
  const level = cleanBefore();
  if (level === 'off' || isVideo(item)) return { id: item.id };
  const unclean = () => { toast(t('Could not clean the image on this device. It was sent as it is.')); return { id: item.id }; };
  try {
    if (cleaner.cached?.id !== item.id || cleaner.cached.level !== level) toast(t('Cleaning the image before sending…'));
    const cleaned = await cleanedImage(item, level);
    // Same pixels as the stored image (a mask is painted over those), and within what one request may carry.
    if (cleaned.width !== item.width || cleaned.height !== item.height || cleaned.blob.size > CLEANED_LIMIT) return unclean();
    return { data: await blobData(cleaned.blob), source_id: item.id, cleaned: true, clean: level };
  } catch (err) {
    if (err.name === 'AbortError') throw err;
    return unclean();
  }
}
/** Limpar: the cleaned image becomes a new version of the one on screen. Nothing goes to the image API. True once saved. */
async function submitClean(button) {
  setError('#edit-error');
  const item = viewer.item, level = cleanLevel(); if (!item) return false;
  const label = $('#edit-submit-label');
  button.disabled = true;
  try {
    label.textContent = t('Cleaning…');
    const cleaned = await cleanedImage(item, level, done => { label.textContent = t('Cleaning… {pct}%', { pct: Math.round(done * 100) }); });
    label.textContent = t('Saving…');
    if (cleaned.blob.size > CLEANED_LIMIT) throw new Error(t('The cleaned image is over 13 MB and could not be saved.'));
    const { image } = await api('import', { data: await blobData(cleaned.blob), parent_id: item.id, clean: level });
    fresh.add(image.id);
    await Promise.all([refreshAfterJobs(), refreshLineage()]);
    if (viewer.id === item.id) openViewer(image.id, { history: 'replace' });
    toast(t('Cleaned image saved as a new version.'));
    return true;
  } catch (err) { if (err.name !== 'AbortError') setError('#edit-error', err.message); return false; }
  finally { button.disabled = false; renderAction(); }
}

// The requests of the grid under the box. Choosing one says, right under the grid, what it does and what the text of
// the box means for it, shows its options, and gives the panel's button its name; choosing it again, the × or Esc goes
// back to a plain edit. The text is the same box for all of them: the notes of Fotorrealista and Melhorar (which have
// priority over the ready-made instruction, withNotes), what to improve in Ampliar, what the new areas of Expandir show.
const ACTIONS = {
  upscale: { tile: '#upscale-open', icon: 'upscale', title: t('Upscale', { ctx: 'action' }), go: t('Upscale', { ctx: 'action' }), quality: false, quantity: false,
    hint: t('OpenAI recreates the image at a higher resolution, clean and well defined. Small details may change; the current version stays saved.'),
    placeholder: t('What to improve in the upscale (optional). E.g.: a sharper face'),
    empty: t('If you want to ask for an improvement, write it in the box above.'),
    // With Fotorrealista the text goes as its notes, with priority (upscalePrompt).
    filled: () => upscaleWith() === 'photoreal' ? t('The text in the box goes along as a note for Photoreal and has priority, even if it changes something.') : t('The text in the box goes along as a requested improvement.') },
  // Recortar: a frame inside the image keeps only a part of it, made on this device. The cropped image opens, to be
  // edited or expanded from there (an expansion then never sees what was cut off).
  crop: { tile: '#crop-open', local: true, icon: 'crop', title: t('Crop', { ctx: 'action' }), go: t('Crop', { ctx: 'action' }), quality: false, quantity: false,
    hint: t('Keep only a part of the image: pull the frame\'s handles or pick a proportion, and drag the image to place it. Made on this device, at no cost; the cropped image opens as a new version, ready to edit or expand.'),
    placeholder: t('A crop does not use text'), empty: '', filled: t('A crop does not use the text in the box.') },
  // The frame crops, extends, or both (outpaint.js): the button and what the text does follow what the frame is doing.
  expand: { tile: '#expand-open', icon: 'expand', title: t('Crop and expand'), quality: false, quantity: false,
    go: () => ({ crop: t('Crop', { ctx: 'action' }), both: t('Crop and expand') })[expansionKind()] || t('Expand'),
    hint: t('Pull the frame\'s handles: inward crops, outward enlarges the canvas. Drag the image to position it.'),
    placeholder: t('What should appear in the new areas (optional). Left blank, the scene continues.'),
    empty: '',
    filled: () => (expansionKind() === 'crop' ? t('A crop does not use the text in the box.') : t('The text in the box says what should appear in the new areas.')) },
  photoreal: { tile: '#photoreal', prompt: PHOTOREAL_PROMPT, label: t('Photoreal'), icon: 'camera', title: t('Photoreal'), go: t('Turn into a photo'), quality: true, quantity: true, before: true,
    hint: t('The same scene photographed for real, keeping the composition, the people, the anatomy and every element.'),
    placeholder: t('Notes for the photo (optional). E.g.: phone photo, late afternoon light; or a change you want'),
    empty: t('If you like, write notes in the box above. They have priority, even if they change something.'), filled: t('The text in the box goes along as a note and has priority, even if it changes something.') },
  enhance: { tile: '#enhance', prompt: ENHANCE_PROMPT, label: t('Enhanced'), icon: 'sparkle', title: t('Enhance'), go: t('Enhance'), quality: true, quantity: true, before: true,
    hint: t('Recreates the same image clean, without noise, specks or artifacts, and without changing the style or the people.'),
    placeholder: t('Notes for the enhancement (optional). E.g.: sharper eyes, remove the stain on the shoulder'),
    empty: t('If you like, write notes in the box above. They have priority, even if they change something.'), filled: t('The text in the box goes along as a note and has priority, even if it changes something.') },
  clean: { tile: '#clean', local: true, icon: 'eraser', title: t('Clean'), go: t('Clean'), quality: false, quantity: false,
    hint: t('Removes specks and the web of fine lines right on your device, without using the API and at no cost. Outlines, eyes, hair and details stay sharp, and the result becomes a new version. The stronger it is, the smoother the surfaces get.'),
    placeholder: t('Clean does not use text'), empty: '', filled: t('Clean does not use the text in the box.') },
};
const EDIT_ONLY_TOOLS = ['#brush-toggle', '#edit-file-open', '#edit-gallery-refs', '#edit-vary-open'];
/** Chooses one of the requests of the grid, or goes back to a plain edit (null). */
function setAction(name) {
  name = name || null;
  // The frame of Expandir is drawn from the picture on the stage: the open version, never the one it is compared with.
  if ((name === 'expand' || name === 'crop') && viewer.compare) {
    const token = viewer.token;
    $('#compare').onclick().then(() => { if (viewer.token === token && !viewer.compare) setAction(name); });
    return;
  }
  if (viewer.action === 'clean' && name !== 'clean') stopCleaner();
  viewer.action = name;
  setError('#edit-error');
  // Ampliar and Expandir also change what the stage shows; an area being marked belongs to a plain edit.
  const stage = name === 'expand' || name === 'crop' ? 'expand' : name === 'upscale' ? 'upscale' : !name && viewer.mode === 'mask' ? 'mask' : 'view';
  if (viewer.mode !== stage) setMode(stage);
  else if (stage === 'expand' && viewer.expansion && viewer.expansion.crop !== (name === 'crop')) startExpansion();
  renderAction();
  if (name && !ACTIONS[name].local && !matchMedia('(pointer: coarse)').matches) $('#edit-prompt').focus({ preventScroll: true });
}
/** What the text of the box will do, said in the panel of the chosen request, and whether there is text to clear. */
function renderBox() {
  const box = $('#edit-prompt'), a = ACTIONS[viewer.action], filled = !!box.value.trim();
  $('#edit-clear').hidden = !box.value;
  const said = value => (typeof value === 'function' ? value() : value);
  const cover = brushCovers();
  box.placeholder = a ? a.placeholder : viewer.maskPainted ? (cover ? t('What should appear in the covered area (optional). Left blank, it is filled with what surrounds it.') : t('What should appear in the marked area (optional). Left blank, that part is redone.')) : REF_TARGETS.edit.placeholder[viewer.refs.length ? 1 : 0];
  if (!$('#edit-submit').disabled || viewer.action !== 'clean') $('#edit-submit-label').textContent = a ? said(a.go) : viewer.maskPainted ? (cover ? t('Fill area') : t('Redo area')) : t('Generate edit');
  renderBrushMode(cover);
  if (!a) return;
  const note = said(filled ? a.filled : a.empty);
  $('#action-note').textContent = note;
  $('#action-note').hidden = !note;
  $('#action-note').classList.toggle('filled', filled && !a.local && !(viewer.action === 'expand' && expansionKind() === 'crop'));
}
function renderAction() {
  const name = viewer.action, a = ACTIONS[name], box = $('#edit-prompt');
  for (const [key, x] of Object.entries(ACTIONS)) $(x.tile).setAttribute('aria-pressed', String(key === name));
  // The area, the references and the variations belong to a plain edit.
  for (const s of EDIT_ONLY_TOOLS) $(s).disabled = !!a;
  $('#edit-refs').hidden = !!a;
  $('#mask-chip').hidden = !!a || !viewer.maskPainted;
  if (a) maskCanvas.hidden = true; else if (viewer.maskPainted && viewer.mode === 'view' && !viewer.compare) maskCanvas.hidden = false;
  $('#edit-quality-block').hidden = !!a && !a.quality;
  $('#edit-more').hidden = !!a?.local;
  $('#edit-quantity').hidden = !!a && !a.quantity;
  box.disabled = !!a?.local;
  $('#edit-submit').disabled = name === 'upscale' ? !viewer.upscale : name === 'expand' || name === 'crop' ? !viewer.expansion || !!expansionError() : false;
  $('#action-panel').hidden = !a;
  if (a) {
    $('#action-icon').replaceChildren(icon(a.icon)); $('#action-title').textContent = a.title; $('#action-hint').textContent = a.hint;
    $('#preset-before').hidden = !a.before; $('#preset-levels').hidden = name !== 'clean';
    $('#upscale-options').hidden = name !== 'upscale'; $('#expand-options').hidden = name !== 'expand' && name !== 'crop';
    if (name === 'clean') renderCleanLevel();
  }
  renderBox(); renderVary('edit'); updateEditCost();
}
function renderCleanLevel() { for (const b of $$('#preset-levels button')) b.setAttribute('aria-pressed', String(b.dataset.level === cleanLevel())); }
/** "Limpar antes de enviar" is one setting shown in two places: under Mais opções and with Fotorrealista and Melhorar. */
function renderCleanBefore() {
  $('#edit-clean').value = cleanBefore();
  for (const b of $$('#preset-before button')) b.setAttribute('aria-pressed', String(b.dataset.before === cleanBefore()));
}
function setCleanBefore(value) {
  if (!state.user?.id) return;
  const clean = state.prefs.clean || {};
  savePrefs({ clean: { ...clean, by: { ...clean.by, [state.user.id]: value } } }); renderCleanBefore();
}
for (const b of $$('#preset-before button')) b.onclick = () => setCleanBefore(b.dataset.before);
for (const b of $$('#preset-levels button')) b.onclick = () => { savePrefs({ clean: { ...state.prefs.clean, level: b.dataset.level } }); renderCleanLevel(); };
for (const [name, a] of Object.entries(ACTIONS)) $(a.tile).onclick = () => setAction(viewer.action === name ? null : name);
$('#action-close').onclick = () => setAction(null);

// Mask brush painted directly over the image
const maskCanvas = $('#mask-canvas'), maskCtx = maskCanvas.getContext('2d');
let drawing = false, lastPoint = null;
function clearMask() {
  viewer.maskPainted = false; maskCtx.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
  $('#mask-chip').hidden = true; if (viewer.mode !== 'mask') maskCanvas.hidden = true;
  renderBox(); updateEditCost();
}
function setMode(mode) {
  const previous = viewer.mode; viewer.mode = mode;
  if (mode === 'mask' && viewer.compare) $('#compare').click();
  if (mode === 'mask' && (maskCanvas.width !== stageImage.naturalWidth || maskCanvas.height !== stageImage.naturalHeight)) {
    maskCanvas.width = stageImage.naturalWidth; maskCanvas.height = stageImage.naturalHeight; viewer.maskPainted = false;
  }
  maskCanvas.hidden = !(mode === 'mask' || (viewer.maskPainted && mode === 'view' && !viewer.compare && !viewer.action));
  maskCanvas.classList.toggle('idle', mode !== 'mask');
  $('#brush-bar').hidden = mode !== 'mask';
  if (mode !== 'mask') hideBrush();
  $('#brush-toggle').setAttribute('aria-pressed', String(mode === 'mask'));
  $('#mask-chip').hidden = !viewer.maskPainted || !!viewer.action;
  $('#expand-stage').hidden = mode !== 'expand'; $('#stage-frame').hidden = mode === 'expand'; $('#stage-bar').hidden = mode === 'expand';
  if (mode === 'expand' && previous !== 'expand') { resetZoom(); startExpansion(); }
  if (mode === 'upscale' && previous !== 'upscale') startUpscale();
  if (mode !== 'expand') viewer.expansion = null;
  if (mode !== 'upscale') viewer.upscale = null;
  renderBox();
  if (mode !== previous && viewerDialog.open) fitStage();
  // On a phone or a tablet the panel is under the picture: the picture comes back to the screen to be painted, or for
  // the frame of Recortar and Expandir to be dragged.
  if ((mode === 'mask' || mode === 'expand') && previous !== mode && viewerDialog.open && matchMedia(STACKED).matches) $('.viewer-shell').scrollTo({ top: 0 });
}
$('#brush-toggle').onclick = () => setMode(viewer.mode === 'mask' ? 'view' : 'mask');
$('#mask-done').onclick = () => { setMode('view'); if (viewer.maskPainted) $('#edit-prompt').focus(); };
$('#mask-clear').onclick = () => { maskCtx.clearRect(0, 0, maskCanvas.width, maskCanvas.height); viewer.maskPainted = false; $('#mask-chip').hidden = true; renderBox(); updateEditCost(); };
// Paint and send from the same place: the area is made again, following the text of the box when there is some.
$('#mask-go').onclick = () => { if (!viewer.maskPainted) { toast(t('First paint the area that should be redone.')); return; } $('#edit-form').requestSubmit(); };
$('#mask-remove').onclick = () => { clearMask(); setMode(viewer.mode === 'mask' ? 'mask' : 'view'); };
/**
 * Cobrir, a setting of the brush: off, the area is only marked and the model sees what is in it (it redoes it from that);
 * on, the area goes erased and the model draws it without seeing what was there. On the picture a covered area is
 * painted solid with the checkered pattern of what is generated (as in Expandir); a marked one is a light blue veil.
 */
const brushCovers = () => state.prefs.brush?.cover === true;
let coverInk = null;
function maskInk() {
  if (!brushCovers()) return '#8fa6ff';
  // Squares of about 8 screen pixels at the size the picture is shown.
  const shown = maskCanvas.getBoundingClientRect().width || maskCanvas.width;
  const unit = Math.max(4, Math.round(8 * maskCanvas.width / shown));
  if (coverInk?.unit !== unit) {
    const tile = document.createElement('canvas'); tile.width = tile.height = unit * 2;
    const g = tile.getContext('2d'); g.fillStyle = '#3a3a42'; g.fillRect(0, 0, unit * 2, unit * 2); g.fillStyle = '#2a2a30'; g.fillRect(0, 0, unit, unit); g.fillRect(unit, unit, unit, unit);
    coverInk = { unit, pattern: maskCtx.createPattern(tile, 'repeat') };
  }
  return coverInk.pattern;
}
function renderBrushMode(cover = brushCovers()) {
  maskCanvas.classList.toggle('cover', cover);
  $('#mask-cover').setAttribute('aria-pressed', String(cover));
  $('#brush-hint').textContent = cover ? t('Paint what the AI should not see') : t('Paint what should be redone');
  $('#mask-go').textContent = cover ? t('Fill area') : t('Redo area');
  $('#mask-go').title = cover ? t('Generate a new version with the painted area drawn from scratch, without the AI seeing what was there. The text in the box, if any, says what should appear in it; without text, it is filled with what surrounds it. (Ctrl+Enter)')
    : t('Generate a new version with only the painted area redone. The text in the box, if any, says what should appear in it. (Ctrl+Enter)');
  $('#mask-chip-text').textContent = cover ? t('Only the covered area') : t('Only the marked area');
}
$('#mask-cover').onclick = () => {
  savePrefs({ brush: { ...state.prefs.brush, cover: !brushCovers() } });
  // What is already painted takes the new ink: the area stays, only what it does changes.
  if (viewer.maskPainted) { maskCtx.save(); maskCtx.globalCompositeOperation = 'source-in'; maskCtx.fillStyle = maskInk(); maskCtx.fillRect(0, 0, maskCanvas.width, maskCanvas.height); maskCtx.restore(); }
  renderBox(); updateEditCost();
};
/** The image with the painted area erased (Cobrir): transparent there, so the model can't see what was in it. */
async function coveredReference(item) {
  const response = await fetch(imageUrl(item.id));
  if (!response.ok) throw new Error(t('Could not load the image to cover the area.'));
  const bitmap = await createImageBitmap(await response.blob());
  if (bitmap.width !== maskCanvas.width || bitmap.height !== maskCanvas.height) { bitmap.close?.(); throw new Error(t('The painted area does not match this image. Paint it again.')); }
  const canvas = document.createElement('canvas'); canvas.width = bitmap.width; canvas.height = bitmap.height;
  const c = canvas.getContext('2d'); c.drawImage(bitmap, 0, 0); bitmap.close?.();
  c.globalCompositeOperation = 'destination-out'; c.drawImage(maskCanvas, 0, 0);
  // The erased area must stay transparent: a PNG, or for a large image a lossless WebP, never a JPEG.
  let blob = await canvasBlob(canvas, 'image/png');
  if (!blob || blob.size > CLEANED_LIMIT) { const webp = await canvasBlob(canvas, 'image/webp', 1); if (webp?.type === 'image/webp' && (!blob || webp.size < blob.size)) blob = webp; }
  if (!blob || blob.size > 14 * 1024 * 1024) throw new Error(t('The image is too large to be sent with a covered area.'));
  return { data: await blobData(blob), source_id: item.id };
}
function maskPoint(e) { const r = maskCanvas.getBoundingClientRect(); return { x: (e.clientX - r.left) * maskCanvas.width / r.width, y: (e.clientY - r.top) * maskCanvas.height / r.height }; }
function paint(e) {
  const p = maskPoint(e);
  maskCtx.strokeStyle = maskCtx.fillStyle = maskInk();
  maskCtx.lineWidth = Number($('#brush-size').value) * maskCanvas.width / maskCanvas.getBoundingClientRect().width;
  maskCtx.lineCap = maskCtx.lineJoin = 'round';
  maskCtx.beginPath(); maskCtx.moveTo(lastPoint?.x ?? p.x, lastPoint?.y ?? p.y); maskCtx.lineTo(p.x, p.y); maskCtx.stroke();
  maskCtx.beginPath(); maskCtx.arc(p.x, p.y, maskCtx.lineWidth / 2, 0, Math.PI * 2); maskCtx.fill();
  lastPoint = p;
  if (!viewer.maskPainted) { viewer.maskPainted = true; $('#mask-chip').hidden = false; renderBox(); updateEditCost(); }
}
maskCanvas.onpointerdown = e => { if (viewer.mode !== 'mask') return; e.preventDefault(); drawing = true; lastPoint = null; maskCanvas.setPointerCapture(e.pointerId); paint(e); };
maskCanvas.onpointermove = e => { if (drawing) paint(e); };
maskCanvas.onpointerup = maskCanvas.onpointercancel = () => { drawing = false; lastPoint = null; };
// The brush's circle: where the next stroke lands and how wide it is. The size of the brush is in screen pixels at any
// zoom, so the circle is as large as the slider says. It follows the pointer over the picture while Área is on (a
// finger shows it while it paints) and shows in the middle of the picture while the size is being changed. It is on
// the stage only while the brush is.
const brushCursor = $('#brush-cursor');
function showBrush(clientX, clientY) {
  const box = stageEl.getBoundingClientRect(), size = Number($('#brush-size').value);
  clearTimeout(timers.brush);
  brushCursor.style.width = brushCursor.style.height = `${size}px`;
  brushCursor.style.transform = `translate(${(clientX - box.left - size / 2).toFixed(1)}px, ${(clientY - box.top - size / 2).toFixed(1)}px)`;
  brushCursor.hidden = false;
}
function hideBrush() { clearTimeout(timers.brush); brushCursor.hidden = true; }
for (const type of ['pointerdown', 'pointermove']) maskCanvas.addEventListener(type, e => { if (viewer.mode === 'mask' && !zoom.panning) showBrush(e.clientX, e.clientY); });
maskCanvas.addEventListener('pointerleave', () => { if (!drawing) hideBrush(); });
// A finger or a pen that lifts takes the circle with it; a mouse keeps it while it is over the picture.
for (const type of ['pointerup', 'pointercancel']) maskCanvas.addEventListener(type, e => { if (e.pointerType !== 'mouse' || !maskCanvas.matches(':hover')) hideBrush(); });
$('#brush-size').addEventListener('input', () => {
  if (viewer.mode !== 'mask') return;
  const center = stageCenter(); showBrush(center.x, center.y);
  timers.brush = setTimeout(hideBrush, 1200);
});
/** OpenAI masks: transparent pixels are edited, opaque ones are kept. */
function buildMask() {
  const out = document.createElement('canvas'); out.width = maskCanvas.width; out.height = maskCanvas.height;
  const c = out.getContext('2d'); c.fillStyle = '#000'; c.fillRect(0, 0, out.width, out.height);
  c.globalCompositeOperation = 'destination-out'; c.drawImage(maskCanvas, 0, 0);
  return out.toDataURL('image/png');
}

// The frame of Expandir: it crops the image, extends it, or both at once
// The frame is the picture that will come out (the geometry is in outpaint.js). Dragged inwards it cuts the image off:
// that is done on this device and saved as a version, without the image API. Dragged outwards it leaves new areas for
// the image API to fill, also as a version. The image it starts from is never changed. While a handle or the image is
// being dragged the view stays still, so what is dragged follows the pointer; let go, the frame and the whole image are
// fitted to the stage again. Nothing of this is on the stage unless Expandir is chosen.
const expandCanvas = $('#expand-canvas'), expandCtx = expandCanvas.getContext('2d');
let expansionDrag = null, newAreaPattern = null;
const frameOf = e => ({ width: e.width, height: e.height, x: e.x, y: e.y });
const imageSize = e => [e.image.naturalWidth, e.image.naturalHeight];
function expansionKind() { const e = viewer.expansion; return e ? frameKind(e.width, e.height, e.x, e.y, ...imageSize(e)) : null; }
function expansionError() { const e = viewer.expansion; return e ? validateFrame(e.width, e.height, e.x, e.y, ...imageSize(e), limitsOf($('#expand-model').value)) : ''; }
function startExpansion() {
  const item = viewer.item; const image = stageImage;
  if (!item || !image.naturalWidth) { viewer.action = null; setMode('view'); return; }
  const iw = image.naturalWidth, ih = image.naturalHeight, crop = viewer.action === 'crop';
  // Expandir starts a quarter larger all around; where that is more than the image API generates, at the size of the
  // image. Recortar starts on the whole image.
  let frame = centerFrame({ width: snap16(iw * 1.25), height: snap16(ih * 1.25), x: 0, y: 0 }, iw, ih);
  if (crop || validateFrame(frame.width, frame.height, frame.x, frame.y, iw, ih)) frame = { width: iw, height: ih, x: 0, y: 0 };
  viewer.expansion = { image, ...frame, crop, view: null, tween: 0 };
  $('#expand-ratio-field').hidden = !crop;
  for (const id of ['#expand-width', '#expand-height']) $(id).step = crop ? 1 : 16;
  const flexible = modelEntries(m => m.flex);
  const preferred = state.prefs.expand?.model || item.options?.model;
  fillSelect($('#expand-model'), flexible, flexible.some(([id]) => id === preferred) ? preferred : DEFAULT_OPTIONS.model);
  syncModelFields($('#expand-model'), $('#expand-quality'), null, state.prefs.expand?.quality || item.options?.quality || 'high');
  fitExpansion(false);
}
/** Takes a new frame. One with new areas can't be larger than the image API generates. */
function takeFrame(frame) {
  const e = viewer.expansion, [iw, ih] = imageSize(e);
  const limits = limitsOf($('#expand-model').value);
  if (frameKind(frame.width, frame.height, frame.x, frame.y, iw, ih) !== 'crop' && frameTooLarge(frame.width, frame.height, limits) && frame.width * frame.height >= e.width * e.height) {
    setError('#edit-error', limits.gpu ? t('With a new area, the canvas cannot go past 12,000 px per side or 40 MP.') : t('With a new area, the canvas cannot go past 3,840 px per side or 8.29 MP.')); return false;
  }
  Object.assign(e, frame); return true;
}
/** Fits the frame and the whole image to the stage, moving there smoothly after a drag. */
function fitExpansion(animate) {
  const e = viewer.expansion; if (!e) return;
  const stage = $('#expand-stage'), target = frameView(frameOf(e), ...imageSize(e), stage.clientWidth, stage.clientHeight, matchMedia('(max-width: 920px)').matches ? 22 : 36);
  cancelAnimationFrame(e.tween);
  const from = e.view;
  if (!animate || !from || matchMedia('(prefers-reduced-motion: reduce)').matches) { e.view = target; renderExpansion(); return; }
  const began = performance.now();
  const step = now => {
    if (viewer.expansion !== e) return;
    // The time of a frame can come a little before the moment the move began: it starts from where the view is.
    const p = Math.max(0, Math.min(1, (now - began) / 160)), k = 1 - (1 - p) ** 3;
    e.view = { scale: from.scale + (target.scale - from.scale) * k, x: from.x + (target.x - from.x) * k, y: from.y + (target.y - from.y) * k };
    paintExpansion();
    if (p < 1) e.tween = requestAnimationFrame(step);
  };
  renderExpansion();
  e.tween = requestAnimationFrame(step);
}
const CROP_STATUS = {
  same: t('The frame takes the whole image: pull its handles inward, or pick a proportion.'),
  crop: t('What is outside the frame is removed. The cropped image opens as a new version.'),
};
const FRAME_STATUS = {
  same: t('The frame is the size of the image: pull a handle inward or outward.'),
  crop: t('Crop only: what is outside the frame is removed, and nothing is generated.'),
  expand: t('The checkered area is generated.'),
  both: t('What is outside the frame is removed, and the checkered area is generated.'),
};
/** The panel follows the frame: its size, what it will do, whether it can be sent, the name of the button and the cost. */
function renderExpansion() {
  const e = viewer.expansion; if (!e) return;
  const kind = expansionKind(), error = expansionError();
  $('#expand-width').value = e.width; $('#expand-height').value = e.height;
  $('#expand-dimensions').textContent = (e.crop ? CROP_STATUS : FRAME_STATUS)[kind];
  $('#expand-engine').hidden = e.crop || kind === 'crop';
  // The proportion the frame has right now, among those that crop with one tap.
  const [iw, ih] = imageSize(e);
  for (const b of $$('#expand-ratios button')) {
    const [rw, rh] = b.dataset.crop.split(':').map(Number), to = ratioFrame(iw, ih, rw, rh);
    b.setAttribute('aria-pressed', String(kind === 'crop' && hasRatio(e.width, e.height, rw, rh)));
    b.disabled = frameKind(to.width, to.height, to.x, to.y, iw, ih) === 'same';
  }
  // A frame at the size of the image is where a crop starts from, not a mistake: the line above says what to do.
  setError('#edit-error', kind === 'same' ? '' : error); $('#edit-submit').disabled = !!error;
  renderBox(); updateExpandCost();
  paintExpansion();
}
/** Draws the stage: the image dimmed where the frame leaves it out, and the frame with the kept part and the new areas. */
function paintExpansion() {
  const e = viewer.expansion; if (!e?.view) return;
  const stage = $('#expand-stage'), ratio = Math.min(2, window.devicePixelRatio || 1), sw = stage.clientWidth, sh = stage.clientHeight;
  if (expandCanvas.width !== Math.round(sw * ratio) || expandCanvas.height !== Math.round(sh * ratio)) { expandCanvas.width = Math.round(sw * ratio); expandCanvas.height = Math.round(sh * ratio); }
  const { scale, x, y } = e.view, [iw, ih] = imageSize(e), c = expandCtx;
  const ix = x + e.x * scale, iy = y + e.y * scale, fw = e.width * scale, fh = e.height * scale;
  c.setTransform(ratio, 0, 0, ratio, 0, 0); c.clearRect(0, 0, sw, sh);
  c.globalAlpha = 0.26; c.drawImage(e.image, ix, iy, iw * scale, ih * scale); c.globalAlpha = 1;
  c.save(); c.beginPath(); c.rect(x, y, fw, fh); c.clip();
  c.fillStyle = newAreaPattern ||= newAreas(c); c.fillRect(x, y, fw, fh);
  c.drawImage(e.image, ix, iy, iw * scale, ih * scale);
  c.restore();
  const frame = $('#expand-frame').style;
  frame.left = `${x}px`; frame.top = `${y}px`; frame.width = `${fw}px`; frame.height = `${fh}px`;
}
/** The checkered fill of the areas that will be generated. */
function newAreas(context) {
  const tile = document.createElement('canvas'); tile.width = tile.height = 16;
  const g = tile.getContext('2d'); g.fillStyle = '#2a2a30'; g.fillRect(0, 0, 16, 16); g.fillStyle = '#222227'; g.fillRect(0, 0, 8, 8); g.fillRect(8, 8, 8, 8);
  return context.createPattern(tile, 'repeat');
}
$('#expand-model').onchange = () => {
  syncModelFields($('#expand-model'), $('#expand-quality'), null);
  for (const side of ['#expand-width', '#expand-height']) $(side).max = String(limitsOf($('#expand-model').value).side);
  updateExpandCost(); renderAction();
};
$('#expand-quality').addEventListener('change', () => updateExpandCost());
function updateExpandCost() {
  const e = viewer.expansion; if (!e) return;
  if (e.crop || expansionKind() === 'crop') { const note = $('#edit-cost'); note.hidden = false; note.textContent = t('No cost: the crop is made on this device.'); note.title = ''; return; }
  showCost('#edit-cost', { model: $('#expand-model').value, quality: $('#expand-quality').value, size: `${e.width}x${e.height}`, previews: editPrefs().previews,
    inputs: [[e.width, e.height]], promptLength: $('#edit-prompt').value.length });
}
for (const id of ['expand-width', 'expand-height']) $('#' + id).onchange = () => {
  const e = viewer.expansion; if (!e) return;
  const w = Number($('#expand-width').value), hh = Number($('#expand-height').value);
  if (!Number.isInteger(w) || !Number.isInteger(hh) || w < 16 || hh < 16) { setError('#edit-error', t('Use whole dimensions, from 16 px up.')); $('#edit-submit').disabled = true; return; }
  const [iw, ih] = imageSize(e);
  if (takeFrame(e.crop ? holdCrop({ ...frameOf(e), width: w, height: hh }, 0, 0, iw, ih) : sizeFrame(frameOf(e), w, hh, iw, ih))) fitExpansion(false); else $('#edit-submit').disabled = true;
};
$('#expand-center').onclick = () => { const e = viewer.expansion; if (!e) return; Object.assign(e, centerFrame(frameOf(e), ...imageSize(e))); fitExpansion(true); };
// A proportion: the largest frame of that shape in the middle of the image, a crop. Dragging the image moves it.
for (const b of $$('#expand-ratios button')) b.onclick = () => {
  const e = viewer.expansion; if (!e) return;
  const [rw, rh] = b.dataset.crop.split(':').map(Number);
  Object.assign(e, ratioFrame(...imageSize(e), rw, rh)); fitExpansion(true);
};
// The frame at the size of the image: where a crop starts from.
$('#expand-fit').onclick = () => { const e = viewer.expansion; if (!e) return; const [iw, ih] = imageSize(e); Object.assign(e, { width: iw, height: ih, x: 0, y: 0 }); fitExpansion(true); };
function beginFrameDrag(type, ev) {
  const e = viewer.expansion; cancelAnimationFrame(e.tween);
  expansionDrag = { type, startX: ev.clientX, startY: ev.clientY, frame: frameOf(e), view: { ...e.view }, imageX: e.view.x + e.x * e.view.scale, imageY: e.view.y + e.y * e.view.scale };
  $('#expand-frame').classList.add('dragging');
}
function frameDragged(ev) {
  const d = expansionDrag, e = viewer.expansion; if (!d || !e) return;
  ev.preventDefault();
  const [iw, ih] = imageSize(e), scale = d.view.scale, dx = (ev.clientX - d.startX) / scale, dy = (ev.clientY - d.startY) / scale, magnet = 8 / scale;
  const frame = e.crop ? (d.type === 'move' ? holdCrop(d.frame, dx, dy, iw, ih) : resizeCrop(d.frame, d.type, dx, dy, iw, ih, magnet))
    : d.type === 'move' ? panFrame(d.frame, dx, dy, iw, ih, magnet) : resizeFrame(d.frame, d.type, dx, dy, iw, ih, magnet);
  if (!takeFrame(frame)) return;
  // A handle moves the frame over the image, which stays where it is; dragging the image moves it under the frame.
  e.view = d.type === 'move' ? { ...d.view } : { scale, x: d.imageX - e.x * scale, y: d.imageY - e.y * scale };
  renderExpansion();
}
function endFrameDrag() {
  if (!expansionDrag) return;
  expansionDrag = null; $('#expand-frame').classList.remove('dragging');
  if (viewer.expansion) fitExpansion(true);
}
$$('#expand-frame [data-corner]').forEach(handle => {
  handle.onpointerdown = ev => { if (!viewer.expansion || ev.button) return; ev.preventDefault(); handle.setPointerCapture(ev.pointerId); beginFrameDrag(handle.dataset.corner, ev); };
  handle.onpointermove = frameDragged;
  handle.onpointerup = handle.onpointercancel = endFrameDrag;
  // The arrow keys move a focused handle 16 px at a time, or 1 px with Shift.
  handle.onkeydown = ev => {
    const e = viewer.expansion, move = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[ev.key];
    if (!e || !move) return;
    ev.preventDefault(); ev.stopPropagation();
    const by = ev.shiftKey ? 1 : 16;
    if (takeFrame((e.crop ? resizeCrop : resizeFrame)(frameOf(e), handle.dataset.corner, move[0] * by, move[1] * by, ...imageSize(e)))) fitExpansion(false);
  };
});
expandCanvas.onpointerdown = ev => { if (!viewer.expansion || ev.button) return; ev.preventDefault(); expandCanvas.setPointerCapture(ev.pointerId); beginFrameDrag('move', ev); };
expandCanvas.onpointermove = frameDragged;
expandCanvas.onpointerup = expandCanvas.onpointercancel = endFrameDrag;
/** Sends what the frame on the stage asks for: a crop is made here and saved, new areas go to the image API. True once done. */
async function submitExpansion() {
  const e = viewer.expansion, item = viewer.item; if (!e || !item) return false;
  const error = expansionError(); if (error) { setError('#edit-error', error); return false; }
  const kind = expansionKind();
  if (kind === 'crop') return submitCrop();
  // The picture as the frame shows it: the part of the image that is kept, and nothing where the new areas are. The
  // mask says which is which.
  const [iw, ih] = imageSize(e), kept = keptArea(e.width, e.height, e.x, e.y, iw, ih);
  const picture = document.createElement('canvas'); picture.width = e.width; picture.height = e.height;
  picture.getContext('2d').drawImage(e.image, e.x, e.y);
  const source = picture.toDataURL('image/png');
  const mask = document.createElement('canvas'); mask.width = e.width; mask.height = e.height;
  const mc = mask.getContext('2d'); mc.fillStyle = '#000'; mc.fillRect(e.x + kept.x, e.y + kept.y, kept.width, kept.height);
  const maskData = mask.toDataURL('image/png');
  if (source.length > 20 * 1024 * 1024 || maskData.length > 5.3 * 1024 * 1024) { setError('#edit-error', t('The prepared image got too large. Make the frame smaller.')); return false; }
  const instructions = boxNotes();
  const model = $('#expand-model').value, quality = $('#expand-quality').value;
  savePrefs({ expand: { model, quality } });
  const options = { ...sourceOptions(item), model, quality, n: 1, size: `${e.width}x${e.height}`, thinking: 'off', previews: editPrefs().previews };
  $('#edit-submit').disabled = true;
  try {
    await submitJob({ prompt: EXPAND_PROMPT + (instructions ? ' Instructions for the new areas: ' + instructions : ''), typed: instructions,
      label: (kind === 'both' ? t('Crop and expansion to {width} × {height}', { width: e.width, height: e.height }) : t('Expansion to {width} × {height}', { width: e.width, height: e.height })) + (instructions ? `: ${instructions}` : ''),
      options, references: [{ data: source, source_id: item.id }], mask: maskData, parent_id: item.id, operation: 'expand' });
    return true;
  } catch (err) { setError('#edit-error', err.message); return false; }
  finally { $('#edit-submit').disabled = false; }
}
/**
 * A crop: the part of the image inside the frame becomes a new version. Nothing goes to the image API. True once saved.
 * From Recortar (open) the cropped image opens, to edit or expand it next.
 */
async function submitCrop(open = false) {
  const e = viewer.expansion, item = viewer.item, button = $('#edit-submit'), label = $('#edit-submit-label');
  const kept = keptArea(e.width, e.height, e.x, e.y, ...imageSize(e));
  button.disabled = true;
  try {
    label.textContent = t('Cropping…');
    const canvas = document.createElement('canvas'); canvas.width = kept.width; canvas.height = kept.height;
    canvas.getContext('2d').drawImage(e.image, -kept.x, -kept.y);
    // Without loss. A photo too large for that is saved as a JPEG of high quality instead.
    let blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
    if (blob && blob.size > 13 * 1024 * 1024) blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.95));
    if (!blob || blob.size > 14 * 1024 * 1024) throw new Error(t('The crop got too large to be saved.'));
    const { image } = await api('import', { data: await blobData(blob), parent_id: item.id, crop: true });
    fresh.add(image.id);
    await Promise.all([refreshAfterJobs(), refreshLineage()]);
    if (open) { await openViewer(image.id, { history: 'replace' }); toast(t('Crop saved as a new version.')); return true; }
    // From Expandir the frame stays on the image it was drawn on, to crop again or change it; the new version is one tap away.
    toast(t('Crop saved as a new version.'), { action: { label: t('View'), run: () => openViewer(image.id, { history: 'replace' }) } });
    return true;
  } catch (err) { setError('#edit-error', err.message); return false; }
  finally { button.disabled = false; renderAction(); }
}

// Upscale: the same picture recreated at a larger size, as a new version
function startUpscale() {
  const item = viewer.item; if (!item) { viewer.action = null; setMode('view'); return; }
  const flexible = modelEntries(m => m.flex);
  const model = [state.prefs.upscale?.model, item.options?.model].find(id => flexible.some(([f]) => f === id)) || DEFAULT_OPTIONS.model;
  fillSelect($('#upscale-model'), flexible, model);
  syncModelFields($('#upscale-model'), $('#upscale-quality'), null, state.prefs.upscale?.quality || 'high');
  renderUpscaleSizes();
  setUpscaleWith(upscaleWithPref());
  updateUpscaleCost();
}
/** The larger sizes the chosen model can bring the image to (a model on the GPU goes further than the image APIs). */
function renderUpscaleSizes() {
  const item = viewer.item; if (!item) return;
  const limits = limitsOf($('#upscale-model').value);
  const sizes = upscaleSizes(item.width, item.height, limits.gpu ? limits : null);
  const preferred = state.prefs.upscale?.scale;
  viewer.upscale = sizes.find(s => s.label === preferred) || sizes.find(s => s.label === '2×') || sizes.at(-1) || null;
  const list = $('#upscale-sizes');
  list.replaceChildren(...sizes.map(size => {
    const button = h('button', { type: 'button', class: 'size-option', 'aria-pressed': String(size === viewer.upscale) },
      h('strong', {}, size.label), h('span', {}, `${size.width} × ${size.height} · ${(size.width * size.height / 1e6).toLocaleString(locale, { maximumFractionDigits: 1 })} MP`));
    button.onclick = () => { viewer.upscale = size; list.querySelectorAll('.size-option').forEach(b => b.setAttribute('aria-pressed', String(b === button))); updateUpscaleCost(); };
    return button;
  }));
  const ratio = item.width / item.height;
  $('#upscale-current').textContent = sizes.length ? t('Current: {width} × {height}.', { width: item.width, height: item.height })
    : ratio > limits.ratio || ratio < 1 / limits.ratio ? (limits.gpu ? t('The model does not make images with an aspect ratio above 5:1.') : t('The API does not generate images with an aspect ratio above 3:1.'))
    : t('This image is already at the largest size the API generates ({width} × {height}).', { width: item.width, height: item.height });
  renderAction();
}
/**
 * What goes along with an upscale: nothing more, the Melhorar cleanup, or the Fotorrealista prompt. Remembered; the
 * switch it replaced (`enhance`) counts as Melhorar.
 */
const UPSCALE_WITH = ['none', 'enhance', 'photoreal'];
function upscaleWithPref() {
  const u = state.prefs.upscale || {};
  return UPSCALE_WITH.includes(u.with) ? u.with : u.enhance ? 'enhance' : 'none';
}
function upscaleWith() { return $('#upscale-with button[aria-pressed="true"]')?.dataset.with || 'none'; }
function setUpscaleWith(value) {
  for (const b of $$('#upscale-with button')) b.setAttribute('aria-pressed', String(b.dataset.with === value));
}
for (const b of $$('#upscale-with button')) b.onclick = () => { setUpscaleWith(b.dataset.with); renderBox(); updateUpscaleCost(); };
$('#upscale-model').onchange = () => { syncModelFields($('#upscale-model'), $('#upscale-quality'), null); renderUpscaleSizes(); updateUpscaleCost(); };
$('#upscale-quality').addEventListener('change', () => updateUpscaleCost());
function updateUpscaleCost() {
  const item = viewer.item, size = viewer.upscale;
  if (!item || !size) { $('#edit-cost').hidden = true; return; }
  showCost('#edit-cost', { model: $('#upscale-model').value, quality: $('#upscale-quality').value, size: `${size.width}x${size.height}`, previews: editPrefs().previews,
    inputs: [[item.width, item.height]], promptLength: upscalePrompt(boxNotes(), upscaleWith()).length });
}
/**
 * Fotorrealista at a larger size: its own prompt, opened by what changes because the result is larger. Its "keep
 * exactly" would not fit after the upscale's "do not reinterpret anything", so it replaces the upscale instruction,
 * and the text of the box goes as its notes, with priority, as in Fotorrealista.
 */
const UPSCALE_PHOTOREAL = 'This is also an upscale: the result is larger than the attached image. Use the extra resolution for a clean, natural photograph at that size, with the same framing, and do not add texture or pores to fill it.';
/**
 * The upscale instruction: with the Melhorar cleanup or as Fotorrealista when asked for, and what the person asked to
 * improve.
 */
function upscalePrompt(instructions, extra) {
  if (extra === 'photoreal') return withNotes(`${UPSCALE_PHOTOREAL}\n\n${PHOTOREAL_PROMPT}`, instructions);
  return UPSCALE_PROMPT + (extra === 'enhance' ? `\n\nAlso clean and improve it:\n${ENHANCE_STEPS}\n\n` : ' ') + (instructions ? 'Requested improvements: ' + instructions : '');
}
function upscaleLabel({ width, height }, extra) {
  return extra === 'enhance' ? t('Upscaled to {width} × {height} and enhanced', { width, height })
    : extra === 'photoreal' ? t('Upscaled to {width} × {height}, photoreal', { width, height }) : t('Upscaled to {width} × {height}', { width, height });
}
/** Sends the upscale to the chosen size; the text of the box says what to improve. True once sent. */
async function submitUpscale() {
  const item = viewer.item, size = viewer.upscale; if (!item || !size) return false;
  const instructions = boxNotes();
  const model = $('#upscale-model').value, quality = $('#upscale-quality').value, extra = upscaleWith();
  savePrefs({ upscale: { model, quality, scale: size.label, with: extra } });
  // Same format and background as the source. Planning would rewrite the instruction, so it stays off.
  const options = { ...sourceOptions(item), format: item.options?.format || FORMAT_OF_MIME[item.mime] || 'png',
    model, quality, n: 1, size: `${size.width}x${size.height}`, thinking: 'off', previews: editPrefs().previews };
  $('#edit-submit').disabled = true;
  try {
    await submitJob({ prompt: upscalePrompt(instructions, extra).trim(), typed: instructions, label: upscaleLabel(size, extra) + (instructions ? `: ${instructions}` : ''),
      options, references: [await sourceReference(item)], parent_id: item.id, operation: 'upscale' });
    return true;
  } catch (err) { setError('#edit-error', err.message); return false; }
  finally { $('#edit-submit').disabled = false; }
}

// ---------------------------------------------------------------------------
// Videos: upload in pieces, a player that moves frame by frame, and frames saved as images
// ---------------------------------------------------------------------------
// The browser reads the video (duration, size, frame rate, a poster) and plays it; the server only stores it, encrypted,
// and serves byte ranges. A frame is drawn on a canvas at the video's own resolution and saved as an image, so editing,
// upscaling and references work on it like on any other image.
const VIDEO_EXTENSIONS = /\.(mp4|m4v|mov|webm|mkv)$/i;
const isVideoFile = file => (file.type || '').startsWith('video/') || VIDEO_EXTENSIONS.test(file.name || '');
const isVideo = item => item?.kind === 'video';
const videoUrl = (id, download = false) => `index.php?action=video&id=${encodeURIComponent(id)}${download ? '&download=1' : ''}`;
const FRAME_LIMIT = 14 * 1024 * 1024; // the import limit of one image
const hasFrameCallback = 'requestVideoFrameCallback' in HTMLVideoElement.prototype;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

/** Resolves on the element's event; rejects on `error` or after `ms`. */
function once(target, event, ms = 20000) {
  return new Promise((resolve, reject) => {
    const finish = (fn, value) => { clearTimeout(timer); target.removeEventListener(event, ok); target.removeEventListener('error', fail); fn(value); };
    const ok = e => finish(resolve, e), fail = () => finish(reject, new Error(event));
    const timer = setTimeout(() => finish(reject, new Error('timeout')), ms);
    target.addEventListener(event, ok); target.addEventListener('error', fail);
  });
}

/** What the upload and the player need, read in the browser: dimensions, duration, frame rate (MP4) and a poster. */
async function prepareVideo(file) {
  const src = URL.createObjectURL(file);
  const probe = document.createElement('video');
  probe.muted = true; probe.preload = 'auto'; probe.playsInline = true; probe.src = src;
  try {
    await once(probe, 'loadeddata');
    // Recorded WebM files only learn their duration once the end is read.
    if (!Number.isFinite(probe.duration)) { probe.currentTime = 1e7; await once(probe, 'seeked', 8000).catch(() => {}); }
    const { videoWidth: width, videoHeight: height, duration } = probe;
    if (!width || !height || !(duration > 0) || !Number.isFinite(duration)) throw new Error('shape');
    let timing = null;
    try { timing = await mp4Timing((offset, length) => file.slice(offset, offset + length).arrayBuffer(), file.size); } catch { /* not an MP4: measured when it plays */ }
    const fps = timing ? snapFps(timing.fps) : null;
    probe.currentTime = Math.min(duration * 0.1, 3);
    await once(probe, 'seeked', 8000);
    const scale = Math.min(1, 1280 / Math.max(width, height));
    const canvas = document.createElement('canvas'); canvas.width = Math.round(width * scale); canvas.height = Math.round(height * scale);
    canvas.getContext('2d').drawImage(probe, 0, 0, canvas.width, canvas.height);
    return { src, width, height, duration, fps, fpsSource: fps ? 'file' : null, poster: canvas.toDataURL('image/jpeg', 0.85) };
  } catch {
    URL.revokeObjectURL(src);
    throw new Error(t('{name}: this browser cannot open the video. Use MP4 (H.264), MOV or WebM, or convert the file.', { name: file.name || t('Video') }));
  } finally { probe.removeAttribute('src'); probe.load(); }
}

// Uploads: { key, file, title, info, src, id, piece, sent, status, error, image, created }. The status is uploading,
// finishing, done, failed, or local: a video that stays only in this browser (too large, uploads off, or cancelled),
// which can still be watched and have frames saved.
async function importVideo(file, { open = false } = {}) {
  toast(open ? t('Opening the video…') : t('Preparing {name}…', { name: file.name || t('the video') }));
  let info;
  try { info = await prepareVideo(file); } catch (e) { toast(e.message, { tone: 'error' }); return; }
  if (!state.unlocked) { URL.revokeObjectURL(info.src); return; }
  hideToast();
  const upload = { key: newId(), file, title: (file.name || t('Video')).replace(/\.[A-Za-z0-9]{1,5}$/, '') || t('Video'), info, src: info.src, id: null, piece: 0, sent: 0,
    status: 'uploading', error: null, image: null, created: now() };
  const max = state.maxVideoBytes;
  let reason = !max ? t('Video uploads are turned off on this server (max_video_mb in config.php).')
    : file.size > max ? t('The video is {file} and this server\'s limit is {max} (max_video_mb in config.php).', { file: megabytes(file.size), max: megabytes(max) }) : '';
  if (!reason) {
    try { const started = await api('video-start', { size: file.size }); upload.id = started.id; upload.piece = started.piece; }
    catch (e) { reason = e.message; }
  }
  if (reason) { upload.status = 'local'; upload.error = reason; }
  if (!open && reason) { URL.revokeObjectURL(upload.src); toast(`${file.name}: ${reason}`, { tone: 'error' }); return; }
  state.uploads.set(upload.key, upload);
  if (open) openLocalVideo(upload);
  if (!reason) runUpload(upload);
  scheduleRender();
}

async function runUpload(upload) {
  const generation = state.generation, size = upload.file.size;
  Object.assign(upload, { status: 'uploading', error: null, cancelled: false, controller: new AbortController() });
  uploadChanged(upload);
  try {
    let stalls = 0;
    while (upload.sent < size) {
      const offset = upload.sent;
      const received = await sendPiece(upload, offset, upload.file.slice(offset, Math.min(size, offset + upload.piece)));
      if (generation !== state.generation || upload.cancelled) return;
      stalls = received > offset ? 0 : stalls + 1;
      if (stalls > 3) throw new Error(t('The server is not storing the video.'));
      upload.sent = received; uploadChanged(upload);
    }
    upload.status = 'finishing'; uploadChanged(upload);
    const { info } = upload;
    const { image } = await api('video-finish', { id: upload.id, name: upload.file.name, duration: info.duration, width: info.width, height: info.height, fps: info.fps, poster: info.poster });
    if (generation !== state.generation || upload.cancelled) return;
    upload.status = 'done'; upload.image = image; fresh.add(image.id);
    if (viewer.item?.upload === upload) adoptUpload(upload);
    else toast(t('{title}: video saved to the gallery.', { title: upload.title }), { action: { label: t('Open'), run: () => openViewer(image.id) } });
    await refreshAfterJobs();
    upload.settled = true; scheduleRender();
  } catch (e) {
    if (generation !== state.generation || upload.cancelled) return;
    upload.status = 'failed'; upload.error = e.message; uploadChanged(upload);
    if (viewer.item?.upload !== upload) toast(`${upload.title}: ${e.message}`, { tone: 'error' });
  }
}

/** Sends one piece and returns how many bytes the server holds. Network failures and server errors are retried. */
async function sendPiece(upload, offset, body) {
  for (let attempt = 0; ; attempt++) {
    let response, result = {};
    try {
      response = await fetch(`index.php?action=video-chunk&id=${upload.id}&offset=${offset}`, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
        signal: upload.controller.signal, headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': state.csrf }, body });
      result = await response.json().catch(() => ({}));
    } catch (e) {
      if (upload.cancelled) throw e;
      if (attempt < 4) { await sleep(1000 * 2 ** attempt); continue; }
      throw new ApiError(t('The connection dropped while uploading the video.'), 0, true);
    }
    if (response.ok && Number.isInteger(result.received)) { resetIdle(); return result.received; }
    if (response.status === 401) { lockUI(); throw new ApiError(t('Unlock your studio.'), 401); }
    if ((response.status >= 500 || !response.status) && response.status !== 507 && attempt < 4) { await sleep(1000 * 2 ** attempt); continue; }
    throw new ApiError(result.error || t('The server answered with error {status} while receiving the video.', { status: response.status }), response.status);
  }
}

async function cancelUpload(upload) {
  if (!['uploading', 'finishing', 'failed'].includes(upload.status)) return;
  if (upload.status !== 'failed' && !await confirmAction({ title: t('Cancel the upload?'), message: t('The video does not stay in the gallery. The frames already saved stay there.'), confirm: t('Cancel upload'), danger: true })) return;
  upload.cancelled = true; upload.controller?.abort();
  if (upload.id) api('video-abort', { id: upload.id }).catch(() => {});
  // Still open here, it keeps playing from the computer.
  Object.assign(upload, { status: 'local', error: t('Upload cancelled: the video is open from your computer and was not saved to the gallery.') });
  uploadChanged(upload);
}
function uploadChanged(upload) {
  scheduleRender();
  if (viewer.item?.upload === upload) renderUploadBox();
}
/** Drops uploads nobody needs to see any more and frees their file from memory. */
function pruneUploads() {
  for (const [key, upload] of state.uploads) {
    if (viewerDialog.open && viewer.item?.upload === upload) continue;
    if (upload.status === 'local' || (upload.status === 'done' && upload.settled)) { state.uploads.delete(key); URL.revokeObjectURL(upload.src); }
  }
}
/** The video the viewer shows from the computer is now in the gallery: same player, with the library's actions. */
function adoptUpload(upload) {
  viewer.item = { ...upload.image, src: upload.src, upload };
  viewer.id = upload.image.id;
  history.replaceState(history.state, '', `#v/${upload.image.id}`);
  renderViewerHeader(); renderVideoPanel(); loadFrames();
  toast(t('Video saved to the gallery.'));
}

function uploadTile(upload) {
  if (!['uploading', 'finishing', 'failed', 'done'].includes(upload.status)) return null;
  // A finished upload keeps its place until the gallery shows the video, so nothing blinks out.
  if (upload.status === 'done' && state.gallery.ids.has(upload.image.id)) return null;
  const key = `upload:${upload.key}`;
  let tile = tileCache.get(key);
  if (!tile) {
    tile = h('div', { class: 'tile job upload has-preview' }, h('img', { class: 'preview loaded', alt: '', src: upload.info.poster }));
    tile.key = key; tileCache.set(key, tile);
  }
  tile.aspect = Math.min(3, Math.max(1 / 3, upload.info.width / upload.info.height));
  const failed = upload.status === 'failed';
  tile.classList.toggle('failed', failed); tile.classList.toggle('has-preview', !failed);
  $('img.preview', tile).hidden = failed;
  const structure = failed ? `failed:${upload.error}` : 'active';
  if (tile.dataset.structure !== structure) {
    tile.dataset.structure = structure;
    const open = h('button', { type: 'button', class: 'tile-open', 'aria-label': t('Open {title}', { title: upload.title }), onclick: () => openLocalVideo(upload) });
    const body = failed
      ? h('div', { class: 'job-body problem' }, h('div', { class: 'job-status' }, icon('alert'), h('span', { class: 'phase' }, t('The upload failed')),
        h('button', { type: 'button', class: 'icon-btn small', 'aria-label': t('Dismiss upload'), title: t('Dismiss'), onclick: () => { upload.status = 'local'; pruneUploads(); scheduleRender(); } }, icon('close'))),
        h('p', { class: 'job-error' }, upload.error), h('p', { class: 'job-prompt' }, upload.title),
        h('div', { class: 'job-actions' }, h('button', { type: 'button', class: 'btn secondary', onclick: () => runUpload(upload) }, icon('redo'), t('Try again'))))
      : h('div', { class: 'job-body' },
        h('div', { class: 'job-status' }, h('span', { class: 'spinner small' }), h('span', { class: 'phase' }), h('span', { class: 'job-time' }),
          h('button', { type: 'button', class: 'icon-btn small', 'aria-label': t('Cancel the upload'), title: t('Cancel the upload'), onclick: () => cancelUpload(upload) }, icon('close'))),
        h('div', { class: 'job-footer' }, h('span', { class: 'job-kind' }, icon('video'), t('Video')), h('p', { class: 'job-prompt' }, upload.title), h('div', { class: 'progress' }, h('span'))));
    tile.replaceChildren($('img.preview', tile), open, body);
  }
  if (!failed) {
    const pct = upload.file.size ? Math.floor(upload.sent / upload.file.size * 100) : 0;
    setText($('.phase', tile), upload.status === 'uploading' ? t('Uploading {pct}%', { pct }) : t('Saving'));
    setText($('.job-time', tile), t('{sent} of {file}', { sent: megabytes(upload.sent), file: megabytes(upload.file.size) }));
    $('.progress span', tile).style.width = `${pct}%`;
    $('.job-status .icon-btn', tile).hidden = upload.status !== 'uploading';
  }
  return tile;
}

function localItem(upload) {
  const { info } = upload;
  return { id: upload.id, kind: 'video', local: true, upload, src: upload.src, title: upload.title, width: info.width, height: info.height, duration: info.duration,
    fps: info.fps, fpsSource: info.fpsSource, size: upload.file.size, format: (upload.file.name.match(VIDEO_EXTENSIONS)?.[1] || t('video')).toUpperCase(), created: Math.floor(upload.created), favorite: false };
}
/** Opens a video straight from the computer while it uploads (or one that stays only here). */
/** The dialog starts on its back button, where Space would close it; a video starts on its play button instead. */
function focusPlayer() {
  // In full screen nothing takes the focus, so the video controls stay hidden.
  if (viewerDialog.classList.contains('immersive')) return;
  const active = document.activeElement;
  if (!viewerDialog.contains(active) || active === $('#viewer-close') || active?.disabled) $('#video-play').focus({ preventScroll: true });
}
async function openLocalVideo(upload) {
  if (upload.image) { openViewer(upload.image.id); return; }
  const opening = !viewerDialog.open;
  if (opening) { viewerDialog.showModal(); viewer.pushed = false; }
  leaveStageMode();
  const token = ++viewer.token;
  Object.assign(viewer, { item: localItem(upload), lineage: null, compare: false, frames: [] });
  viewer.id = viewer.item.id;
  if (opening) { history.pushState({ viewer: true }, '', '#v/local'); viewer.pushed = true; } else history.replaceState(history.state, '', '#v/local');
  resetZoom(); renderViewer(); loadFrames(); focusPlayer();
  $('#stage-loading').hidden = false;
  try { await loadVideo(viewer.item); } finally { if (token === viewer.token) $('#stage-loading').hidden = true; }
}

// The player. Which frame is on screen comes from requestVideoFrameCallback (the frame's own timestamp) where the browser
// has it, otherwise from currentTime. Steps seek to the middle of the wanted frame; seeks that arrive while one is running
// are merged into the last one, so holding an arrow key or dragging the bar never queues up stale seeks.
const stageVideo = $('#stage-video');
const player = { token: 0, fps: 30, fpsSource: 'default', detected: null, frames: 1, duration: 0, presented: null, target: null, pendingTime: null, callback: 0, scrubbing: false };
function stopVideo() {
  player.token++;
  if (player.callback) stageVideo.cancelVideoFrameCallback?.(player.callback);
  player.callback = 0; clearTimeout(timers.videoSettle);
  if (stageVideo.getAttribute('src')) { stageVideo.pause(); stageVideo.removeAttribute('src'); stageVideo.load(); }
}
async function loadVideo(item, { at = null } = {}) {
  stopVideo();
  const token = player.token;
  Object.assign(player, { fps: item.fps || 30, fpsSource: item.fps ? item.fpsSource || 'file' : 'default', detected: item.fps || null, duration: item.duration || 0,
    presented: null, target: null, pendingTime: null, scrubbing: false });
  player.frames = frameCount(player.duration, player.fps);
  // Videos start playing at once, muted and in a loop. Opened at a saved frame, the video waits on that frame.
  stageVideo.muted = true; stageVideo.loop = true;
  stageVideo.defaultPlaybackRate = stageVideo.playbackRate = Number($('#video-rate').value) || 1;
  stageVideo.src = item.src || videoUrl(item.id);
  renderVideoPanel(); renderPlayer();
  try { await once(stageVideo, 'loadeddata', 60000); }
  catch { if (token === player.token) toast(t('Could not load the video in this browser.'), { tone: 'error' }); return false; }
  if (token !== player.token) return false;
  if (Number.isFinite(stageVideo.duration) && stageVideo.duration > 0) player.duration = stageVideo.duration;
  if (!item.fps) {
    const measured = await measureFps(token);
    if (token !== player.token) return false;
    if (measured) {
      Object.assign(player, { fps: measured, fpsSource: 'measured', detected: measured });
      // A video still uploading sends the measured rate with its details.
      if (item.upload && !item.upload.info.fps) Object.assign(item.upload.info, { fps: measured, fpsSource: 'measured' });
    }
  }
  player.frames = frameCount(player.duration, player.fps);
  fitStage(); watchFrames();
  // A frame picked with the keys while the video loaded is kept.
  if (at !== null || player.target !== null) seekFrame(at !== null ? frameAt(at + 0.25 / player.fps, player.fps) : Math.min(player.target, player.frames - 1));
  else { if (stageVideo.currentTime > 0) stageVideo.currentTime = 0; player.target = null; stageVideo.play().catch(() => {}); }
  renderVideoPanel();
  return true;
}
/**
 * Frame rate of a file that doesn't state it, from the timestamps of the frames shown after seeks. One frame step is
 * only as exact as the container's timestamps (WebM keeps milliseconds), so the rate is refined over spans of about 10
 * and then 100 frames, counting the frames in each span with the estimate so far.
 */
async function measureFps(token) {
  if (!hasFrameCallback || !(stageVideo.duration > 0.3)) return null;
  const shown = time => new Promise(resolve => {
    let handle = 0, tries = 0;
    const timer = setTimeout(() => { stageVideo.cancelVideoFrameCallback(handle); resolve(null); }, 2000);
    const watch = () => { handle = stageVideo.requestVideoFrameCallback((now, meta) => {
      // A frame painted before this seek (the first one, after loading) is not the answer.
      if (Math.abs(meta.mediaTime - time) > 0.25 && ++tries < 4) { watch(); return; }
      clearTimeout(timer); resolve(meta.mediaTime);
    }); };
    watch(); stageVideo.currentTime = time;
  });
  const duration = stageVideo.duration;
  const start = await shown(Math.min(duration / 4, 1));
  if (start === null || token !== player.token) return null;
  // 2 ms back: the browser rounds seeks to the millisecond, and no common rate has frames that short.
  const before = await shown(Math.max(0, start - 0.002));
  if (before === null || token !== player.token || !(start - before > 0.003)) return null;
  let fps = 1 / (start - before);
  for (const span of [10, 100]) {
    if (start + (span + 1) / fps > duration) break;
    const later = await shown(start + (span + 0.5) / fps);
    if (later === null || token !== player.token) break;
    const frames = Math.round((later - start) * fps);
    if (frames < span / 2) break;
    fps = frames / (later - start);
  }
  return snapFps(fps);
}
function watchFrames() {
  if (!hasFrameCallback) return;
  const token = player.token;
  player.callback = stageVideo.requestVideoFrameCallback((now, meta) => {
    if (token !== player.token) return;
    player.presented = meta.mediaTime;
    // The frame of the last seek is on screen: the readout follows the picture again.
    if (!stageVideo.seeking && player.pendingTime === null) player.target = null;
    renderPlayer(); watchFrames();
  });
}
function shownFrame() {
  const time = hasFrameCallback && player.presented !== null ? player.presented : stageVideo.currentTime;
  return Math.min(player.frames - 1, frameAt(time, player.fps));
}
const currentFrame = () => player.target ?? shownFrame();
function seekTime(time) {
  const end = (player.duration || stageVideo.duration || 0) - 0.001;
  time = Math.max(0, Math.min(time, end > 0 ? end : 0));
  if (stageVideo.seeking) { player.pendingTime = time; return; }
  player.pendingTime = null;
  stageVideo.currentTime = time;
}
function seekFrame(index) {
  if (!stageVideo.getAttribute('src')) return;
  index = Math.max(0, Math.min(player.frames - 1, Math.round(index)));
  if (!stageVideo.paused) stageVideo.pause();
  player.target = index;
  seekTime(frameTime(index, player.fps));
  renderPlayer();
}
const stepFrames = delta => seekFrame(currentFrame() + delta);
function togglePlay() {
  if (!stageVideo.getAttribute('src')) return;
  if (stageVideo.paused || stageVideo.ended) { player.target = null; stageVideo.play().catch(() => {}); } else stageVideo.pause();
}
stageVideo.addEventListener('seeked', () => {
  if (player.pendingTime !== null) { const time = player.pendingTime; player.pendingTime = null; stageVideo.currentTime = time; return; }
  // A seek inside the frame already shown paints nothing new: settle the readout after a moment.
  clearTimeout(timers.videoSettle);
  timers.videoSettle = setTimeout(() => { if (!stageVideo.seeking && player.pendingTime === null && player.target !== null) { player.target = null; renderPlayer(); } }, hasFrameCallback ? 250 : 0);
  renderPlayer();
});
for (const event of ['play', 'pause', 'ended', 'volumechange']) stageVideo.addEventListener(event, () => renderPlayer());
stageVideo.addEventListener('timeupdate', () => { if (!hasFrameCallback) renderPlayer(); });
stageVideo.addEventListener('loadedmetadata', () => { if (isVideo(viewer.item)) fitStage(); });

function renderPlayer() {
  if (!isVideo(viewer.item)) return;
  const n = currentFrame(), total = player.frames;
  setText($('#video-frame'), t('Frame {n} of {total}', { n: number(n + 1), total: number(total) }));
  setText($('#video-time'), `${timecode(n / player.fps)} / ${timecode(player.duration)}`);
  const scrub = $('#video-scrub');
  if (scrub.max !== String(total - 1)) scrub.max = String(total - 1);
  if (!player.scrubbing) scrub.value = String(n);
  scrub.style.setProperty('--progress', `${total > 1 ? n / (total - 1) * 100 : 0}%`);
  setText($('#frame-label'), t('Frame {n}', { n: number(n + 1) })); setText($('#frame-at'), timecode(n / player.fps));
  const playing = !stageVideo.paused && !stageVideo.ended, play = $('#video-play');
  if (play.dataset.state !== String(playing)) {
    play.dataset.state = String(playing); play.replaceChildren(icon(playing ? 'pause' : 'play'));
    play.setAttribute('aria-label', playing ? t('Pause') : t('Play')); play.title = playing ? t('Pause (Space)') : t('Play (Space)');
  }
  const mute = $('#video-mute');
  if (mute.dataset.state !== String(stageVideo.muted)) {
    mute.dataset.state = String(stageVideo.muted); mute.replaceChildren(icon(stageVideo.muted ? 'volumeOff' : 'volume'));
    mute.setAttribute('aria-pressed', String(stageVideo.muted)); mute.setAttribute('aria-label', stageVideo.muted ? t('Turn sound on') : t('Muted'));
  }
  for (const node of $('#frames-list').children) node.classList?.toggle('current', node.dataset.frame === String(n));
}
// Buttons keep the focus where it was, so Space still plays and pauses after a click.
$$('.video-controls button').forEach(button => button.addEventListener('mousedown', e => e.preventDefault()));
$('#video-first').onclick = () => seekFrame(0);
$('#video-last').onclick = () => seekFrame(player.frames - 1);
$('#video-back').onclick = () => stepFrames(-1);
$('#video-forward').onclick = () => stepFrames(1);
$('#video-back10').onclick = () => stepFrames(-10);
$('#video-forward10').onclick = () => stepFrames(10);
$('#video-play').onclick = togglePlay;
$('#video-mute').onclick = () => { stageVideo.muted = !stageVideo.muted; };
$('#video-rate').onchange = () => { stageVideo.defaultPlaybackRate = stageVideo.playbackRate = Number($('#video-rate').value) || 1; };
$('#video-scrub').addEventListener('input', e => { player.scrubbing = true; seekFrame(Number(e.target.value)); });
$('#video-scrub').addEventListener('change', () => { player.scrubbing = false; renderPlayer(); });
function videoKey(e) {
  const onButton = e.target.closest?.('button, a, summary');
  // Comma and period step one frame and, with Shift (< and >), ten; the arrows move between items as for images (up and
  // down, between the items of its stack).
  const keys = {
    ArrowLeft: () => navigate(-1), ArrowRight: () => navigate(1), ArrowUp: () => navigateVersion(-1), ArrowDown: () => navigateVersion(1), ',': () => stepFrames(-1), '.': () => stepFrames(1), '<': () => stepFrames(-10), '>': () => stepFrames(10),
    Home: () => seekFrame(0), End: () => seekFrame(player.frames - 1), k: togglePlay, m: () => $('#video-mute').click(),
    ' ': onButton ? null : togglePlay, f: () => $('#viewer-favorite').click(), t: () => setImmersive(!viewerDialog.classList.contains('immersive')),
    '+': () => zoomBy(1.5), '=': () => zoomBy(1.5), '-': () => zoomBy(1 / 1.5), '0': () => resetZoom({ animate: true }),
  };
  const run = keys[e.key];
  if (!run) return;
  e.preventDefault(); run();
}

// Inspector: details, frame rate and upload progress
function renderVideoPanel() {
  const item = viewer.item; if (!isVideo(item)) return;
  const format = item.format || (item.mime === 'video/webm' ? 'WebM' : 'MP4');
  const rows = [[t('Duration'), timecode(player.duration || item.duration)], [t('Resolution'), `${item.width} × ${item.height}`], ['Frames', number(player.frames)],
    [t('File'), `${format} · ${megabytes(item.size || 0)}`]];
  if (!item.local) rows.push([t('Sent'), dateTime(item.created)]);
  $('#video-details').replaceChildren(...rows.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]));
  $('#video-from-part').hidden = !item.id || !!item.local;
  const rates = [...new Set([player.fps, ...(player.detected ? [player.detected] : []), ...COMMON_RATES.map(snapFps)])].sort((a, b) => a - b);
  fillSelect($('#video-fps'), rates.map(rate => [String(rate), `${fpsLabel(rate)}${rate === player.detected ? (player.fpsSource === 'manual' ? ' (detectado)' : '') : ''}`]), String(player.fps));
  $('#video-fps-hint').textContent = { file: t('Read from the file.'), measured: t('Measured in the browser.'), manual: t('Chosen by you. Go back to the detected value if frames skip or repeat.') }[player.fpsSource]
    || t('Could not detect it; using 30. If the arrows skip or repeat frames, choose the right rate.');
  renderUploadBox();
}
$('#video-fps').onchange = () => {
  const time = currentFrame() / player.fps;
  Object.assign(player, { fps: Number($('#video-fps').value), fpsSource: Number($('#video-fps').value) === player.detected ? 'file' : 'manual' });
  if (player.fpsSource === 'file' && viewer.item?.fpsSource === 'measured') player.fpsSource = 'measured';
  player.frames = frameCount(player.duration, player.fps);
  seekFrame(frameAt(time + 0.25 / player.fps, player.fps));
  renderVideoPanel();
};
function renderUploadBox() {
  const upload = viewer.item?.upload, box = $('#video-upload');
  box.hidden = !upload || !!upload.image;
  if (box.hidden) return;
  const size = upload.file.size, pct = size ? Math.floor(upload.sent / size * 100) : 0;
  const text = { uploading: t('Saving to the gallery: {pct}% ({sent} of {size}). You can already browse and save frames.', { pct, sent: megabytes(upload.sent), size: megabytes(size) }), finishing: t('Finishing the upload…'),
    failed: t('The upload to the gallery failed: {error}', { error: upload.error }) }[upload.status] || upload.error || '';
  setText($('#video-upload-text'), text);
  box.dataset.tone = upload.status === 'failed' || upload.status === 'local' ? 'warn' : '';
  $('#video-upload-bar').style.width = `${upload.status === 'local' ? 0 : pct}%`;
  $('#video-upload-bar').parentElement.hidden = upload.status === 'local';
  const action = $('#video-upload-action');
  action.hidden = !['uploading', 'failed'].includes(upload.status);
  action.textContent = upload.status === 'failed' ? t('Try again') : t('Cancel upload');
  action.onclick = () => (upload.status === 'failed' ? runUpload(upload) : cancelUpload(upload));
}

// Frames saved from this video, in order. A click goes to that frame; the arrow opens the saved image.
async function loadFrames() {
  const item = viewer.item, token = viewer.token;
  if (!isVideo(item) || !item.id) { viewer.frames = []; renderFrames(); return; }
  try {
    const { frames } = await api('frames', undefined, { id: item.id });
    if (token !== viewer.token || viewer.item?.id !== item.id) return;
    viewer.frames = frames; renderFrames();
  } catch { /* the list stays as it was */ }
}
const savedFrameIndex = frame => frameAt((frame.source?.time || 0) + 0.25 / player.fps, player.fps);
function renderFrames() {
  const list = $('#frames-list'), frames = viewer.frames || [];
  $('#frames-count').textContent = frames.length ? number(frames.length) : '';
  if (!frames.length) {
    list.replaceChildren(h('p', { class: 'hint frames-empty' }, t('No frames saved yet. Find the frame with the arrows or the bar and use the buttons beside it to save it, edit it, upscale it or use it as a reference.')));
    return;
  }
  list.replaceChildren(...frames.map(frame => {
    const index = savedFrameIndex(frame);
    return h('div', { class: 'vnode frame-node', 'data-frame': String(index) },
      h('button', { type: 'button', class: 'frame-seek', title: t('Go to frame {n}', { n: number(index + 1) }), onclick: () => seekFrame(index) },
        h('span', { class: 'vthumb' }, versionThumb(frame.id)),
        h('span', { class: 'vtext' }, h('span', { class: 'vtitle' }, h('span', { class: 'vnum' }, t('Frame {n}', { n: number(index + 1) }))),
          h('span', { class: 'vsub' }, `${timecode(frame.source?.time)}${frame.versions > 1 ? ` · ${plural(frame.versions, t('{n} version'), t('{n} versions'))}` : ''}`))),
      h('button', { type: 'button', class: 'icon-btn small', title: t('Open the image to edit or upscale it'), 'aria-label': t('Open frame {n}', { n: number(index + 1) }),
        onclick: () => openViewer(frame.id, { history: 'replace' }) }, icon('chevron-right')));
  }));
  renderPlayer();
}

// Saving the frame on screen
const canvasBlob = (canvas, type, quality) => new Promise(resolve => canvas.toBlob(resolve, type, quality));
const blobData = blob => new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(r.result); r.onerror = reject; r.readAsDataURL(blob); });
/** The frame on screen at the video's resolution: PNG, or a near-lossless WebP/JPEG when the PNG passes the import limit. */
async function captureFrame() {
  if (!stageVideo.paused) stageVideo.pause();
  for (let i = 0; i < 60 && (stageVideo.seeking || player.pendingTime !== null || player.target !== null); i++) await sleep(50);
  const width = stageVideo.videoWidth, height = stageVideo.videoHeight;
  if (!width || !height || stageVideo.readyState < 2) throw new Error(t('The video is still loading.'));
  const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
  canvas.getContext('2d').drawImage(stageVideo, 0, 0, width, height);
  const index = currentFrame();
  let blob = await canvasBlob(canvas, 'image/png');
  if (blob && blob.size > FRAME_LIMIT) { const webp = await canvasBlob(canvas, 'image/webp', 0.97); blob = webp?.type === 'image/webp' && webp.size <= FRAME_LIMIT ? webp : await canvasBlob(canvas, 'image/jpeg', 0.95); }
  if (!blob || blob.size > FRAME_LIMIT) throw new Error(t('The frame got too large to save (limit of 14 MB).'));
  return { blob, index, time: index / player.fps };
}
/** Saves the frame on screen to the gallery, or reuses the copy saved before. */
async function saveFrame() {
  const item = viewer.item, shot = await captureFrame();
  const saved = (viewer.frames || []).find(frame => savedFrameIndex(frame) === shot.index);
  if (saved) return { image: saved, existed: true };
  const { image } = await api('import', { data: await blobData(shot.blob), name: `${item.title || t('Video')} · frame ${shot.index + 1}`,
    source: { video_id: item.id || null, frame: shot.index, time: Number(shot.time.toFixed(6)) } });
  fresh.add(image.id); refreshAfterJobs(); loadFrames();
  return { image, existed: false };
}
async function frameAction(label, run) {
  if (!isVideo(viewer.item)) return;
  const buttons = $$('.frame-actions button');
  buttons.forEach(b => { b.disabled = true; });
  setText($('#frame-status'), label);
  try { await run(); }
  catch (e) { toast(e.message, { tone: 'error' }); }
  finally { buttons.forEach(b => { b.disabled = false; }); setText($('#frame-status'), ''); }
}
$('#frame-save').onclick = () => frameAction(t('Saving the frame…'), async () => {
  const { image, existed } = await saveFrame(), n = number((image.source?.frame ?? 0) + 1);
  toast(existed ? t('Frame {n} was already in the gallery.', { n }) : t('Frame {n} saved to the gallery.', { n }), { action: { label: t('Open'), run: () => openViewer(image.id, { history: 'replace' }) } });
});
$('#frame-edit').onclick = () => frameAction(t('Saving the frame…'), async () => {
  const { image } = await saveFrame();
  await openViewer(image.id, { history: 'replace' });
  if (viewer.item?.id === image.id) $('#edit-prompt').focus();
});
$('#frame-upscale').onclick = () => frameAction(t('Saving the frame…'), async () => {
  const { image } = await saveFrame();
  await openViewer(image.id, { history: 'replace' });
  if (viewer.item?.id === image.id) setAction('upscale');
});
$('#frame-reference').onclick = () => frameAction(t('Saving the frame…'), async () => { addReference((await saveFrame()).image); });
// A new video that starts on the frame on screen: the frame goes to the gallery first, as for an edit.
$('#frame-animate').onclick = () => frameAction(t('Saving the frame…'), async () => {
  const { image } = await saveFrame();
  gpuUi.open({ keys: [{ at: 0, id: image.id, width: image.width, height: image.height }], album: viewer.item?.album || null });
});
// A new video from the one on screen: continue it, follow its movement or replace a part (gpu.js).
$('#video-from').onclick = () => { const item = viewer.item; if (isVideo(item) && item.id) gpuUi.open({ source: { id: item.id, width: item.width, height: item.height, duration: player.duration || item.duration }, album: item.album || null }); };
$('#frame-download').onclick = () => frameAction(t('Preparing the frame…'), async () => {
  const shot = await captureFrame(), url = URL.createObjectURL(shot.blob);
  const ext = { 'image/png': 'png', 'image/webp': 'webp', 'image/jpeg': 'jpg' }[shot.blob.type] || 'png';
  const link = h('a', { href: url, download: `${(viewer.item.title || 'video').replace(/[^\p{L}\p{N}_-]+/gu, '-')}-frame-${shot.index + 1}.${ext}` });
  document.body.append(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
});
window.addEventListener('beforeunload', e => {
  if ([...state.uploads.values()].some(u => u.status === 'uploading' || u.status === 'finishing')) { e.preventDefault(); e.returnValue = ''; }
});

// ---------------------------------------------------------------------------
// Requests list, settings
// ---------------------------------------------------------------------------
const STATUS = { queued: t('Queued'), running: t('Generating'), cancelling: t('Cancelling'), completed: t('Completed'), failed: t('Failed'), uncertain: t('Interrupted'), cancelled: t('Cancelled') };
// The history of prompts: everything asked for, newest first. Image requests (also the illustrations, covers, character
// sheets and comic pages of the stories) and the messages to the writer. It opens at once and fills a page at a time
// (the server reads only the light part of each request); a search, a kind or a story narrows it, and the server reads
// on page after page while the end of the list is in view.
const historyView = { kind: 'all', q: '', story: null, items: [], shown: 0, next: null, done: false, loading: false, error: '', token: 0 };
const historyDialog = $('#requests-dialog'), historyList = $('#requests-list'), historyFoot = $('#history-foot');
const historyEnd = new IntersectionObserver(entries => { if (entries.some(e => e.isIntersecting) && historyDialog.open) loadHistory(); }, { root: historyDialog, rootMargin: '0px 0px 400px 0px' });
function showRequests() { openHistory(); }
/** story: { id, title } shows only what was asked in that story (its images and the messages to its writer). */
function openHistory({ story = null } = {}) {
  historyView.story = story; historyView.q = ''; $('#history-search').value = '';
  renderHistoryBar();
  if (!historyDialog.open) historyDialog.showModal();
  loadHistory({ reset: true });
}
function renderHistoryBar() {
  for (const b of $$('#history-kind button')) b.setAttribute('aria-pressed', String(b.dataset.kind === historyView.kind));
  const chip = $('#history-story'), s = historyView.story;
  chip.hidden = !s;
  chip.replaceChildren(...(s ? [icon('book'), h('span', { text: t('Only from the story “{title}”', { title: s.title || t('Untitled') }) }),
    h('button', { type: 'button', class: 'icon-btn small', title: t('Show all'), 'aria-label': t('Show the whole history'), onclick: () => { historyView.story = null; renderHistoryBar(); loadHistory({ reset: true }); } }, icon('close'))] : []));
}
async function loadHistory({ reset = false } = {}) {
  if (reset) { historyView.token++; Object.assign(historyView, { items: [], shown: 0, next: null, done: false, loading: false, error: '' }); historyList.replaceChildren(); }
  else if (historyView.loading || historyView.done || historyView.error) return;
  const token = historyView.token; historyView.loading = true; renderHistory();
  try {
    const query = { kind: historyView.kind };
    if (historyView.q) query.q = historyView.q;
    if (historyView.story) query.story = historyView.story.id;
    if (historyView.next) query.cursor = historyView.next;
    const { items, next, now: serverNow } = await api('history', undefined, query);
    if (token !== historyView.token) return;
    state.skew = serverNow - Date.now() / 1000;
    historyView.items.push(...items); historyView.next = next; historyView.done = !next;
  } catch (e) { if (token === historyView.token) historyView.error = e.message; }
  finally {
    if (token === historyView.token) {
      historyView.loading = false; renderHistory();
      // The end still in view (a short page, a search that found little): the next page comes on its own.
      historyEnd.unobserve(historyFoot); if (!historyView.done && !historyView.error) historyEnd.observe(historyFoot);
    }
  }
}
function renderHistory() {
  historyList.append(...historyView.items.slice(historyView.shown).map(historyItem));
  historyView.shown = historyView.items.length;
  const v = historyView, searching = v.q !== '';
  let foot;
  if (v.loading) foot = [h('span', { class: 'spinner small' }), h('span', { text: searching ? t('Searching…') : t('Loading…') })];
  else if (v.error) foot = [h('span', { class: 'error', text: v.error }), h('button', { type: 'button', class: 'btn ghost small', onclick: () => { v.error = ''; loadHistory(); } }, t('Try again'))];
  else if (!v.done) foot = [h('button', { type: 'button', class: 'btn ghost small', onclick: () => loadHistory() }, t('Load more'))];
  else if (!v.items.length) foot = [h('span', { class: 'hint', text: searching ? t('Nothing found for “{q}”.', { q: v.q }) : v.story ? t('Nothing requested in this story yet.') : t('No requests yet.') })];
  else foot = [h('span', { class: 'hint', text: t('End of the history.') })];
  historyFoot.replaceChildren(...foot);
}
/** A prompt cut to a few lines: a click shows the whole text (and another hides it again). */
function historyText(text, title = '') {
  const el = h('p', { class: 'request-prompt', title: title || t('Click to see the whole text'), text: text || t('(no text)') });
  el.addEventListener('click', () => { if (!selectingIn(el)) el.classList.toggle('open'); });
  return el;
}
async function copyText(text) {
  try { await navigator.clipboard.writeText(text); toast(t('Copied.')); } catch { toast(t('Could not copy.'), { tone: 'error' }); }
}
function storyChip(story) {
  return h('button', { type: 'button', class: 'request-story', title: t('Open the story'), onclick: () => openStoryFromHistory(story.id) }, icon('book'), h('span', { text: story.title || t('Untitled') }));
}
async function openStoryFromHistory(id, text = null) {
  historyDialog.close(); closeViewer();
  if (!WRITING[state.section]) setSection('stories');
  await stories.openFromHistory(id, text);
}
function historyItem(item) {
  if (item.type === 'writer') {
    const thumb = h('div', { class: 'request-thumb' }, item.images.length ? thumbOf(item.images[0], 'request') : icon('feather'));
    return h('div', { class: 'request writer' }, thumb, h('div', { class: 'request-main' },
      historyText(item.text),
      h('div', { class: 'request-meta' }, h('span', { class: 'status writer' }, t('To the writer')), storyChip(item.story), h('span', {}, dateTime(item.created)),
        item.during ? h('span', {}, t('while it was working')) : '', item.images.length ? h('span', {}, plural(item.images.length, t('{n} image attached'), t('{n} images attached'))) : ''),
      h('div', { class: 'job-actions' },
        h('button', { type: 'button', class: 'btn secondary small', onclick: () => openStoryFromHistory(item.story.id) }, t('Open story')),
        h('button', { type: 'button', class: 'btn ghost small', title: t('Writes this message again in the conversation with the writer, to change and send'), onclick: () => openStoryFromHistory(item.story.id, item.text) }, icon('redo'), t('Use again')),
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => copyText(item.text) }, icon('copy'), t('Copy')))));
  }
  const job = item, active = ACTIVE.has(job.status);
  const duration = active ? t('{time} ago', { time: clock(now() - job.created) }) : job.status === 'completed' ? `levou ${clock(job.updated - job.created)}` : '';
  const thumb = h('div', { class: 'request-thumb', title: t('Request details'), onclick: () => openJobDetails(job.id, job) }, job.image_ids.length ? thumbOf(job.image_ids[0], 'request') : icon(job.status === 'failed' || job.status === 'uncertain' ? 'alert' : 'image'));
  const actions = [];
  if (job.image_ids.length) actions.push(h('button', { type: 'button', class: 'btn secondary small', onclick: () => { historyDialog.close(); if (state.section !== 'images') setSection('images'); openViewer(job.image_ids[0]); } }, t('Open')));
  if (!active) actions.push(h('button', { type: 'button', class: 'btn ghost small', onclick: async () => { try { await retryJob(job); historyDialog.close(); toast(t('Request repeated.')); } catch (e) { toast(e.message, { tone: 'error' }); } } }, icon('redo'), t('Repeat')));
  actions.push(h('button', { type: 'button', class: 'btn ghost small', onclick: () => { historyDialog.close(); useJobPrompt(job); } }, t('Use prompt')));
  actions.push(h('button', { type: 'button', class: 'btn ghost small', onclick: () => copyText(job.prompt) }, icon('copy'), t('Copy')));
  // Fotorrealista, Melhorar, Ampliar, Expandir: what the person wrote, without the ready-made instruction.
  if (typedText(job)) actions.push(h('button', { type: 'button', class: 'btn ghost small', onclick: () => copyTyped(job) }, icon('copy'), t('Copy my text')));
  if (active && job.status !== 'cancelling') actions.push(h('button', { type: 'button', class: 'btn ghost small', onclick: async () => { await cancelJob(job); loadHistory({ reset: true }); } }, t('Cancel')));
  return h('div', { class: 'request' }, thumb, h('div', { class: 'request-main' },
    job.label && job.label !== job.prompt ? h('p', { class: 'request-label', text: job.label }) : '',
    historyText(job.prompt),
    h('div', { class: 'request-meta' }, h('span', { class: `status ${job.status}` }, STATUS[job.status] || job.status), job.story ? storyChip(job.story) : '', h('span', {}, dateTime(job.created)),
      h('span', {}, state.models[job.options?.model]?.label || job.options?.model || ''), h('span', {}, job.options?.size === 'auto' ? t('Automatic', { ctx: 'size' }) : String(job.options?.size || '').replace('x', ' × ')),
      job.options?.n > 1 ? h('span', {}, t('{n} images', { n: job.options.n })) : '', duration ? h('span', {}, duration) : '', job.reference_count ? h('span', {}, plural(job.reference_count, t('{n} reference'), t('{n} references'))) : ''),
    job.error ? h('p', { class: 'job-error' }, job.error) : '',
    h('div', { class: 'job-actions' }, actions)));
}
$$('#history-kind button').forEach(b => b.addEventListener('click', () => {
  if (historyView.kind === b.dataset.kind) return;
  historyView.kind = b.dataset.kind; renderHistoryBar(); loadHistory({ reset: true });
}));
{
  let timer = null;
  $('#history-search').addEventListener('input', e => {
    clearTimeout(timer);
    timer = setTimeout(() => { const q = e.target.value.trim(); if (q === historyView.q) return; historyView.q = q; loadHistory({ reset: true }); }, 350);
  });
}
historyDialog.addEventListener('close', () => { historyView.token++; historyEnd.unobserve(historyFoot); });
$('#requests-open').onclick = showRequests;
$('#activity').onclick = showRequests;
$('#stories-history').onclick = () => openHistory();

// Configurações: the sections are listed on the left (across the top on a phone), grouped, and one shows at a time.
// Without the OpenAI key the window opens on the keys; otherwise on the last section seen.
const settings = { pane: 'keys' };
function showSettings(pane) {
  fillBalanceForm(); refreshBalance().then(fillBalanceForm);
  renderKeys();
  setError('#settings-error');
  renderAccount();
  showSettingsPane(typeof pane === 'string' ? pane : state.has_key ? settings.pane : 'keys');
  const dialog = $('#settings-dialog'); if (!dialog.open) dialog.showModal();
  // On a phone the sections are a row that scrolls sideways: the one shown comes into view.
  $('.settings-tab.active').scrollIntoView({ block: 'nearest', inline: 'nearest' });
  if (state.user?.admin) loadUsers();
}
function showSettingsPane(pane) {
  const tab = $(`.settings-tab[data-pane="${pane}"]`);
  if (!tab || tab.hidden) pane = 'keys';
  settings.pane = pane;
  for (const tab of $$('.settings-tab')) { const on = tab.dataset.pane === pane; tab.classList.toggle('active', on); tab.setAttribute('aria-current', on ? 'true' : 'false'); }
  for (const section of $$('.settings-pane')) section.hidden = section.id !== `settings-${pane}`;
  $('.settings-main').scrollTop = 0;
  if (pane === 'host') loadHostTest();
  if (pane === 'about') loadAbout();
  if (pane === 'gpu') gpuUi.showPane();
}
$$('.settings-tab').forEach(tab => tab.onclick = () => { setError('#settings-error'); showSettingsPane(tab.dataset.pane); });
$('#settings-open').onclick = () => showSettings();

// The language menus are in language.js, which works before the modules of the app have loaded.

// The hosting test: how long this server lets each kind of process live (HostTest.php). It goes on on the server when
// the dialog closes; the result is read again when the section opens.
const HOST_TEST_KINDS = [['web', t('In the page, with the .htaccess rule (how the writer used to run)')], ['web-plain', t('In the page, without the .htaccess rule')], ['separate', t('Separate process (how the writer and the images run now)')]];
let hostTestTimer = null;
function hostTestLine(s) {
  const at = storyHelpers.duration(s.seconds || 0);
  switch (s.state) {
    case 'running': return [t('running for {at}', { at }), ''];
    case 'completed': return [t('reached the end ({at}) without being stopped', { at }), 'ok'];
    case 'stopped': return [s.error ? t('stopped at {at}: PHP error ({error})', { at, error: s.error }) : t('stopped at {at}: the server told PHP to stop, with no PHP error', { at }), 'bad'];
    case 'killed': return [t('stopped at {at} without warning: the process was ended from outside', { at }), 'bad'];
    case 'unavailable': return [t('did not start: {why}', { why: s.why }), 'bad'];
    default: return [t('starting…'), ''];
  }
}
function renderHostTest(test) {
  const box = $('#host-test-result');
  if (!test) { box.hidden = true; return false; }
  const info = test.info || {}, kinds = test.kinds || {};
  const web = kinds.web || {}, separate = kinds.separate || {};
  const busy = HOST_TEST_KINDS.some(([k]) => ['running', 'waiting', undefined].includes(kinds[k]?.state));
  const facts = [info.server || t('unknown server'), `PHP ${info.php} (${info.sapi})`, `max_execution_time ${info.max_execution_time}${info.after_set_time_limit !== info.max_execution_time ? ' ' + t('({seconds} after set_time_limit)', { seconds: info.after_set_time_limit }) : ''}`,
    t('.htaccess rule seen by PHP: {seen}', { seen: web.noabort ? t('yes') : web.state ? t('no') : '…' }), info.disabled?.length ? `desativadas: ${info.disabled.join(', ')}` : null,
    info.limit ? t('LiteSpeed limit for each PHP process of the page: {limit} (LSAPI_MAX_PROCESS_TIME)', { limit: storyHelpers.duration(info.limit) }) : null,
    separate.php && separate.php !== info.php ? t('PHP of the separate process: {php}', { php: separate.php }) : null,
    info.separate_failed ? t('last separate process attempt: {separate_failed}', { separate_failed: info.separate_failed }) : null].filter(Boolean);
  const lacking = separate.extensions?.length ? separate.extensions.join(', ') : null;
  let verdict = null;
  if (!busy) {
    const cut = ['stopped', 'killed'].includes(web.state);
    if (separate.state === 'completed' && lacking) verdict = cut ? t('The separate process ran to the end, but the command-line PHP lacks {lacking}, so the writer and the images keep running in the page, which the host stops at {limit}. Ask your host\'s support to turn on these extensions in the command-line PHP.', { lacking, limit: storyHelpers.duration(web.seconds) })
      : t('The separate process ran to the end, but the command-line PHP lacks {lacking}, so the writer and the images keep running in the page. Ask your host\'s support to turn on these extensions in the command-line PHP.', { lacking });
    else if (cut && separate.state === 'completed') verdict = t('The host stops the page\'s processes at {limit}, but the separate process ran to the end: the writer and the images run in it and work as long as they need.', { limit: storyHelpers.duration(web.seconds) });
    else if (cut) verdict = t('The host stops the page\'s processes at {limit} and does not let a separate process run to the end. Send this result to your host\'s support and ask them to allow long PHP processes (LiteSpeed/LSAPI\'s time limit).', { limit: storyHelpers.duration(web.seconds) });
    else if (web.state === 'completed') verdict = t('No limit showed up in 10 minutes: the page\'s processes also run to the end.');
  } else if (info.limit && info.limit < 600) verdict = t('This server stops each PHP process of the page at {limit}. The test shows whether the separate process goes past that.', { limit: storyHelpers.duration(info.limit) });
  box.replaceChildren(
    h('ul', {}, ...HOST_TEST_KINDS.map(([k, label]) => { const [text, tone] = hostTestLine(kinds[k] || {}); return h('li', { class: tone }, h('strong', { text: label }), h('span', { text })); })),
    h('p', { class: 'hint', text: t('Started {started}. {facts}.', { started: dateTime(test.started), facts: facts.join(' · ') }) }),
    ...(verdict ? [h('p', { class: 'host-test-verdict', text: verdict })] : []));
  box.hidden = false;
  return busy;
}
async function loadHostTest() {
  clearTimeout(hostTestTimer); hostTestTimer = null;
  let busy = false;
  try { busy = renderHostTest((await api('host-test-status')).test); } catch { /* tried again when the section opens */ }
  if (busy && $('#settings-dialog').open && settings.pane === 'host') hostTestTimer = setTimeout(loadHostTest, 3000);
}
$('#settings-dialog').addEventListener('close', () => { clearTimeout(hostTestTimer); hostTestTimer = null; });
$('#host-test-start').onclick = async () => {
  const button = $('#host-test-start'); button.disabled = true; setError('#settings-error');
  try {
    const { test } = await api('host-test-start', { seconds: 600 });
    // The processes in the page answer at once and go on in the background.
    await Promise.all([api('host-test', { run: test.id, kind: 'web' }), api('host-test-plain', { run: test.id, kind: 'web-plain' }), api('host-test', { run: test.id, kind: 'separate' })].map(p => p.catch(() => null)));
    await loadHostTest();
  } catch (err) { setError('#settings-error', err.message); }
  finally { button.disabled = false; }
};
$('#account-open').onclick = () => showSettings('account');

// Sobre mim como leitor: the person's own words, which the writer reads in every story, in the app and over MCP
// (Vault::readerAbout). Read from the server when the section opens, and gone from the page when the window closes.
async function loadAbout() {
  const fields = [$('#about-text'), $('#about-notes')], status = $('#about-status');
  status.textContent = t('Loading…'); for (const f of fields) f.disabled = true;
  try {
    const about = await api('reader-about');
    for (const f of fields) f.maxLength = about.max || 2000;
    fields[0].value = about.text || ''; fields[1].value = about.notes || ''; status.textContent = '';
  } catch (err) { status.textContent = ''; setError('#settings-error', err.message); }
  finally { for (const f of fields) f.disabled = false; }
}
$('#about-form').onsubmit = async e => {
  e.preventDefault(); setError('#settings-error');
  const button = $('#about-form [type=submit]'); button.disabled = true;
  try {
    const saved = await api('reader-about-save', { text: $('#about-text').value, notes: $('#about-notes').value });
    $('#about-text').value = saved.text; $('#about-notes').value = saved.notes;
    toast($('#about-text').value || $('#about-notes').value ? t('Saved. The writer reads this from the next message on.') : t('Deleted. The writer no longer gets anything from here.'));
  } catch (err) { setError('#settings-error', err.message); }
  finally { button.disabled = false; }
};
$('#settings-dialog').addEventListener('close', () => { $('#about-text').value = ''; $('#about-notes').value = ''; $('#about-status').textContent = ''; });
// A token is shown once: it leaves the screen when the dialog closes.
$('#settings-dialog').addEventListener('close', () => { for (const s of ['#mcp-output', '#user-created']) { $(s).replaceChildren(); $(s).hidden = true; } });

async function refreshBalance() {
  if (!state.unlocked) return;
  try { balance.info = await api('balance'); } catch { return; }
  renderBalance();
  // The estimates take the planning from the same answer.
  if (state.models) { updateComposerCost(); if (viewerDialog.open) updateEditCost(); }
}
const percent = scale => `${scale >= 1 ? '+' : '−'}${(Math.abs(scale - 1) * 100).toLocaleString(locale, { maximumFractionDigits: 1 })}%`;
/**
 * The balances, one per service (Vault::ACCOUNTS), each with the same card in Saldos: none of the three tells the
 * prepaid balance through the key, so each is followed from the amount entered there. OpenAI's spending comes in tokens
 * and is priced here; Claude's and Google's come priced by the server.
 */
const BALANCES = {
  openai: { name: 'OpenAI', of: t('for OpenAI'), prefix: 'balance' },
  claude: { name: 'Claude', of: t('for Claude'), prefix: 'claude-balance' },
  gemini: { name: 'Gemini', of: t('for Gemini'), prefix: 'gemini-balance' },
};
const balanceEl = (id, part) => $(`#${BALANCES[id].prefix}-${part}`);
/** What the server says of an account: its balance as entered and what was paid since ({ billing, spent }). */
function accountInfo(id) { const info = balance.info; return !info ? null : id === 'openai' ? info : info.accounts?.[id] || null; }
function accountNow(id) { const a = accountInfo(id); return a ? balanceNow(a) : null; }
/** Who is paid for a picture of the model: Google for Nano Banana, OpenAI for the rest. */
const imagePayer = model => state.models?.[model]?.provider === 'gemini' ? 'gemini' : 'openai';
function renderBalance() {
  for (const id of Object.keys(BALANCES)) renderAccountBalance(id);
  renderBalanceChips();
}
function renderAccountBalance(id) {
  const a = accountInfo(id), now = a && balanceNow(a), pill = balanceEl(id, 'state');
  pill.className = `key-state${now ? (now.balance < 5 ? ' warn' : ' ok') : ''}`;
  setText(pill, now ? formatBalance(now.balance) : t('Off'));
  balanceEl(id, 'off').disabled = !now;
  setText(balanceEl(id, 'status'), now ? t('{story} Estimated balance now: {balance}.', { story: balanceStory(a.billing, now), balance: formatBalance(now.balance) }) : t('Off: this balance does not show at the top.'));
}
/** Since when the balance is followed, and what went out since then, by kind of call. */
function balanceStory(b, now) {
  const k = now.kinds || {}, parts = [];
  const add = (label, cost) => { if (cost >= 0.005) parts.push(`${label}, ${formatBalance(cost)}`); };
  if (k.image) add(plural(k.image.calls, t('{n} image request'), t('{n} image requests')), k.image.cost);
  add('planejamento', (k.planning?.cost || 0) + (k.variation?.cost || 0));
  if (k.writing) add(plural(k.writing.calls, t('{n} writer step'), t('{n} writer steps')), k.writing.cost);
  if (k.warm) add(t('conversation kept ready'), k.warm.cost);
  const date = dateTime(b.since), entered = formatBalance(b.balance);
  const told = Number.isFinite(b.shown) ? t('Entered on {date}: {entered} (the app showed {shown}).', { date, entered, shown: formatBalance(b.shown) }) : t('Entered on {date}: {entered}.', { date, entered });
  const detail = parts.length ? ` (${parts.join('; ')})` : '';
  const topups = now.topups ? t(', and {topups} of {amount}', { topups: plural(now.topups, t('{n} top-up'), t('{n} top-ups')), amount: formatBalance(b.topup) }) : '';
  return [told, t('Since then, {spent} spent here{detail}{topups}.', { spent: formatBalance(now.spent), detail, topups }),
    now.scale !== 1 ? t('Spending counted at {percent}, from the latest correction.', { percent: percent(now.scale) }) : '',
    now.unpriced ? plural(now.unpriced, t('{n} request for a model with no known price was not counted.'), t('{n} requests for models with no known price were not counted.')) : ''].filter(Boolean).join(' ');
}
/**
 * The services the screen in view pays, in order, each with what it pays for there: in the images, the one of the
 * model chosen; in the stories and the visual novels, the writer's and the pictures' of the story open (in the list,
 * of the next story). A visual novel whose model can't cut a sprite out has its sprites made by OpenAI (WriterVn::spriteModel).
 */
function payersInView() {
  const list = [];
  // A model on the account's GPU has no balance to show: its time is paid where the GPU is rented.
  if (!WRITING[state.section]) { if (state.models && !isGpu($('#model').value)) list.push([imagePayer(composerOptions().model), t('the images of the chosen model')]); }
  else {
    const w = stories.writer, here = stories.story ? t('of this story') : t('of a new story');
    if (w) {
      list.push([BALANCES[w.provider] ? w.provider : 'openai', t('the writer {here}', { here })]);
      list.push([imagePayer(w.image_model), t('the images {here}', { here })]);
      if (state.section === 'vn' && state.models?.[w.image_model]?.transparent === false && state.has_key) list.push(['openai', t('the sprites')]);
    }
  }
  const by = new Map();
  for (const [id, what] of list) by.set(id, [...(by.get(id) || []), what]);
  return [...by];
}
function renderBalanceChips() {
  const shown = payersInView().map(([id, what]) => [id, what, accountNow(id)]).filter(([, , now]) => now);
  for (const chip of $$('.balance-chip')) if (!shown.some(([id]) => id === chip.dataset.account)) chip.hidden = true;
  $('#balances').dataset.count = String(shown.length);
  shown.forEach(([id, what, now], i) => {
    const chip = $(`#balance-chip-${id}`);
    chip.hidden = false; chip.style.order = String(i);
    setText(chip.querySelector('.balance-text'), formatBalance(now.balance));
    chip.classList.toggle('low', now.balance < 5);
    const paid = listText(what);
    chip.title = t('Estimated balance {of}, which pays for {paid}. {story}', { of: BALANCES[id].of, paid, story: balanceStory(accountInfo(id).billing, now) });
    chip.setAttribute('aria-label', t('Estimated balance {of}: {balance}', { of: BALANCES[id].of, balance: formatBalance(now.balance) }));
  });
}
/** Each form starts from the balance as estimated now, ready to be corrected with the amount on the service's site. */
function fillBalanceForm() {
  for (const id of Object.keys(BALANCES)) {
    const a = accountInfo(id), b = a?.billing, now = a && balanceNow(a);
    if (document.activeElement?.closest(`#${BALANCES[id].prefix}-form`)) continue;
    balanceEl(id, 'amount').value = now ? now.balance.toFixed(2) : '';
    balanceEl(id, 'topup').value = String(b ? b.topup : id === 'openai' ? 100 : 0);
    balanceEl(id, 'day').value = String(b ? b.day : 24);
  }
}
for (const id of Object.keys(BALANCES)) {
  balanceEl(id, 'form').onsubmit = async e => {
    e.preventDefault(); setError('#settings-error');
    const billing = { balance: balanceEl(id, 'amount').valueAsNumber, topup: balanceEl(id, 'topup').valueAsNumber || 0, day: Math.round(balanceEl(id, 'day').valueAsNumber) };
    if (!Number.isFinite(billing.balance)) { setError('#settings-error', t('Enter the balance in dollars.')); balanceEl(id, 'amount').focus(); return; }
    // A different amount is a correction: the app keeps what it showed and learns from the difference how much more
    // (or less) than it counted is really spent. The same amount (only the top-up changed) teaches nothing.
    const info = accountInfo(id), before = info && balanceNow(info), corrected = before && Math.abs(before.balance - billing.balance) >= 0.01;
    billing.scale = corrected ? learnedScale(info, billing.balance) : before ? before.scale : 1;
    if (corrected) billing.shown = Math.round(before.balance * 100) / 100;
    else if (Number.isFinite(info?.billing?.shown)) billing.shown = info.billing.shown;
    try {
      balance.info = await api('billing', { account: id, billing }); renderBalance();
      toast(!corrected ? t('Balance {of} saved.', { of: BALANCES[id].of }) : t('Balance {of} corrected: the app showed {shown}.', { of: BALANCES[id].of, shown: formatBalance(before.balance) })
        + (billing.scale !== before.scale ? ' ' + t('Upcoming spending is counted at {percent}.', { percent: percent(billing.scale) }) : ''));
    } catch (err) { setError('#settings-error', err.message); }
  };
  balanceEl(id, 'off').onclick = async () => {
    setError('#settings-error');
    try { balance.info = await api('billing', { account: id, billing: null }); renderBalance(); fillBalanceForm(); }
    catch (err) { setError('#settings-error', err.message); }
  };
  // The balance at the top opens its form with the amount ready to be typed over.
  $(`#balance-chip-${id}`).onclick = () => {
    showSettings('balance'); balanceEl(id, 'form').scrollIntoView({ block: 'nearest' });
    balanceEl(id, 'amount').focus(); balanceEl(id, 'amount').select();
  };
}

function renderAccount() {
  const user = state.user || {};
  $('#settings-user').textContent = user.name || '';
  $('#settings-role').textContent = user.admin ? t('Administrator') : '';
  $('#rename-input').value = user.name || '';
  // Only the administrator manages users and runs the hosting test (the server refuses both to anyone else).
  $('#settings-tab-users').hidden = !user.admin;
  $('#settings-tab-host').hidden = !user.admin;
  if (!user.admin && (settings.pane === 'users' || settings.pane === 'host')) showSettingsPane('keys');
  $('#account-name').textContent = user.name || '';
  const mcp = user.mcp;
  $('#mcp-status').textContent = mcp ? t('Active token, created on {created}. {used}', { created: dateTime(mcp.created), used: mcp.used ? t('Last used on {used}.', { used: dateTime(mcp.used) }) : t('Not used yet.') }) : t('No active token.');
  $('#mcp-revoke').hidden = !mcp;
  $('#mcp-generate').textContent = mcp ? t('Generate a new token') : t('Generate token');
}
/** The token once, ready to paste into a Claude connector (URL) or into Claude Code (header). */
function tokenBlock(url, token) {
  const row = (label, value) => {
    const input = h('input', { readonly: true, value, spellcheck: 'false', autocomplete: 'off' });
    input.addEventListener('focus', () => input.select());
    const copy = h('button', { type: 'button', class: 'icon-btn small', title: t('Copy'), 'aria-label': t('Copy: {label}', { label }), onclick: async () => {
      try { await navigator.clipboard.writeText(value); toast(t('Copied.')); } catch { input.focus(); input.select(); }
    } }, icon('copy'));
    return h('label', {}, label, h('span', { class: 'copy-row' }, input, copy));
  };
  return [
    h('p', { class: 'hint attention' }, t('Copy it now: the token is not shown again. Whoever has this address uses this user and its API key.')),
    row(t('Address for the Claude connector (it already includes the token)'), `${url}?token=${token}`),
    row(t('In Claude Code'), `claude mcp add --transport http atelier ${url} --header "Authorization: Bearer ${token}"`),
  ];
}
$('#mcp-generate').onclick = async () => {
  setError('#settings-error');
  if (state.user?.mcp && !await confirmAction({ title: t('Generate a new token?'), message: t('The current token stops working. Update the address in Claude afterwards.'), confirm: t('Generate new') })) return;
  try {
    const result = await api('mcp-token', {});
    state.user = result.user; renderAccount();
    $('#mcp-output').replaceChildren(...tokenBlock(result.url, result.token)); $('#mcp-output').hidden = false;
  } catch (err) { setError('#settings-error', err.message); }
};
$('#mcp-revoke').onclick = async () => {
  setError('#settings-error');
  if (!await confirmAction({ title: t('Revoke MCP access?'), message: t('Clients that use this token lose access to this user.'), confirm: t('Revoke'), danger: true })) return;
  try { state.user = (await api('mcp-revoke', {})).user; renderAccount(); $('#mcp-output').replaceChildren(); $('#mcp-output').hidden = true; toast(t('MCP access revoked.')); }
  catch (err) { setError('#settings-error', err.message); }
};
$('#rename-form').onsubmit = async e => {
  e.preventDefault(); setError('#settings-error');
  try { state.user = (await api('account', { name: $('#rename-input').value })).user; renderAccount(); toast(t('Name saved. Use it to sign in.')); if (state.user.admin) loadUsers(); }
  catch (err) { setError('#settings-error', err.message); }
};

async function loadUsers() {
  try { renderUsers((await api('users')).users); } catch (err) { setError('#settings-error', err.message); }
}
function renderUsers(users) {
  $('#users-list').replaceChildren(...users.map(user => {
    const self = user.id === state.user?.id;
    const meta = [plural(user.images, t('{n} image'), t('{n} images')), user.has_key ? '' : t('no API key'), user.mcp ? t('MCP on') : '', t('created on {date}', { date: dateTime(user.created) })].filter(Boolean).join(' · ');
    const row = h('div', { class: 'user-row' }, h('div', { class: 'user-main' },
      h('span', { class: 'user-name' }, user.name, self ? h('span', { class: 'muted' }, ' ' + t('(you)')) : '', user.admin ? h('span', { class: 'pill' }, t('Administrator')) : ''),
      h('span', { class: 'muted' }, meta)));
    if (self) return row;
    const password = h('input', { type: 'password', autocomplete: 'current-password', required: true, 'aria-label': t('Your password') });
    const confirmBox = h('form', { class: 'user-delete stack', hidden: true },
      h('p', { class: 'hint' }, t('Deleting {name} erases this user\'s library ({images}) and their requests, for good. Type your password to confirm.', { name: user.name, images: plural(user.images, t('{n} image'), t('{n} images')) })),
      password,
      h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn ghost small', onclick: () => { confirmBox.hidden = true; password.value = ''; } }, t('Cancel')),
        h('button', { type: 'submit', class: 'btn danger small' }, t('Delete user'))));
    confirmBox.onsubmit = async e => {
      e.preventDefault(); setError('#settings-error');
      try { await api('user-delete', { id: user.id, password: password.value }); toast(t('User {name} deleted.', { name: user.name })); loadUsers(); }
      catch (err) { setError('#settings-error', err.message); password.select(); }
    };
    row.append(h('button', { type: 'button', class: 'icon-btn small danger', title: t('Delete {name}', { name: user.name }), 'aria-label': t('Delete {name}', { name: user.name }),
      onclick: () => { confirmBox.hidden = !confirmBox.hidden; if (!confirmBox.hidden) password.focus(); } }, icon('trash')), confirmBox);
    return row;
  }));
}
$('#user-form').onsubmit = async e => {
  e.preventDefault(); setError('#settings-error');
  if ($('#user-password').value !== $('#user-confirm').value) { setError('#settings-error', t('The new user\'s passwords do not match.')); return; }
  try {
    const result = await api('user-create', { name: $('#user-name').value.trim(), password: $('#user-password').value, copy_api_key: $('#user-copy-key').checked, mcp: $('#user-mcp').checked });
    $('#user-form').reset(); loadUsers();
    $('#user-created').replaceChildren(h('p', { class: 'hint' }, result.user.has_key ? t('User {name} created, with your API key. To use their library, sign out and sign in with their name and password.', { name: result.user.name })
      : t('User {name} created. To use their library, sign out and sign in with their name and password.', { name: result.user.name })),
      ...(result.token ? tokenBlock(result.url, result.token) : []));
    $('#user-created').hidden = false; $('#user-created').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  } catch (err) { setError('#settings-error', err.message); }
};
// The three API keys look and work alike: what the key is for and whether one is saved, the field, and Remover,
// Testar and Salvar. Salvar waits for a key to be typed (an empty field never takes a key away); Remover asks first.
const API_KEYS = {
  openai: { form: '#key-form', input: '#api-key', state: '#key-state', status: '#key-status', remove: '#key-remove', test: '#key-test', result: '#key-test-result', has: 'has_key', name: t('for OpenAI'),
    save: key => api('settings', { api_key: key }), clear: () => api('settings', { api_key: '', remove: true }), check: () => api('openai-test', {}),
    missing: t('Needed to generate and edit images. Create one at platform.openai.com, under API keys.'),
    removing: t('Without it, generating and editing images stops until you save another one. The library and the stories stay saved.') },
  claude: { form: '#claude-key-form', input: '#claude-key', state: '#claude-key-state', status: '#claude-key-status', remove: '#claude-key-remove', test: '#claude-key-test', result: '#claude-test-result', has: 'has_claude_key', name: t('for Claude'),
    save: key => api('claude-key', { key }), clear: () => api('claude-key', { key: '' }), check: () => api('claude-test', {}),
    missing: t('To write with Claude, with your account\'s credits. Create one at platform.claude.com, under API keys.'),
    removing: t('The stories stay saved; to write with Claude again, you will need to add the key.') },
  gemini: { form: '#gemini-key-form', input: '#gemini-key', state: '#gemini-key-state', status: '#gemini-key-status', remove: '#gemini-key-remove', test: '#gemini-key-test', result: '#gemini-test-result', has: 'has_gemini_key', name: t('for Gemini'),
    save: key => api('gemini-key', { key }), clear: () => api('gemini-key', { key: '' }), check: () => api('gemini-test', {}),
    missing: t('To write with Gemini and generate with Nano Banana, with your Google account\'s credits. Create one at aistudio.google.com, under API keys.'),
    removing: t('The stories and images stay saved; to use Gemini or Nano Banana again, you will need to add the key.') },
};
const KEY_TEST_HINT = t('Checks the key and the models the app uses and makes a tiny request, which costs less than a cent.');
function renderKey(id) {
  const k = API_KEYS[id], saved = Boolean(state[k.has]), tag = $(k.state);
  tag.textContent = saved ? t('Saved') : id === 'openai' ? t('Needed') : t('No key');
  tag.className = `key-state${saved ? ' ok' : id === 'openai' ? ' warn' : ''}`;
  $(k.status).textContent = saved ? t('A key is saved. To replace it, paste the new one and save.') : k.missing;
  $(k.input).placeholder = saved ? t('Paste a new key to replace it') : $(k.input).dataset.placeholder;
  $(k.remove).disabled = !saved; $(k.test).disabled = !saved;
  $(k.test).title = saved ? KEY_TEST_HINT : t('Save a key to test it.');
  $(k.result).hidden = true; $(k.result).replaceChildren();
  $(`${k.form} [type=submit]`).disabled = !$(k.input).value.trim();
}
const renderKeys = () => Object.keys(API_KEYS).forEach(renderKey);
for (const [id, k] of Object.entries(API_KEYS)) {
  $(k.input).dataset.placeholder = $(k.input).placeholder;
  $(k.input).addEventListener('input', () => { $(`${k.form} [type=submit]`).disabled = !$(k.input).value.trim(); });
  $(k.form).onsubmit = async e => {
    e.preventDefault(); setError('#settings-error');
    const value = $(k.input).value.trim(), button = $(`${k.form} [type=submit]`);
    if (!value) { $(k.input).focus(); return; }
    // The first OpenAI key is what the app was waiting for: the window closes, back to the images.
    const first = id === 'openai' && !state.has_key;
    button.disabled = true;
    try {
      await k.save(value); $(k.form).reset(); state[k.has] = true; renderKey(id);
      toast(t('Key {name} saved. Use Test to check it.', { name: k.name })); loadModels({ force: true });
      if (first) $('#settings-dialog').close();
    } catch (err) { setError('#settings-error', err.message); button.disabled = !$(k.input).value.trim(); }
  };
  $(k.remove).onclick = async () => {
    if (!await confirmAction({ title: t('Remove the key {name}?', { name: k.name }), message: k.removing, confirm: t('Remove the key'), danger: true })) return;
    setError('#settings-error');
    try { await k.clear(); state[k.has] = false; renderKey(id); toast(t('Key {name} removed.', { name: k.name })); loadModels({ force: true }); }
    catch (err) { setError('#settings-error', err.message); }
  };
  $(k.test).onclick = async () => {
    const button = $(k.test), out = $(k.result);
    button.disabled = true; out.hidden = false;
    out.replaceChildren(h('p', { class: 'wait' }, h('span', { class: 'spinner small' }), h('span', {}, t('Testing the key {name}…', { name: k.name }))));
    try {
      const { steps } = await k.check();
      out.replaceChildren(...steps.map(step => h('p', { class: step.ok ? 'ok' : 'bad' }, icon(step.ok ? 'check' : 'alert'), h('span', {}, h('strong', {}, `${step.name}: `), step.detail))));
    } catch (err) { out.replaceChildren(h('p', { class: 'bad' }, icon('alert'), h('span', {}, err.message))); }
    finally { button.disabled = !state[k.has]; }
  };
}
$('#password-form').onsubmit = async e => {
  e.preventDefault();
  if ($('#new-password').value !== $('#new-password-confirm').value) { setError('#settings-error', t('The new passwords do not match.')); return; }
  try { await api('password', { current: $('#current-password').value, password: $('#new-password').value }); $('#password-form').reset(); toast(t('Password changed. The other sessions were ended.')); }
  catch (err) { setError('#settings-error', err.message); }
};

// ---------------------------------------------------------------------------
// Keyboard
// ---------------------------------------------------------------------------
document.addEventListener('keydown', e => {
  if (!state.unlocked) return;
  const typing = e.target instanceof HTMLElement && (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable);
  const dialogs = $$('dialog[open]');
  const onlyViewer = dialogs.length === 1 && dialogs[0] === viewerDialog;
  if (WRITING[state.section] && !viewerDialog.open) { stories.key(e); return; }
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    if (onlyViewer && isVideo(viewer.item)) return;
    if (onlyViewer) { e.preventDefault(); $('#edit-form').requestSubmit(); }
    else if (!dialogs.length) { e.preventDefault(); $('#generate-form').requestSubmit(); }
    return;
  }
  if (typing || e.ctrlKey || e.metaKey || e.altKey) return;
  // In full screen the keys work without the controls: moving to another item or frame hides them again.
  if (onlyViewer && viewerDialog.classList.contains('immersive')) {
    const moving = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 't', ',', '.', '<', '>', 'Home', 'End'].includes(e.key);
    if (moving && document.activeElement?.matches?.('button, a, summary')) document.activeElement.blur();
    if (moving || !e.target.closest?.('.viewer-bar, .versions, .inspector, .video-bar')) hideChrome();
  }
  if (onlyViewer && isVideo(viewer.item)) { videoKey(e); return; }
  if (onlyViewer) {
    if (e.key === 'ArrowLeft') { e.preventDefault(); navigate(-1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); navigate(1); }
    else if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && viewer.mode === 'view') { e.preventDefault(); navigateVersion(e.key === 'ArrowUp' ? -1 : 1); }
    else if (e.key === 't') setImmersive(!viewerDialog.classList.contains('immersive'));
    else if (e.key === 'f') $('#viewer-favorite').click();
    else if (e.key === 'b' && (viewer.mode === 'view' || viewer.mode === 'mask')) $('#brush-toggle').click();
    else if (e.key === 'e') { e.preventDefault(); $('#edit-prompt').focus(); }
    else if (viewer.mode !== 'expand' && (e.key === '+' || e.key === '=')) { e.preventDefault(); zoomBy(1.5); }
    else if (viewer.mode !== 'expand' && e.key === '-') { e.preventDefault(); zoomBy(1 / 1.5); }
    else if (viewer.mode !== 'expand' && e.key === '0') { e.preventDefault(); resetZoom({ animate: true }); }
    else if (e.key === ' ' && viewer.mode === 'mask' && !e.target.closest?.('button, a, summary')) { e.preventDefault(); if (!e.repeat) holdSpace(true); }
  } else if (!dialogs.length) {
    if (e.key === '/') { e.preventDefault(); $('#search').focus(); }
    else if (e.key === 'n') { e.preventDefault(); $('#prompt').focus(); }
  }
});

// Prompts, API keys and images are never written to localStorage, IndexedDB or service workers.
init();
