// The whole story in a zip, and back: the shape of the file. stories.js asks the server for the story and moves the
// pictures; this file turns what the server keeps into the files of the zip and a zip into what the server takes.
// Pure functions, with no page: tests/pack.mjs runs them in Node.
import { t } from './i18n.js';

export const MANIFEST = 'historia.json';
const ID = /^[a-f0-9]{32}$/;
/** An illustration line of a chapter as the app keeps it (Stories::MARKER). */
const MARKER = /^!\[([^\]\n]*)\]\((img|job):([a-f0-9]{32})\)[ \t]*$/gm;
/** An image line of a chapter in the file: ![caption](path), alone on its line. */
const LINK = /^!\[([^\]\n]*)\]\(([^)\n]+)\)[ \t]*$/gm;
const EXTENSIONS = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' };
export const TYPES = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp' };

// The names of the files are the format of the zip, the same in every language: zips made before still open.
export const README = t('Atelier · exported story\n\nThis file holds everything in the story. It goes back into the app in Stories › Import, as a new story (the original does not change).\n\nhistoria.json     The story\'s data: title, series, synopsis, cover, the writer\'s settings, the list of\n                  chapters with the summary of each one, the character and place sheets, the visual style and\n                  the lettering, and the comics (settings and the script of each page).\ncapitulos/        The text of each chapter, in Markdown. An illustration is a line on its own:\n                  ![caption](../imagens/file.png)\nbiblia.md         The story bible.\nplanejamento.md   The writer\'s plan (it has the spoilers).\nconversa.json     The conversation with the writer, as it went to the model, so it remembers everything.\nimagens/          The cover, the illustrations, the images of the sheets and the style, and the comics pages.\n\nTo put a story together by hand, a zip with only .md files also works: each one becomes a chapter, in\nthe order of the names (01-..., 02-...), with the title from the first line "# Title". biblia.md and\nplanejamento.md become the bible and the plan, and the name of the zip becomes the title. The images\nlinked in the chapters (![caption](imagens/photo.png)) go into the gallery.\n');

/** A name for a file from a title: no accents, no spaces. */
export function slug(text, fallback = 'capitulo') {
  const s = String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase().slice(0, 50);
  return s || fallback;
}

/**
 * Every picture the story names outside the chapters' text (the cover, the sheets, the style and its letters, the
 * comic's style, pages and the sheets its panels use): fn(value) gives what goes in its place; null takes a picture
 * out of its list (and empties a single one).
 */
export function mapPictures(pack, fn) {
  const one = v => (v === null || v === undefined || v === '' ? null : fn(v) ?? null);
  const list = images => (Array.isArray(images) ? images.map(im => (im && typeof im === 'object' ? { ...im, image: one(im.image) } : null)).filter(im => im?.image) : []);
  if (pack.story && typeof pack.story === 'object') pack.story.cover = one(pack.story.cover);
  const cast = pack.cast && typeof pack.cast === 'object' ? pack.cast : null;
  if (cast?.style && typeof cast.style === 'object') {
    cast.style.images = list(cast.style.images);
    if (cast.style.lettering && typeof cast.style.lettering === 'object') cast.style.lettering.image = one(cast.style.lettering.image);
  }
  if (Array.isArray(cast?.characters)) for (const c of cast.characters) if (c && typeof c === 'object') c.images = list(c.images);
  const comic = pack.comic && typeof pack.comic === 'object' ? pack.comic : null;
  if (comic?.settings?.style && typeof comic.settings.style === 'object') comic.settings.style.images = list(comic.settings.style.images);
  if (Array.isArray(comic?.pages)) for (const p of comic.pages) {
    if (!p || typeof p !== 'object') continue;
    p.image = one(p.image);
    for (const q of Array.isArray(p.panels) ? p.panels : []) for (const w of Array.isArray(q?.characters) ? q.characters : []) if (w?.sheet) w.sheet = one(w.sheet) ?? '';
  }
  return pack;
}

