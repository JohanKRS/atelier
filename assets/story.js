// Stories: the pure parts (no DOM), tested by tests/story.mjs. The page builds elements from what these return, so
// text from a person or a model never goes through innerHTML.
import { t, language, number } from './i18n.js';

/** An illustration line in a chapter: ![caption](img:ID) when the image is ready, ![caption](job:ID) while it is made. */
export const MARKER = /^!\[([^\]\n]*)\]\((img|job):([a-f0-9]{32})\)[ \t]*$/;
const SCENE_BREAK = /^\s*(?:\*\s*\*\s*\*[\s*]*|-{3,}|_{3,}|#{1}\s*#\s*#\s*)$/;

export function wordCount(text) {
  const plain = String(text || '').split('\n').filter(line => !MARKER.test(line)).join('\n');
  return (plain.match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu) || []).length;
}

/**
 * Inline Markdown: **bold**, *italic* or _italic_, and line breaks. Nodes: {t:'text', v}, {t:'em'|'strong', c:[...]},
 * {t:'br'}. Whatever is not closed stays as written.
 */
export function parseInline(text) {
  const out = [];
  const push = v => { if (!v) return; const last = out[out.length - 1]; if (last && last.t === 'text') last.v += v; else out.push({ t: 'text', v }); };
  let i = 0;
  while (i < text.length) {
    const ch = text[i];
    if (ch === '\n') { out.push({ t: 'br' }); i++; continue; }
    if (ch === '\\' && i + 1 < text.length && '*_\\'.includes(text[i + 1])) { push(text[i + 1]); i += 2; continue; }
    if (text.startsWith('**', i)) {
      const end = text.indexOf('**', i + 2);
      if (end > i + 2) { out.push({ t: 'strong', c: parseInline(text.slice(i + 2, end)) }); i = end + 2; continue; }
    }
    if (ch === '*' || ch === '_') {
      // An underscore inside a word is not emphasis (snake_case); an asterisk followed by a space is not either.
      const opens = ch === '*' ? /\S/.test(text[i + 1] || '') : /\S/.test(text[i + 1] || '') && !/[\p{L}\p{N}]/u.test(text[i - 1] || '');
      if (opens) {
        let end = i + 1;
        while ((end = text.indexOf(ch, end)) !== -1) {
          if (ch === '*' && text[end + 1] === '*') { end += 2; continue; }
          if (/\S/.test(text[end - 1]) && (ch === '*' || !/[\p{L}\p{N}]/u.test(text[end + 1] || ''))) break;
          end++;
        }
        if (end !== -1 && end > i + 1 && !text.slice(i + 1, end).includes('\n\n')) { out.push({ t: 'em', c: parseInline(text.slice(i + 1, end)) }); i = end + 1; continue; }
      }
    }
    push(ch); i++;
  }
  return out;
}

/**
 * A chapter's Markdown as blocks: {type:'p', inline}, {type:'h', level, inline}, {type:'quote', inline},
 * {type:'list', ordered, items:[inline]}, {type:'break'} (scene break), {type:'figure', caption, kind, id}.
 */
export function parseBlocks(markdown) {
  const lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
  const blocks = [];
  let para = [];
  const flush = () => { if (para.length) { blocks.push({ type: 'p', inline: parseInline(para.join('\n')) }); para = []; } };
  for (let n = 0; n < lines.length; n++) {
    const line = lines[n];
    const figure = MARKER.exec(line.trim());
    if (figure) { flush(); blocks.push({ type: 'figure', caption: figure[1].trim(), kind: figure[2], id: figure[3] }); continue; }
    if (!line.trim()) { flush(); continue; }
    if (SCENE_BREAK.test(line)) { flush(); blocks.push({ type: 'break' }); continue; }
    const heading = /^(#{1,4})\s+(.+)$/.exec(line);
    if (heading) { flush(); blocks.push({ type: 'h', level: heading[1].length, inline: parseInline(heading[2].trim()) }); continue; }
    if (/^>\s?/.test(line)) {
      flush(); const quote = [];
      while (n < lines.length && /^>\s?/.test(lines[n])) quote.push(lines[n++].replace(/^>\s?/, ''));
      n--; blocks.push({ type: 'quote', inline: parseInline(quote.join('\n')) }); continue;
    }
    // Lists start with * + or a number. A line starting with a hyphen stays text: it is how many people type a dialogue dash.
    const item = /^(\s*)([*+]|\d+[.)])\s+(.+)$/.exec(line);
    if (item && !para.length) {
      const ordered = /\d/.test(item[2]); const items = [];
      while (n < lines.length) {
        const m = /^(\s*)([*+]|\d+[.)])\s+(.+)$/.exec(lines[n]);
        if (!m || /\d/.test(m[2]) !== ordered) break;
        items.push(parseInline(m[3])); n++;
      }
      n--; blocks.push({ type: 'list', ordered, items }); continue;
    }
    para.push(line);
  }
  flush();
  return blocks;
}

