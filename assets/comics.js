// Comics: a story adapted as a manga or a comic book. Each page has a script (its panels, who is in them, what they
// say) and a storyboard (the writer's own SVG, or one the app draws from the script); it is drawn whole, in one picture.
// The window of the adaptation, the page's script changed by hand, the reader of the drawn pages and the downloads
// (PDF and CBZ, made on this device). stories.js hands in what it shares. Text from a person or a model is only ever set
// as text: never through innerHTML.
import { t, number } from './i18n.js';
import { storyLanguage } from './story.js';

export function createComics(ctx) {
  const { api, h, icon, toast, confirmAction, imageUrl, openViewer, trackJob, launchQueued, formatUsd, plural, $ } = ctx;
  const fill = (el, ...children) => el.replaceChildren(...children.flat().filter(c => c !== null && c !== undefined && c !== false));
  const ACTIVE = new Set(['queued', 'running', 'cancelling']);
  // Comics::KINDS, READINGS, COLORS, ASPECTS, SCALES, TEXT_TYPES, MAX_PANELS, MAX_STYLE_IMAGES
  const KINDS = [['manga', t('Manga')], ['comic', t('Comic book')], ['graphic_novel', 'Graphic novel']];
  // How each kind tells a story: they are different languages, and the writer adapts to the one chosen.
  const KIND_HINTS = {
    manga: t('Manga: decompressed pacing, one moment over several panels, speed lines, screentone, chibi in the light scenes, flashbacks on black backgrounds.'),
    comic: t('Comic book: like a monthly series, fuller pages, narration captions, strong colors, action and a hook at the end of each chapter.'),
    graphic_novel: t('Graphic novel: a book read in one go, with the pacing of a novel, more personal art, freer pages, silence and literary narration.') };
  // The kind inside a sentence (the writer's messages): "as a manga", "as a comic book"; and its name alone.
  const kindWord = kind => ({ manga: t('as a manga'), comic: t('as a comic book'), graphic_novel: t('as a graphic novel') })[kind] || t('as comics');
  const kindName = kind => ({ manga: t('manga', { ctx: 'kind' }), comic: t('comic book', { ctx: 'kind' }), graphic_novel: t('graphic novel', { ctx: 'kind' }) })[kind] || t('comics', { ctx: 'kind' });
  const READINGS = [['rtl', t('Right → left')], ['ltr', t('Left → right')]];
  const COLORS = [['bw', t('Black and white')], ['color', t('Color', { ctx: 'comics' })]];
  const ASPECTS = [['2:3', t('2:3 (the most common)')], ['3:4', '3:4'], ['4:5', '4:5'], ['9:16', t('9:16 (to read on a phone)')], ['1:1', t('1:1 (square)')]];
  const SCALES = [['1', '1×'], ['1.5', `${number(1.5)}×`], ['2', '2×']];
  const TEXT_TYPES = [['speech', t('Speech')], ['thought', t('Thought')], ['whisper', t('Whisper')], ['shout', t('Shout')], ['caption', t('Narration')], ['sfx', t('Sound effect')], ['sign', t('Sign')]];
  const MAX_PANELS = 12, MAX_PANEL_CHARACTERS = 8, MAX_PANEL_TEXTS = 12, MAX_STYLE_IMAGES = 4, MAX_DRAW_REFS = 3;
  const labelOf = (list, id) => (list.find(([k]) => k === id) || [id, id])[1];
  const ratio = aspect => aspect.replace(':', ' / ');

  const story = () => ctx.story();
  const comic = () => story()?.comic || null;
  // The adaptation's own sheets and lettering (apart from the book's): the pages are drawn with these.
  const sheets = () => comic()?.cast || { characters: [], style: { lettering: {} } };
  const dialog = () => $('#comic-dialog');
  // view: the pages, or one page (page: its id). draft: the script being changed by hand (or a new page).
  const ui = { view: 'pages', page: null, draft: null, settings: false, instruction: '', fix: '', refs: [], previous: true, busy: false, error: '', styleBox: null, story: null, reorder: false };

  /**
   * Pictures stay the same elements from one redraw to the next (the server sends images with no-store, and the window
   * is redrawn while pages are drawn): they would load again and blink. Only the ones of the last redraw are kept.
   */
  let kept = new Map(), used = new Map();
  function pic(src, alt, cls = '') {
    let img = used.has(src) ? null : kept.get(src);
    if (img) { if (img.alt !== alt) img.alt = alt; }
    else img = h('img', { src, alt, loading: 'lazy', decoding: 'async' });
    if (!used.has(src)) used.set(src, img);
    img.className = cls;
    return img;
  }
  function startPics() { used = new Map(); }
  function endPics() { kept = used; }

  /** The page's storyboard as the image model gets it: the writer's SVG, or the app's from the script. */
  function sketchUrl(p) {
    const s = comic().settings;
    return `index.php?action=comic-sketch&story=${encodeURIComponent(story().id)}&page=${encodeURIComponent(p.id)}&v=${p.rev}.${s.aspect}.${s.reading}`;
  }

  /** Where a page is: drawn (or drawn from an older script), being drawn, failed, waiting for sheets, or only a script. */
  function pageState(p) {
    const pending = ctx.pending();
    if (p.job) {
      const job = pending.get(p.job);
      if (!job || ACTIVE.has(job.status)) return { kind: job?.status === 'running' ? 'running' : 'queued' };
      if (job.status !== 'completed') return { kind: 'failed', error: job.error || '' };
    }
    // A new version of the drawing (Corrigir, Melhorar, Ampliar, or the writer's edit_illustration): the page stays until it is ready.
    let edit = null;
    if (p.image) {
      const found = Object.entries(story().editing || {}).find(([, e]) => e.image === p.image);
      const job = found && pending.get(found[0]);
      if (found && !found[1].failed && (!job || ACTIVE.has(job.status))) edit = { status: job?.status === 'running' ? 'running' : 'queued' };
      else if (found && job && !ACTIVE.has(job.status) && job.status !== 'completed') edit = { status: 'failed', error: job.error || '' };
    }
    if (p.image) return { kind: p.drawn !== null && p.drawn < p.rev ? 'stale' : 'drawn', edit };
    if (p.missing?.length) return { kind: 'missing' };
    return { kind: 'script' };
  }

  /** What the adaptation holds, in a line (the side of the story). */
  function summary(s) {
    const c = s.comic;
    if (!c?.settings?.started) return t('Adapt as manga, comic book or graphic novel');
    const n = c.pages.length, drawn = c.pages.filter(p => p.image).length;
    return `${labelOf(KINDS, c.settings.kind)} · ${n ? `${plural(n, t('{n} page'), t('{n} pages'))} · ${plural(drawn, t('{n} drawn'), t('{n} drawn', { ctx: 'many' }))}` : t('no pages yet')}`;
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The window
  // ---------------------------------------------------------------------------------------------------------------
  function open(pageId = null) {
    if (!story()) return;
    if (ui.story !== story().id) Object.assign(ui, { instruction: '', fix: '', refs: [], previous: true, settings: false, story: story().id });
    Object.assign(ui, { view: pageId ? 'page' : 'pages', page: pageId, draft: null, error: '', styleBox: null, reorder: false });
    render();
    if (!dialog().open) dialog().showModal();
  }
  function close() {
    if (dialog()?.open) dialog().close();
    if (reader()?.open) reader().close();
  }
  /** The story changed (a poll, a page drawn, the writer at work): the window and the reader follow it. */
  function refresh() {
    const s = story();
    if (!s || s.id !== ui.story) { close(); return; }
    if (reader()?.open) refreshReader();
    if (!dialog()?.open) return;
    // What is being typed in a page's script stays: the window says the page changed meanwhile instead.
    if (ui.draft) { markChanged(); return; }
    render();
  }
  dialog()?.addEventListener('close', () => { ui.draft = null; kept = new Map(); });

  function render() {
    const body = $('#comic-body'); if (!body || !story() || !comic()) return;
    startPics();
    const c = comic();
    // A page moved with the keyboard keeps its arrow focused, so it can go on moving.
    const moving = body.contains(document.activeElement) ? document.activeElement.dataset.move : null;
    $('#comic-title').textContent = c.settings.started ? `${labelOf(KINDS, c.settings.kind)}: ${story().title || t('Untitled')}` : t('Comics');
    if (ui.draft) fill(body, editor());
    else {
      const p = ui.view === 'page' ? c.pages.find(x => x.id === ui.page) : null;
      if (ui.view === 'page' && !p) ui.view = 'pages';
      fill(body, p ? pageView(p) : overview());
    }
    endPics();
    if (moving) (body.querySelector(`[data-move="${CSS.escape(moving)}"]:not(:disabled)`) || body.querySelector(`[data-move^="${CSS.escape(moving.split(':')[0])}:"]:not(:disabled)`))?.focus();
  }
  function showPage(id) { Object.assign(ui, { view: 'page', page: id, error: '', fix: '' }); render(); $('#comic-body')?.scrollTo?.({ top: 0 }); dialog()?.scrollTo?.({ top: 0 }); }
  function showPages() { Object.assign(ui, { view: 'pages', page: null, error: '' }); render(); }

  // The adaptation: its settings, what is missing, the pages
  function overview() {
    const c = comic(), set = c.settings;
    if (!set.started) return [
      h('p', { class: 'hint', text: t('The writer adapts the story into comic pages. First it reads the whole story and thinks about how to tell it best in the chosen format: it can reorder, cut, merge or add scenes and rewrite the lines, using the format\'s own devices. Then it scripts each page (panels, who appears, lines, narration and sound effects), draws a simple storyboard of each one and asks for the whole page in a single generation, with the sheets of the characters and settings, the previous page and the lettering sample as reference. That way the line work, the faces and the lettering stay the same from one page to the next.') }),
      settingsForm(),
      h('div', { class: 'row-end' },
        h('button', { type: 'button', class: 'btn ghost', onclick: () => saveSettings({ kind: set.kind }) }, t('Start without the writer')),
        h('button', { type: 'button', class: 'btn primary', onclick: startWithWriter }, icon('feather'), h('span', { text: t('Ask the writer') })))];
    const pages = c.pages, drawn = pages.filter(p => p.image).length, reorder = ui.reorder && pages.length > 1;
    return [
      h('div', { class: 'comic-top' },
        h('div', { class: 'comic-chips' }, ...chips(set).map(text => h('span', { class: 'pill', text }))),
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'btn ghost small', 'aria-expanded': String(ui.settings), onclick: () => { ui.settings = !ui.settings; ui.styleBox = null; render(); } }, icon('sliders'), h('span', { text: t('Set up') })),
        h('button', { type: 'button', class: 'btn secondary small', disabled: !drawn, title: drawn ? t('Read the drawn pages') : t('No page drawn yet'), onclick: () => openReader() }, icon('book'), h('span', { text: t('Read') })),
        h('button', { type: 'button', class: 'btn ghost small', disabled: !drawn, title: t('Download the drawn pages as PDF'), 'data-export': 'pdf', onclick: () => exportComic('pdf') }, icon('download'), h('span', { text: 'PDF' })),
        h('button', { type: 'button', class: 'btn ghost small', disabled: !drawn, title: t('Download as CBZ (for comic readers)'), 'data-export': 'cbz', onclick: () => exportComic('cbz') }, icon('download'), h('span', { text: 'CBZ' }))),
      ui.settings ? settingsForm() : null,
      workspace(),
      ...warnings(),
      pages.length
        ? h('div', { class: 'comic-grid', vars: { '--page-ratio': ratio(set.aspect) } }, ...pages.map((p, i) => reorder ? moveCard(p, i) : card(p)))
        : h('p', { class: 'hint', text: t('No pages yet. Ask the writer to script the first ones (it draws the storyboard of each one and shows it before asking for the drawing), or build one yourself.') }),
      h('div', { class: 'row-end comic-foot' },
        pages.length > 1 ? h('button', { type: 'button', class: 'btn ghost small', id: 'comic-reorder', 'aria-pressed': String(reorder), title: reorder ? t('Stop reordering') : t('Change the order of the pages'),
          onclick: () => { ui.reorder = !reorder; render(); $('#comic-reorder')?.focus(); } }, icon(reorder ? 'check' : 'sort'), h('span', { text: reorder ? t('Done', { ctx: 'reorder' }) : t('Reorder') })) : null,
        pages.length > 1 ? h('span', { class: 'grow' }) : null,
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => startEdit(null) }, icon('plus'), h('span', { text: t('Page written by you') })),
        h('button', { type: 'button', class: 'btn secondary small', onclick: () => ctx.prefillChat(pages.length
          ? t('Continue the adaptation {kind}, following the adaptation plan: script the next pages with a storyboard, check the missing sheets and draw.', { kind: kindWord(set.kind) })
          : startText(set.kind)) },
        icon('feather'), h('span', { text: t('Ask the writer') })))];
  }
  /** The adaptation's own workspace, apart from the book's: its plan, its bible, its sheets and lettering. */
  function workspace() {
    const c = comic(), notes = c.notes || {}, plan = c.plan || {}, cast = sheets();
    const people = cast.characters.filter(x => x.kind !== 'place').length, places = cast.characters.length - people, l = cast.style.lettering || {};
    const words = n => plural(n, t('{n} word'), t('{n} words'));
    const doc = (name, cls, ic, title, line) => h('button', { type: 'button', class: `side-doc${cls}`, 'data-comic-space': name, onclick: () => { close(); name === 'cast' ? ctx.openCast('comic') : ctx.openDocument(name); } },
      icon(ic), h('span', {}, h('strong', { text: title }), h('small', { text: line })));
    return h('div', { class: 'comic-space' },
      doc('comic_plan', ' spoiler', 'eye-off', t('The adaptation\'s plan'), plan.words ? `${words(plan.words)} · escondido (spoilers)` : t('Empty: the writer plans before the 1st page')),
      doc('comic_notes', '', 'book', t('The adaptation\'s bible'), notes.words ? t('{words} · what changed, looks, conventions', { words: words(notes.words) }) : t('Empty: the writer fills it in')),
      doc('cast', '', 'user', t('Sheets and lettering of the adaptation'), [people ? plural(people, t('{n} character'), t('{n} characters')) : null, places ? plural(places, t('{n} place'), t('{n} places')) : null,
        l.image ? t('lettering with a sample') : l.text ? t('lettering without a sample') : null].filter(Boolean).join(' · ') || t('Empty: bring them from the book or ask the writer')));
  }
  function chips(set) {
    return [labelOf(KINDS, set.kind), labelOf(COLORS, set.color), set.reading === 'rtl' ? t('Reads right to left') : t('Reads left to right'), t('Pages {aspect}', { aspect: set.aspect }), t('Size {scale}', { scale: labelOf(SCALES, set.scale) })];
  }
  function startWithWriter() {
    const set = comic().settings;
    saveSettings({ kind: set.kind }).then(ok => {
      if (ok) ctx.prefillChat(startText(set.kind));
    });
  }
  /** What the writer is asked to start the adaptation: an adaptation of the whole story, not the chapters in panels. */
  function startText(kind) {
    return t('Let\'s adapt the story {kind}. Read the whole story first and plan the adaptation: how to tell it best {kind}, what to reorder, cut, merge or add, and which devices of the format to use. Use the adaptation\'s own plan, bible and sheets. Build the comics lettering and the adaptation\'s sheets (bring from the book the ones that work; each setting with at least one reference image), script the first pages with a storyboard and draw the first one for me to see.', { kind: kindWord(kind) });
  }

  /** What stops pages from coming out right: no sample of the letters, characters or places without a sheet picture. */
  function warnings() {
    const c = comic(), out = [];
    const lettering = sheets().style?.lettering || {};
    if (!lettering.image) out.push(h('div', { class: 'comic-warn' }, icon('alert'),
      h('div', {},
        h('p', { text: lettering.job ? t('The lettering sample is being made. Pages drawn before it may come out with different lettering.') : t('No lettering sample: each page may come out with balloons, speech and sound effects done its own way. Define the lettering and generate the sample (it goes with every page).') }),
        h('div', { class: 'sheet-actions' },
          h('button', { type: 'button', class: 'link-btn', onclick: () => { close(); ctx.openCast('comic'); } }, t('Open the adaptation\'s lettering')),
          h('button', { type: 'button', class: 'link-btn', onclick: () => ctx.prefillChat(t('Define the comics lettering (balloons, narration and sound effects the {kind} way) and generate the sample.', { kind: kindName(c.settings.kind) })) }, t('Ask the writer'))))));
    const waiting = c.pages.filter(p => p.missing?.length && !p.image);
    if (waiting.length) {
      const names = [...new Set(waiting.flatMap(p => p.missing))];
      out.push(h('div', { class: 'comic-warn' }, icon('alert'),
        h('div', {},
          h('p', { text: `${waiting.length === 1 ? t('Page {n} is waiting for adaptation sheets with an image of: {names}.', { n: waiting[0].number, names: names.join(', ') }) : t('{n} pages are waiting for adaptation sheets with an image of: {names}.', { n: waiting.length, names: names.join(', ') })} ${t('Each character and each setting of a page needs a reference image before the drawing.')}` }),
          h('div', { class: 'sheet-actions' },
            h('button', { type: 'button', class: 'link-btn', onclick: () => { close(); ctx.openCast('comic'); } }, t('Open the adaptation\'s sheets')),
            h('button', { type: 'button', class: 'link-btn', onclick: () => ctx.prefillChat(t('Make the adaptation\'s sheets with an image of: {names} (bring from the book the ones that work), to draw the comics pages.', { names: names.join(', ') })) }, t('Ask the writer'))))));
    }
    return out;
  }

  function card(p) {
    const state = pageState(p);
    return h('button', { type: 'button', class: `comic-card ${state.kind}`, onclick: () => showPage(p.id), title: p.summary || t('Page {n}', { n: p.number }) },
      h('span', { class: 'comic-thumb' },
        p.image ? pic(imageUrl(p.image, true), t('Page {n}', { n: p.number })) : pic(sketchUrl(p), t('Storyboard of page {n}', { n: p.number }), 'sketch'),
        badge(state)),
      h('span', { class: 'comic-card-text' }, h('strong', { text: t('Page {n}', { n: p.number }) }), p.summary ? h('small', { text: p.summary }) : null));
  }
  /** A page in "Reordenar": the arrows take the place of opening it (in plain view they were too easy to press by mistake). */
  function moveCard(p, i) {
    const last = comic().pages.length - 1;
    return h('div', { class: 'comic-card moving' },
      h('span', { class: 'comic-thumb' }, p.image ? pic(imageUrl(p.image, true), t('Page {n}', { n: p.number })) : pic(sketchUrl(p), t('Storyboard of page {n}', { n: p.number }), 'sketch')),
      h('span', { class: 'comic-card-move' },
        h('button', { type: 'button', class: 'icon-btn small', title: t('Move earlier'), 'aria-label': t('Page {n} earlier', { n: p.number }), 'data-move': `${p.id}:before`, disabled: i === 0, onclick: () => movePage(p, i - 1) }, icon('chevron-left')),
        h('strong', { text: t('Page {n}', { n: p.number }) }),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Move later'), 'aria-label': t('Page {n} later', { n: p.number }), 'data-move': `${p.id}:after`, disabled: i === last, onclick: () => movePage(p, i + 1) }, icon('chevron-right'))));
  }
  function badge(state) {
    if (state.kind === 'queued' || state.kind === 'running' || state.edit?.status === 'queued' || state.edit?.status === 'running')
      return h('span', { class: 'comic-badge busy' }, h('span', { class: 'spinner small' }), h('span', { text: state.kind === 'running' || state.edit?.status === 'running' ? t('Drawing') : t('Queued') }));
    if (state.kind === 'failed') return h('span', { class: 'comic-badge failed' }, icon('alert'), h('span', { text: t('Did not come out') }));
    if (state.kind === 'missing') return h('span', { class: 'comic-badge warn', text: t('Sheets missing') });
    if (state.kind === 'stale') return h('span', { class: 'comic-badge warn', text: t('Script changed') });
    if (state.kind === 'script') return h('span', { class: 'comic-badge', text: t('Script only') });
    return null;
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The settings: what kind of comic, how it reads, its color, the pages' shape and size, its own style
  // ---------------------------------------------------------------------------------------------------------------
  function settingsForm() {
    const set = comic().settings, s = story();
    const seg = (label, list, value, change) => h('div', { class: 'stack tight' }, h('span', { class: 'field-label', text: label }),
      h('div', { class: 'segmented', role: 'group', 'aria-label': label }, ...list.map(([id, text]) => h('button', { type: 'button', 'aria-pressed': String(value === id), onclick: () => change(id) }, text))));
    const local = !set.started; // before it starts, the choices are kept here and saved with Começar
    const change = c => { if (local) { Object.assign(set, c); render(); } else saveSettings(c); };
    const box = ui.styleBox ||= h('textarea', { id: 'comic-style', rows: 3, maxlength: 4000, 'aria-label': t('The comics\' own style'),
      placeholder: t('Optional. E.g.: shoujo manga, fine-line ink, soft screentone, big expressive eyes. Empty: uses the story\'s visual style.'),
      onchange: e => saveSettings({ style_text: e.target.value }) });
    if (document.activeElement !== box) box.value = set.style.text;
    const size = ctx.imageSize({ ...ctx.writer(), image_scale: set.scale }, set.aspect);
    return h('section', { class: 'comic-settings stack' },
      h('div', { class: 'comic-settings-grid' },
        seg(t('Type'), KINDS, set.kind, id => change({ kind: id, reading: id === 'manga' ? 'rtl' : 'ltr' })),
        seg(t('Reading'), READINGS, set.reading, id => change({ reading: id })),
        seg(t('Color'), COLORS, set.color, id => change({ color: id })),
        h('label', {}, t('Page format'), h('select', { onchange: e => change({ aspect: e.target.value }) }, ...ASPECTS.map(([id, text]) => h('option', { value: id, selected: id === set.aspect }, text)))),
        seg(t('Size'), SCALES, set.scale, id => change({ scale: id }))),
      h('p', { class: 'hint comic-kind-hint', text: KIND_HINTS[set.kind] || '' }),
      h('p', { class: 'hint', text: [size ? t('Each page comes out at {size} pixels.', { size: size.replace('x', ' × ') }) : '', t('Larger makes small letters sharper, and costs more.'),
        set.reading === 'rtl' ? t('Right to left, as in manga: the first panel is at the top right.') : t('Left to right: the first panel is at the top left.')].filter(Boolean).join(' ') }),
      local ? null : h('div', { class: 'stack tight' },
        h('span', { class: 'field-label', text: t('The comics\' own style') }),
        box,
        set.style.images.length ? h('div', { class: 'sheet-pics' }, ...set.style.images.map(im => h('figure', { class: 'sheet-pic' },
          h('button', { type: 'button', class: 'sheet-open', title: t('Open the image'), onclick: () => openViewer(im.image) }, pic(imageUrl(im.image, true), im.caption || t('Style image'))),
          h('div', { class: 'sheet-actions' }, h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove from the style (it stays in the gallery)'), 'aria-label': t('Remove from the style'),
            onclick: () => saveSettings({ style_remove: [im.image] }) }, icon('close')))))) : null,
        h('div', { class: 'sheet-foot' },
          set.style.images.length < MAX_STYLE_IMAGES ? h('button', { type: 'button', class: 'btn ghost small', onclick: addStyleImages }, icon('gallery'), h('span', { text: t('Style images from the gallery (up to {max})', { max: MAX_STYLE_IMAGES }) })) : null),
        h('p', { class: 'hint', text: set.style.text || set.style.images.length
          ? t('The comics use this style instead of the story\'s visual style. A page that came out well also works as a style image for the next ones.')
          : s.cast?.style?.text ? t('No style of their own: the pages use the story\'s visual style.') : t('No style of their own: the pages use the story\'s visual style (which is still empty: the writer sets it, or you in the book\'s Sheets and style).') })));
  }
  async function saveSettings(change) {
    try {
      const { comic: settings } = await api('story-comic', { story: story().id, mode: 'settings', settings: change });
      story().comic.settings = settings; render(); ctx.changed();
      ctx.reload();
      return true;
    } catch (e) { toast(e.message, { tone: 'error' }); render(); return false; }
  }
  function addStyleImages() {
    const list = [], room = MAX_STYLE_IMAGES - comic().settings.style.images.length;
    ctx.pickImages(list, room, () => { const add = list.filter(x => x.id).map(x => ({ image: x.id })); if (add.length) saveSettings({ style_add: add }); }, t('Comics style'));
  }

  // ---------------------------------------------------------------------------------------------------------------
  // One page: its drawing, its storyboard, its script, and what can be done with it
  // ---------------------------------------------------------------------------------------------------------------
  function pageView(p) {
    const c = comic(), state = pageState(p), i = c.pages.indexOf(p);
    return [
      h('div', { class: 'comic-nav' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: showPages }, icon('back'), h('span', { text: t('Pages') })),
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Previous page'), 'aria-label': t('Previous page'), disabled: i === 0, onclick: () => showPage(c.pages[i - 1].id) }, icon('chevron-left')),
        h('span', { class: 'muted comic-count', text: t('Page {n} of {total}', { n: p.number, total: c.pages.length }) }),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Next page'), 'aria-label': t('Next page'), disabled: i === c.pages.length - 1, onclick: () => showPage(c.pages[i + 1].id) }, icon('chevron-right'))),
      h('div', { class: 'comic-page', vars: { '--page-ratio': ratio(c.settings.aspect) } },
        h('div', { class: 'comic-art' },
          h('figure', { class: 'comic-figure' },
            p.image ? h('button', { type: 'button', class: 'figure-open', title: t('Open the image (versions, zoom)'), onclick: () => openViewer(p.image) }, pic(imageUrl(p.image), t('Page {n}', { n: p.number })))
              : h('div', { class: 'comic-blank' }, state.kind === 'queued' || state.kind === 'running' ? h('span', { class: 'spinner' }) : icon('image'),
                h('span', { text: state.kind === 'running' ? t('Drawing the page…') : state.kind === 'queued' ? t('Page queued…') : state.kind === 'failed' ? t('The drawing did not come out.') : t('No drawing yet.') })),
            h('figcaption', { text: p.image ? t('The drawn page') : t('The drawing') })),
          h('figure', { class: 'comic-figure sketch' },
            h('a', { class: 'figure-open', href: sketchUrl(p), target: '_blank', rel: 'noopener', title: t('Open the storyboard') }, pic(sketchUrl(p), t('Storyboard of page {n}', { n: p.number }))),
            h('figcaption', { text: p.own_sketch ? t('Storyboard drawn by the writer') : t('Storyboard built by the app from the script') }))),
        h('div', { class: 'comic-script' }, ...stateNotes(p, state), ...script(p), ...actions(p, state)))];
  }
  function stateNotes(p, state) {
    const out = [];
    if (state.kind === 'queued' || state.kind === 'running') out.push(h('p', { class: 'cover-note' }, h('span', { class: 'spinner small' }), h('span', { text: state.kind === 'running' ? t('Drawing the whole page… It shows up here when it is ready.') : t('Page queued…') })));
    if (state.kind === 'failed') out.push(h('div', { class: 'comic-warn failed' }, icon('alert'), h('div', {},
      h('p', { text: t('The drawing did not come out{error}', { error: state.error ? `: ${state.error}` : '.' }) }),
      h('div', { class: 'sheet-actions' },
        h('button', { type: 'button', class: 'link-btn', 'data-comic-go': '', onclick: () => pageAction(p, { mode: 'retry' }, t('Drawing again.')) }, t('Try again')),
        h('button', { type: 'button', class: 'link-btn', onclick: () => pageAction(p, { mode: 'dismiss' }) }, t('Set aside'))))));
    if (state.edit?.status === 'queued' || state.edit?.status === 'running') out.push(h('p', { class: 'cover-note' }, h('span', { class: 'spinner small' }), h('span', { text: state.edit.status === 'running' ? t('Making the new version… It takes its place when it is ready.') : t('New version queued…') })));
    if (state.edit?.status === 'failed') out.push(h('p', { class: 'cover-note failed' }, icon('alert'), h('span', { text: t('The new version did not come out{error}', { error: state.edit.error ? `: ${state.edit.error}` : '.' }) })));
    if (state.kind === 'stale') out.push(h('p', { class: 'cover-note' }, icon('alert'), h('span', { text: t('The script changed after this drawing. Draw again so the page follows the new script.') })));
    if (p.missing?.length) out.push(h('div', { class: 'comic-warn' }, icon('alert'), h('div', {},
      h('p', { text: `${t('The adaptation sheet with an image is missing for: {names}.', { names: p.missing.join(', ') })} ${t('Each character and each setting of a page needs a reference image before the drawing.')}` }),
      h('div', { class: 'sheet-actions' },
        h('button', { type: 'button', class: 'link-btn', onclick: () => { close(); ctx.openCast('comic'); } }, t('Open the adaptation\'s sheets')),
        h('button', { type: 'button', class: 'link-btn', onclick: () => ctx.prefillChat(t('Make the adaptation\'s sheets with an image of: {names} (bring from the book the ones that work), to draw page {n} of the comics.', { names: p.missing.join(', '), n: p.number })) }, t('Ask the writer'))))));
    return out;
  }
  /** The script as it reads: what happens, the chapter, the notes, then each panel with its people and words. */
  function script(p) {
    const s = story(), chapter = p.chapter ? s.chapters.findIndex(c => c.id === p.chapter) : -1;
    let n = 0;
    return [
      p.summary ? h('p', { class: 'comic-summary', text: p.summary }) : null,
      h('p', { class: 'muted comic-meta', text: [chapter >= 0 ? t('Adapts chapter {n}: {title}', { n: chapter + 1, title: s.chapters[chapter].title || t('Untitled') }) : null,
        plural(p.panels.length, t('{n} panel'), t('{n} panels')), p.rows ? t('rows {rows}', { rows: p.rows.join(' · ') }) : null, p.by === 'agent' ? t('the writer\'s script') : p.by === 'person' ? t('your script') : p.by === 'mcp' ? t('script via MCP') : null].filter(Boolean).join(' · ') }),
      p.notes ? h('p', { class: 'comic-notes', text: p.notes }) : null,
      h('ol', { class: 'comic-panels' }, ...p.panels.map((panel, k) => h('li', {},
        h('p', { class: 'comic-panel-head' }, h('strong', { text: t('Panel {n}', { n: k + 1 }) }), panel.framing ? h('span', { text: panel.framing }) : null, panel.place ? h('span', { class: 'pill', text: panel.place }) : null),
        ...panel.characters.map(ch => h('p', { class: 'comic-who' }, h('strong', { text: ch.name }), ch.where ? h('span', { class: 'muted', text: ` (${ch.where})` }) : null, ch.doing ? h('span', { text: `: ${ch.doing}` }) : null)),
        panel.description ? h('p', { class: 'comic-desc', text: panel.description }) : null,
        ...panel.text.map(line => {
          const label = line.type === 'sfx' ? null : `B${++n}`;
          return h('p', { class: `comic-line ${line.type}` },
            h('span', { class: 'comic-line-kind', text: `${label ? `${label} ` : ''}${labelOf(TEXT_TYPES, line.type)}${line.by ? (line.type === 'sfx' ? ` (${line.by})` : ` · ${line.by}`) : ''}` }),
            h('span', { class: 'comic-line-text', text: `“${line.text}”` }),
            line.where ? h('span', { class: 'muted', text: ` (${line.where})` }) : null);
        }))))];
  }
  function actions(p, state) {
    const drawing = state.kind === 'queued' || state.kind === 'running';
    const editing = state.edit?.status === 'queued' || state.edit?.status === 'running';
    const instruction = h('textarea', { id: 'comic-instruction', rows: 2, maxlength: 4000, placeholder: t('Optional: notes for this drawing only (e.g.: more rain in panel 2; Bruno in a coat).'), oninput: e => { ui.instruction = e.target.value; } });
    instruction.value = ui.instruction;
    const fix = h('textarea', { id: 'comic-fix', rows: 2, maxlength: 4000, placeholder: t('E.g.: Ana\'s left hand; balloon B2 with the right line; darker in the last panel. In Enhance and Upscale, optional notes.'), oninput: e => { ui.fix = e.target.value; } });
    fix.value = ui.fix;
    return [
      h('section', { class: 'comic-actions stack tight' },
        h('h3', { text: p.image ? t('Draw again') : t('Draw the page') }),
        h('p', { class: 'hint', text: t('The whole page comes out in one generation: the storyboard as a guide, the sheets of the characters and settings, the previous page, the style and the lettering sample. The lines come out written by the generator, in the sample\'s lettering.') }),
        instruction,
        ui.refs.length ? h('div', { class: 'comic-refs' }, ...ui.refs.map(r => h('span', { class: 'comic-ref' }, pic(imageUrl(r.id, true), t('Reference')),
          h('button', { type: 'button', class: 'icon-btn tiny', title: t('Remove', { ctx: 'take' }), 'aria-label': t('Remove the reference'), onclick: () => { ui.refs = ui.refs.filter(x => x !== r); render(); } }, icon('close'))))) : null,
        h('div', { class: 'comic-draw-options' },
          ui.refs.length < MAX_DRAW_REFS ? h('button', { type: 'button', class: 'btn ghost small', onclick: addRefs }, icon('gallery'), h('span', { text: t('Other references (up to {max})', { max: MAX_DRAW_REFS }) })) : null,
          p.number > 1 ? h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: ui.previous, onchange: e => { ui.previous = e.target.checked; } }),
            h('span', { class: 'switch-track', 'aria-hidden': 'true' }), h('span', { text: t('Previous page as reference') })) : null),
        h('p', { id: 'comic-cost', class: 'hint', text: drawCost() }),
        ui.error ? h('p', { class: 'error', role: 'alert', text: ui.error }) : null,
        h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn primary', 'data-comic-go': '', disabled: drawing || ui.busy || Boolean(p.missing?.length),
          onclick: () => pageAction(p, { mode: 'draw', instruction: ui.instruction.trim(), references: ui.refs.map(r => r.id), previous: ui.previous }, t('Drawing page {n}.', { n: p.number }), () => { ui.instruction = ''; ui.refs = []; }) },
        icon('sparkle'), h('span', { text: p.image ? t('Draw again') : t('Draw') })))),
      p.image ? h('section', { class: 'comic-actions stack tight' },
        h('h3', { text: t('Fix the drawing') }),
        fix,
        h('div', { class: 'cover-edit-actions' },
          ...[['edit', t('Apply'), 'pencil', 'secondary'], ['enhance', t('Enhance'), 'sparkle', 'ghost'], ['upscale', t('Upscale', { ctx: 'action' }), 'upscale', 'ghost']].map(([kind, label, ic, tone]) => h('button', { type: 'button', class: `btn ${tone} small`, 'data-comic-go': '', disabled: editing || drawing || ui.busy,
            onclick: () => {
              if (kind === 'edit' && !ui.fix.trim()) { ui.error = t('Say what to fix on the page.'); render(); return; }
              pageAction(p, { mode: 'edit', kind, instruction: ui.fix.trim() }, t('Making the new version of the page.'), () => { ui.fix = ''; });
            } }, icon(ic), h('span', { text: label })))),
        h('p', { class: 'hint', text: t('The new version replaces the page when it is ready; the previous one stays in the gallery. To change the script and draw again, edit the script or ask the writer.') })) : null,
      h('section', { class: 'comic-actions page-tools' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => startEdit(p) }, icon('pencil'), h('span', { text: t('Edit the script') })),
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => ctx.prefillChat(t('On page {n} of the comics, ', { n: p.number })) }, icon('feather'), h('span', { text: t('Ask the writer') })),
        p.image ? h('button', { type: 'button', class: 'btn ghost small', onclick: () => openReader(p.id) }, icon('book'), h('span', { text: t('Read from here') })) : null,
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'btn ghost small danger-text', onclick: () => deletePage(p) }, icon('trash'), h('span', { text: t('Delete') })))];
  }
  /** What one drawing costs, at the adaptation's size: the estimate, the balance, a missing key. */
  function drawCost() {
    const set = comic().settings, w = { ...ctx.writer(), image_scale: set.scale };
    const credit = ctx.credit(w, set.aspect), size = ctx.imageSize(w, set.aspect), parts = [];
    if (size) parts.push(size.replace('x', ' × '));
    if (credit.image !== null) parts.push(t('about {cost} per drawing, plus the references', { cost: formatUsd(credit.image) }));
    if (credit.balance !== null) parts.push(t('estimated {provider} balance: {balance}', { provider: credit.of, balance: formatUsd(credit.balance) }));
    if (!ctx.hasKey()) parts.push(t('the OpenAI key is missing in Settings'));
    return parts.length ? `${parts.join(' · ')}.` : '';
  }
  function addRefs() {
    const list = [];
    ctx.pickImages(list, MAX_DRAW_REFS - ui.refs.length, () => {
      for (const x of list) if (x.id && !ui.refs.some(r => r.id === x.id) && ui.refs.length < MAX_DRAW_REFS) ui.refs.push({ id: x.id });
      render();
    }, t('References for the page'));
  }
  /** Draw, fix, make again or set aside a drawing that failed: the request starts here, and the page waits for it. */
  async function pageAction(p, body, done = '', after = null) {
    if (ui.busy) return;
    ui.busy = true; ui.error = '';
    for (const b of document.querySelectorAll('#comic-body [data-comic-go]')) b.disabled = true;
    try {
      const result = await api('story-comic', { story: story().id, page: p.id, ...body });
      for (const job of result.jobs || []) trackJob(job);
      if (result.jobs?.length) launchQueued();
      after?.();
      if (done) toast(done);
      await ctx.reload(); ctx.poll();
    } catch (e) { ui.error = e.message; }
    finally { ui.busy = false; render(); }
  }
  async function movePage(p, index) {
    try { await api('story-comic', { story: story().id, mode: 'page-move', page: p.id, index }); await ctx.reload(); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }
  async function deletePage(p) {
    const ok = await confirmAction({ title: t('Delete page {n}?', { n: p.number }), message: t('The page\'s script and storyboard leave the comics. The drawing, if there is one, stays in the gallery.'), confirm: t('Delete') });
    if (!ok) return;
    try { await api('story-comic', { story: story().id, mode: 'page-delete', page: p.id }); showPages(); await ctx.reload(); toast(t('Page deleted.')); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }

  // ---------------------------------------------------------------------------------------------------------------
  // A page's script changed by hand (or a new page)
  // ---------------------------------------------------------------------------------------------------------------
  const blankPanel = () => ({ framing: '', place: '', description: '', characters: [], text: [] });
  function startEdit(p) {
    const s = story();
    ui.draft = p
      ? { id: p.id, rev: p.rev, number: p.number, summary: p.summary, chapter: p.chapter || '', notes: p.notes, rows: p.rows ? p.rows.join(', ') : '', panels: structuredClone(p.panels), own: p.own_sketch, dropSketch: false, changed: false }
      : { id: null, rev: null, number: comic().pages.length + 1, summary: '', chapter: s.chapters[ctx.chapter()]?.id || '', notes: '', rows: '', panels: [blankPanel()], own: false, dropSketch: false, changed: false };
    ui.error = '';
    render();
    dialog()?.scrollTo?.({ top: 0 });
  }
  function cancelEdit() { const id = ui.draft?.id; ui.draft = null; if (id) showPage(id); else showPages(); }
  function markChanged() {
    const d = ui.draft, p = d?.id ? comic().pages.find(x => x.id === d.id) : null;
    if (!d || !d.id || d.changed || !p || p.rev === d.rev) return;
    d.changed = true;
    const note = $('#comic-edit-changed'); if (note) note.hidden = false;
  }
  function editor() {
    const d = ui.draft, s = story(), cast = sheets(), people = cast.characters.filter(c => c.kind !== 'place'), places = cast.characters.filter(c => c.kind === 'place');
    const text = (value, set, attrs = {}) => { const el = h('input', { type: 'text', ...attrs, oninput: e => set(e.target.value) }); el.value = value; return el; };
    const area = (value, set, attrs = {}) => { const el = h('textarea', { ...attrs, oninput: e => set(e.target.value) }); el.value = value; return el; };
    const chapters = h('select', { onchange: e => { d.chapter = e.target.value; } }, h('option', { value: '' }, t('None')),
      ...s.chapters.map((c, i) => h('option', { value: c.id, selected: c.id === d.chapter }, `${i + 1}. ${c.title || t('Untitled')}`)));
    const dropSketch = d.own ? h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: d.dropSketch, onchange: e => { d.dropSketch = e.target.checked; } }),
      h('span', { class: 'switch-track', 'aria-hidden': 'true' }), h('span', { text: t('Replace the writer\'s storyboard with the app\'s (the writer\'s does not follow what you change in the panels)') })) : null;
    return h('form', { class: 'comic-editor stack', novalidate: true, onsubmit: e => { e.preventDefault(); saveDraft(); } },
      h('div', { class: 'comic-nav' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: cancelEdit }, icon('back'), h('span', { text: t('Go back without saving') })),
        h('span', { class: 'grow' }),
        h('strong', { text: d.id ? t('Script of page {n}', { n: d.number }) : t('New page') })),
      h('p', { id: 'comic-edit-changed', class: 'cover-note failed', hidden: !d.changed }, icon('alert'), h('span', { text: t('This page changed while you were editing (the writer may have touched it). Go back without saving to see the new version.') })),
      h('datalist', { id: 'comic-people' }, ...people.map(c => h('option', { value: c.name }))),
      h('datalist', { id: 'comic-places' }, ...places.map(c => h('option', { value: c.name }))),
      h('label', {}, t('What happens on the page'), text(d.summary, v => { d.summary = v; }, { maxlength: 1000, placeholder: t('E.g.: Ana reaches the lighthouse and finds Bruno.') })),
      h('div', { class: 'row2' },
        h('label', {}, t('Chapter it adapts'), chapters),
        h('label', {}, t('Panels per row (optional)'), text(d.rows, v => { d.rows = v; }, { placeholder: t('E.g.: 2, 1, 2 (top to bottom)'), inputmode: 'numeric' }))),
      h('label', {}, t('Notes for the whole page (light, mood, pacing)'), area(d.notes, v => { d.notes = v; }, { rows: 2, maxlength: 3000 })),
      ...d.panels.map((panel, k) => panelEditor(panel, k)),
      d.panels.length < MAX_PANELS ? h('button', { type: 'button', class: 'btn ghost small comic-add', onclick: () => { d.panels.push(blankPanel()); render(); } }, icon('plus'), h('span', { text: t('Panel') })) : null,
      dropSketch,
      h('p', { class: 'hint', text: t('Characters and settings named after an adaptation sheet use its image as reference; with no sheet with an image, the page waits. Speech, narration and signs get numbers (B1, B2…) in reading order; the storyboard and the request use the same numbers.') }),
      ui.error ? h('p', { class: 'error', role: 'alert', text: ui.error }) : null,
      h('div', { class: 'row-end' },
        h('button', { type: 'button', class: 'btn ghost', onclick: cancelEdit }, t('Cancel')),
        h('button', { type: 'submit', class: 'btn primary', 'data-comic-go': '' }, d.id ? t('Save the script') : t('Create the page'))));
  }
  function panelEditor(panel, k) {
    const d = ui.draft, cast = sheets();
    const text = (value, set, attrs = {}) => { const el = h('input', { type: 'text', ...attrs, oninput: e => set(e.target.value) }); el.value = value; return el; };
    const area = (value, set, attrs = {}) => { const el = h('textarea', { ...attrs, oninput: e => set(e.target.value) }); el.value = value; return el; };
    const move = to => { const [x] = d.panels.splice(k, 1); d.panels.splice(to, 0, x); render(); };
    return h('fieldset', { class: 'comic-panel-edit' },
      h('legend', { text: t('Panel {n}', { n: k + 1 }) }),
      h('div', { class: 'comic-panel-tools' },
        h('button', { type: 'button', class: 'icon-btn tiny', title: t('Move earlier'), 'aria-label': t('Panel {n} earlier', { n: k + 1 }), disabled: k === 0, onclick: () => move(k - 1) }, icon('to-top')),
        h('button', { type: 'button', class: 'icon-btn tiny', title: t('Move later'), 'aria-label': t('Panel {n} later', { n: k + 1 }), disabled: k === d.panels.length - 1, onclick: () => move(k + 1) }, icon('to-bottom')),
        h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove the panel'), 'aria-label': t('Remove panel {n}', { n: k + 1 }), disabled: d.panels.length === 1, onclick: () => { d.panels.splice(k, 1); render(); } }, icon('trash'))),
      h('div', { class: 'row2' },
        h('label', {}, t('Framing'), text(panel.framing, v => { panel.framing = v; }, { maxlength: 300, placeholder: t('E.g.: wide shot, from below') })),
        h('label', {}, t('Setting (place sheet)'), text(panel.place, v => { panel.place = v; }, { maxlength: 120, list: 'comic-places', placeholder: t('E.g.: The Lighthouse') }))),
      h('label', {}, t('What is seen'), area(panel.description, v => { panel.description = v; }, { rows: 2, maxlength: 3000, placeholder: t('The action, the objects, the light. Extras who do not come back go here.') })),
      h('div', { class: 'comic-rows' },
        h('span', { class: 'field-label', text: t('Characters') }),
        ...panel.characters.map((ch, i) => {
          const sheet = cast.characters.find(c => c.kind !== 'place' && c.name.toLowerCase() === ch.name.trim().toLowerCase());
          const forms = (sheet?.images || []).filter(im => im.image);
          return h('div', { class: 'comic-row' },
            text(ch.name, v => { ch.name = v; }, { maxlength: 80, list: 'comic-people', placeholder: t('Name'), 'aria-label': t('Name'), onchange: () => render() }),
            forms.length > 1 ? h('select', { 'aria-label': t('Look (sheet image)'), onchange: e => { ch.sheet = e.target.value; } },
              h('option', { value: '' }, t('Main image')), ...forms.map((im, n) => h('option', { value: im.image, selected: im.image === ch.sheet }, im.caption || t('Image {n}', { n: n + 1 })))) : null,
            text(ch.where, v => { ch.where = v; }, { maxlength: 200, placeholder: t('Where (e.g.: on the left, in front)'), 'aria-label': t('Where') }),
            text(ch.doing, v => { ch.doing = v; }, { maxlength: 600, placeholder: t('Doing what, with what expression'), 'aria-label': t('Doing what') }),
            h('button', { type: 'button', class: 'icon-btn tiny', title: t('Remove', { ctx: 'take' }), 'aria-label': t('Remove the character'), onclick: () => { panel.characters.splice(i, 1); render(); } }, icon('close')));
        }),
        panel.characters.length < MAX_PANEL_CHARACTERS ? h('button', { type: 'button', class: 'link-btn', onclick: () => { panel.characters.push({ name: '', sheet: '', where: '', doing: '' }); render(); } }, '+ Personagem') : null),
      h('div', { class: 'comic-rows' },
        h('span', { class: 'field-label', text: t('Texts') }),
        ...panel.text.map((line, i) => h('div', { class: 'comic-row text' },
          h('select', { 'aria-label': t('Type of text'), onchange: e => { line.type = e.target.value; render(); } }, ...TEXT_TYPES.map(([id, label]) => h('option', { value: id, selected: id === line.type }, label))),
          text(line.by, v => { line.by = v; }, { maxlength: 80, placeholder: line.type === 'sfx' ? t('Sound of what') : line.type === 'caption' || line.type === 'sign' ? t('Whose (optional)') : t('Who speaks'), 'aria-label': t('Who') }),
          area(line.text, v => { line.text = v; }, { rows: 1, maxlength: 600, placeholder: line.type === 'sfx' ? t('E.g.: KRASH') : t('The exact words'), 'aria-label': t('Text') }),
          text(line.where, v => { line.where = v; }, { maxlength: 200, placeholder: t('Where (optional)'), 'aria-label': t('Where the text goes') }),
          h('button', { type: 'button', class: 'icon-btn tiny', title: t('Remove', { ctx: 'take' }), 'aria-label': t('Remove the text'), onclick: () => { panel.text.splice(i, 1); render(); } }, icon('close')))),
        panel.text.length < MAX_PANEL_TEXTS ? h('button', { type: 'button', class: 'link-btn', onclick: () => { panel.text.push({ type: 'speech', by: '', text: '', where: '' }); render(); } }, '+ Texto') : null));
  }
  async function saveDraft() {
    const d = ui.draft; if (!d || ui.busy) return;
    const rows = d.rows.trim() ? d.rows.split(/[\s,;·]+/).filter(Boolean).map(Number) : null;
    if (rows && rows.some(n => !Number.isInteger(n) || n < 1 || n > 5)) { ui.error = t('Panels per row: numbers from 1 to 5, separated by commas (e.g.: 2, 1, 2).'); render(); return; }
    const panels = d.panels.map(p => ({ framing: p.framing.trim(), place: p.place.trim(), description: p.description.trim(),
      characters: p.characters.filter(c => c.name.trim()).map(c => ({ name: c.name.trim(), sheet: c.sheet || '', where: c.where.trim(), doing: c.doing.trim() })),
      text: p.text.filter(t => t.text.trim()).map(t => ({ type: t.type, by: t.by.trim(), text: t.text.trim(), where: t.where.trim() })) }));
    const body = { story: story().id, summary: d.summary.trim(), chapter: d.chapter || null, notes: d.notes.trim(), rows, panels };
    ui.busy = true; ui.error = '';
    try {
      if (d.id) await api('story-comic', { ...body, mode: 'page-save', page: d.id, rev: d.rev, drop_sketch: d.dropSketch });
      else { const { page } = await api('story-comic', { ...body, mode: 'page-add' }); ui.page = page.id; }
      ui.page = d.id || ui.page; ui.draft = null; ui.view = 'page';
      await ctx.reload();
      toast(d.id ? t('Script saved.') : t('Page created.'));
    } catch (e) { ui.error = e.message; }
    finally { ui.busy = false; render(); }
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The reader: the drawn pages on the whole screen, one at a time or two side by side, in the comic's reading order
  // ---------------------------------------------------------------------------------------------------------------
  const reader = () => $('#comic-reader');
  const rd = { index: 0, two: null, bars: true, saveTimer: null, saved: null, urls: new Map(), loading: new Map(), down: null };
  const readable = () => comic()?.pages.filter(p => p.image) || [];
  const rtl = () => comic()?.settings.reading === 'rtl';
  /** Two pages side by side on a screen wider than tall (or as the person chose). */
  const spread = () => readable().length > 1 && (rd.two ?? window.innerWidth > window.innerHeight * 1.15);

  function openReader(pageId = null) {
    const pages = readable(); if (!pages.length) return;
    const start = pageId || comic().reading_page;
    rd.index = Math.max(0, pages.findIndex(p => p.id === start)); rd.bars = true; rd.saved = start;
    reader().showModal();
    renderReader();
  }
  /** The image of a page, fetched once and kept for a few pages (the server sends images with no-store). */
  function pageImage(id) {
    if (rd.urls.has(id)) return Promise.resolve(rd.urls.get(id));
    if (rd.loading.has(id)) return rd.loading.get(id);
    const loading = fetch(imageUrl(id), { credentials: 'same-origin' }).then(r => { if (!r.ok) throw new Error(String(r.status)); return r.blob(); })
      .then(blob => {
        const url = URL.createObjectURL(blob); rd.urls.set(id, url);
        // Only a few pages around the one on screen stay in memory.
        while (rd.urls.size > 8) { const [old, oldUrl] = rd.urls.entries().next().value; rd.urls.delete(old); URL.revokeObjectURL(oldUrl); }
        return url;
      })
      .finally(() => rd.loading.delete(id));
    rd.loading.set(id, loading);
    return loading;
  }
  function renderReader() {
    const pages = readable();
    if (!pages.length) { reader().close(); return; }
    // The reader's own element: the notices of the page show in the dialog too (app.js puts them in the top one).
    const el = reader().querySelector(':scope > .comic-view') || reader().appendChild(h('div', { class: 'comic-view' }));
    const two = spread();
    rd.index = Math.min(Math.max(0, rd.index), pages.length - 1);
    if (two) rd.index -= rd.index % 2;
    const shown = two ? pages.slice(rd.index, rd.index + 2) : [pages[rd.index]];
    // Right to left: the first page of a spread is the one on the right.
    const ordered = rtl() ? [...shown].reverse() : shown;
    const last = two ? Math.ceil(pages.length / 2) - 1 : pages.length - 1, at = two ? rd.index / 2 : rd.index;
    const slider = h('input', { type: 'range', min: 0, max: Math.max(0, last), value: at, dir: rtl() ? 'rtl' : 'ltr', 'aria-label': t('Page'), disabled: last === 0,
      onchange: e => { rd.index = Number(e.target.value) * (two ? 2 : 1); renderReader(); } });
    // The pages' own numbers, out of all the pages (the ones not drawn yet are skipped).
    const total = comic().pages.length, counter = shown.length === 2 ? t('{a}–{b} of {total}', { a: shown[0].number, b: shown[1].number, total }) : t('{n} of {total}', { n: shown[0].number, total });
    fill(el,
      h('div', { class: `comic-stage${shown.length === 2 ? ' two' : ''}`, onpointerdown: e => { rd.down = { x: e.clientX, y: e.clientY, t: Date.now() }; }, onpointerup: stageUp },
        ...ordered.map(p => {
          const img = h('img', { alt: t('Page {n}', { n: p.number }), draggable: 'false' });
          const url = rd.urls.get(p.image);
          if (url) img.src = url;
          else { img.classList.add('loading'); pageImage(p.image).then(u => { img.src = u; img.classList.remove('loading'); }).catch(() => { img.alt = t('Page {n} (did not load)', { n: p.number }); img.classList.remove('loading'); }); }
          return img;
        })),
      h('header', { class: 'comic-bar top', hidden: !rd.bars },
        h('button', { type: 'button', class: 'icon-btn', title: t('Close (Esc)'), 'aria-label': t('Close'), onclick: () => reader().close() }, icon('close')),
        h('span', { class: 'comic-bar-title', text: story()?.title || '' }),
        h('span', { class: 'grow' }),
        h('span', { class: 'comic-bar-count', text: counter }),
        h('button', { type: 'button', class: 'icon-btn', 'aria-pressed': String(shown.length === 2), disabled: pages.length < 2, title: two ? t('One page at a time') : t('Two pages side by side'), 'aria-label': t('Two pages side by side'),
          onclick: () => { rd.two = !two; renderReader(); } }, icon('book')),
        h('button', { type: 'button', class: 'icon-btn', title: t('Open the image (zoom, versions)'), 'aria-label': t('Open the image'), onclick: () => openViewer(shown[0].image) }, icon('expand')),
        document.fullscreenEnabled ? h('button', { type: 'button', class: 'icon-btn', title: t('Full screen'), 'aria-label': t('Full screen'), onclick: toggleFullscreen }, icon(document.fullscreenElement ? 'fullscreen-exit' : 'fullscreen')) : null),
      // The arrows sit where they lead: right to left, the next page is on the left.
      h('footer', { class: 'comic-bar bottom', hidden: !rd.bars, dir: rtl() ? 'rtl' : 'ltr' },
        h('button', { type: 'button', class: 'icon-btn', title: t('Previous page'), 'aria-label': t('Previous page'), disabled: rd.index === 0, onclick: () => turn(-1) }, icon(rtl() ? 'chevron-right' : 'chevron-left')),
        slider,
        h('button', { type: 'button', class: 'icon-btn', title: t('Next page'), 'aria-label': t('Next page'), disabled: at >= last, onclick: () => turn(1) }, icon(rtl() ? 'chevron-left' : 'chevron-right'))));
    // The next pages load ahead.
    for (const p of pages.slice(rd.index + shown.length, rd.index + shown.length + (two ? 2 : 1))) pageImage(p.image).catch(() => {});
    saveReaderPlace(shown[0].id);
  }
  function refreshReader() {
    const pages = readable(), current = rd.saved;
    if (!pages.length) { reader().close(); return; }
    const i = pages.findIndex(p => p.id === current);
    if (i >= 0) rd.index = i;
    renderReader();
  }
  function turn(step) {
    const pages = readable(), size = spread() ? 2 : 1, to = rd.index + step * size;
    if (to < 0 || to > pages.length - 1) { if (step > 0 && to > pages.length - 1) toast(t('Last page.')); return; }
    rd.index = to; renderReader();
  }
  /** A tap on the side the next page comes from turns to it; in the middle it shows or hides the bars. A swipe turns too. */
  function stageUp(e) {
    const down = rd.down; rd.down = null; if (!down) return;
    const dx = e.clientX - down.x, dy = e.clientY - down.y;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.3 && Date.now() - down.t < 800) { turn((dx > 0) === rtl() ? 1 : -1); return; }
    if (Math.abs(dx) > 10 || Math.abs(dy) > 10) return;
    const w = e.currentTarget.clientWidth, x = e.clientX - e.currentTarget.getBoundingClientRect().left;
    if (x < w * 0.3) turn(rtl() ? 1 : -1);
    else if (x > w * 0.7) turn(rtl() ? -1 : 1);
    else { rd.bars = !rd.bars; for (const bar of reader().querySelectorAll('.comic-bar')) bar.hidden = !rd.bars; }
  }
  function readerKey(e) {
    if (e.target instanceof HTMLInputElement && e.key !== 'Escape') return;
    const next = { ArrowLeft: rtl() ? 1 : -1, ArrowRight: rtl() ? -1 : 1, PageDown: 1, PageUp: -1, ' ': e.shiftKey ? -1 : 1, ArrowDown: 1, ArrowUp: -1 }[e.key];
    if (next) { e.preventDefault(); turn(next); return; }
    if (e.key === 'Home') { e.preventDefault(); rd.index = 0; renderReader(); }
    if (e.key === 'End') { e.preventDefault(); rd.index = readable().length - 1; renderReader(); }
    if (e.key === 'f' || e.key === 'F') { e.preventDefault(); toggleFullscreen(); }
  }
  function toggleFullscreen() {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    else reader().requestFullscreen?.().catch(() => {});
  }
  /** Where the person stopped, kept with the adaptation on the server (on any device, it opens there next time). */
  function saveReaderPlace(id, now = false) {
    rd.saved = id;
    clearTimeout(rd.saveTimer);
    const send = () => {
      rd.saveTimer = null;
      const c = comic(); if (!c || c.reading_page === id) return;
      c.reading_page = id;
      api('story-comic', { story: story().id, mode: 'reading', page: id }, {}, { keepalive: now }).catch(() => { if (comic()?.reading_page === id) comic().reading_page = null; });
    };
    if (now) send(); else rd.saveTimer = setTimeout(send, 1200);
  }
  const readerEl = reader();
  if (readerEl) {
    // On the whole page while the reader is open: a page turn redraws the bars, and the focus can fall back to the body.
    document.addEventListener('keydown', e => { if (!readerEl.open || e.key === 'Escape') return; readerKey(e); e.stopPropagation(); }, true);
    readerEl.addEventListener('close', () => {
      if (rd.saveTimer && rd.saved) saveReaderPlace(rd.saved, true);
      if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
      for (const url of rd.urls.values()) URL.revokeObjectURL(url);
      rd.urls.clear();
      readerEl.querySelector(':scope > .comic-view')?.remove();
      if (dialog()?.open && !ui.draft) render();
    });
    window.addEventListener('resize', () => { if (readerEl.open && rd.two === null) renderReader(); });
    document.addEventListener('fullscreenchange', () => { if (readerEl.open) renderReader(); });
  }

  // ---------------------------------------------------------------------------------------------------------------
  // Downloads: the drawn pages as a PDF (right to left for a manga) or a CBZ (a zip of the pages, with ComicInfo.xml)
  // ---------------------------------------------------------------------------------------------------------------
  async function exportComic(format) {
    const pages = readable(), s = story(), set = comic().settings; if (!pages.length) return;
    const buttons = [...document.querySelectorAll('#comic-body [data-export]')];
    for (const b of buttons) b.disabled = true;
    try {
      const jpegs = [];
      for (const [n, p] of pages.entries()) {
        toast(t('Preparing page {n} of {total}…', { n: n + 1, total: pages.length }), { duration: 60000 });
        jpegs.push({ number: p.number, ...(await toJpeg(p.image)) });
      }
      const name = ctx.fileName(s.title || 'quadrinhos', format);
      if (format === 'pdf') ctx.download(name, 'application/pdf', pdf(jpegs, { title: s.title || t('Comics'), rtl: set.reading === 'rtl' }));
      else ctx.download(name, 'application/vnd.comicbook+zip', zip([
        ...jpegs.map((j, i) => ({ name: `${String(i + 1).padStart(3, '0')}.jpg`, bytes: j.bytes })),
        { name: 'ComicInfo.xml', bytes: new TextEncoder().encode(comicInfo(s, set, jpegs.length)) }]));
      toast(format === 'pdf' ? t('PDF ready.') : t('CBZ ready.'));
    } catch (e) { toast(t('Could not build the file: {error}', { error: e.message || e }), { tone: 'error' }); }
    finally { for (const b of buttons) b.disabled = false; }
  }
  /** A page as a JPEG of the same size (the drawings are PNG: a JPEG keeps the file a size people can send). */
  async function toJpeg(id) {
    const response = await fetch(imageUrl(id), { credentials: 'same-origin' });
    if (!response.ok) throw new Error(t('the image {id} did not load', { id }));
    const bitmap = await createImageBitmap(await response.blob());
    const canvas = document.createElement('canvas'); canvas.width = bitmap.width; canvas.height = bitmap.height;
    const g = canvas.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, canvas.width, canvas.height); g.drawImage(bitmap, 0, 0);
    bitmap.close?.();
    const blob = await new Promise((resolve, reject) => canvas.toBlob(b => b ? resolve(b) : reject(new Error(t('the browser did not make the JPEG'))), 'image/jpeg', 0.92));
    canvas.width = canvas.height = 0;
    return { bytes: new Uint8Array(await blob.arrayBuffer()), width: bitmap.width, height: bitmap.height };
  }

  return { open, close, refresh, summary, render };
}