/**
 * The files of the zip, from what story-export gave (with the conversation's messages filled in) and the pictures
 * (Map id → { bytes, type }): historia.json with the pictures as paths in the zip, each chapter in Markdown, the bible,
 * the plan, the conversation, the pictures and a LEIA-ME. A picture that didn't come is left out of the file.
 */
export function packFiles(pack, pictures) {
  const enc = new TextEncoder(), files = [];
  const data = structuredClone(pack);
  const pathOf = id => (pictures.has(id) ? `imagens/${id}.${EXTENSIONS[pictures.get(id).type] || 'png'}` : null);
  mapPictures(data, pathOf);
  const digits = Math.max(2, String((data.chapters || []).length).length);
  data.chapters = (data.chapters || []).map((c, i) => {
    const file = `capitulos/${String(i + 1).padStart(digits, '0')}-${slug(c.title)}.md`;
    const text = String(c.text || '').replace(MARKER, (line, caption, kind, id) => { const path = kind === 'img' ? pathOf(id) : null; return path ? `![${caption}](../${path})` : ''; });
    files.push({ name: file, bytes: enc.encode(text.replace(/\n{3,}/g, '\n\n').trim() + '\n') });
    const { text: _text, ...rest } = c;
    return { ...rest, file };
  });
  for (const [name, file] of [['notes', 'biblia.md'], ['plan', 'planejamento.md']]) {
    if (!data[name]) continue;
    files.push({ name: file, bytes: enc.encode(String(data[name].text || '')) });
    const { text: _text, ...rest } = data[name];
    data[name] = { ...rest, file };
  }
  if (data.conversation) { files.push({ name: 'conversa.json', bytes: enc.encode(JSON.stringify(data.conversation)) }); data.conversation = 'conversa.json'; }
  data.images = Object.fromEntries(Object.entries(data.images || {}).filter(([id]) => pictures.has(id)).map(([id, info]) => [pathOf(id), info]));
  files.unshift({ name: MANIFEST, bytes: enc.encode(JSON.stringify(data, null, 2)) });
  files.push({ name: 'LEIA-ME.txt', bytes: enc.encode(README) });
  for (const [id, p] of pictures) files.push({ name: pathOf(id), bytes: p.bytes });
  return files;
}

/** A path inside the zip, from one written in a file of the folder base (./, ../ and \ understood). */
export function resolve(base, path) {
  const parts = [];
  for (const piece of `${base ? `${base}/` : ''}${String(path).replace(/\\/g, '/')}`.split('/')) {
    if (piece === '' || piece === '.') continue;
    if (piece === '..') parts.pop(); else parts.push(piece);
  }
  return parts.join('/');
}
const folderOf = name => (name.includes('/') ? name.slice(0, name.lastIndexOf('/')) : '');
const typeOf = name => TYPES[name.split('.').pop().toLowerCase()] || null;

/**
 * What a zip holds (files: Map name → bytes), ready for story-import: the story (historia.json, with the texts of the
 * files it names put in), the conversation, and the pictures it uses (path → { bytes, type, old: the id it had when its
 * name is one }). The pictures are still paths: withIds() puts in the ids they get in the library. A zip without
 * historia.json works too: its Markdown files are the chapters, in the order of their names (biblia.md and
 * planejamento.md the bible and the plan), with the title of a first "# " line, and the zip's name the title.
 */