/** Plain text of inline nodes. */
export function inlineText(nodes) {
  return nodes.map(n => n.t === 'text' ? n.v : n.t === 'br' ? '\n' : inlineText(n.c)).join('');
}

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export function escapeHtml(text) { return String(text ?? '').replace(/[&<>"']/g, c => ESCAPES[c]); }

function inlineHtml(nodes) {
  return nodes.map(n => n.t === 'text' ? escapeHtml(n.v) : n.t === 'br' ? '<br>' : n.t === 'em' ? `<em>${inlineHtml(n.c)}</em>` : `<strong>${inlineHtml(n.c)}</strong>`).join('');
}

/** The story as one Markdown file: the title, the synopsis and each chapter, illustrations as their captions. */
export function storyMarkdown(story) {
  const words = bookWords(story);
  const parts = [`# ${story.title || words.untitled}`];
  if (story.series) parts.push(`*${story.series}*`);
  if (story.synopsis) parts.push(story.synopsis);
  story.chapters.forEach((c, i) => {
    const text = c.text.split('\n').map(line => { const m = MARKER.exec(line.trim()); return m ? `*[${words.illustration}${m[1] ? `: ${m[1]}` : ''}]*` : line; }).join('\n');
    parts.push(`## ${i + 1}. ${c.title || words.untitled}`, text);
  });
  return parts.join('\n\n') + '\n';
}

/**
 * The story as a page to read anywhere or print to PDF, with its illustrations inside (images: id → data URL; a
 * missing one shows its caption).
 */
export function storyHtml(story, images = new Map()) {
  const body = [], lang = storyLanguage(story), words = bookWords(story);
  if (story.cover && images.has(story.cover)) body.push(`<img class="cover" src="${escapeHtml(images.get(story.cover))}" alt="">`);
  body.push(`<h1>${escapeHtml(story.title || words.untitled)}</h1>`);
  if (story.series) body.push(`<p class="series">${escapeHtml(story.series)}</p>`);
  if (story.synopsis) body.push(`<p class="synopsis">${escapeHtml(story.synopsis)}</p>`);
  story.chapters.forEach((c, i) => {
    body.push(`<section class="chapter"><h2><span>${i + 1}</span>${escapeHtml(c.title || words.untitled)}</h2>`);
    for (const b of parseBlocks(c.text)) {
      if (b.type === 'p') body.push(`<p>${inlineHtml(b.inline)}</p>`);
      else if (b.type === 'h') body.push(`<h3>${inlineHtml(b.inline)}</h3>`);
      else if (b.type === 'quote') body.push(`<blockquote>${inlineHtml(b.inline)}</blockquote>`);
      else if (b.type === 'list') body.push(`<${b.ordered ? 'ol' : 'ul'}>${b.items.map(it => `<li>${inlineHtml(it)}</li>`).join('')}</${b.ordered ? 'ol' : 'ul'}>`);
      else if (b.type === 'break') body.push('<p class="break">* * *</p>');
      else if (b.type === 'figure') body.push(images.has(b.id) ? `<figure><img src="${escapeHtml(images.get(b.id))}" alt="${escapeHtml(b.caption)}">${b.caption ? `<figcaption>${escapeHtml(b.caption)}</figcaption>` : ''}</figure>`
        : (b.caption ? `<p class="missing">[${escapeHtml(b.caption)}]</p>` : ''));
    }
    body.push('</section>');
  });
  return `<!doctype html><html lang="${lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(story.title || words.story)}</title>
<style>body{margin:0;background:#faf8f3;color:#222;font:18px/1.7 Georgia,'Times New Roman',serif}main{max-width:40em;margin:0 auto;padding:3em 1.4em 5em}
h1{font-size:2.2em;line-height:1.2;margin:.2em 0 .3em;text-align:center}.series{text-align:center;font-style:italic;color:#666;margin:0}.synopsis{color:#555;font-style:italic;margin:2em 0 3em}
.chapter{margin-top:4em;break-before:page}h2{font-size:1.5em;line-height:1.3;margin:0 0 1.4em;text-align:center}h2 span{display:block;font-size:.6em;color:#999;letter-spacing:.2em}
p{margin:0 0 1em;text-align:justify;hyphens:auto}.break{text-align:center;color:#999;letter-spacing:.5em}figure{margin:2em 0;text-align:center}figure img,.cover{max-width:100%;border-radius:4px}
.cover{display:block;margin:0 auto 2em}figcaption{font-size:.8em;color:#777;font-style:italic;margin-top:.5em}blockquote{margin:1em 2em;font-style:italic}.missing{color:#999;font-style:italic;text-align:center}
@media print{body{background:#fff}main{padding:0}}</style></head><body><main>
${body.join('\n')}
</main></body></html>
`;
}

// ---------------------------------------------------------------------------------------------------------------------
// The story as an EPUB 3 book, for e-readers (Apple Books, Kobo, Google Play Books, Kindle by Send to Kindle, KOReader…):
// the cover, a title page, the contents and one file per chapter with its illustrations. Like the page and the Markdown,
// it leaves the plan out. Pure: the page fetches and converts the pictures, and the zip of comics.js packs the files.
// ---------------------------------------------------------------------------------------------------------------------

/** Words common in one language and rare in the others, to tell the story's language from its chapters. */
const LANGUAGE_WORDS = {
  'pt-BR': ['não', 'um', 'uma', 'do', 'da', 'dos', 'das', 'em', 'na', 'você', 'ele', 'ela', 'isso', 'então', 'também', 'mas', 'ao', 'foi', 'seu', 'sua', 'muito', 'eu', 'é'],
  en: ['the', 'and', 'of', 'to', 'was', 'he', 'she', 'his', 'her', 'that', 'it', 'with', 'you', 'for', 'but', 'had', 'not', 'they', 'is'],
  es: ['el', 'los', 'las', 'una', 'y', 'pero', 'él', 'ella', 'del', 'muy', 'dijo', 'es', 'su', 'lo', 'yo', 'en', 'un', 'por'],
  fr: ['le', 'les', 'et', 'des', 'une', 'est', 'il', 'elle', 'pas', 'qui', 'dans', 'du', 'pour', 'sur', 'avec', 'au', 'je', 'un'],
  it: ['il', 'che', 'di', 'della', 'gli', 'lei', 'lui', 'non', 'sono', 'anche', 'per', 'una', 'io', 'ma', 'è', 'un'],
  de: ['der', 'die', 'und', 'das', 'nicht', 'ist', 'ich', 'sie', 'er', 'ein', 'eine', 'mit', 'den', 'zu', 'auf', 'war'],
};
/** The language of the story, as a language tag, read from the words of its chapters (the interface's when there are none). */
export function storyLanguage(story) {
  const text = (story.chapters || []).map(c => c.text || '').join('\n').slice(0, 60000).toLowerCase();
  const counts = new Map();
  for (const word of text.match(/\p{L}+/gu) || []) counts.set(word, (counts.get(word) || 0) + 1);
  let best = language, top = 0;
  for (const [tag, words] of Object.entries(LANGUAGE_WORDS)) {
    const score = words.reduce((n, w) => n + (counts.get(w) || 0), 0);
    if (score > top) { best = tag; top = score; }
  }
  return best;
}

/** The few words the exported story says itself (the book, the page, the Markdown), in the story's language. */
const BOOK_WORDS = {
  pt: { cover: 'Capa', contents: 'Sumário', chapter: 'Capítulo', start: 'Início', untitled: 'Sem título', illustration: 'Ilustração', story: 'História' },
  en: { cover: 'Cover', contents: 'Contents', chapter: 'Chapter', start: 'Start', untitled: 'Untitled', illustration: 'Illustration', story: 'Story' },
  es: { cover: 'Portada', contents: 'Índice', chapter: 'Capítulo', start: 'Inicio', untitled: 'Sin título', illustration: 'Ilustración', story: 'Historia' },
  fr: { cover: 'Couverture', contents: 'Table des matières', chapter: 'Chapitre', start: 'Début', untitled: 'Sans titre', illustration: 'Illustration', story: 'Histoire' },
  it: { cover: 'Copertina', contents: 'Indice', chapter: 'Capitolo', start: 'Inizio', untitled: 'Senza titolo', illustration: 'Illustrazione', story: 'Storia' },
  de: { cover: 'Cover', contents: 'Inhalt', chapter: 'Kapitel', start: 'Anfang', untitled: 'Ohne Titel', illustration: 'Illustration', story: 'Geschichte' },
};
function bookWords(story) { return BOOK_WORDS[storyLanguage(story).slice(0, 2)] || BOOK_WORDS.en; }
const BOOK_TYPES = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp' };
const BOOK_CSS = `@charset "utf-8";
body { margin: 0 4%; line-height: 1.5; widows: 2; orphans: 2; }
p { margin: 0; text-indent: 1.5em; }
h2 + p, h3 + p, .break + p, figure + p, blockquote + p, ul + p, ol + p { text-indent: 0; }
h2.chapter { margin: 3em 0 2em; font-size: 1.5em; font-weight: normal; line-height: 1.3; text-align: center; page-break-before: always; break-before: page; }
h2.chapter .number { display: block; margin-bottom: .4em; font-size: .65em; letter-spacing: .2em; text-transform: uppercase; }
h3 { margin: 1.5em 0 .8em; font-size: 1.1em; text-align: center; }
.break { margin: 1.2em 0; text-indent: 0; text-align: center; letter-spacing: .5em; }
blockquote { margin: 1em 1.5em; font-style: italic; }
blockquote p { text-indent: 0; }
ul, ol { margin: 1em 0 1em 1.5em; padding: 0; }
figure { margin: 1.5em 0; text-align: center; page-break-inside: avoid; break-inside: avoid; }
figure img { max-width: 100%; max-height: 95vh; }
figcaption { margin-top: .5em; font-size: .85em; font-style: italic; }
.titlepage { margin-top: 30%; text-align: center; }
.titlepage h1 { margin: 0 0 .6em; font-size: 2em; font-weight: normal; line-height: 1.2; }
.titlepage p { text-indent: 0; }
.titlepage .series { font-style: italic; }
.titlepage .author { margin-top: 3em; font-size: 1.15em; letter-spacing: .08em; }
.cover { margin: 0; padding: 0; text-align: center; }
.cover svg, .cover img { height: 100%; max-width: 100%; }
nav ol { list-style: none; margin-left: 0; }
nav li { margin: .4em 0; }
`;

/** Text fit for XML: escaped, without the control characters XML forbids. */
function xml(text) {
  return escapeHtml(String(text ?? '').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F￾￿]|[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g, ''));
}
function inlineXhtml(nodes) {
  return nodes.map(n => n.t === 'text' ? xml(n.v) : n.t === 'br' ? '<br/>' : n.t === 'em' ? `<em>${inlineXhtml(n.c)}</em>` : `<strong>${inlineXhtml(n.c)}</strong>`).join('');
}
/** One XHTML file of the book. */
function xhtmlPage(lang, title, body, { css = true } = {}) {
  return `<?xml version="1.0" encoding="utf-8"?>\n<!DOCTYPE html>\n<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="${lang}" xml:lang="${lang}">\n`
    + `<head>\n<meta charset="utf-8"/>\n<title>${xml(title)}</title>\n${css ? '<link rel="stylesheet" type="text/css" href="style.css"/>\n' : ''}</head>\n${body}\n</html>\n`;
}
/** The story's id as a stable UUID (version 4 bits set), so a new download replaces the same book on an e-reader. */
function bookUuid(id) {
  const hex = /^[a-f0-9]{32}$/.test(String(id || '')) ? id : Array.from({ length: 32 }, () => '0123456789abcdef'[Math.floor(Math.random() * 16)]).join('');
  const h = hex.slice(0, 12) + '4' + hex.slice(13, 16) + '89ab'[parseInt(hex[16], 16) & 3] + hex.slice(17);
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/**
 * The files of the story's EPUB, in the order the zip takes them (the mimetype first, as the format asks).
 * pictures: image id → { bytes, type, width, height } for the cover and the illustrations (a missing one is left out).
 * The author is the one lettered on the cover (cover_design.author). Returns [{ name, bytes }] and the pictures used.
 */
export function storyEpub(story, pictures = new Map(), { now = Date.now() } = {}) {
  const enc = new TextEncoder(), lang = storyLanguage(story), words = bookWords(story);
  const title = String(story.title || '').trim() || words.untitled, author = String(story.cover_design?.author || '').trim(), series = String(story.series || '').trim();
  const uuid = bookUuid(story.id);
  const modified = new Date(Number.isFinite(story.updated) && story.updated > 0 ? story.updated * 1000 : now).toISOString().replace(/\.\d+Z$/, 'Z');
  const files = [{ name: 'mimetype', text: 'application/epub+zip' },
    { name: 'META-INF/container.xml', text: '<?xml version="1.0" encoding="utf-8"?>\n<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">\n  <rootfiles>\n    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>\n  </rootfiles>\n</container>\n' }];
  const manifest = [], spine = [], used = new Map();
  // A picture becomes images/<id>.<ext> once, however many times the book shows it.
  const image = id => {
    if (used.has(id)) return used.get(id);
    const p = pictures.get(id), ext = p && BOOK_TYPES[p.type];
    if (!ext || !p.bytes?.length) return null;
    const item = { id: `img-${id}`, href: `images/${id}.${ext}`, type: p.type, width: p.width, height: p.height, bytes: p.bytes, cover: false };
    used.set(id, item);
    return item;
  };
  const toc = [];

  // The cover, on a page of its own (an SVG keeps it whole on any screen when its size is known).
  const cover = story.cover ? image(story.cover) : null;
  if (cover) {
    cover.cover = true;
    const art = cover.width > 0 && cover.height > 0
      ? `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" width="100%" height="100%" viewBox="0 0 ${cover.width} ${cover.height}" preserveAspectRatio="xMidYMid meet"><image width="${cover.width}" height="${cover.height}" xlink:href="${cover.href}"/></svg>`
      : `<img src="${cover.href}" alt="${xml(title)}"/>`;
    files.push({ name: 'OEBPS/cover.xhtml', text: xhtmlPage(lang, words.cover, `<body class="cover" epub:type="cover">\n<div class="cover">${art}</div>\n</body>`) });
    manifest.push(`<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"${cover.width > 0 && cover.height > 0 ? ' properties="svg"' : ''}/>`);
    spine.push('<itemref idref="cover"/>');
  }

  // The title page: the title, the series and the author.
  files.push({ name: 'OEBPS/title.xhtml', text: xhtmlPage(lang, title, `<body>\n<section class="titlepage" epub:type="titlepage">\n<h1>${xml(title)}</h1>\n`
    + `${series ? `<p class="series">${xml(series)}</p>\n` : ''}${author ? `<p class="author">${xml(author)}</p>\n` : ''}</section>\n</body>`) });
  manifest.push('<item id="titlepage" href="title.xhtml" media-type="application/xhtml+xml"/>');
  // The contents come next as a page too, as Kindle's guidelines ask (an inline table of contents).
  spine.push('<itemref idref="titlepage"/>', '<itemref idref="nav"/>');

  // One file per chapter. Illustrations still being made, and pictures that did not come, are left out.
  (story.chapters || []).forEach((c, i) => {
    const n = i + 1, name = `chapter-${String(n).padStart(3, '0')}.xhtml`, heading = String(c.title || '').trim();
    const label = heading || `${words.chapter} ${n}`;
    const body = [`<section epub:type="chapter" aria-labelledby="h${n}">`, `<h2 class="chapter" id="h${n}"><span class="number">${n}</span>${xml(label)}</h2>`];
    for (const b of parseBlocks(c.text)) {
      if (b.type === 'p') body.push(`<p>${inlineXhtml(b.inline)}</p>`);
      else if (b.type === 'h') body.push(`<h3>${inlineXhtml(b.inline)}</h3>`);
      else if (b.type === 'quote') body.push(`<blockquote><p>${inlineXhtml(b.inline)}</p></blockquote>`);
      else if (b.type === 'list') body.push(`<${b.ordered ? 'ol' : 'ul'}>${b.items.map(it => `<li>${inlineXhtml(it)}</li>`).join('')}</${b.ordered ? 'ol' : 'ul'}>`);
      else if (b.type === 'break') body.push('<p class="break">* * *</p>');
      else if (b.type === 'figure' && b.kind === 'img') {
        const item = image(b.id);
        if (item) body.push(`<figure><img src="${item.href}" alt="${xml(b.caption)}"/>${b.caption ? `<figcaption>${xml(b.caption)}</figcaption>` : ''}</figure>`);
      }
    }
    body.push('</section>');
    files.push({ name: `OEBPS/${name}`, text: xhtmlPage(lang, label, `<body epub:type="bodymatter">\n${body.join('\n')}\n</body>`) });
    manifest.push(`<item id="c${n}" href="${name}" media-type="application/xhtml+xml"/>`);
    spine.push(`<itemref idref="c${n}"/>`);
    toc.push({ href: name, label: heading ? `${n}. ${heading}` : label });
  });

  // The contents, as EPUB 3 readers read them (nav.xhtml) and as older ones do (toc.ncx).
  const start = toc[0]?.href || 'title.xhtml';
  files.push({ name: 'OEBPS/nav.xhtml', text: xhtmlPage(lang, words.contents, `<body>\n<nav epub:type="toc" id="toc">\n<h1>${xml(words.contents)}</h1>\n<ol>\n`
    + `${(toc.length ? toc : [{ href: 'title.xhtml', label: title }]).map(t => `<li><a href="${t.href}">${xml(t.label)}</a></li>`).join('\n')}\n</ol>\n</nav>\n`
    + `<nav epub:type="landmarks" id="landmarks" hidden="hidden">\n<ol>\n${cover ? `<li><a epub:type="cover" href="cover.xhtml">${xml(words.cover)}</a></li>\n` : ''}`
    + `<li><a epub:type="toc" href="nav.xhtml">${xml(words.contents)}</a></li>\n<li><a epub:type="bodymatter" href="${start}">${xml(words.start)}</a></li>\n</ol>\n</nav>\n</body>`) });
  files.push({ name: 'OEBPS/toc.ncx', text: `<?xml version="1.0" encoding="utf-8"?>\n<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="${lang}">\n`
    + `<head>\n<meta name="dtb:uid" content="urn:uuid:${uuid}"/>\n<meta name="dtb:depth" content="1"/>\n<meta name="dtb:totalPageCount" content="0"/>\n<meta name="dtb:maxPageNumber" content="0"/>\n</head>\n`
    + `<docTitle><text>${xml(title)}</text></docTitle>\n${author ? `<docAuthor><text>${xml(author)}</text></docAuthor>\n` : ''}<navMap>\n`
    + (toc.length ? toc : [{ href: 'title.xhtml', label: title }]).map((t, i) => `<navPoint id="p${i + 1}" playOrder="${i + 1}"><navLabel><text>${xml(t.label)}</text></navLabel><content src="${t.href}"/></navPoint>`).join('\n')
    + '\n</navMap>\n</ncx>\n' });
  files.push({ name: 'OEBPS/style.css', text: BOOK_CSS });
  manifest.push('<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>', '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>',
    '<item id="css" href="style.css" media-type="text/css"/>');
  for (const item of used.values()) {
    manifest.push(`<item id="${item.id}" href="${item.href}" media-type="${item.type}"${item.cover ? ' properties="cover-image"' : ''}/>`);
    files.push({ name: `OEBPS/${item.href}`, bytes: item.bytes });
  }

  const metadata = [`<dc:identifier id="book-id">urn:uuid:${uuid}</dc:identifier>`, `<dc:title>${xml(title)}</dc:title>`, `<dc:language>${lang}</dc:language>`,
    author ? `<dc:creator id="author">${xml(author)}</dc:creator>` : '', author ? '<meta refines="#author" property="role" scheme="marc:relators">aut</meta>' : '',
    story.synopsis ? `<dc:description>${xml(story.synopsis)}</dc:description>` : '',
    series ? `<meta property="belongs-to-collection" id="series">${xml(series)}</meta>` : '', series ? '<meta refines="#series" property="collection-type">series</meta>' : '',
    series ? `<meta name="calibre:series" content="${xml(series)}"/>` : '',
    `<meta property="dcterms:modified">${modified}</meta>`, cover ? `<meta name="cover" content="${cover.id}"/>` : ''].filter(Boolean);
  // Readers that still read the EPUB 2 guide (older Kindles among them) find the cover and the start there.
  const guide = [cover ? '<reference type="cover" title="' + xml(words.cover) + '" href="cover.xhtml"/>' : '', `<reference type="toc" title="${xml(words.contents)}" href="nav.xhtml"/>`,
    `<reference type="text" title="${xml(words.start)}" href="${start}"/>`].filter(Boolean);
  files.splice(2, 0, { name: 'OEBPS/content.opf', text: `<?xml version="1.0" encoding="utf-8"?>\n<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="book-id" xml:lang="${lang}">\n`
    + `<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">\n${metadata.map(m => `  ${m}`).join('\n')}\n</metadata>\n<manifest>\n${manifest.map(m => `  ${m}`).join('\n')}\n</manifest>\n`
    + `<spine toc="ncx">\n${spine.map(s => `  ${s}`).join('\n')}\n</spine>\n<guide>\n${guide.map(g => `  ${g}`).join('\n')}\n</guide>\n</package>\n` });
  return { files: files.map(f => ({ name: f.name, bytes: f.bytes || enc.encode(f.text) })), used: [...used.keys()] };
}

/** A file name from a title. */
export function fileName(title, extension) {
  const base = String(title || t('story', { ctx: 'file' })).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase().slice(0, 60);
  return `${base || t('story', { ctx: 'file' })}.${extension}`;
}

/** What the writer is doing, from the tool it is calling, for the line shown while it works. */
export const TOOL_DOING = {
  get_outline: t('Checking the outline'), read_chapter: t('Reading a chapter'), write_chapter: t('Writing a chapter'), append_to_chapter: t('Continuing the chapter'),
  edit_chapter: t('Revising a chapter'), rewrite_chapter: t('Rewriting a chapter'), delete_chapter: t('Deleting a chapter'), move_chapter: t('Reordering the chapters'),
  read_notes: t('Reading the story bible'), update_notes: t('Updating the bible'), read_plan: t('Checking the plan'), update_plan: t('Updating the plan'),
  update_story: t('Updating the story\'s details'), search_story: t('Checking a detail'), illustrate: t('Asking for an illustration'), edit_illustration: t('Editing an illustration'), view_image: t('Looking at an image'),
  search_gallery: t('Searching the gallery for images'), character_sheet: t('Making a sheet'), update_character: t('Updating the sheets'), set_style: t('Setting the visual style'),
  read_scenes: t('Reading scenes'), write_scene: t('Writing a scene'), edit_scene: t('Revising a scene'), delete_scene: t('Deleting a scene'), move_scene: t('Reordering the scenes'),
  vn_settings: t('Adjusting variables, routes and music'), read_gameplay: t('Reading the gameplay notes'), update_gameplay: t('Updating the gameplay notes'), check_vn: t('Checking the choice tree'),
  make_sprite: t('Asking for a sprite'), make_background: t('Asking for a background'), make_cg: t('Asking for a CG'), update_art: t('Organizing the art'),
};
export function doing(tool) { return TOOL_DOING[tool] ? `${TOOL_DOING[tool]}…` : t('Thinking…'); }
/** Tools whose input is prose: while one streams in, the page counts its words. */
const WRITES = new Set(['write_chapter', 'append_to_chapter', 'rewrite_chapter', 'edit_chapter', 'update_notes', 'update_plan', 'write_scene', 'edit_scene', 'update_gameplay']);

/** Seconds as the page says them: 40 s, 3 min 05 s, 1 h 05 min. */
export function duration(seconds) {
  const s = Math.max(0, Math.round(Number(seconds) || 0));
  if (s < 60) return `${s} s`;
  if (s < 3600) { const m = Math.floor(s / 60), r = s % 60; return r ? `${m} min ${String(r).padStart(2, '0')} s` : `${m} min`; }
  return `${Math.floor(s / 3600)} h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')} min`;
}

/**
 * What the writer is doing, for the block shown while it works: the line beside the spinner, the details under it
 * (step, time, words being written) and a warning when the provider failed and it is trying again.
 */
/**
 * A reply of the writer split for the chat while its replies are hidden. The writer puts between <no_spoilers> and
 * </no_spoilers> what can be read before the chapters (what it did, its question, technical answers): that shows; the
 * rest may tell the story and stays hidden. safe: the marked parts, joined; full: the whole reply without the tags;
 * hiddenWords: the words outside the marked parts. A block left open counts to the end (a reply being streamed, or one
 * whose closing tag was forgotten), and a tag cut off at the end of a streamed reply is dropped. A reply with no block
 * (older ones, or one with nothing safe to say) is all hidden.
 */
const SAFE_TAG = /<\s*(\/?)\s*no_spoilers\s*>/gi;
export function spoilerParts(text) {
  let raw = String(text || '');
  const cut = raw.lastIndexOf('<'), tail = raw.slice(cut).replace(/\s/g, '').toLowerCase();
  if (cut >= 0 && !tail.includes('>') && ['<no_spoilers>', '</no_spoilers>'].some(tag => tag.startsWith(tail))) raw = raw.slice(0, cut);
  const safe = [], hidden = [];
  let open = false, at = 0;
  for (const m of raw.matchAll(SAFE_TAG)) {
    (open ? safe : hidden).push(raw.slice(at, m.index));
    open = m[1] !== '/'; at = m.index + m[0].length;
  }
  (open ? safe : hidden).push(raw.slice(at));
  const join = parts => parts.map(p => p.trim()).filter(Boolean).join('\n\n');
  const rest = join(hidden);
  return { safe: join(safe), full: raw.replace(SAFE_TAG, '').replace(/\n{3,}/g, '\n\n').trim(), hiddenWords: (rest.match(/\S+/g) || []).length };
}

export function liveStatus(turn) {
  const live = turn.live || {};
  const what = turn.status === 'queued' ? t('Starting…') : turn.status === 'cancelling' ? t('Stopping…')
    : live.tool ? doing(live.tool) : live.text ? t('Answering…') : t('Thinking…');
  const details = [];
  if (live.step > 1) details.push(t('step {n}', { n: live.step }));
  if (turn.started && turn.now >= turn.started) details.push(duration(turn.now - turn.started));
  if (WRITES.has(live.tool) && live.words >= 10) details.push(t('~{n} words', { n: number(live.words) }));
  const warnings = [];
  if (live.retry) warnings.push(live.retry);
  if (live.fallback) warnings.push(t('The chosen model refused this step; Anthropic passed the request to {model}.', { model: live.fallback }));
  return { what, details, warnings };
}

/**
 * The steps of a working turn that the chat doesn't show yet. The turn lists every step it finished (live.done), and the
 * chat gets them as a message once the writer's answer has run all its tools: only the rest goes above the live block,
 * so each step shows once and in order, and what the writer is doing now stays last.
 */
export function unsavedSteps(turn, messages) {
  const done = turn?.live?.done || [];
  const saved = messages.reduce((n, m) => n + (m.role === 'tools' && m.turn_id === turn.id ? m.results?.length || 0 : 0), 0);
  return done.slice(saved);
}

/** Why a turn ended without finishing, as the chat says it. */
export function stoppedMessage(turn) {
  if (turn.status !== 'interrupted') return turn.error || t('The writer could not finish. What it already saved stays in the story.');
  const time = turn.started && turn.updated > turn.started ? duration(turn.updated - turn.started) : null;
  const doingNow = TOOL_DOING[turn.was?.tool], was = doingNow ? doingNow.charAt(0).toLowerCase() + doingNow.slice(1) : null;
  const stopped = time ? (was ? t('The server stopped the writer without warning after {time} of work (it was {doing}).', { time, doing: was }) : t('The server stopped the writer without warning after {time} of work.', { time }))
    : was ? t('The server stopped the writer without warning (it was {doing}).', { doing: was }) : t('The server stopped the writer without warning.');
  return `${stopped} ${t('This happens when the host ends the process: a time limit, lack of memory or a restart (Settings › Hosting test shows this server\'s limit). What it already saved stays in the story.')}`;
}

export const EFFORTS = [['low', t('Low', { ctx: 'effort' })], ['medium', t('Medium', { ctx: 'effort' })], ['high', t('High', { ctx: 'effort' })], ['xhigh', t('Extra high', { ctx: 'effort' })], ['max', t('Maximum', { ctx: 'effort' })]];
/** How the writer illustrates a story: the choice, its name and what it means. */
export const ILLUSTRATE = [
  ['text', t('Text only'), t('The writer only writes and does not even offer images. When you want one, ask in the chat or use the Illustrate button.')],
  ['ask', t('When I ask'), t('The writer may suggest a moment to illustrate, but only makes images when you ask.')],
  ['each', t('One per chapter'), t('Each chapter gets an image at its most striking moment. Each image uses OpenAI credit.')],
];
/** Settings saved before the three choices were a yes or no. */
export function illustrateMode(value) { return value === true ? 'each' : ILLUSTRATE.some(([id]) => id === value) ? value : 'ask'; }
export function effortLabel(effort) { return (EFFORTS.find(([id]) => id === effort) || [effort, effort])[1]; }

/** Moves an item of a list to another index: the order the page shows while the server saves it. */
export function moved(list, from, to) {
  const copy = list.slice(); const [item] = copy.splice(from, 1); copy.splice(Math.max(0, Math.min(copy.length, to)), 0, item); return copy;
}