// -------------------------------------------------------------------------------------------------------------------
// File formats, with no library: a PDF of JPEG pages and a zip with no compression (JPEGs don't shrink anyway)
// -------------------------------------------------------------------------------------------------------------------

/** A PDF with one JPEG per page, each page 500 points wide. rtl: readers show it right to left, two pages from the right. */
export function pdf(pages, { title = '', rtl = false } = {}) {
  const enc = new TextEncoder(), parts = [], offsets = [];
  let size = 0;
  const put = data => { const bytes = typeof data === 'string' ? enc.encode(data) : data; parts.push(bytes); size += bytes.length; };
  const obj = (n, body) => { offsets[n] = size; put(`${n} 0 obj\n`); for (const b of [].concat(body)) put(b); put('\nendobj\n'); };
  // A text string the PDF way: UTF-16BE with its mark, in hex, so any title (accents, Japanese) reads right.
  const text = s => `<FEFF${[...s].map(ch => { const c = ch.codePointAt(0); return c > 0xffff ? [0xd800 + ((c - 0x10000) >> 10), 0xdc00 + ((c - 0x10000) & 0x3ff)] : [c]; }).flat().map(c => c.toString(16).padStart(4, '0')).join('').toUpperCase()}>`;
  const count = pages.length, first = 4; // 1 catalog, 2 page tree, 3 info; then 3 objects a page
  // The header, with four bytes over 127 so programs take the file as binary.
  put(new Uint8Array([...enc.encode('%PDF-1.7\n%'), 0xe2, 0xe3, 0xcf, 0xd3, 0x0a]));
  obj(1, `<< /Type /Catalog /Pages 2 0 R${rtl ? ' /ViewerPreferences << /Direction /R2L >> /PageLayout /TwoPageRight' : ''} >>`);
  obj(2, `<< /Type /Pages /Count ${count} /Kids [${pages.map((_, i) => `${first + i * 3 + 2} 0 R`).join(' ')}] >>`);
  obj(3, `<< /Title ${text(title)} /Producer (Atelier) >>`);
  pages.forEach((p, i) => {
    const image = first + i * 3, content = image + 1, page = image + 2;
    const w = 500, h = Math.round(500 * p.height / p.width * 100) / 100;
    obj(image, [`<< /Type /XObject /Subtype /Image /Width ${p.width} /Height ${p.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${p.bytes.length} >>\nstream\n`, p.bytes, '\nendstream']);
    const draw = `q ${w} 0 0 ${h} 0 0 cm /P Do Q`;
    obj(content, `<< /Length ${draw.length} >>\nstream\n${draw}\nendstream`);
    obj(page, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Resources << /XObject << /P ${image} 0 R >> >> /Contents ${content} 0 R >>`);
  });
  const total = first + count * 3, xref = size;
  put(`xref\n0 ${total}\n0000000000 65535 f \n${offsets.slice(1, total).map(o => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}`);
  put(`trailer\n<< /Size ${total} /Root 1 0 R /Info 3 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
  return new Blob(parts, { type: 'application/pdf' });
}

let crcTable = null;
function crc32(bytes) {
  if (!crcTable) { crcTable = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; crcTable[n] = c >>> 0; } }
  let c = 0xffffffff;
  for (let i = 0; i < bytes.length; i++) c = crcTable[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}
/** A zip whose files are stored as they are (CBZ readers take any zip). files: [{ name, bytes }], in reading order. */
export function zip(files) {
  const enc = new TextEncoder(), parts = [], central = [];
  let offset = 0;
  const now = new Date(), time = (now.getHours() << 11) | (now.getMinutes() << 5) | (now.getSeconds() >> 1), date = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();
  for (const f of files) {
    const name = enc.encode(f.name), crc = crc32(f.bytes), head = new DataView(new ArrayBuffer(30));
    head.setUint32(0, 0x04034b50, true); head.setUint16(4, 20, true); head.setUint16(6, 0x0800, true); head.setUint16(8, 0, true);
    head.setUint16(10, time, true); head.setUint16(12, date, true); head.setUint32(14, crc, true);
    head.setUint32(18, f.bytes.length, true); head.setUint32(22, f.bytes.length, true); head.setUint16(26, name.length, true); head.setUint16(28, 0, true);
    parts.push(new Uint8Array(head.buffer), name, f.bytes);
    const entry = new DataView(new ArrayBuffer(46));
    entry.setUint32(0, 0x02014b50, true); entry.setUint16(4, 20, true); entry.setUint16(6, 20, true); entry.setUint16(8, 0x0800, true); entry.setUint16(10, 0, true);
    entry.setUint16(12, time, true); entry.setUint16(14, date, true); entry.setUint32(16, crc, true); entry.setUint32(20, f.bytes.length, true); entry.setUint32(24, f.bytes.length, true);
    entry.setUint16(28, name.length, true); entry.setUint32(42, offset, true);
    central.push(new Uint8Array(entry.buffer), name);
    offset += 30 + name.length + f.bytes.length;
  }
  const centralSize = central.reduce((n, b) => n + b.length, 0), end = new DataView(new ArrayBuffer(22));
  end.setUint32(0, 0x06054b50, true); end.setUint16(8, files.length, true); end.setUint16(10, files.length, true); end.setUint32(12, centralSize, true); end.setUint32(16, offset, true);
  return new Blob([...parts, ...central, new Uint8Array(end.buffer)], { type: 'application/zip' });
}
/** ComicInfo.xml, what comic readers read of a CBZ: the title, the series, the pages and, for a manga, its direction. */
export function comicInfo(story, settings, count) {
  const x = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]));
  return `<?xml version="1.0" encoding="utf-8"?>\n<ComicInfo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">\n`
    + `  <Title>${x(story.title || t('Comics'))}</Title>\n${story.series ? `  <Series>${x(story.series)}</Series>\n` : ''}${story.synopsis ? `  <Summary>${x(story.synopsis)}</Summary>\n` : ''}`
    + `  <PageCount>${count}</PageCount>\n  <LanguageISO>${storyLanguage(story).slice(0, 2)}</LanguageISO>\n  <BlackAndWhite>${settings.color === 'bw' ? 'Yes' : 'No'}</BlackAndWhite>\n`
    + `  <Manga>${settings.reading === 'rtl' ? 'YesAndRightToLeft' : settings.kind === 'manga' ? 'Yes' : 'No'}</Manga>\n</ComicInfo>\n`;
}