export function readPack(input, zipName = '') {
  const dec = new TextDecoder();
  let files = new Map([...input].filter(([name]) => !name.startsWith('__MACOSX/') && !name.split('/').some(p => p.startsWith('.'))));
  // A zip made by hand often holds one folder with everything in it.
  const tops = new Set([...files.keys()].map(n => (n.includes('/') ? n.slice(0, n.indexOf('/')) : '')));
  if (tops.size === 1 && !tops.has('')) { const top = [...tops][0].length + 1; files = new Map([...files].map(([n, b]) => [n.slice(top), b])); }
  const text = name => (files.has(name) ? dec.decode(files.get(name)).replace(/^﻿/, '').replace(/\r\n?/g, '\n') : null);
  const used = new Map();
  const picture = path => {
    const type = typeOf(path);
    if (!type || !files.has(path)) return null;
    const base = path.split('/').pop().replace(/\.[^.]+$/, '');
    used.set(path, { bytes: files.get(path), type, old: ID.test(base) ? base : null });
    return path;
  };
  // The image lines of a chapter in the file: a picture of the zip is kept as its path, for withIds().
  const links = (body, base) => body.replace(LINK, (line, caption, target) => {
    if (/^(img|job):/.test(target)) return line;
    const path = picture(resolve(base, target.trim()));
    return path ? `![${caption}](${path})` : line;
  });

  let pack;
  const manifest = text(MANIFEST);
  if (manifest !== null) {
    try { pack = JSON.parse(manifest); } catch { throw new Error(t('{file} is not valid JSON.', { file: MANIFEST })); }
    if (!pack || typeof pack !== 'object' || Array.isArray(pack)) throw new Error(t('{file} does not describe a story.', { file: MANIFEST }));
    pack.chapters = (Array.isArray(pack.chapters) ? pack.chapters : []).map((c, i) => {
      if (!c || typeof c !== 'object') throw new Error(t('Chapter {n} of {file} is not an object.', { n: i + 1, file: MANIFEST }));
      const file = typeof c.file === 'string' ? resolve('', c.file) : null;
      const body = file !== null ? text(file) : null;
      if (file !== null && body === null && typeof c.text !== 'string') throw new Error(t('The file {file} of chapter {n} is missing.', { file, n: i + 1 }));
      const { file: _file, ...rest } = c;
      return { ...rest, text: links(body ?? c.text ?? '', file !== null ? folderOf(file) : '') };
    });
    for (const name of ['notes', 'plan']) {
      const d = pack[name];
      if (!d || typeof d !== 'object' || typeof d.file !== 'string') continue;
      const { file, ...rest } = d;
      pack[name] = { ...rest, text: text(resolve('', file)) ?? d.text ?? '' };
    }
  } else {
    const documents = { notes: ['biblia.md', 'bible.md'], plan: ['planejamento.md', 'plano.md', 'plan.md'] };
    const skip = new Set(['leia-me.md', 'leia-me.txt', 'readme.md', 'readme.txt', ...Object.values(documents).flat()]);
    const named = [...files.keys()].filter(n => /\.(md|markdown|txt)$/i.test(n) && !skip.has(n.split('/').pop().toLowerCase()))
      .sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    if (!named.length) throw new Error(t('The zip has neither {file} nor chapters in Markdown (.md).', { file: MANIFEST }));
    pack = { story: { title: String(zipName).replace(/\.zip$/i, '').trim() }, chapters: named.map(n => {
      let body = text(n).trim(); let title = n.split('/').pop().replace(/\.[^.]+$/, '').replace(/^\d+[\s._-]*/, '').replace(/[-_]+/g, ' ').trim();
      const heading = body.match(/^#{1,3}[ \t]+(.+)\n*/);
      if (heading) { title = heading[1].trim(); body = body.slice(heading[0].length); }
      return { title, text: links(body, folderOf(n)) };
    }) };
    for (const [name, candidates] of Object.entries(documents)) {
      const found = [...files.keys()].find(n => candidates.includes(n.toLowerCase()));
      if (found) pack[name] = { text: text(found) };
    }
  }
  mapPictures(pack, v => (typeof v === 'string' ? picture(resolve('', v)) ?? (ID.test(v) ? v : null) : null));
  let conversation = null;
  if (typeof pack.conversation === 'string') {
    const raw = text(resolve('', pack.conversation));
    if (raw !== null) { try { conversation = JSON.parse(raw); } catch { throw new Error(t('{file} is not valid JSON.', { file: pack.conversation })); } }
  } else if (pack.conversation && typeof pack.conversation === 'object') conversation = pack.conversation;
  delete pack.conversation;
  if (conversation && (typeof conversation !== 'object' || !Array.isArray(conversation.messages ?? []))) conversation = null;
  const titles = pack.images && typeof pack.images === 'object' ? pack.images : {};
  delete pack.images;
  for (const [path, p] of used) p.title = String(titles[path]?.title || path.split('/').pop().replace(/\.[^.]+$/, ''));
  return { pack, conversation, pictures: used };
}

/**
 * The story with the pictures' ids in the library in place of their paths (ids: Map path → id). A path with no id
 * (its upload failed) goes; an id already there (a file made by hand for this same account) stays, for the server to
 * check.
 */
export function withIds(pack, ids) {
  mapPictures(pack, v => ids.get(v) ?? (ID.test(v) ? v : null));
  for (const c of pack.chapters || []) c.text = String(c.text || '').replace(LINK, (line, caption, target) => {
    if (/^(img|job):/.test(target)) return line;
    return ids.has(target) ? `![${caption}](img:${ids.get(target)})` : (typeOf(target) ? '' : line);
  });
  return pack;
}

/**
 * The messages of the conversation in pages for story-import-messages: each message's content as JSON text (its
 * objects stay objects), as many as fit in `limit` characters, at least one a page.
 */
export function messagePages(messages, limit) {
  const pages = []; let page = [], size = 0;
  for (const m of messages || []) {
    const item = { conversation: m.conversation, turn: m.turn ?? null, role: m.role, created: m.created, content: JSON.stringify(m.content) };
    const length = item.content.length + 200;
    if (page.length && size + length > limit) { pages.push(page); page = []; size = 0; }
    page.push(item); size += length;
  }
  if (page.length) pages.push(page);
  return pages;
}

/**
 * The files of a zip (Map name → bytes). Stored and deflated files (what zip programs make) are read; a zip with a
 * password, or larger than 4 GB, is not.
 */
export async function unzip(input) {
  const bytes = input instanceof Uint8Array ? input : new Uint8Array(await input.arrayBuffer());
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  let end = -1;
  for (let i = bytes.length - 22; i >= Math.max(0, bytes.length - 22 - 65535); i--) if (view.getUint32(i, true) === 0x06054b50) { end = i; break; }
  if (end < 0) throw new Error(t('The file is not a zip.'));
  const count = view.getUint16(end + 10, true); let at = view.getUint32(end + 16, true);
  if (count === 0xffff || at === 0xffffffff) throw new Error(t('Zips over 4 GB are not accepted.'));
  const utf8 = new TextDecoder(), latin = new TextDecoder('latin1'), files = new Map();
  for (let n = 0; n < count; n++) {
    if (at + 46 > bytes.length || view.getUint32(at, true) !== 0x02014b50) throw new Error(t('The zip is corrupted.'));
    const flags = view.getUint16(at + 8, true), method = view.getUint16(at + 10, true), size = view.getUint32(at + 20, true);
    const nameLength = view.getUint16(at + 28, true), extra = view.getUint16(at + 30, true), comment = view.getUint16(at + 32, true), local = view.getUint32(at + 42, true);
    const name = (flags & 0x800 ? utf8 : latin).decode(bytes.subarray(at + 46, at + 46 + nameLength)).replace(/\\/g, '/').replace(/^\/+/, '');
    at += 46 + nameLength + extra + comment;
    if (name.endsWith('/')) continue;
    if (flags & 1) throw new Error(t('Zips with a password are not accepted.'));
    if (view.getUint32(local, true) !== 0x04034b50) throw new Error(t('The zip is corrupted.'));
    const start = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
    const raw = bytes.subarray(start, start + size);
    if (method === 0) files.set(name, raw);
    else if (method === 8) files.set(name, new Uint8Array(await new Response(new Blob([raw]).stream().pipeThrough(new DecompressionStream('deflate-raw'))).arrayBuffer()));
    else throw new Error(t('The file {name} in the zip uses a compression the app does not read.', { name }));
  }
  return files;
}
