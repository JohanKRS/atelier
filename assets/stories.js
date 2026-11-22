// Stories: the list, the story page (contents, the text of each chapter, the chat with the writer), the bible, the
// hidden plan and the writer's settings. app.js hands in what it shares (requests, elements, notices, image jobs).
// Text from a person or a model is only ever set as text: never through innerHTML.
import { t, number, locale } from './i18n.js';

export function createStories(ctx) {
  const { api, h, icon, toast, confirmAction, askName, imageUrl, openViewer, trackJob, launchQueued, formatUsd, dateTime, plural, newId, story: S, $, models, prefs, savePrefs } = ctx;
  const { createReader, placeOf, pageOrScroll } = ctx.reader;
  const P = ctx.pack;
  const view = $('#stories-view');
  // replaceChildren() would write a null child as the text "null": what is left out is dropped here.
  /** Names in a sentence: "a, b and c". */
  const listOf = names => names.length > 1 ? t('{names} and {last}', { names: names.slice(0, -1).join(', '), last: names.at(-1) }) : names[0] || '';
  const fill = (el, ...children) => el.replaceChildren(...children.flat().filter(c => c !== null && c !== undefined && c !== false));
  const ACTIVE = new Set(['queued', 'running', 'cancelling']);
  const MAX_ATTACHED = 6; // gallery images with one message (Writer::MAX_ATTACHED)
  // inbox: messages sent while the writer works, waiting for its next step. started: turns this page asked to run.
  // kind: what the section lists, stories or visual novel projects (a story of kind vn, worked on by vn.js).
  const st = { kind: 'story', list: [], story: null, messages: [], seq: 0, turn: null, spent: { claude: 0, openai: 0, gemini: 0 }, pending: new Map(), chapter: 0, editing: null, attach: [], reorder: false,
    inbox: [], started: new Set(), copying: new Set(), stamp: 0, token: 0, timer: null, warmTimer: null, listTimer: null, writers: null, tab: 'doc', open: false, sending: false, loadingList: false };

  /**
   * Pictures stay the same elements from one redraw to the next. The server sends images with no-store, so a new <img>
   * loads again, and the chat and the chapter are redrawn at every poll while the writer works: they would blink.
   */
  const pictures = { chat: { kept: new Map(), used: new Set() }, doc: { kept: new Map(), used: new Set() }, list: { kept: new Map(), used: new Set() }, cast: { kept: new Map(), used: new Set() }, cover: { kept: new Map(), used: new Set() } };
  function startPictures(scope) { pictures[scope].used = new Set(); }
  function forgetPictures() { for (const p of Object.values(pictures)) { p.kept.clear(); p.used.clear(); } }
  function picture(scope, id, thumb, alt) {
    const p = pictures[scope], key = `${id}:${thumb ? 't' : 'f'}`;
    // The same picture twice in one redraw gets a second element.
    let img = p.used.has(key) ? null : p.kept.get(key);
    if (img) { if (img.alt !== alt) img.alt = alt; }
    else { img = h('img', { src: imageUrl(id, thumb), alt, loading: 'lazy' }); if (!p.used.has(key)) p.kept.set(key, img); }
    p.used.add(key);
    return img;
  }

  // What the chat shows of the writer, kept with the account (Vault::storySettings). Its replies can give the story
  // away, so they start hidden: the chat then keeps the person's messages, the writer's steps and whether it is done.
  // shown: the replies opened one at a time while the rest stay hidden (until the page is left).
  const chat = { replies: false, saving: null, shown: new Set() };
  function setStoryPrefs(saved) { chat.replies = saved?.replies === true; chat.shown.clear(); }
  async function setReplies(on) {
    chat.replies = on; chat.shown.clear(); renderChatHead(); renderChat();
    const saving = chat.saving = api('story-prefs', { stories: { replies: on } });
    try { await saving; }
    catch (e) { if (chat.saving === saving) toast(t('The choice was not saved to your account: {error}', { error: e.message }), { tone: 'error' }); }
  }

  // The reader (reader.js): the text on the whole screen, with its settings kept with the account. It has an address of
  // its own (#historias/<id>/ler), over the story's, so going back leaves it for the story.
  const reader = createReader({ h, icon, api, toast, local: prefs, saveLocal: savePrefs, host: {
    root: () => $('#story'), doc: () => $('#story-doc'), story: () => st.story, index: () => st.chapter,
    go: (i, at) => goTo(i, { at }), exit: () => exitReader(), moved: () => onDocScroll() } });
  reader.attach($('#story'));
  const readerHash = id => `#historias/${id}/ler`;

  // The comic adaptation (comics.js): its window, the reader of its pages and the downloads.
  const comics = ctx.createComics({ api, h, icon, toast, confirmAction, imageUrl, openViewer, trackJob, launchQueued, formatUsd, plural, $,
    story: () => st.story, pending: () => st.pending, chapter: () => st.chapter, writer: () => writerOf(), reload: () => reloadStory(), poll: () => schedule(1500),
    changed: () => renderSide(), prefillChat: text => prefillChat(text), openCast: space => openCast(space), openDocument: name => openDocument(name), credit: ctx.credit, imageSize: ctx.imageSize, hasKey: ctx.hasKey,
    pickImages: ctx.pickImages, fileName: S.fileName, download: (name, type, content) => download(name, type, content) });

  // A visual novel project (vn.js): its tree, scenes, art, routes, variables, preview, documents and the Ren'Py game,
  // in the middle pane, with the contents and the chat around it as in a story.
  const isVn = (s = st.story) => s?.kind === 'vn';
  const vn = ctx.createVn({ api, h, icon, toast, confirmAction, askName, imageUrl, openViewer, trackJob, launchQueued, formatUsd, plural, dateTime, $,
    story: () => st.story, pending: () => st.pending, writer: () => writerOf(), reload: () => reloadStory(), poll: () => schedule(1500),
    prefillChat: text => prefillChat(text), openCast: () => openCast(), credit: ctx.credit, pickImages: ctx.pickImages, zip: ctx.zip, unzip: P.unzip,
    fileName: S.fileName, wordCount: S.wordCount, download: (name, type, content) => download(name, type, content),
    tab: id => { if (st.tab !== id && $('#story')) { st.tab = id; $('#story').dataset.tab = id; $$tabs(); } },
    rendered: () => { renderSide(); renderDoc(); } });
  // What the section's page says, for stories and for visual novels.
  const HOME = {
    story: { title: t('Stories'), hint: t('Written with Claude or GPT, planned from beginning to end, one chapter at a time.'), create: t('New story'), fresh: t('New story'),
      empty: t('No stories yet'), emptyHint: t('Create one and tell the writer the idea.'), hash: '#historias' },
    vn: { title: 'Visual Novel', hint: t('A script with choices, routes and endings; sprites and backgrounds from the sheets; a playable preview and export to Ren\'Py.'), create: t('New visual novel'), fresh: t('New visual novel'),
      empty: t('No visual novels yet'), emptyHint: t('Create one and bring your documents (design, chapters, characters), or tell the writer the idea.'), hash: '#vn' },
  };
  const homeOf = () => HOME[st.kind] || HOME.story;
  /** The address of a story of the section's kind (#historias/<id> or #vn/<id>). */
  const hashOf = id => `${homeOf().hash}/${id}`;
  function renderHome() {
    const home = homeOf();
    $('#stories-title').textContent = home.title; $('#stories-hint').textContent = home.hint; $('#story-new-label').textContent = home.create;
    $('#stories-empty-title').textContent = home.empty; $('#stories-empty-hint').textContent = home.emptyHint;
  }

  // ---------------------------------------------------------------------------------------------------------------
  // Showing the section
  // ---------------------------------------------------------------------------------------------------------------
  /** Shows the section, for stories or for visual novels: a story of the other kind open is left for the list. */
  function show(kind = st.kind) {
    st.open = true; view.hidden = false;
    const changed = kind !== st.kind;
    st.kind = kind; renderHome();
    if (st.story && (st.story.kind || 'story') !== kind) { saveReading(); backToList({ load: false }); }
    if (st.story) { renderStory(); schedule(0); } else { if (changed) renderList(); loadList(); }
  }
  function hide({ save = true } = {}) { if (save) saveReading(); reader.exit(); st.open = false; view.hidden = true; clearTimeout(st.timer); st.timer = null; clearTimeout(st.listTimer); st.listTimer = null; scheduleWarm(null); }
  function reset() {
    hide({ save: false }); reader.setPrefs(null); setStoryPrefs(null); st.token++; clearTimeout(reading.timer); Object.assign(reading, { saved: '', sending: '', timer: null, restore: null });
    Object.assign(st, { list: [], story: null, messages: [], seq: 0, turn: null, pending: new Map(), chapter: 0, editing: null, attach: [], inbox: [], started: new Set(), stamp: 0 });
    forgetPictures(); vn.reset();
    $('#stories-grid').replaceChildren(); $('#story').replaceChildren(); $('#story').hidden = true; $('#stories-home').hidden = false;
    for (const s of ['#story-doc-text', '#writer-chapter-words']) { const el = $(s); if (el) el.value = ''; }
  }
  function setWriters(writers) { st.writers = writers; }

  // ---------------------------------------------------------------------------------------------------------------
  // The list
  // ---------------------------------------------------------------------------------------------------------------
  /** quiet: a refresh while covers are being made (no error notices; the list stays as it is meanwhile). */
  async function loadList({ quiet = false } = {}) {
    clearTimeout(st.listTimer); st.listTimer = null;
    if (!quiet) { $('#stories-home').hidden = false; $('#story').hidden = true; }
    const token = st.token; if (!quiet) { st.loadingList = true; renderList(); }
    try { const { stories } = await api('stories'); if (token !== st.token) return; st.list = stories; }
    catch (e) { if (!quiet) toast(e.message, { tone: 'error' }); }
    finally { st.loadingList = false; }
    if (token !== st.token || st.story) return;
    renderList();
    if ($('#story-cover-dialog').open && !st.story) renderCoverTop();
    // A cover being made shows up on its own when it is ready, and so do the others of its set.
    if (st.open && st.list.some(s => s.cover_making === 'queued' || s.cover_making === 'running' || (s.cover_choices || []).some(c => !c.image && ACTIVE.has(c.status))))
      st.listTimer = setTimeout(() => loadList({ quiet: true }), 4000);
  }
  /**
   * The cover's shape, its width over its height (cover_size from the server), for a story whose cover is known; a
   * book's 2:3 otherwise. Kept within a range: a picture far wider or taller than that is cut at its sides or its ends.
   */
  function coverShape(s) {
    const [w, ht] = s.cover && Array.isArray(s.cover_size) ? s.cover_size : [];
    return w > 0 && ht > 0 ? Math.min(2.4, Math.max(.4, w / ht)) : 2 / 3;
  }
  /** The cover's size as a CSS aspect-ratio, where it is known: the picture takes that shape before it loads. */
  const coverAspect = s => (s.cover && Array.isArray(s.cover_size) && s.cover_size[0] > 0 && s.cover_size[1] > 0 ? `aspect-ratio: ${s.cover_size[0]} / ${s.cover_size[1]}` : null);
  /**
   * Every card has the same height: the synopsis takes the room the title, the series and the numbers leave (its box),
   * and its text is cut at the last whole line that fits there, with an ellipsis. The lines are counted once the card is
   * laid out, and again when it changes size.
   */
  const synopsisFit = new ResizeObserver(entries => {
    for (const { target } of entries) {
      const text = target.firstElementChild; if (!text) continue;
      const line = parseFloat(getComputedStyle(text).lineHeight) || 19, lines = Math.floor((target.clientHeight + 1) / line);
      target.style.setProperty('--lines', String(Math.max(1, lines)));
      target.classList.toggle('no-room', lines < 1);
    }
  });
  function renderList() {
    const grid = $('#stories-grid');
    const mine = st.list.filter(s => (s.kind || 'story') === st.kind);
    $('#stories-empty').hidden = st.loadingList || mine.length > 0;
    startPictures('list');
    const sorted = mine.sort((a, b) => (b.favorite - a.favorite) || (b.updated - a.updated));
    // The whole cover at one height for all the cards: each card is as wide as its cover's shape.
    fill(grid, ...sorted.map(s => h('article', { class: 'story-card', tabindex: 0, vars: { '--shape': coverShape(s).toFixed(4) }, onclick: () => openStory(s.id), onkeydown: e => { if (e.key === 'Enter' && e.target === e.currentTarget) openStory(s.id); } },
      h('div', { class: 'story-cover' }, s.cover ? picture('list', s.cover, true, '') : h('span', { class: 'story-cover-title', text: s.title || t('Untitled') }),
        s.cover_making ? h('span', { class: `story-cover-state${s.cover_making === 'failed' ? ' failed' : ''}` }, s.cover_making === 'failed' ? icon('alert') : h('span', { class: 'spinner small' }),
          h('span', { text: coverSet(s) ? (s.cover_making === 'failed' ? t('The covers did not come out') : t('Making the covers…')) : s.cover_making === 'failed' ? t('The cover did not come out') : t('Making the cover…') })) : null,
        h('button', { type: 'button', class: 'story-cover-edit', title: t('Cover: choose, generate or edit'), 'aria-label': t('Cover of “{title}”', { title: s.title || t('Untitled') }),
          onclick: e => { e.stopPropagation(); openCover(s); } }, icon('image'), h('span', { text: t('Cover') }))),
      h('div', { class: 'story-card-body' },
        h('div', { class: 'story-card-head' }, h('h3', { text: s.title || t('Untitled'), title: s.title || null }),
          h('button', { type: 'button', class: 'icon-btn small story-card-copy', title: t('Duplicate: a copy to change separately'), 'aria-label': t('Duplicate “{title}”', { title: s.title || t('Untitled') }),
            disabled: st.copying.has(s.id), onclick: e => { e.stopPropagation(); duplicateStory(s); } }, st.copying.has(s.id) ? h('span', { class: 'spinner small' }) : icon('copy')),
          h('button', { type: 'button', class: 'icon-btn small', 'aria-pressed': String(s.favorite), title: s.favorite ? t('Remove from favorites', { ctx: 'story' }) : t('Favorite'), 'aria-label': t('Favorite', { ctx: 'state' }),
            onclick: async e => { e.stopPropagation(); s.favorite = !s.favorite; renderList(); try { await api('story-favorite', { id: s.id, on: s.favorite }); } catch (err) { toast(err.message, { tone: 'error' }); } } }, icon('star'))),
        s.series ? h('p', { class: 'muted story-card-series', text: s.series, title: s.series }) : null,
        s.synopsis ? h('div', { class: 'story-card-synopsis' }, h('p', { text: s.synopsis })) : null,
        h('p', { class: 'muted story-card-meta', text: s.kind === 'vn' ? `${plural(s.scenes || 0, t('{n} scene'), t('{n} scenes'))}${s.chapters ? ` · ${plural(s.chapters, t('{n} chapter'), t('{n} chapters'))}` : ''} · ${dateTime(s.updated)}`
          : `${plural(s.chapters, t('{n} chapter'), t('{n} chapters'))} · ${plural(s.words, t('{n} word'), t('{n} words'))} · ${dateTime(s.updated)}` })))));
    synopsisFit.disconnect();
    for (const el of grid.querySelectorAll('.story-card-synopsis')) synopsisFit.observe(el);
  }
  /**
   * A copy of the story to change on its own (Writer::duplicate): chapters, bible, plan, sheets, cover, comic and the
   * conversation with the writer. The pictures are the same gallery images; a new version made in the copy goes only
   * there. It shows at the top of the list, and the notice opens it.
   */
  async function duplicateStory(s) {
    const name = s.title || t('Untitled');
    const title = await askName({ title: t('Duplicate “{name}”', { name }), value: t('{name} (copy)', { name }), confirm: t('Duplicate'), label: t('Title of the copy'), maxLength: 200,
      message: s.kind === 'vn' ? t('The copy takes the scenes, the tree, the variables, the routes, the art, the gameplay notes, the chapters, the bible, the sheets and the conversation with the writer. It changes separately: nothing you or the writer do in it changes the original. The images are the same ones in the gallery.')
        : t('The copy takes the chapters, the bible, the plan, the sheets, the cover, the comics and the conversation with the writer. It changes separately: nothing you or the writer do in it changes the original. The images are the same ones in the gallery.') });
    if (!title) return;
    st.copying.add(s.id); renderList();
    try {
      const { story } = await api('story-duplicate', { id: s.id, title });
      st.list = [story, ...st.list.filter(x => x.id !== story.id)];
      toast(t('Copy created: “{title}”.', { title: story.title }), { action: { label: t('Open'), run: () => openStory(story.id) } });
    } catch (e) { toast(e.message, { tone: 'error' }); }
    finally { st.copying.delete(s.id); if (!st.story) renderList(); }
  }
  $('#story-new').onclick = async () => {
    try {
      const writer = prefs().storyWriter && typeof prefs().storyWriter === 'object' ? prefs().storyWriter : undefined;
      const { story } = await api('story-create', { title: homeOf().fresh, kind: st.kind, ...(writer ? { writer } : {}) });
      st.list.unshift(story); await openStory(story.id, { fresh: true });
    } catch (e) { toast(e.message, { tone: 'error' }); }
  };

  // ---------------------------------------------------------------------------------------------------------------
  // A story
  // ---------------------------------------------------------------------------------------------------------------
  /**
   * address: true when the person opens the story from the list, which makes an entry in the browser's history (going
   * back returns to the list); false when the address already names it (back, forward, a reload).
   */
  async function openStory(id, { fresh = false, address = true, reading: inReader = false } = {}) {
    const token = ++st.token;
    try {
      const data = await api('story', undefined, { id });
      if (token !== st.token) return;
      forgetPictures();
      if (st.story?.id !== id) { vn.reset(); st.reorder = false; }
      // Where the person stopped reading, on this device or another one.
      const place = data.story.reading, at = place ? data.story.chapters.findIndex(c => c.id === place.chapter) : -1;
      st.story = data.story; st.messages = data.messages; st.seq = data.messages.reduce((m, x) => Math.max(m, x.seq), 0);
      st.turn = data.turn; st.inbox = data.inbox || []; st.spent = data.spent; st.stamp = data.story.updated; st.editing = null; st.attach = [];
      st.chapter = at >= 0 ? at : Math.max(0, data.story.chapters.length - 1);
      setPending(data.pending); scheduleWarm(data.warm_in);
      const empty = isVn(data.story) ? !data.story.vn?.scenes.length && !data.messages.length : !data.story.chapters.length;
      st.tab = fresh || empty ? 'chat' : 'doc';
      // A story opened from the other section (the history, an address): the section follows it.
      const kind = data.story.kind || 'story';
      if (kind !== st.kind) { st.kind = kind; renderHome(); ctx.kindChanged?.(kind); }
      $('#stories-home').hidden = true; $('#story').hidden = false;
      if (address && location.hash !== hashOf(id)) history.pushState({ atelier: 'story', depth: depthHere(1) + 1 }, '', `${location.pathname}${location.search}${hashOf(id)}`);
      else if (!address && !inReader && location.hash !== hashOf(id)) history.replaceState({ atelier: 'story', depth: depthHere(2) }, '', `${location.pathname}${location.search}${hashOf(id)}`);
      reading.saved = at >= 0 ? placeKey(id, place) : '';
      renderStory(); schedule(0); ctx.inView?.();
      if (at >= 0 && st.tab === 'doc') restoreReading(place);
      // In the reader: its address (a reload there), or the setting to open stories in it.
      if ((inReader || (address && reader.prefs().open)) && st.tab === 'doc' && st.story.chapters.length && !isVn()) enterReader({ address: !inReader, place: at >= 0 ? place : null, screen: address });
      else if (inReader) history.replaceState({ atelier: 'story', depth: depthHere(2) }, '', `${location.pathname}${location.search}${hashOf(id)}`);
      if (fresh) { $('#chat-input')?.focus(); }
    } catch (e) {
      toast(e.message, { tone: 'error' });
      // An address that names a story no longer there (deleted, another account's) shows the list.
      if (!address && token === st.token) { history.replaceState({ atelier: 'stories', depth: depthHere(1) }, '', `${location.pathname}${location.search}${homeOf().hash}`); loadList(); }
    }
  }
  /** How many entries the writing sections made over the images (the images button walks back that many): kept when an
   *  entry is replaced, one more for an entry pushed. */
  const depthHere = fallback => (history.state?.atelier && Number(history.state.depth)) || fallback;
  function backToList({ load = true } = {}) {
    reader.exit(); comics.close(); vn.reset(); st.token++; clearTimeout(st.timer); clearTimeout(reading.timer); reading.restore = null; scheduleWarm(null); st.story = null; st.editing = null;
    ctx.inView?.();
    if (load) loadList();
  }
  /** The arrow back to all the stories: the same as the browser's back button when the story made its entry. */
  function leaveStory() { if (isVn() && vn.dirty && !window.confirm(t('Discard the changes to this scene?'))) return; saveReading(); toLeave(); }
  function toLeave() {
    const own = history.state?.atelier === 'story' && location.hash === hashOf(st.story?.id);
    backToList();
    if (own) history.back(); else history.replaceState({ atelier: 'stories', depth: depthHere(1) }, '', `${location.pathname}${location.search}${homeOf().hash}`);
  }
  /** The address changed (back, forward, a reload): the story it names (in the reader or not), or the list. */
  function route(id, inReader = false) {
    if (id) {
      if (st.story?.id !== id) openStory(id, { address: false, reading: inReader });
      else if (inReader && !reader.on) enterReader({ address: false, screen: false });
      else if (!inReader && reader.on) exitReader({ address: false });
    }
    else if (st.story) { saveReading(); backToList(); }
  }
  /**
   * The text on the whole screen, at the place on screen now (or the one given). address: false when the address already
   * names the reader. screen: false when no tap asked for it (the browser takes the whole screen only after one).
   */
  function enterReader({ address = true, place = null, screen = true } = {}) {
    const s = st.story;
    if (!s || reader.on || st.editing || !s.chapters[st.chapter] || isVn(s)) return;
    if (st.tab !== 'doc') { st.tab = 'doc'; $('#story').dataset.tab = 'doc'; $$tabs(); }
    const at = place || readingPlace();
    reader.enter(at, { screen });
    if (address && location.hash !== readerHash(s.id)) history.pushState({ atelier: 'reader', depth: depthHere(2) + 1 }, '', `${location.pathname}${location.search}${readerHash(s.id)}`);
  }
  /** Back to the story's panes, at the place the reading reached. The same as the browser's back when the reader made its entry. */
  function exitReader({ address = true } = {}) {
    if (!reader.on) return;
    saveReading();
    const place = reader.exit(), doc = $('#story-doc');
    if (place && doc) {
      pageOrScroll(doc, place);
      const now = readingPlace(); if (now && st.story) reading.saved = placeKey(st.story.id, now);
    }
    if (!address || !st.story) return;
    const own = history.state?.atelier === 'reader' && location.hash === readerHash(st.story.id);
    if (own) history.back(); else history.replaceState({ atelier: 'story', depth: depthHere(2) }, '', `${location.pathname}${location.search}${hashOf(st.story.id)}`);
  }

  // ---------------------------------------------------------------------------------------------------------------
  // Where the person stopped reading: the chapter, the block of its text at the top of the screen and how far into it.
  // Kept with the story on the server (on the way out, too), so it opens there next time, on any device.
  // ---------------------------------------------------------------------------------------------------------------
  const reading = { saved: '', sending: '', timer: null, restore: null };
  const placeKey = (id, p) => `${id}:${p.chapter}:${p.block}:${Number(p.offset).toFixed(2)}`;
  function readingPlace() {
    const c = st.story?.chapters[st.chapter], doc = $('#story-doc');
    // Not while editing, nor when the text is out of sight (the other tabs on a phone).
    if (!c || st.editing || !doc || !st.open || !doc.getClientRects().length) return null;
    return { chapter: c.id, ...placeOf(doc, reader.paged()) };
  }
  function saveReading({ leaving = false } = {}) {
    clearTimeout(reading.timer); reading.timer = null;
    const s = st.story, place = readingPlace();
    if (!s || !place) return;
    const key = placeKey(s.id, place);
    // A save still on its way when the page is left goes again, as one that goes on after the page closes.
    if (key === reading.saved && !(leaving && reading.sending === key)) return;
    reading.saved = key; reading.sending = leaving ? '' : key; s.reading = place;
    api('story-reading', { id: s.id, ...place }, {}, { keepalive: leaving })
      .catch(() => { if (reading.saved === key) reading.saved = ''; })
      .finally(() => { if (reading.sending === key) reading.sending = ''; });
  }
  function onDocScroll() { if (!reading.timer) reading.timer = setTimeout(saveReading, 1500); }
  /**
   * Scrolls the text to a place (or, in the reader with pages, turns to its page), and keeps it there while the
   * pictures above it load (they have no size until then).
   */
  function restoreReading(place) {
    const position = () => reader.on ? reader.position() : $('#story-doc')?.scrollTop;
    const apply = () => {
      const doc = $('#story-doc');
      if (!doc || (reader.on ? reader.showPlace(place) : pageOrScroll(doc, place)) === null) return null;
      // This scrolling isn't the person's: it doesn't save the place again (over a newer one, say).
      const now = readingPlace(); if (now && st.story) reading.saved = placeKey(st.story.id, now);
      return position();
    };
    const restore = reading.restore = { place, at: null, until: Date.now() + 4000 };
    requestAnimationFrame(() => { if (reading.restore === restore) restore.at = apply(); });
    $('#story-doc')?.addEventListener('load', () => {
      // Only while the person hasn't scrolled (or turned a page) since.
      if (reading.restore !== restore || Date.now() > restore.until || restore.at === null || position() !== restore.at) return;
      restore.at = apply();
    }, true);
  }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden' && st.open) saveReading({ leaving: true }); });
  window.addEventListener('pagehide', () => { if (st.open) saveReading({ leaving: true }); });

  /** Reloads the story (the agent or an illustration changed it), keeping the chapter on screen and an edit in progress. */
  async function reloadStory() {
    if (!st.story) return;
    const token = st.token, id = st.story.id, current = st.story.chapters[st.chapter]?.id;
    try {
      const data = await api('story', undefined, { id });
      if (token !== st.token) return;
      const grew = data.story.chapters.length > st.story.chapters.length;
      st.story = data.story; st.stamp = data.story.updated; st.spent = data.spent; setPending(data.pending); scheduleWarm(data.warm_in);
      const at = data.story.chapters.findIndex(c => c.id === current);
      // A chapter the writer just added is the one to read (not while reading another one in the reader).
      st.chapter = grew && !st.editing && !reader.on ? data.story.chapters.length - 1 : Math.max(0, at === -1 ? Math.min(st.chapter, data.story.chapters.length - 1) : at);
      renderSide(); if (!st.editing) renderDoc(); else markEditedElsewhere(); renderChatHead();
      if ($('#story-cast-dialog').open) renderCast();
      if ($('#story-cover-dialog').open && cover.id === st.story.id) renderCoverTop();
      comics.refresh();
    } catch { /* the next poll tries again */ }
  }

  function setPending(jobs) {
    st.pending = new Map((jobs || []).map(j => [j.id, j]));
    // Illustrations the writer asked for run like any image request: the page starts them, the gallery shows them.
    let queued = false;
    for (const job of st.pending.values()) if (ACTIVE.has(job.status)) { trackJob(job); queued = queued || job.status === 'queued'; }
    if (queued) launchQueued();
  }

  function renderStory() {
    const root = $('#story');
    if (!st.story) return;
    fill(root, 
      h('nav', { class: 'story-tabs segmented', role: 'group', 'aria-label': t('Parts of the story') },
        ...[['side', t('Contents')], ['doc', isVn() ? t('Project') : t('Text')], ['chat', t('Writer')]].map(([id, label]) => h('button', { type: 'button', 'data-tab': id, 'aria-pressed': String(st.tab === id), onclick: () => { if (st.tab === 'doc' && id !== 'doc') saveReading(); st.tab = id; root.dataset.tab = id; $$tabs(); } }, label))),
      h('aside', { class: 'story-side', id: 'story-side' }),
      h('article', { class: 'story-doc', id: 'story-doc', tabindex: -1, onscroll: onDocScroll }),
      h('aside', { class: 'story-chat', id: 'story-chat' },
        h('div', { class: 'chat-head', id: 'chat-head' }),
        h('div', { class: 'chat-log', id: 'chat-log', 'aria-live': 'polite' }),
        chatForm()),
      reader.ui());
    root.dataset.tab = st.tab;
    renderSide(); renderDoc(); renderChatHead(); renderChat(); renderAttached();
  }
  function $$tabs() { for (const b of $('#story').querySelectorAll('.story-tabs button')) b.setAttribute('aria-pressed', String(b.dataset.tab === st.tab)); }

  // ---------------------------------------------------------------------------------------------------------------
  // Contents
  // ---------------------------------------------------------------------------------------------------------------
  function renderSide() {
    const s = st.story, side = $('#story-side'); if (!side) return;
    if (isVn(s)) { renderSideVn(s, side); return; }
    const words = s.chapters.reduce((sum, c) => sum + c.words, 0), reorder = st.reorder && s.chapters.length > 1;
    // A chapter moved with the keyboard keeps its arrow focused, so it can go on moving.
    const moving = side.contains(document.activeElement) ? document.activeElement.dataset.move : null;
    fill(side, 
      h('div', { class: 'side-head' },
        h('button', { type: 'button', class: 'icon-btn small', title: t('All stories'), 'aria-label': t('All stories'), onclick: leaveStory }, icon('back')),
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Cover: choose, generate or edit'), 'aria-label': t('Cover'), onclick: () => openCover(st.story) }, icon('image')),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Download'), 'aria-label': t('Download'), onclick: exportMenu }, icon('download')),
        h('button', { type: 'button', class: 'icon-btn small danger', title: t('Delete the story'), 'aria-label': t('Delete the story'), onclick: deleteStory }, icon('trash'))),
      h('button', { type: 'button', class: 'story-title', title: t('Edit title, series and synopsis'), onclick: openDetails },
        s.cover ? h('img', { src: imageUrl(s.cover, true), alt: '', style: coverAspect(s) }) : null,
        h('span', {}, h('strong', { text: s.title || t('Untitled') }), s.series ? h('small', { text: s.series }) : null)),
      h('p', { class: 'muted side-stats', text: `${plural(s.chapters.length, t('{n} chapter'), t('{n} chapters'))} · ${plural(words, t('{n} word'), t('{n} words'))}` }),
      h('ol', { class: `chapter-list${reorder ? ' reorder' : ''}` }, ...s.chapters.map((c, i) => h('li', { class: i === st.chapter ? 'current' : '' },
        h('button', { type: 'button', class: 'chapter-link', onclick: () => goTo(i) }, h('span', { class: 'chapter-n', text: String(i + 1) }), h('span', { class: 'chapter-t', text: c.title || t('Untitled') }), h('span', { class: 'chapter-w', text: number(c.words) })),
        // The arrows only in "Reordenar": in plain view they were too easy to press by mistake.
        reorder ? h('span', { class: 'chapter-move' },
          h('button', { type: 'button', class: 'icon-btn small', title: t('Up'), 'aria-label': t('Move chapter {n} up', { n: i + 1 }), 'data-move': `${c.id}:up`, disabled: i === 0, onclick: () => moveChapter(i, i - 1) }, icon('to-top')),
          h('button', { type: 'button', class: 'icon-btn small', title: t('Down'), 'aria-label': t('Move chapter {n} down', { n: i + 1 }), 'data-move': `${c.id}:down`, disabled: i === s.chapters.length - 1, onclick: () => moveChapter(i, i + 1) }, icon('to-bottom'))) : null))),
      s.chapters.length ? null : h('p', { class: 'hint', text: t('No chapters yet. Talk to the writer: it plans the whole story before starting, and writes one chapter at a time.') }),
      h('div', { class: 'side-actions' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: addChapter }, icon('plus'), h('span', { text: t('Chapter written by you') })),
        s.chapters.length ? h('button', { type: 'button', class: 'btn ghost small', onclick: () => openIllustrate() }, icon('image'), h('span', { text: t('Illustrate') })) : null,
        s.chapters.length > 1 ? h('button', { type: 'button', class: 'btn ghost small', id: 'chapter-reorder', 'aria-pressed': String(reorder), title: reorder ? t('Stop reordering') : t('Change the order of the chapters'),
          onclick: () => { st.reorder = !reorder; renderSide(); $('#chapter-reorder')?.focus(); } }, icon(reorder ? 'check' : 'sort'), h('span', { text: reorder ? t('Done', { ctx: 'reorder' }) : t('Reorder') })) : null),
      h('div', { class: 'side-docs' },
        h('button', { type: 'button', class: 'side-doc', onclick: () => openDocument('notes') }, icon('book'), h('span', {}, h('strong', { text: t('Story bible') }), h('small', { text: s.notes.text ? plural(S.wordCount(s.notes.text), t('{n} word · characters, places, rules'), t('{n} words · characters, places, rules')) : t('Empty: the writer fills it in') }))),
        h('button', { type: 'button', class: 'side-doc', id: 'side-cast', onclick: openCast }, icon('user'), h('span', {}, h('strong', { text: t('Sheets and style') }), h('small', { text: castSummary(s.cast) }))),
        h('button', { type: 'button', class: 'side-doc', id: 'side-comic', onclick: () => comics.open() }, icon('comic'), h('span', {}, h('strong', { text: t('Comics') }), h('small', { text: comics.summary(s) }))),
        h('button', { type: 'button', class: 'side-doc spoiler', onclick: () => openDocument('plan') }, icon('eye-off'), h('span', {}, h('strong', { text: t('Planning') }), h('small', { text: s.plan.words ? plural(s.plan.words, t('{n} word · hidden (spoilers)'), t('{n} words · hidden (spoilers)')) : t('Empty: the writer plans before the 1st chapter') })))));
    if (moving) (side.querySelector(`[data-move="${CSS.escape(moving)}"]:not(:disabled)`) || side.querySelector(`[data-move^="${CSS.escape(moving.split(':')[0])}:"]:not(:disabled)`))?.focus();
  }
  /**
   * The contents of a visual novel project: the scenes by chapter (a chapter opens its base text), the problems the
   * checks found, and the project's documents.
   */
  function renderSideVn(s, side) {
    const scenes = s.vn?.scenes || [], problems = vn.problemsLine();
    const known = new Set(s.chapters.map(c => c.id));
    const groups = [...s.chapters.map((c, i) => ({ c, i, list: scenes.filter(x => x.chapter === c.id) })), { c: null, i: -1, list: scenes.filter(x => !x.chapter || !known.has(x.chapter)) }];
    const sceneLink = x => h('li', { class: vn.view === 'scene' && vn.scene === x.id ? 'current' : '' },
      h('button', { type: 'button', class: 'chapter-link vn-side-scene', title: x.summary || x.title || x.label, onclick: () => vn.open('scene', x.id) },
        h('span', { class: `vn-side-exit exit-${x.exit.type}`, 'aria-hidden': 'true' }), h('span', { class: 'chapter-t', text: x.title || x.label }), h('span', { class: 'chapter-w', text: x.words ? number(x.words) : '' })));
    fill(side,
      h('div', { class: 'side-head' },
        h('button', { type: 'button', class: 'icon-btn small', title: t('All visual novels'), 'aria-label': t('All visual novels'), onclick: leaveStory }, icon('back')),
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Cover: choose, generate or edit'), 'aria-label': t('Cover'), onclick: () => openCover(st.story) }, icon('image')),
        h('button', { type: 'button', class: 'icon-btn small', title: t('Download (Ren\'Py, project zip)'), 'aria-label': t('Download'), onclick: exportMenu }, icon('download')),
        h('button', { type: 'button', class: 'icon-btn small danger', title: t('Delete the project'), 'aria-label': t('Delete the project'), onclick: deleteStory }, icon('trash'))),
      h('button', { type: 'button', class: 'story-title', title: t('Edit title, series and synopsis'), onclick: openDetails },
        s.cover ? h('img', { src: imageUrl(s.cover, true), alt: '', style: coverAspect(s) }) : null,
        h('span', {}, h('strong', { text: s.title || t('Untitled') }), h('small', { text: s.series || 'Visual novel' }))),
      h('p', { class: 'muted side-stats', text: vn.summary() }),
      problems ? h('button', { type: 'button', class: `vn-side-problems ${problems.errors ? 'error' : 'warn'}`, onclick: () => vn.showProblems() }, icon('alert'), h('span', { text: problems.text })) : null,
      ...groups.filter(g => g.c || g.list.length).map(g => h('div', { class: 'vn-side-group' },
        g.c ? h('button', { type: 'button', class: `vn-side-chapter${vn.view === 'chapter' && st.chapter === g.i ? ' current' : ''}`, title: t('Open the chapter\'s base'), onclick: () => openChapterBase(g.i) },
          h('span', { class: 'chapter-n', text: String(g.i + 1) }), h('span', { class: 'chapter-t', text: g.c.title || t('Untitled') }), h('span', { class: 'chapter-w', text: plural(g.list.length, t('{n} scene'), t('{n} scenes')) }))
          : s.chapters.length ? h('span', { class: 'vn-side-chapter none', text: t('No chapter') }) : null,
        g.list.length ? h('ol', { class: 'chapter-list' }, ...g.list.map(sceneLink)) : null)),
      scenes.length || s.chapters.length ? null : h('p', { class: 'hint', text: t('No scenes yet. Import your documents, create the first scene or talk to the writer: it builds the choice tree from the design document.') }),
      h('div', { class: 'side-actions' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => vn.newScene() }, icon('plus'), h('span', { text: t('Scene') })),
        h('button', { type: 'button', class: 'btn ghost small', title: t('A chapter groups scenes and keeps its base (the text you already have)'), onclick: addChapter }, icon('plus'), h('span', { text: t('Chapter') })),
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => vn.openImport() }, icon('upload'), h('span', { text: t('Import') }))),
      h('div', { class: 'side-docs' },
        h('button', { type: 'button', class: 'side-doc', onclick: () => openDocument('notes') }, icon('book'), h('span', {}, h('strong', { text: t('Design document') }), h('small', { text: s.notes.text ? t('{words} words · the project bible', { words: number(S.wordCount(s.notes.text)) }) : t('Empty: import yours or let the writer build it') }))),
        h('button', { type: 'button', class: 'side-doc', id: 'side-cast', onclick: openCast }, icon('user'), h('span', {}, h('strong', { text: t('Sheets and style') }), h('small', { text: castSummary(s.cast) }))),
        h('button', { type: 'button', class: 'side-doc', onclick: () => vn.open('gameplay') }, icon('list'), h('span', {}, h('strong', { text: t('Gameplay') }), h('small', { text: s.vn?.gameplay?.text ? t('{words} words · systems, routes, comments', { words: number(S.wordCount(s.vn.gameplay.text)) }) : t('Game notes: systems, routes, comments') }))),
        h('button', { type: 'button', class: 'side-doc', onclick: () => openDocument('plan') }, icon('feather'), h('span', {}, h('strong', { text: t('Planning') }), h('small', { text: s.plan.words ? t('{words} words · the writer\'s guide', { words: number(s.plan.words) }) : t('Empty: the writer plans before writing') })))));
  }
  function openChapterBase(i) {
    if (!st.story?.chapters[i] || (st.editing && !confirmLeaveEdit())) return;
    st.editing = null; st.chapter = i;
    if (!vn.open('chapter')) return;
    $('#story-doc').scrollTop = 0;
  }
  /** What the sheets hold, in a line. */
  function castSummary(cast) {
    const people = cast.characters.filter(c => c.kind !== 'place').length, places = cast.characters.length - people;
    const parts = [people ? plural(people, t('{n} character'), t('{n} characters')) : null, places ? plural(places, t('{n} place'), t('{n} places')) : null,
      cast.style.text || cast.style.images.length ? t('style set') : null, cast.style.lettering?.text || cast.style.lettering?.image ? t('lettering set') : null].filter(Boolean);
    return parts.length ? parts.join(' · ') : t('Empty: the writer builds them as the story moves on');
  }
  /** Shows chapter i from its start (or, in the reader going back a page, from its end). */
  function goTo(i, { at = 'start' } = {}) {
    if (!st.story?.chapters[i] || (st.editing && !confirmLeaveEdit())) return;
    if (isVn() && vn.view !== 'chapter') { openChapterBase(i); return; }
    st.editing = null; st.chapter = i; st.tab = 'doc'; $('#story').dataset.tab = 'doc'; $$tabs(); renderSide(); reading.restore = null;
    if (reader.on) renderDoc({ at });
    else { renderDoc(); $('#story-doc').scrollTop = 0; window.scrollTo({ top: 0 }); }
    saveReading();
  }
  function confirmLeaveEdit() { return !st.editing?.dirty || window.confirm(t('Discard the changes to this chapter?')); }

  async function moveChapter(from, to) {
    const s = st.story; const c = s.chapters[from];
    s.chapters = S.moved(s.chapters, from, to); if (st.chapter === from) st.chapter = to; else if (st.chapter === to) st.chapter = from;
    renderSide(); renderDoc();
    try { await api('chapter-move', { story: s.id, id: c.id, index: to }); reloadStory(); }
    catch (e) { toast(e.message, { tone: 'error' }); reloadStory(); }
  }
  async function addChapter() {
    try {
      const { chapter } = await api('chapter-add', { story: st.story.id, title: t('Chapter {n}', { n: st.story.chapters.length + 1 }), text: '' });
      await reloadStory(); st.chapter = st.story.chapters.findIndex(c => c.id === chapter.id);
      if (isVn() && !vn.open('chapter')) return;
      startEdit();
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The text
  // ---------------------------------------------------------------------------------------------------------------
  /** at: in the reader, 'start' or 'end' of a chapter just turned to; otherwise the reader keeps its place. */
  function renderDoc({ at = null } = {}) {
    const doc = $('#story-doc'); if (!doc) return;
    // A visual novel's pane is vn.js's, except a chapter's base, shown as a chapter.
    if (isVn() && vn.view === 'chapter' && !st.story.chapters[st.chapter] && !st.editing) { vn.open('tree'); return; }
    if (isVn() && vn.view !== 'chapter') { doc.classList.add('vn'); vn.render(doc); return; }
    doc.classList.remove('vn');
    startPictures('doc');
    const s = st.story, c = s.chapters[st.chapter];
    if (st.editing) { renderEditor(); return; }
    if (!c) {
      if (reader.on) exitReader();
      fill(doc, h('div', { class: 'empty compact' }, icon('book'), h('strong', { text: s.title || t('New story') }),
        h('p', { class: 'hint', text: t('Tell the writer the idea: genre, tone, length, who tells the story. It builds a hidden plan (so as not to spoil surprises), a bible with characters and places, and then writes chapter by chapter.') }),
        h('button', { type: 'button', class: 'btn secondary small', onclick: () => { st.tab = 'chat'; $('#story').dataset.tab = 'chat'; $$tabs(); $('#chat-input').focus(); } }, t('Talk to the writer'))));
      return;
    }
    const body = h('div', { class: 'prose' });
    for (const b of S.parseBlocks(c.text)) body.append(block(b, c));
    if (!c.text.trim()) body.append(h('p', { class: 'hint', text: t('Empty chapter.') }));
    // One flow inside the text's element: the reader lays it out in columns, as pages.
    fill(doc, isVn(s) ? vn.bar() : null, h('div', { class: 'doc-flow' },
      h('header', { class: 'doc-head' },
        h('span', { class: 'doc-n', text: isVn(s) ? t('Base of chapter {n}', { n: st.chapter + 1 }) : t('Chapter {n}', { n: st.chapter + 1 }) }),
        h('span', { class: 'grow' }),
        c.by === 'agent' ? h('span', { class: 'pill', text: t('Writer') }) : c.by === 'person' ? h('span', { class: 'pill', text: t('You') })
          : c.by === 'mcp' ? h('span', { class: 'pill', text: t('Via MCP'), title: t('Written by an assistant connected to Atelier (MCP)') }) : null,
        isVn(s) ? h('button', { type: 'button', class: 'btn ghost small', title: t('A new scene in this chapter'), onclick: () => vn.newScene({ chapter: c.id }) }, icon('plus'), h('span', { text: t('Scene') }))
          : h('button', { type: 'button', class: 'btn ghost small', title: t('Ask the writer for an illustration of this chapter'), 'aria-label': t('Illustrate'), onclick: () => openIllustrate(st.chapter) }, icon('image'), h('span', { text: t('Illustrate') })),
        h('button', { type: 'button', class: 'btn ghost small', title: t('Edit the chapter'), 'aria-label': t('Edit', { ctx: 'action' }), onclick: startEdit }, icon('pencil'), h('span', { text: t('Edit', { ctx: 'action' }) })),
        isVn(s) ? null : h('button', { type: 'button', class: 'btn ghost small doc-read', title: t('Read in full screen (T)'), 'aria-label': t('Read in full screen'), onclick: () => enterReader() }, icon('fullscreen'), h('span', { text: t('Full screen') }))),
      h('h1', { class: 'doc-title', text: c.title || t('Untitled') }),
      body,
      c.summary ? h('details', { class: 'doc-summary' }, h('summary', { text: t('Chapter summary (the writer\'s memory)') }), h('p', { text: c.summary })) : null,
      h('nav', { class: 'doc-nav' },
        h('button', { type: 'button', class: 'btn ghost small', disabled: st.chapter === 0, onclick: () => goTo(st.chapter - 1) }, icon('chevron-left'), h('span', { text: t('Previous') })),
        h('span', { class: 'muted', text: `${plural(c.words, t('{n} word'), t('{n} words'))} · ${dateTime(c.updated)}` }),
        h('button', { type: 'button', class: 'btn ghost small', disabled: st.chapter >= s.chapters.length - 1, onclick: () => goTo(st.chapter + 1) }, h('span', { text: t('Next', { ctx: 'chapter' }) }), icon('chevron-right')))));
    reader.rendered({ at });
  }
  function inline(nodes) {
    return nodes.map(n => n.t === 'text' ? document.createTextNode(n.v) : n.t === 'br' ? h('br') : h(n.t, {}, ...inline(n.c)));
  }
  function block(b, chapter) {
    if (b.type === 'p') return h('p', {}, ...inline(b.inline));
    if (b.type === 'h') return h(b.level <= 2 ? 'h3' : 'h4', {}, ...inline(b.inline));
    if (b.type === 'quote') return h('blockquote', {}, ...inline(b.inline));
    if (b.type === 'list') return h(b.ordered ? 'ol' : 'ul', {}, ...b.items.map(it => h('li', {}, ...inline(it))));
    if (b.type === 'break') return h('p', { class: 'scene-break', 'aria-label': t('Scene change'), text: '* * *' });
    return figure(b, chapter);
  }
  function figure(b, chapter) {
    const caption = b.caption ? h('figcaption', { text: b.caption }) : null;
    if (b.kind === 'img') {
      // A new version the writer asked for: the picture stays until it is ready.
      const edit = Object.entries(st.story.editing || {}).find(([, e]) => e.image === b.id);
      const job = edit && st.pending.get(edit[0]);
      const making = edit && !edit[1].failed && (!job || ACTIVE.has(job.status));
      const failed = !making && job && !ACTIVE.has(job.status) && job.status !== 'completed';
      return h('figure', { class: 'illustration' },
        h('button', { type: 'button', class: 'figure-open', title: t('Open the image'), onclick: () => openViewer(b.id) }, picture('doc', b.id, false, b.caption || t('Illustration'))),
        making ? h('p', { class: 'figure-note' }, h('span', { class: 'spinner' }), h('span', { text: job?.status === 'running' ? t('Making the new version…') : t('New version queued…') })) : null,
        failed ? h('p', { class: 'figure-note failed' }, icon('alert'), h('span', { text: t('The new version did not come out{error}', { error: job.error ? `: ${job.error}` : '.' }) })) : null,
        caption,
        h('div', { class: 'figure-actions' }, st.story.cover === b.id ? h('span', { class: 'muted', text: t('Story cover') })
          : h('button', { type: 'button', class: 'link-btn', onclick: () => setCover(b.id) }, t('Use as cover'))));
    }
    const job = st.pending.get(b.id);
    const failed = job && !ACTIVE.has(job.status) && job.status !== 'completed';
    return h('figure', { class: `illustration pending${failed ? ' failed' : ''}` },
      h('div', { class: 'figure-wait' }, failed ? icon('alert') : h('span', { class: 'spinner' }),
        h('span', { text: failed ? (job.error || t('The illustration did not come out.')) : (job?.status === 'running' ? t('Making the illustration…') : t('Illustration queued…')) })),
      caption,
      failed ? h('div', { class: 'figure-actions' },
        h('button', { type: 'button', class: 'link-btn', onclick: () => retryIllustration(chapter, b.id) }, t('Try again')),
        h('button', { type: 'button', class: 'link-btn', onclick: () => removeIllustration(chapter, b.id) }, t('Remove'))) : null);
  }
  async function setCover(imageId) {
    try { await api('story-update', { id: st.story.id, cover: imageId }); Object.assign(st.story, { cover: imageId, cover_size: null, cover_chosen: true, cover_by: 'person', cover_job: null }); renderSide(); renderDoc(); toast(t('Story cover changed.')); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }
  async function retryIllustration(chapter, jobId) {
    try {
      const { jobs } = await api('retry', { id: newId(), from: jobId });
      const text = chapter.text.replace(`(job:${jobId})`, `(job:${jobs[0].id})`);
      await api('chapter-save', { story: st.story.id, id: chapter.id, text, rev: chapter.rev });
      for (const job of jobs) trackJob(job); launchQueued(); await reloadStory(); schedule(1500);
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }
  async function removeIllustration(chapter, jobId) {
    const text = chapter.text.split('\n').filter(line => !line.includes(`(job:${jobId})`)).join('\n');
    try { await api('chapter-save', { story: st.story.id, id: chapter.id, text, rev: chapter.rev }); await reloadStory(); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }

  // Editing a chapter by hand
  function startEdit() {
    const c = st.story.chapters[st.chapter]; if (!c) return;
    if (reader.on) exitReader();
    st.editing = { id: c.id, rev: c.rev, title: c.title, text: c.text, summary: c.summary, dirty: false, stale: false };
    st.tab = 'doc'; $('#story').dataset.tab = 'doc'; $$tabs(); renderDoc();
    $('#edit-chapter-text')?.focus();
  }
  function renderEditor() {
    const e = st.editing, doc = $('#story-doc');
    const mark = () => { e.dirty = true; };
    fill(doc, h('form', { class: 'chapter-editor stack', onsubmit: ev => { ev.preventDefault(); saveEdit(); } },
      h('div', { class: 'doc-head' }, h('span', { class: 'doc-n', text: t('Editing chapter {n}', { n: st.chapter + 1 }) }), h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => { if (confirmLeaveEdit()) { st.editing = null; renderDoc(); } } }, t('Cancel')),
        h('button', { type: 'submit', class: 'btn primary small' }, t('Save'))),
      h('p', { class: 'error', id: 'edit-chapter-error', role: 'alert' }),
      h('label', {}, t('Title'), h('input', { id: 'edit-chapter-title', maxlength: 200, value: e.title, oninput: ev => { e.title = ev.target.value; mark(); } })),
      h('label', { class: 'grow-field' }, t('Text (Markdown: *italics*, **bold**, * * * between scenes)'), h('textarea', { id: 'edit-chapter-text', rows: 24, oninput: ev => { e.text = ev.target.value; mark(); } }, e.text)),
      h('label', {}, t('Summary (the writer uses it to remember the chapter)'), h('textarea', { rows: 3, maxlength: 4000, oninput: ev => { e.summary = ev.target.value; mark(); } }, e.summary)),
      h('p', { class: 'hint', text: t('Your changes count as part of the story: the writer is told in the next message and goes on from them.') })));
    if (e.stale) markEditedElsewhere();
  }
  function markEditedElsewhere() {
    const c = st.story.chapters.find(x => x.id === st.editing?.id);
    if (c && c.rev !== st.editing.rev) { st.editing.stale = true; const el = $('#edit-chapter-error'); if (el) el.textContent = t('The writer changed this chapter while you were editing. Saving will fail: copy your text, cancel and edit again.'); }
  }
  async function saveEdit() {
    const e = st.editing;
    try {
      await api('chapter-save', { story: st.story.id, id: e.id, title: e.title, text: e.text, summary: e.summary, rev: e.rev });
      st.editing = null; await reloadStory(); renderDoc(); toast(t('Chapter saved.'));
    } catch (err) { const el = $('#edit-chapter-error'); if (el) el.textContent = err.message; }
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The chat with the writer
  // ---------------------------------------------------------------------------------------------------------------
  function writerOf(story = st.story) { return story.writer || st.writers?.defaults; }
  /** The settings a new story starts with: the last ones chosen, over the server's defaults. */
  function newWriter() {
    const saved = prefs().storyWriter && typeof prefs().storyWriter === 'object' ? prefs().storyWriter : {};
    return st.writers?.defaults ? { ...st.writers.defaults, ...saved } : null;
  }
  // The writer models of a provider, as the server lists them (claude, openai, gemini).
  function writerModels(provider) { return st.writers?.[provider] || {}; }
  function writerLabel(w) {
    const names = writerModels(w.provider);
    return `${names?.[w.model] || w.model} · ${S.effortLabel(w.effort)}`;
  }
  function renderChatHead() {
    const head = $('#chat-head'); if (!head || !st.story) return;
    const w = writerOf(); const cost = (st.spent.claude || 0) + (st.spent.openai || 0) + (st.spent.gemini || 0);
    const shown = chat.replies || isVn();
    fill(head, 
      h('button', { type: 'button', class: 'writer-chip', title: t('Choose the writer, the model and the illustrations'), onclick: openWriter }, icon('feather'), h('span', { text: writerLabel(w) }), icon('chevron-down')),
      h('span', { class: 'grow' }),
      cost > 0 ? h('button', { type: 'button', class: 'cost-chip', id: 'story-cost', title: t('Claude: {claude} · GPT: {gpt}{gemini} (GPT counts toward the OpenAI balance). Click to see the use of cache, input, output and reasoning.', { claude: formatUsd(st.spent.claude || 0), gpt: formatUsd(st.spent.openai || 0), gemini: st.spent.gemini ? ` · Gemini: ${formatUsd(st.spent.gemini)}` : '' }), onclick: openUsage, text: formatUsd(cost) }) : null,
      isVn() ? null : h('button', { type: 'button', class: 'icon-btn small chat-replies', id: 'chat-replies', 'aria-pressed': String(shown), 'aria-label': t('The writer\'s replies'),
        title: shown ? t('Hide what the writer answers here (it may have spoilers). The steps and the done notice stay.') : t('Show what the writer answers here (it may have spoilers)'),
        onclick: () => setReplies(!shown) }, icon(shown ? 'eye' : 'eye-off')),
      h('button', { type: 'button', class: 'icon-btn small', id: 'story-history', title: t('History of this story: the messages to the writer and the images asked for here'), 'aria-label': t('History of this story'),
        onclick: () => ctx.openHistory({ id: st.story.id, title: st.story.title }) }, icon('history')),
      isVn() || !st.story.chapters.length ? null : h('button', { type: 'button', class: 'icon-btn small', id: 'story-review', 'aria-label': t('Review the book'),
        title: t('Review the book: the writer rereads the whole book as an editor would (scenes and phrases that repeat, clichés, continuity, pacing)'), onclick: reviewBook }, icon('search')),
      h('button', { type: 'button', class: 'icon-btn small', title: t('New conversation: the writer forgets the chat, not the story'), 'aria-label': t('New conversation'), onclick: newConversation }, icon('compose')));
  }
  // What the writer's requests used: the cache read and written, the input, the output and the reasoning.
  const tokens = n => n >= 1e6 ? t('{n} M', { n: number(n / 1e6, { maximumFractionDigits: 2 }) })
    : n >= 1000 ? t('{n} k', { n: number(n / 1000, { maximumFractionDigits: 1 }) }) : number(n);
  async function openUsage() {
    const s = st.story; if (!s) return;
    const body = $('#story-usage-body');
    fill(body, h('p', { class: 'hint', text: t('Adding up what each request used…') }));
    $('#story-usage-dialog').showModal();
    try {
      const data = await api('story-usage', undefined, { id: s.id });
      if (st.story?.id === s.id) fill(body, ...usageView(data));
    } catch (e) { fill(body, h('p', { class: 'error', text: e.message })); }
  }
  function usageView({ total, turns }) {
    const out = [];
    for (const [provider, name] of [['claude', 'Claude'], ['openai', 'GPT'], ['gemini', 'Gemini']]) {
      const u = total[provider]; if (!u) continue;
      const input = u.read + u.write + u.input, share = input ? Math.round(u.read / input * 100) : 0;
      const row = (label, n, usd, note) => h('tr', {}, h('th', { scope: 'row' }, label, note ? h('small', { text: note }) : null), h('td', { text: tokens(n) }), h('td', { text: formatUsd(usd) }));
      out.push(h('section', { class: 'usage-part' },
        h('h3', { text: `${name}: ${formatUsd(u.cost)}` }),
        h('p', { class: 'hint', text: `${plural(u.requests, t('{n} request to the model'), t('{n} requests to the model'))}${u.warms ? t(', plus {renewals} ({cost})', { renewals: plural(u.warms, t('{n} cache renewal'), t('{n} cache renewals')), cost: formatUsd(u.warm_cost) }) : ''}. ${t('{share}% of the input came from the cache.', { share })}`
          + (provider === 'gemini' ? ` ${t('The Gemini cache is automatic: Google reads the repeated start of the conversation from it, with nothing to pay for writing it.')}` : '') }),
        h('table', { class: 'usage-table' },
          h('thead', {}, h('tr', {}, h('th', { scope: 'col', text: '' }), h('th', { scope: 'col', text: 'Tokens' }), h('th', { scope: 'col', text: t('Cost') }))),
          h('tbody', {},
            row(t('Read from the cache'), u.read, u.costs.read, t('the conversation that was already there, reread at each step')),
            provider === 'claude' ? row(t('Written to the cache'), u.write, u.costs.write, t('what came in new: messages, what the writer wrote and thought, tool results')) : null,
            row(t('Input without cache'), u.input, u.costs.input, null),
            row(t('Output'), u.output, u.costs.output, u.thinking !== null ? t('text, tool calls and reasoning ({tokens} of reasoning)', { tokens: tokens(u.thinking) }) : t('text, tool calls and reasoning'))))));
    }
    if (!out.length) return [h('p', { class: 'hint', text: t('No request to the writer has recorded usage yet.') })];
    out.push(h('p', { class: 'hint', text: t('Each step of the writer sends the whole conversation again. What was already in it comes from the cache, much cheaper than input; what is new is written to the cache once. Reasoning is charged as output.') }));
    out.push(h('h3', { text: t('Latest messages') }), h('div', { class: 'usage-turns' }, ...turns.map(turn => {
      const [provider, u] = Object.entries(turn.usage)[0] || [];
      if (!u) return null;
      const parts = [t('read {tokens}', { tokens: tokens(u.read) }), provider === 'claude' ? t('written {tokens}', { tokens: tokens(u.write) }) : null, u.input ? t('input {tokens}', { tokens: tokens(u.input) }) : null,
        u.thinking ? t('output {tokens} (reasoning {reasoning})', { tokens: tokens(u.output), reasoning: tokens(u.thinking) }) : t('output {tokens}', { tokens: tokens(u.output) })].filter(Boolean);
      return h('div', { class: 'usage-turn' },
        h('div', {}, h('strong', { text: dateTime(turn.at) }), h('span', { class: 'muted', text: ` · ${turn.writer}${turn.effort ? ` · ${S.effortLabel(turn.effort)}` : ''} · ${plural(turn.steps, t('{n} step'), t('{n} steps'))}` })),
        h('div', { class: 'usage-turn-line' }, h('span', { class: 'muted', text: parts.join(' · ') }), h('span', { text: formatUsd(u.cost) })));
    })));
    return out;
  }
  function chatForm() {
    const input = h('textarea', { id: 'chat-input', rows: 3, maxlength: 20000, placeholder: IDLE_PLACEHOLDER,
      onkeydown: e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); } } });
    return h('form', { class: 'chat-form', id: 'chat-form', onsubmit: e => { e.preventDefault(); send(); } },
      h('div', { class: 'chat-attached', id: 'chat-attached', hidden: true }),
      input,
      h('div', { class: 'chat-actions' },
        h('button', { type: 'button', class: 'btn ghost small', id: 'chat-attach', title: t('Attach images from the gallery: characters, places, a style to follow'),
          onclick: () => ctx.pickImages(st.attach, MAX_ATTACHED, renderAttached) }, icon('gallery'), h('span', { text: t('Images') })),
        h('p', { class: 'error', id: 'chat-error', role: 'alert' }),
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'btn ghost small', id: 'chat-stop', hidden: true, onclick: stop }, icon('stop'), h('span', { text: t('Stop') })),
        h('button', { type: 'submit', class: 'btn primary small', id: 'chat-send' }, icon('send'), h('span', { text: t('Send') }))));
  }
  const IDLE_PLACEHOLDER = t('Ask for the next chapter, a change, an illustration…');
  const BUSY_PLACEHOLDER = t('The writer is working. You can write: it reads your message at its next step, without stopping what it is doing.');
  /** A message of the person: its images, its text and, for one sent while the writer worked, a line saying so. */
  function userMessage(m, note = null) {
    return h('div', { class: `msg user${note ? ' waiting' : ''}` },
      m.images?.length ? h('div', { class: 'msg-images' }, ...m.images.map(id => h('button', { type: 'button', class: 'figure-open', title: t('Open the image'), onclick: () => openViewer(id) },
        picture('chat', id, true, t('Attached image'))))) : null,
      m.text ? h('div', { class: 'bubble', text: m.text }) : null,
      note || m.during ? h('small', { class: 'msg-meta', text: note || t('Sent while it was working') }) : null);
  }
  function renderChat() {
    const log = $('#chat-log'); if (!log) return;
    startPictures('chat');
    const stick = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
    const shown = chat.replies || isVn();
    const items = [];
    let conversation = null;
    for (const m of st.messages) {
      if (conversation !== null && m.conversation !== conversation && m.role !== 'notice') items.push(h('div', { class: 'chat-notice', text: t('New conversation', { ctx: 'divider' }) }));
      conversation = m.conversation;
      if (m.role === 'notice') items.push(h('div', { class: 'chat-notice', text: m.text }));
      else if (m.role === 'user') items.push(userMessage(m));
      else if (m.role === 'assistant') {
        if (m.refused) { items.push(h('div', { class: 'chat-notice warn', text: m.notice || t('The writer refused this request.') })); continue; }
        // Hidden, a reply shows only what the writer marked as free of spoilers, and a line for the rest (its notes alone
        // leave nothing: the steps that follow them say what it did).
        if (!shown) { if (m.text) items.push(...hiddenReply(m)); continue; }
        if (m.updates?.length) items.push(h('div', { class: 'msg notes' }, ...m.updates.map(u => h('p', { text: u }))));
        if (m.text) items.push(agentText(S.spoilerParts(m.text).full));
      } else if (m.role === 'tools' && m.results?.length) items.push(stepList(m.results));
    }
    if (!st.messages.length && isVn()) items.push(h('div', { class: 'chat-empty' }, icon('feather'),
      h('p', { text: t('Tell the writer about your visual novel, or import the documents you already have (the design, the chapter bases, the characters) and ask it to organize them. It builds the scene tree with the choices, the routes, the variables and the endings, writes the script of each scene and makes the sprites, the backgrounds and the CGs from the sheets.') }),
      h('button', { type: 'button', class: 'btn secondary small', onclick: () => vn.openImport() }, icon('upload'), h('span', { text: t('Import documents') }))));
    else if (!st.messages.length) {
      const mode = S.illustrateMode(writerOf().illustrate);
      items.push(h('div', { class: 'chat-empty' }, icon('feather'),
        h('p', { text: t('Tell the writer what story you want: the idea, the genre, the tone, who it is for and roughly the length. It plans the whole work first (the plan stays hidden so there are no spoilers) and then writes one chapter at a time.') }),
        h('div', { class: 'chat-empty-choice' },
          h('span', { id: 'start-illustrate-label', text: t('Illustrations') }),
          h('div', { class: 'segmented small', id: 'start-illustrate', role: 'group', 'aria-labelledby': 'start-illustrate-label' },
            ...S.ILLUSTRATE.map(([id, label]) => h('button', { type: 'button', 'data-illustrate': id, 'aria-pressed': String(id === mode), onclick: () => { if (id !== mode) saveIllustrate(id); } }, label))),
          h('small', { text: S.ILLUSTRATE.find(([id]) => id === mode)[2] })),
        mode !== 'text' && (writerOf().cover !== 'ask' || writerOf().sheets !== 'ask') ? h('p', { class: 'muted', text: writerOf().cover !== 'ask' && writerOf().sheets !== 'ask' ? t('The writer also makes the cover while planning and builds the character sheets as the characters enter the story. You can change this in the writer\'s settings.')
          : writerOf().cover !== 'ask' ? t('The writer also makes the cover while planning. You can change this in the writer\'s settings.') : t('The writer also builds the character sheets as the characters enter the story. You can change this in the writer\'s settings.') }) : null));
    }
    const turn = st.turn;
    const busy = Boolean(turn && ACTIVE.has(turn.status));
    // The steps it finished that the chat doesn't have yet, then what it is doing now, always last.
    if (busy) { const steps = S.unsavedSteps(turn, st.messages); if (steps.length) items.push(stepList(steps)); items.push(liveBlock(turn, shown)); }
    else if (turn && turn.status === 'completed' && !shown) items.push(h('div', { class: 'chat-done', role: 'status' }, icon('check'), h('span', { text: t('Done: the writer finished') })));
    else if (turn && (turn.status === 'failed' || turn.status === 'interrupted')) items.push(h('div', { class: 'chat-notice error-box' },
      h('span', { text: S.stoppedMessage(turn) }),
      h('button', { type: 'button', class: 'btn secondary small', onclick: resume }, t('Continue from where it stopped'))));
    else if (turn && turn.status === 'cancelled') items.push(h('div', { class: 'chat-notice', text: t('You stopped the writer. What it already saved stays in the story.') }));
    if (turn && turn.note && !busy) items.push(h('div', { class: 'chat-notice', text: turn.note }));
    // Messages sent while the writer works: they go in at its next step (or, if it stopped, with the next request).
    for (const m of st.inbox) items.push(userMessage(m, busy ? t('Queued: the writer reads it at the next step') : t('Not read yet: it goes with the next message or when it continues')));
    fill(log, ...items);
    if (stick) log.scrollTop = log.scrollHeight;
    const sendBtn = $('#chat-send'), stopBtn = $('#chat-stop'), input = $('#chat-input');
    if (sendBtn) sendBtn.disabled = st.sending;
    if (input) input.placeholder = busy ? BUSY_PLACEHOLDER : isVn() ? t('Ask for a scene, a route, a choice, sprites, a review of the tree…') : IDLE_PLACEHOLDER;
    if (stopBtn) { stopBtn.hidden = !busy; stopBtn.disabled = turn?.status === 'cancelling'; }
  }
  function agentText(text) {
    return h('div', { class: 'msg agent' }, h('div', { class: 'bubble' }, ...S.parseBlocks(text).map(b => b.type === 'figure' ? h('p', { text: b.caption }) : block(b, null))));
  }
  /**
   * A reply of the writer while its replies are hidden: what it marked as free of spoilers (S.spoilerParts), then one
   * line for the rest, with a button that opens that reply alone. Nothing of the hidden part is in the page (not even
   * for find-in-page or a screen reader) until it is opened.
   */
  function hiddenReply(m) {
    const { safe, full, hiddenWords } = S.spoilerParts(m.text);
    if (!hiddenWords) return safe ? [agentText(safe)] : [];
    const key = `${st.story.id}:${m.seq}`, open = chat.shown.has(key);
    const toggle = h('button', { type: 'button', class: 'hidden-reply', 'aria-expanded': String(open),
      title: open ? t('Hide this part again') : t('Show this whole reply (it may have spoilers)'),
      onclick: () => { if (open) chat.shown.delete(key); else chat.shown.add(key); renderChat(); } },
      icon(open ? 'eye' : 'eye-off'),
      h('span', { text: safe ? plural(hiddenWords, t('{n} more hidden word'), t('{n} more hidden words')) : t('The writer answered · {words}', { words: plural(hiddenWords, t('{n} word'), t('{n} words')) }) }),
      h('span', { class: 'hidden-reply-action', text: open ? t('Hide', { ctx: 'reply' }) : t('View') }));
    if (!open) return [safe ? agentText(safe) : null, toggle];
    return [m.updates?.length ? h('div', { class: 'msg notes' }, ...m.updates.map(u => h('p', { text: u }))) : null, agentText(full), toggle];
  }
  function stepList(results) {
    return h('ul', { class: 'msg steps' }, ...results.map(r => h('li', { class: r.ok ? '' : 'failed' }, icon(r.ok ? 'check' : 'alert'), h('span', { text: r.label }))));
  }
  function liveBlock(turn, shown) {
    const live = turn.live || {};
    const { what, details, warnings } = S.liveStatus(turn);
    // The reply as it streams: whole when replies are shown, else only what the writer marks as free of spoilers.
    const parts = live.text ? S.spoilerParts(live.text) : null;
    const text = parts ? (shown ? parts.full : parts.safe) : '';
    if (turn.cost > 0) details.push(t('{cost} so far', { cost: formatUsd(turn.cost) }));
    return h('div', { class: 'msg agent live' },
      h('div', { class: 'live-head' }, h('span', { class: 'spinner small' }), h('span', { text: what }),
        details.length ? h('span', { class: 'live-details', text: details.join(' · ') }) : null),
      ...warnings.map(w => h('p', { class: 'live-warn', role: 'status', text: w })),
      shown && live.updates ? h('div', { class: 'msg notes' }, h('p', { text: live.updates })) : null,
      text ? h('div', { class: 'bubble' }, ...S.parseBlocks(text).map(b => b.type === 'figure' ? h('p', { text: b.caption }) : block(b, null))) : null);
  }

  /** The gallery images going with the next message. */
  function renderAttached() {
    const box = $('#chat-attached'); if (!box) return;
    box.hidden = !st.attach.length;
    fill(box, ...st.attach.map((ref, i) => h('span', { class: 'attached' },
      h('img', { src: imageUrl(ref.id, true), alt: ref.name || t('Image from the gallery') }),
      h('button', { type: 'button', class: 'attached-remove', title: t('Remove', { ctx: 'take' }), 'aria-label': t('Remove {name}', { name: ref.name || t('the image') }), onclick: () => { st.attach.splice(i, 1); renderAttached(); } }, icon('close')))));
  }
  async function send(prepared = null) {
    const input = $('#chat-input'); const text = (prepared ?? input.value).trim();
    const images = prepared === null ? st.attach.map(r => r.id) : [];
    if ((!text && !images.length) || st.sending) return false;
    if (st.editing?.dirty && !window.confirm(t('You have unsaved changes in this chapter. Send anyway? (The writer does not see what was not saved.)'))) return;
    st.sending = true; $('#chat-error').textContent = ''; renderChat();
    try {
      const { turn } = await api('story-send', { story: st.story.id, text, credit: ctx.credit(writerOf()), ...(images.length ? { images } : {}) });
      if (prepared === null) { input.value = ''; st.attach.splice(0); renderAttached(); }
      if (turn.waiting) {
        // The writer is working: the message waits for its next step, and the poll brings the list from the server.
        st.inbox.push({ id: newId(), text, images, local: true });
        st.turn = { ...turn, waiting: undefined }; renderChat(); schedule(400);
        return true;
      }
      st.turn = turn;
      // The message shows at once; the poll brings it with its number.
      st.messages.push({ seq: st.seq + 0.5, role: 'user', text, images, conversation: st.messages.at(-1)?.conversation ?? 1, local: true });
      renderChat(); run(turn.id);
      return true;
    } catch (e) { $('#chat-error').textContent = e.message; return false; }
    finally { st.sending = false; renderChat(); }
  }
  function run(turnId) {
    st.started.add(turnId);
    api('story-run', { turn: turnId })
      .then(({ turn }) => { if (st.turn?.id === turn.id) { st.turn = turn; renderChat(); } })
      .catch(e => { if (!e.transport && e.status !== 401) { const el = $('#chat-error'); if (el) el.textContent = e.message; } })
      .finally(() => schedule(400));
    schedule(800);
  }
  async function stop() {
    if (!st.turn) return;
    try { st.turn = (await api('story-cancel', { turn: st.turn.id })).turn; renderChat(); schedule(500); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }
  async function resume() {
    try { const { turn } = await api('story-resume', { story: st.story.id, credit: ctx.credit(writerOf()) }); st.turn = turn; renderChat(); run(turn.id); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }
  async function newConversation() {
    const ok = await confirmAction({ title: t('Start a new conversation?'), message: t('The writer forgets the chat, but not the story: it rereads the chapters, the bible and the plan at the next message. Good when the conversation got long (and expensive) or went off course.'), confirm: t('New conversation') });
    if (!ok) return;
    try { await api('story-new-conversation', { story: st.story.id }); addLocalNotice(t('New conversation. The writer rereads the story at the next message.')); scheduleWarm(null); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }
  // Revisar o livro: the writer reads the whole book in a new conversation, as an editor, and notes in the plan what to vary.
  async function reviewBook() {
    const s = st.story; if (!s || st.sending) return;
    let est;
    try { est = await api('story-review-estimate', undefined, { id: s.id }); }
    catch (e) { toast(e.message, { tone: 'error' }); return; }
    const ok = await confirmAction({ title: t('Review the whole book?'),
      message: t('The writer ({writer}) reads {chapters} ({words}) in a new conversation, as an editor would: scenes and beats that repeat, repeated phrases (the app counts them), clichés, continuity and pacing. It notes in the plan what it found, so as not to repeat it from then on, and answers with what it suggests fixing; it only touches the chapters if you ask. Then it goes on writing with the whole book in memory. Estimated cost: {cost}.',
        { writer: est.writer, chapters: est.chapters === 1 ? t('the chapter') : t('the {n} chapters', { n: number(est.chapters) }), words: plural(est.words, t('{n} word'), t('{n} words')), cost: formatUsd(est.cost) }),
      confirm: t('Review') });
    if (!ok || st.story?.id !== s.id) return;
    st.sending = true; $('#chat-error').textContent = ''; renderChat();
    try {
      const { turn } = await api('story-review', { story: s.id, credit: ctx.credit(writerOf()) });
      st.turn = turn;
      st.messages.push({ seq: st.seq + 0.5, role: 'user', text: t('Review the whole book'), conversation: st.messages.at(-1)?.conversation ?? 1, local: true });
      renderChat(); run(turn.id);
    } catch (e) { $('#chat-error').textContent = e.message; }
    finally { st.sending = false; renderChat(); }
  }
  function addLocalNotice(text) { st.messages.push({ seq: st.seq + 0.5, role: 'notice', text, conversation: -1, local: true }); renderChat(); }

  // Polling while the writer works or illustrations are being made
  function schedule(delay) {
    if (!st.open || !st.story) return;
    clearTimeout(st.timer); st.timer = setTimeout(poll, delay);
  }
  async function poll() {
    st.timer = null;
    if (!st.open || !st.story) return;
    const token = st.token, id = st.story.id;
    try {
      const data = await api('story-poll', undefined, { id, after: String(st.seq) });
      if (token !== st.token) return;
      if (data.messages.length) {
        st.messages = st.messages.filter(m => !m.local).concat(data.messages);
        st.seq = data.messages.reduce((m, x) => Math.max(m, x.seq), st.seq);
      }
      const wasActive = st.turn && ACTIVE.has(st.turn.status);
      st.turn = data.turn; st.inbox = data.inbox || []; setPending(data.pending); scheduleWarm(data.warm_in);
      // A turn the server queued to answer a message sent as the last one ended: started here if nothing has started it.
      if (st.turn?.status === 'queued' && !st.started.has(st.turn.id)) run(st.turn.id);
      if (data.stamp !== st.stamp) await reloadStory();
      else {
        if (!st.editing) renderDoc();
        if ($('#story-cast-dialog').open) renderCast();
        if ($('#story-cover-dialog').open && cover.id === st.story.id) renderCoverTop();
        comics.refresh();
      }
      renderChat(); renderChatHead();
      // A turn that ended was paid for: the balance of the writer's service comes again.
      if (wasActive && !ACTIVE.has(st.turn?.status)) { ctx.spent?.(); if (st.turn?.status === 'completed') notifyDone(); }
    } catch { /* a dropped poll is tried again */ }
    const busy = st.turn && ACTIVE.has(st.turn.status);
    const waiting = [...st.pending.values()].some(j => ACTIVE.has(j.status));
    if (busy) schedule(1200); else if (waiting) schedule(4000);
  }
  /**
   * Claude's cache of the conversation lasts an hour. While the story is open, the server is asked, a little before the
   * hour runs out, to read the conversation again (cheap) so the next message doesn't write it all again (expensive).
   * The server decides whether one is due and stops some hours after the last message; this doesn't keep the studio
   * unlocked.
   */
  function scheduleWarm(seconds) {
    clearTimeout(st.warmTimer); st.warmTimer = null;
    if (seconds == null || !st.open || !st.story) return;
    const id = st.story.id;
    st.warmTimer = setTimeout(async () => {
      st.warmTimer = null;
      if (!st.open || st.story?.id !== id) return;
      try {
        const r = await api('story-warm', { story: id });
        if (st.story?.id !== id) return;
        if (r.warmed && r.cost) { st.spent = { ...st.spent, claude: (st.spent.claude || 0) + r.cost }; renderChatHead(); ctx.spent?.(); }
        scheduleWarm(r.warm_in);
      } catch { /* the next message pays for the cache */ }
    }, Math.max(5, seconds) * 1000);
  }
  function notifyDone() {
    if (st.tab !== 'doc' && window.matchMedia('(max-width: 920px)').matches) toast(t('The writer finished.'), { action: { label: t('Read'), run: () => { st.tab = 'doc'; $('#story').dataset.tab = 'doc'; $$tabs(); } } });
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The cover: chosen from the gallery, made from a description, a new version of it, or asked of the writer
  // ---------------------------------------------------------------------------------------------------------------
  const COVER_ASPECTS = [['2:3', t('Portrait 2:3 (book)')], ['3:4', t('Portrait 3:4')], ['4:5', t('Portrait 4:5')], ['9:16', t('Tall 9:16')], ['1:1', t('Square', { ctx: 'cover' })], ['3:2', t('Landscape 3:2')], ['16:9', t('Wide 16:9')]];
  const MAX_COVER_FACES = 8; // Writer::makeCover
  // The kinds of cover (Stories::COVER_FORMATS): the app designs each as the real front cover of its format.
  const COVER_FORMATS = [
    ['novel', t('Book (novel)'), t('A book cover in the mood of the story, from painting or photography to type alone, depending on the style of each cover.')],
    ['illustrated', t('Illustrated book'), t('The art in the same style as the story\'s illustrations, with the title drawn along with it.')],
    ['light_novel', 'Light novel', t('A classy Japanese light novel cover: refined anime-style illustration and the title as a drawn logo.')],
    ['manga', t('Manga (volume)'), t('A manga volume cover: colored art in the manga\'s style and the title as a manga logo.')],
    ['comic', t('Comic (issue)'), t('A comic book cover: colored art in the comic\'s style and the title as a big logo.')],
    ['graphic_novel', 'Graphic novel', t('A graphic novel cover: strong, personal art in the comic\'s style, looking like a bookstore book, and the title drawn along with the art.')]];
  const COVER_WHY = { comic: t('because of the story\'s comics'), light_novel: t('the story talks of light novels'), style: t('because of the anime or manga visual style'), illustrated: t('the story has illustrations'), text: t('the story is text only') };
  // The design directions (Writer::COVER_STYLES): a request makes one cover in each style marked, to choose from.
  const COVER_STYLES = [
    ['classic', t('Classic'), t('elegant and timeless, refined serif, the air of a special edition')],
    ['literal', 'Literal', t('a scene from the story, like a movie poster, with the title inside the scene')],
    ['modern', t('Modern'), t('today\'s graphic design: grid, blocks of color, cutouts and big letters')],
    ['minimal', t('Minimalist'), t('a single visual idea, a lot of empty space and precise typography')],
    ['retro', t('Retro'), t('screen printing from the 50s and 60s: few colors, flat shapes and period lettering')],
    ['abstract', t('Abstract'), t('the mood of the story in color, shape and texture, with no literal scene')],
    ['typographic', t('Typographic'), t('the title is the image: huge, cut-out letters with the art inside them')]];
  // Three families (classic or literal; a graphic one; abstract or typographic): a new set takes one of each.
  const COVER_FAMILIES = [['classic', 'literal'], ['modern', 'minimal', 'retro'], ['abstract', 'typographic']];
  const MAX_COVER_STYLES = 4; // Writer::coverStyles
  const styleName = id => (id === 'before' ? t('Previous') : COVER_STYLES.find(x => x[0] === id)?.[1] || t('Cover'));
  /** Three styles at random, one of each family: every new set is different. */
  const drawStyles = () => new Set(COVER_FAMILIES.map(f => f[Math.floor(Math.random() * f.length)]));
  const cover = { id: null, tab: 'generate', aspect: '2:3', lettering: true, faces: new Set(), prompt: '', instruction: '', notes: '', busy: false,
    format: '', author: '', volume: '', styles: drawStyles(), stylesTouched: false };
  /** The story whose cover the window shows: the one open, or one of the list. */
  function coverStory() { return st.story?.id === cover.id ? st.story : st.list.find(s => s.id === cover.id) || null; }
  /** A story's writer settings, filled with the defaults (the list has them as saved). */
  function settingsOf(s) { return s === st.story ? writerOf(s) : { ...(st.writers?.defaults || {}), ...(s.writer || {}) }; }
  /** Whether the cover being made is a set of covers (to choose from). */
  const coverSet = s => !!s.cover_job && (s.cover_choices || []).some(c => c.batch === s.cover_job);
  /** How a cover of a set that isn't ready goes: queued, running or failed (with why). */
  function choiceState(s, c) {
    if (s !== st.story) return { status: c.status || 'queued', error: c.error || '' };
    const job = st.pending.get(c.job);
    if (!job) return { status: 'queued', error: '' };
    return ACTIVE.has(job.status) ? { status: job.status, error: '' } : { status: 'failed', error: job.error || '' };
  }
  /** A cover being made (a set of them), or a new version of it: queued, running or failed (with why); null when there is none. */
  function coverState(s) {
    if (s !== st.story) return s.cover_making ? { status: s.cover_making, error: s.cover_error || '' } : null;
    // A set is being made while one of its covers is; it failed when all of them did.
    const set = coverSet(s) ? s.cover_choices.filter(c => c.batch === s.cover_job && !c.image).map(c => choiceState(s, c)) : [];
    if (set.length) return { status: set.some(x => x.status === 'running' || x.status === 'cancelling') ? 'running' : set.some(x => x.status === 'queued') ? 'queued' : 'failed', error: set.find(x => x.error)?.error || '' };
    const edit = s.cover_chosen ? Object.entries(s.editing || {}).filter(([, e]) => e.image === s.cover).map(([id]) => id).pop() : null;
    const jobId = s.cover_job || edit; if (!jobId) return null;
    const job = st.pending.get(jobId);
    if (!s.cover_job && s.editing[edit].failed) return { status: 'failed', error: job?.error || '' };
    if (!job) return { status: 'queued', error: '' };
    return ACTIVE.has(job.status) ? { status: job.status, error: '' } : { status: 'failed', error: job.error || '' };
  }
  function openCover(s) {
    if (!s) return;
    if (cover.id !== s.id) {
      const d = s.cover_design || {};
      Object.assign(cover, { prompt: '', instruction: '', notes: '', aspect: '2:3', lettering: true, format: d.chosen || '', author: d.author || '', volume: d.volume || '', styles: drawStyles(), stylesTouched: false });
    }
    cover.id = s.id; cover.tab = 'generate';
    cover.faces = new Set((s.faces || []).slice(0, MAX_COVER_FACES).map(f => f.image));
    renderCover();
    $('#story-cover-dialog').showModal();
  }
  function renderCover() {
    if (!coverStory()) return;
    fill($('#story-cover-body'),
      h('div', { id: 'story-cover-top', class: 'cover-top' }),
      h('section', { id: 'story-cover-choices', class: 'cover-choices', 'aria-label': t('Covers to choose from'), hidden: true }),
      h('nav', { class: 'segmented', id: 'story-cover-tabs', role: 'group', 'aria-label': t('What to do with the cover') }),
      h('div', { id: 'story-cover-panel', class: 'stack' }));
    renderCoverTop(); renderCoverPanel();
  }
  /** The cover as it is now, and the one being made. Redrawn while it is made; what is being typed below stays. */
  function renderCoverTop() {
    const s = coverStory(), top = $('#story-cover-top'); if (!s || !top) return;
    const state = coverState(s), making = state && state.status !== 'failed';
    const who = !s.cover ? t('No cover yet.') : !s.cover_chosen ? t('No cover chosen: the list shows the first illustration.')
      : s.cover_by === 'agent' ? t('Made by the writer.') : s.cover_by === 'mcp' ? t('Made by an assistant via MCP.') : t('Chosen or made by you.');
    startPictures('cover');
    fill(top,
      h('div', { class: 'cover-preview', vars: { '--shape': coverShape(s).toFixed(4) } }, s.cover
        ? h('button', { type: 'button', class: 'figure-open', title: t('Open the image'), onclick: () => openViewer(s.cover) }, picture('cover', s.cover, true, t('Current cover')))
        : h('span', { class: 'story-cover-title', text: s.title || t('Untitled') })),
      h('div', { class: 'cover-info' },
        h('p', { class: 'muted', text: who }),
        making ? h('p', { class: 'cover-note' }, h('span', { class: 'spinner small' }), h('span', { text: coverSet(s)
          ? (state.status === 'running' ? t('Making the covers… The first one ready takes its place; the others stay below, to choose from.') : t('Covers queued…'))
          : state.status === 'running' ? t('Making the cover… It takes its place when it is ready.') : t('Cover queued…') })) : null,
        state?.status === 'failed' ? h('p', { class: 'cover-note failed' }, icon('alert'), h('span', { text: `${coverSet(s) ? t('The covers did not come out') : t('The cover did not come out')}${state.error ? `: ${state.error}` : '.'}` })) : null,
        state?.status === 'failed' ? h('div', { class: 'figure-actions' },
          h('button', { type: 'button', class: 'link-btn', onclick: () => coverAction({ mode: 'retry' }) }, t('Try again')),
          h('button', { type: 'button', class: 'link-btn', onclick: dismissCover }, t('Set aside'))) : null,
        h('div', { class: 'cover-buttons' },
          h('button', { type: 'button', class: 'btn secondary small', onclick: chooseCover }, icon('gallery'), h('span', { text: t('Choose from the gallery') })),
          s.cover_chosen ? h('button', { type: 'button', class: 'btn ghost small', onclick: removeCover }, icon('trash'), h('span', { text: t('Remove', { ctx: 'take' }) })) : null)));
    const edit = $('#story-cover-tabs button[data-tab=edit]');
    if (edit) edit.disabled = !s.cover;
    renderCoverChoices(s);
  }
  /**
   * The covers made to choose from, the newest first: each opens large, and Usar makes it the cover. One still being
   * made shows how it goes; one that failed can be made again or left out.
   */
  function renderCoverChoices(s) {
    const box = $('#story-cover-choices'); if (!box) return;
    const choices = s.cover_choices || [];
    box.hidden = !choices.length;
    if (!choices.length) { box.replaceChildren(); return; }
    const tile = c => {
      const name = styleName(c.style);
      if (!c.image) {
        const state = choiceState(s, c), failed = state.status === 'failed';
        return h('figure', { class: `cover-choice waiting${failed ? ' failed' : ''}` },
          h('div', { class: 'cover-choice-art', title: failed && state.error ? state.error : null }, failed ? icon('alert') : h('span', { class: 'spinner small' }),
            h('span', { text: failed ? t('Did not come out') : state.status === 'queued' ? t('Queued') : t('Making…') })),
          h('figcaption', {}, h('span', { class: 'cover-choice-name', text: name }),
            failed ? h('span', { class: 'cover-choice-actions' },
              h('button', { type: 'button', class: 'link-btn', onclick: () => coverAction({ mode: 'retry', job: c.job }) }, t('Again')),
              h('button', { type: 'button', class: 'link-btn', onclick: () => dismissCover(c.job) }, t('Remove', { ctx: 'take' }))) : null));
      }
      const current = s.cover_chosen && s.cover === c.image, [w, ht] = Array.isArray(c.size) ? c.size : [];
      return h('figure', { class: `cover-choice${current ? ' current' : ''}`, vars: { '--shape': (w > 0 && ht > 0 ? Math.min(2.4, Math.max(.4, w / ht)) : 2 / 3).toFixed(4) } },
        h('button', { type: 'button', class: 'figure-open cover-choice-art', title: t('See larger'), onclick: () => openViewer(c.image) }, picture('cover', c.image, true, t('Cover {name}', { name }))),
        h('figcaption', {}, h('span', { class: 'cover-choice-name', text: name }),
          current ? h('span', { class: 'cover-choice-current' }, icon('check'), h('span', { text: t('Current') }))
            : h('button', { type: 'button', class: 'btn secondary small', 'data-cover-use': c.image, onclick: () => saveCover(c.image) }, t('Use'))));
    };
    fill(box, h('div', { class: 'cover-choices-head' }, h('h3', { text: t('To choose from') }), h('p', { class: 'hint', text: t('The covers made here, newest first. Tap to see larger.') })),
      h('div', { class: 'cover-choices-row' }, ...choices.map(tile)));
  }
  function renderCoverPanel() {
    const s = coverStory(), panel = $('#story-cover-panel'); if (!s || !panel) return;
    if (cover.tab === 'edit' && !s.cover) cover.tab = 'generate';
    fill($('#story-cover-tabs'), ...[['generate', t('Generate')], ['edit', t('Edit', { ctx: 'action' })], ['writer', t('Ask the writer')]].map(([id, label]) => h('button', { type: 'button', 'data-tab': id,
      'aria-pressed': String(cover.tab === id), disabled: id === 'edit' && !s.cover, onclick: () => { cover.tab = id; renderCoverPanel(); } }, label)));
    const error = h('p', { id: 'cover-error', class: 'error', role: 'alert' }), cost = h('p', { id: 'cover-cost', class: 'hint' });
    if (cover.tab === 'generate') {
      const faces = s.faces || [];
      const prompt = h('textarea', { id: 'cover-prompt', rows: 4, maxlength: 4000, oninput: e => { cover.prompt = e.target.value; },
        placeholder: s.synopsis ? t('Optional: the scene, who appears, the mood. Empty, the cover comes from the title and the synopsis, in the chosen type of cover.') : t('The scene, who appears, the mood…') });
      prompt.value = cover.prompt;
      fill(panel,
        ...coverDesignFields(s),
        coverStyleFields(),
        h('label', {}, t('What the cover shows'), prompt),
        h('label', {}, t('Aspect ratio'), h('select', { id: 'cover-aspect', onchange: e => { cover.aspect = e.target.value; coverCost(); } },
          ...COVER_ASPECTS.map(([id, label]) => h('option', { value: id, selected: id === cover.aspect }, label)))),
        faces.length ? h('fieldset', { class: 'cover-faces' }, h('legend', { text: t('Characters as reference') }),
          h('div', { class: 'face-picks' }, ...faces.map(f => h('button', { type: 'button', class: 'face-pick', 'aria-pressed': String(cover.faces.has(f.image)), title: f.name,
            onclick: e => toggleFace(f.image, e.currentTarget) }, picture('cover', f.image, true, ''), h('span', { text: f.name })))),
          h('p', { class: 'hint', text: t('Each one\'s main sheet image (up to {max}) is only for their appearance: the pose, the setting and the composition come from the cover. Uncheck whoever does not need to be in it.', { max: MAX_COVER_FACES }) }))
          : h('p', { class: 'hint', text: t('No character sheets yet. With them (in Sheets and style, or by asking the writer), the cover comes out with the story\'s faces.') }),
        cost, error,
        h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn primary', 'data-cover-go': '', onclick: () => coverAction({ mode: 'generate', prompt: cover.prompt, aspect: cover.aspect,
          lettering: cover.lettering, design: coverDesign(), styles: pickedStyles(), references: faces.filter(f => cover.faces.has(f.image)).map(f => f.image) }) },
          icon('sparkle'), h('span', { id: 'cover-go-text', text: goText() }))));
    } else if (cover.tab === 'edit') {
      const instruction = h('textarea', { id: 'cover-instruction', rows: 3, maxlength: 4000, oninput: e => { cover.instruction = e.target.value; },
        placeholder: t('E.g.: at night; the red coat; more space at the top for the title. In Photoreal, Enhance and Upscale, optional notes.') });
      instruction.value = cover.instruction;
      fill(panel,
        h('label', {}, t('What to change'), instruction),
        cost, error,
        h('div', { class: 'cover-edit-actions' },
          ...[['edit', t('Apply'), 'pencil', 'primary'], ['photoreal', t('Photoreal'), 'camera', 'secondary'], ['enhance', t('Enhance'), 'sparkle', 'secondary'], ['upscale', t('Upscale', { ctx: 'action' }), 'upscale', 'secondary']]
            .map(([kind, label, ic, tone]) => h('button', { type: 'button', class: `btn ${tone}`, 'data-cover-go': '', onclick: () => coverEdit(kind) }, icon(ic), h('span', { text: label })))),
        h('p', { class: 'hint', text: t('The new version replaces the cover when it is ready; the previous one stays in the gallery. The chapters\' illustrations do not change.') }));
    } else {
      const notes = h('textarea', { id: 'cover-notes', rows: 3, maxlength: 2000, oninput: e => { cover.notes = e.target.value; }, placeholder: t('What to show, the mood, with or without the title…') });
      notes.value = cover.notes;
      fill(panel,
        h('p', { class: 'hint', text: t('The writer knows the whole story: it says what the covers show, uses the character sheets and the visual style, and asks for the images; the app builds each cover in the chosen type and style. The request goes as a message in the conversation.') }),
        ...coverDesignFields(s),
        coverStyleFields(),
        h('label', {}, t('Guidance (optional)'), notes),
        error,
        h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn primary', onclick: askWriterCover }, icon('feather'), h('span', { text: t('Ask the writer') }))));
    }
    coverCost();
  }
  /** The cover's format, with what it looks like, and the words lettered on it: the same for a cover made here or by the writer. */
  function coverDesignFields(s) {
    const d = s.cover_design || { auto: 'novel', why: 'text' };
    const name = id => COVER_FORMATS.find(f => f[0] === id)?.[1] || id;
    const current = () => cover.format || d.auto;
    const hint = h('p', { class: 'hint', id: 'cover-format-hint' });
    const volumeLabel = h('span');
    const volume = h('input', { type: 'text', id: 'cover-volume', maxlength: 12, autocomplete: 'off', placeholder: t('None'), oninput: e => { cover.volume = e.target.value; } });
    volume.value = cover.volume;
    const author = h('input', { type: 'text', id: 'cover-author', maxlength: 80, autocomplete: 'off', placeholder: t('Optional'), oninput: e => { cover.author = e.target.value; } });
    author.value = cover.author;
    const show = () => {
      const f = current();
      hint.textContent = `${COVER_FORMATS.find(x => x[0] === f)?.[2] || ''}${cover.format ? '' : ` ${t('Chosen on its own: {why}.', { why: COVER_WHY[d.why] || t('from the story') })}`}`;
      volumeLabel.textContent = f === 'comic' ? t('Issue number (optional)') : t('Volume (optional)');
    };
    show();
    return [
      h('div', { class: 'row2' },
        h('label', {}, t('Type of cover'), h('select', { id: 'cover-format', onchange: e => { cover.format = e.target.value; show(); } },
          h('option', { value: '', selected: !cover.format }, t('Automatic: {name}', { name: name(d.auto) })),
          ...COVER_FORMATS.map(([id, label]) => h('option', { value: id, selected: cover.format === id }, label)))),
        h('label', {}, t('Author on the cover'), author)),
      hint,
      h('div', { class: 'row2' },
        h('label', {}, volumeLabel, volume),
        h('label', { class: 'switch cover-lettering' }, h('input', { type: 'checkbox', id: 'cover-lettering', checked: cover.lettering, onchange: e => { cover.lettering = e.target.checked; } }),
          h('span', { class: 'switch-track', 'aria-hidden': 'true' }), h('span', { text: t('Title and names on the cover') })))];
  }
  /**
   * The styles of the covers to make, one cover in each (1 to MAX_COVER_STYLES): three different ones are marked when
   * the window opens, and drawn again after each set unless the person chose them. Sortear draws three others.
   */
  function coverStyleFields() {
    const chips = h('div', { class: 'cover-style-picks', id: 'cover-styles' }), about = h('ul', { class: 'cover-style-about' });
    const draw = () => {
      fill(chips, ...COVER_STYLES.map(([id, label, what]) => h('button', { type: 'button', class: 'chip', 'data-style': id, 'aria-pressed': String(cover.styles.has(id)), title: what,
        onclick: () => {
          if (cover.styles.has(id) && cover.styles.size === 1) { toast(t('Check at least one style.')); return; }
          if (!cover.styles.has(id) && cover.styles.size >= MAX_COVER_STYLES) { toast(t('Up to {max} styles ({max} covers) per request.', { max: MAX_COVER_STYLES })); return; }
          if (cover.styles.has(id)) cover.styles.delete(id); else cover.styles.add(id);
          cover.stylesTouched = true; draw();
        } }, label)));
      fill(about, ...COVER_STYLES.filter(([id]) => cover.styles.has(id)).map(([, label, what]) => h('li', {}, h('strong', { text: label }), h('span', { text: ` ${what}.` }))));
      const go = $('#cover-go-text'); if (go) go.textContent = goText();
      coverCost();
    };
    draw();
    return h('fieldset', { class: 'cover-styles' },
      h('legend', {}, h('span', { text: t('Styles: one cover in each') }),
        h('button', { type: 'button', class: 'link-btn', title: t('Check another three styles, very different from each other'), onclick: () => { cover.styles = drawStyles(); cover.stylesTouched = false; draw(); } }, t('Shuffle'))),
      chips, about);
  }
  /** The styles marked, in the order of COVER_STYLES. */
  const pickedStyles = () => COVER_STYLES.map(([id]) => id).filter(id => cover.styles.has(id));
  const goText = () => (cover.styles.size === 1 ? t('Generate cover') : t('Generate {n} covers', { n: cover.styles.size }));
  /** What goes with a cover request: the format ('' reads it from the story), the author's name and the volume. */
  function coverDesign() { return { format: cover.format || null, author: cover.author.trim(), volume: cover.volume.trim() }; }
  function toggleFace(image, button) {
    if (cover.faces.has(image)) cover.faces.delete(image);
    else if (cover.faces.size >= MAX_COVER_FACES) { toast(t('Up to {max} characters as reference.', { max: MAX_COVER_FACES })); return; }
    else cover.faces.add(image);
    button.setAttribute('aria-pressed', String(cover.faces.has(image)));
  }
  function coverCost() {
    const el = $('#cover-cost'), s = coverStory(); if (!el || !s) return;
    const w = settingsOf(s), making = cover.tab === 'generate';
    // A new version keeps the size of the cover (Ampliar doubles it): the estimate is for the usual size.
    const credit = ctx.credit(making ? w : { ...w, image_scale: '1' }, making ? cover.aspect : '2:3'), size = making ? ctx.imageSize(w, cover.aspect) : null;
    const parts = [], count = making ? cover.styles.size : 1;
    if (size) parts.push(size.replace('x', ' × '));
    if (credit.image !== null) { const cost = formatUsd(credit.image * count); parts.push(!making ? t('about {cost} per version (Upscale costs more)', { cost }) : count > 1 ? t('about {cost} for the {n} covers', { cost, n: number(count) }) : t('about {cost}', { cost })); }
    if (credit.balance !== null) parts.push(t('estimated {provider} balance: {balance}', { provider: credit.of, balance: formatUsd(credit.balance) }));
    if (!ctx.hasKey()) parts.push(t('the OpenAI key is missing in Settings'));
    el.textContent = parts.length ? `${parts.join(' · ')}.` : '';
    el.className = !ctx.hasKey() || (credit.image !== null && credit.balance !== null && credit.image * count > credit.balance) ? 'error' : 'hint';
  }
  function coverEdit(kind) {
    const instruction = cover.instruction.trim();
    if (kind === 'edit' && !instruction) { $('#cover-error').textContent = t('Say what to change in the cover.'); return; }
    coverAction({ mode: 'edit', kind, instruction });
  }
  async function coverAction(body) {
    const s = coverStory(); if (!s || cover.busy) return;
    cover.busy = true;
    for (const b of document.querySelectorAll('#story-cover-body [data-cover-go]')) b.disabled = true;
    const error = $('#cover-error'); if (error) error.textContent = '';
    try {
      const { jobs } = await api('story-cover', { id: s.id, ...body });
      for (const job of jobs) trackJob(job);
      launchQueued();
      if (body.mode === 'generate') { cover.prompt = ''; if (!cover.stylesTouched) cover.styles = drawStyles(); }
      if (body.mode === 'edit') cover.instruction = '';
      if (body.mode !== 'retry') renderCoverPanel();
      await coverChanged(s.id);
    } catch (e) { if (error && body.mode !== 'retry') error.textContent = e.message; else toast(e.message, { tone: 'error' }); }
    finally { cover.busy = false; for (const b of document.querySelectorAll('#story-cover-body [data-cover-go]')) b.disabled = false; }
  }
  /** After a change to a cover: the story (or the list) again, and the window with it. */
  async function coverChanged(id) {
    if (st.story?.id === id) { await reloadStory(); schedule(1500); }
    else await loadList({ quiet: true });
    renderCoverTop();
  }
  /** A cover that didn't come out, set aside: the set's, or one of it ($job). */
  async function dismissCover(job) {
    try { await api('story-cover', { id: cover.id, mode: 'dismiss', ...(typeof job === 'string' ? { job } : {}) }); await coverChanged(cover.id); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }
  function chooseCover() {
    const list = [];
    ctx.pickImages(list, 1, () => { if (list[0]?.id) saveCover(list[0].id); }, t('Story cover'));
  }
  async function removeCover() {
    const ok = await confirmAction({ title: t('Remove the cover?'), message: t('The story is left with no chosen cover: the list shows the first illustration, if there is one. The image stays in the gallery.'), confirm: t('Remove', { ctx: 'take' }) });
    if (ok) saveCover(null);
  }
  async function saveCover(imageId) {
    const id = cover.id;
    try { await api('story-update', { id, cover: imageId }); await coverChanged(id); if (st.story?.id === id) renderDoc(); toast(imageId ? t('Cover changed.') : t('Cover removed.')); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }
  /** The writer makes it: a message in the conversation, from the story (opened first when the list asked). */
  async function askWriterCover() {
    const id = cover.id, notes = cover.notes.trim(), s = coverStory(); if (!s) return;
    const design = coverDesign(), format = design.format || s.cover_design?.auto || 'novel';
    // The format and the words are kept first: the writer's cover is made with them, like one made here.
    try { await api('story-update', { id, cover_design: design }); }
    catch (e) { $('#cover-error').textContent = e.message; return; }
    const kind = { novel: t('a book cover (novel), in the cover style of the story\'s genre'), illustrated: t('an illustrated book cover, in the style of the illustrations'),
      light_novel: t('a Japanese light novel cover'), manga: t('a manga volume cover (tankōbon)'), comic: t('a comic book cover'), graphic_novel: t('a graphic novel cover') }[format];
    const volume = design.volume ? (format === 'comic' ? t('issue #{volume}', { volume: design.volume }) : t('Vol. {volume}', { volume: design.volume })) : null;
    const words = [volume, design.author ? t('author “{author}”', { author: design.author }) : null].filter(Boolean);
    const text = !cover.lettering ? t('with no text at all in the image (lettering: false)') : words.length ? t('with the title and {words}', { words: listOf(words) }) : t('with the title');
    const styles = pickedStyles(), list = listOf(styles.map(styleName));
    const how = styles.length === 1 ? t('in the {style} style', { style: list }) : t('one in each style: {styles}', { styles: list });
    $('#story-cover-dialog').close();
    if (st.story?.id !== id) await openStory(id);
    if (st.story?.id !== id) return;
    st.tab = 'chat'; $('#story').dataset.tab = 'chat'; $$tabs();
    const vars = { kind, how, styles: styles.join(', '), text, n: number(styles.length) };
    if (await send(`${styles.length === 1 ? t('Make a new cover for the story: {kind}, {how} (cover_styles: {styles}), {text}.', vars) : t('Make {n} new covers for me to choose from for the story: {kind}, {how} (cover_styles: {styles}), {text}.', vars)}${notes ? `\n\n${notes}` : ''}`)) {
      cover.notes = ''; if (!cover.stylesTouched) cover.styles = drawStyles();
    }
  }

  // ---------------------------------------------------------------------------------------------------------------
  // The sheets of characters and places, and the visual style: what every new picture of the story is made with
  // ---------------------------------------------------------------------------------------------------------------
  const MAX_SHEET_PICTURES = 24, MAX_STYLE_PICTURES = 4; // Stories::MAX_SHEETS, MAX_STYLE_IMAGES
  // The text boxes stay the same elements from one redraw to the next (the window is redrawn while pictures are made):
  // what is being typed stays, and is saved when the box is left. space: the book's sheets (story) or the comic
  // adaptation's own (comic), kept apart; the same window shows either.
  const castUi = { notes: new Map(), style: null, lettering: null, space: 'story' };
  const comicSpace = () => castUi.space === 'comic';
  const castOf = () => comicSpace() ? st.story?.comic?.cast || null : st.story?.cast || null;
  function setCast(cast) { if (comicSpace()) st.story.comic.cast = cast; else st.story.cast = cast; }
  function openCast(space = 'story') {
    if (!st.story) return;
    castUi.notes = new Map(); castUi.style = null; castUi.lettering = null; castUi.space = space === 'comic' ? 'comic' : 'story';
    if (!castOf()) return;
    $('#story-cast-title').textContent = comicSpace() ? t('Sheets and lettering of the adaptation') : t('Sheets and style');
    renderCast();
    $('#story-cast-dialog').showModal();
  }
  function renderCast() {
    const s = st.story, body = $('#story-cast-body'); if (!s || !body) return;
    const cast = castOf();
    if (!cast) { if ($('#story-cast-dialog').open) $('#story-cast-dialog').close(); return; }
    const comic = comicSpace(), people = cast.characters.filter(c => c.kind !== 'place'), places = cast.characters.filter(c => c.kind === 'place');
    startPictures('cast');
    fill(body,
      h('p', { class: 'hint', text: comic
        ? t('The adaptation\'s sheets belong to the comics only: the pages are drawn with them and with this lettering, and the book\'s sheets stay as they are. Bring from the book the ones that work as they are, or ask for versions in the comics style (the style is in Comics › Set up).')
        : t('The sheets keep how each character and each place looks: ages, clothes, changes along the story. The writer uses these images as reference so they look the same from one illustration to the next, and builds the sheets as the story moves on. You can too.') }),
      comic ? h('section', { class: 'cast-style' }, letteringSection(cast.style.lettering)) : styleSection(cast),
      ...[['character', t('Characters'), people, t('New character')], ['place', t('Places'), places, t('New place')]].map(([kind, title, list, add]) => h('section', { class: 'cast-group' },
        h('div', { class: 'cast-group-head' }, h('h3', { text: title }),
          h('button', { type: 'button', class: 'btn ghost small', onclick: () => newSheet(kind) }, icon('plus'), h('span', { text: add }))),
        list.length ? list.map(sheetCard) : h('p', { class: 'hint', text: kind === 'place' ? t('No places yet.') : t('No characters yet.') }))),
      comic ? bookSheets(cast) : null,
      h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn secondary', onclick: () => prefillChat(comic
        ? (people.length || places.length
          ? t('Update the adaptation\'s sheets: add who and what the next pages need, and the new looks of whoever changes in the comics.')
          : t('Build the adaptation\'s sheets and the comics lettering: bring from the book the sheets that work as they are and make in the comics style the ones that need to change.'))
        : people.length || places.length
          ? t('Update the sheets: add who and what came into the story, and the new looks of whoever changed.')
          : t('Build the sheets of the main characters and the important places, in the story\'s visual style.')) }, icon('feather'), h('span', { text: t('Ask the writer') }))));
  }
  /** The book's sheets, in the adaptation's window: each one can be brought in (its pictures, kind and note), free. */
  function bookSheets(cast) {
    const book = st.story.cast.characters;
    if (!book.length) return null;
    const mine = name => cast.characters.find(c => c.name.toLocaleLowerCase(locale) === name.toLocaleLowerCase(locale));
    return h('section', { class: 'cast-group book-sheets' },
      h('div', { class: 'cast-group-head' }, h('h3', { text: t('The book\'s sheets') })),
      h('p', { class: 'hint', text: t('For reference. Bringing copies the images, the type and the note to the adaptation\'s sheet with the same name; after that it changes without touching the book\'s.') }),
      h('div', { class: 'book-sheet-list' }, ...book.map(c => {
        const have = mine(c.name), ids = new Set((have?.images || []).map(im => im.image || im.job));
        const missing = c.images.filter(im => !ids.has(im.image || im.job)).length;
        const first = c.images.find(im => im.image);
        return h('div', { class: 'book-sheet', 'data-book-sheet': c.id },
          first ? h('button', { type: 'button', class: 'sheet-open', title: t('Open the image'), onclick: () => openViewer(first.image) }, picture('cast', first.image, true, c.name)) : h('span', { class: 'book-sheet-blank' }, icon(c.kind === 'place' ? 'image' : 'user')),
          h('span', { class: 'book-sheet-text' }, h('strong', { text: c.name }),
            h('small', { text: [c.kind === 'place' ? t('place') : t('character'), plural(c.images.length, t('{n} image'), t('{n} images')), have ? (missing ? plural(missing, t('{n} missing from the adaptation'), t('{n} missing from the adaptation', { ctx: 'many' })) : t('already in the adaptation')) : null].filter(Boolean).join(' · ') })),
          !have || missing ? h('button', { type: 'button', class: 'btn ghost small', onclick: () => bringSheet(c) }, icon('plus'), h('span', { text: have ? t('Bring the missing ones') : t('Bring') })) : null);
      })));
  }
  async function bringSheet(c) {
    try {
      const { cast } = await api('story-cast', { story: st.story.id, space: 'comic', from_book: true, character: c.name });
      st.story.comic.cast = cast; renderCast(); comics.refresh(); reloadStory();
      toast(t('{name}\'s sheet is in the adaptation.', { name: c.name }));
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }
  function styleSection(cast) {
    const box = castUi.style ||= h('textarea', { id: 'cast-style', rows: 4, maxlength: 4000, 'aria-label': t('Description of the visual style'),
      placeholder: t('E.g.: watercolor with ink outlines, cool colors, soft light; or black and white manga with screentone, thin clean lines.'),
      onchange: e => saveStyle({ text: e.target.value }) });
    if (document.activeElement !== box) box.value = cast.style.text;
    return h('section', { class: 'cast-style' },
      h('h3', { text: t('Visual style') }),
      h('p', { class: 'hint', text: t('It goes with every new illustration, cover and sheet of this story: the description at the end of the request, and the images as a style reference (the line, the color, the light; not what they show). Saved when you leave the field.') }),
      box,
      cast.style.images.length ? h('div', { class: 'sheet-pics' }, ...cast.style.images.map(im => h('figure', { class: 'sheet-pic' },
        h('button', { type: 'button', class: 'sheet-open', title: t('Open the image'), onclick: () => openViewer(im.image) }, picture('cast', im.image, true, im.caption || t('Style image'))),
        im.caption ? h('figcaption', { text: im.caption }) : null,
        h('div', { class: 'sheet-actions' }, h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove from the style (it stays in the gallery)'), 'aria-label': t('Remove from the style'),
          onclick: () => saveStyle({ remove: [im.image] }) }, icon('close')))))) : null,
      cast.style.images.length < MAX_STYLE_PICTURES ? h('div', { class: 'sheet-foot' }, h('button', { type: 'button', class: 'btn ghost small', onclick: addStylePictures }, icon('gallery'),
        h('span', { text: t('Style images from the gallery (up to {max})', { max: MAX_STYLE_PICTURES }) }))) : null,
      letteringSection(cast.style.lettering));
  }
  /** The lettering: how every text drawn in a picture looks, and a sample of it that goes along as the reference. */
  function letteringSection(l) {
    const box = castUi.lettering ||= h('textarea', { id: 'cast-lettering', rows: 3, maxlength: 1000, 'aria-label': t('Description of the lettering'),
      placeholder: t('E.g.: hand-lettered manga, all caps, firm black strokes; white balloons with thin outlines; big slanted sound effects with a white outline, behind the characters.'),
      onchange: e => saveStyle({ lettering: e.target.value }) });
    if (document.activeElement !== box) box.value = l.text;
    const job = l.job ? st.pending.get(l.job) : null, failed = job && !ACTIVE.has(job.status);
    const sample = [];
    if (l.image) sample.push(h('figure', { class: 'sheet-pic' },
      h('button', { type: 'button', class: 'sheet-open wide', title: t('Open the sample'), onclick: () => openViewer(l.image) }, picture('cast', l.image, true, t('Lettering sample')),
        l.job && !failed ? h('span', { class: 'sheet-badge busy', title: t('New sample being made') }, h('span', { class: 'spinner small' })) : null),
      h('div', { class: 'sheet-actions' }, h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove the sample (it stays in the gallery)'), 'aria-label': t('Remove the sample'),
        onclick: () => saveStyle({ lettering_image: null }) }, icon('close')))));
    if (l.job && (!l.image || failed)) sample.push(h('figure', { class: `sheet-pic pending${failed ? ' failed' : ''}` },
      h('div', { class: 'sheet-wait wide' }, failed ? icon('alert') : h('span', { class: 'spinner small' }),
        h('span', { text: failed ? (job.error || t('The sample did not come out.')) : job?.status === 'running' ? t('Making the sample…') : t('Sample queued…') })),
      failed ? h('div', { class: 'sheet-actions' },
        h('button', { type: 'button', class: 'link-btn', onclick: () => retrySheetPicture(l.job) }, t('Try again')),
        h('button', { type: 'button', class: 'link-btn', onclick: () => saveStyle({ lettering_image: l.image }) }, t('Set aside'))) : null));
    return h('div', { class: 'cast-lettering' },
      h(comicSpace() ? 'h3' : 'h4', { text: comicSpace() ? t('Comics lettering') : t('Lettering') }),
      h('p', { class: 'hint', text: comicSpace()
        ? t('The balloons, the narration, the signs and the sound effects of every page use this lettering, and the sample goes with every drawing, so the font comes out the same from one page to the next. The book\'s lettering (cover, illustrations) stays as it is.')
        : t('All text drawn in an image (the title on the cover, signs, speech balloons, sound effects) uses this lettering. The sample goes along as a reference, so the font comes out the same in all of them.') }),
      box,
      sample.length ? h('div', { class: 'sheet-pics lettering-pics' }, ...sample) : null,
      h('div', { class: 'sheet-foot' },
        h('button', { type: 'button', class: 'btn ghost small', disabled: Boolean(l.job && !failed), onclick: () => makeLetteringSample(box) }, icon('sparkle'), h('span', { text: l.image ? t('Generate another sample') : t('Generate sample') })),
        h('button', { type: 'button', class: 'btn ghost small', onclick: chooseLetteringSample }, icon('gallery'), h('span', { text: t('Sample from the gallery') }))));
  }
  async function makeLetteringSample(box) {
    const text = box.value.trim();
    if (!text) { toast(t('Describe the lettering before generating the sample.')); box.focus(); return; }
    if (text !== castOf().style.lettering.text && !(await saveStyle({ lettering: text }))) return;
    const credit = ctx.credit(writerOf());
    try {
      const { jobs, cast } = await api('story-cast', { story: st.story.id, space: castUi.space, lettering_sample: true });
      for (const job of jobs) trackJob(job);
      launchQueued(); setCast(cast); await reloadStory(); schedule(1500);
      toast(credit.image !== null ? t('Making the lettering sample (about {cost}).', { cost: formatUsd(credit.image) }) : t('Making the lettering sample.'));
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }
  function chooseLetteringSample() {
    const list = [];
    ctx.pickImages(list, 1, () => { if (list[0]?.id) saveStyle({ lettering_image: list[0].id }); }, t('Lettering sample'));
  }
  function sheetCard(c) {
    return h('article', { class: 'sheet-card', 'data-sheet': c.id },
      h('div', { class: 'sheet-head' },
        h('h4', { text: c.name }),
        h('button', { type: 'button', class: 'icon-btn tiny', title: t('Rename'), 'aria-label': t('Rename {name}', { name: c.name }), onclick: () => renameSheet(c) }, icon('pencil')),
        h('span', { class: 'grow' }),
        h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Delete the sheet (the images stay in the gallery)'), 'aria-label': t('Delete {name}\'s sheet', { name: c.name }), onclick: () => deleteSheet(c) }, icon('trash'))),
      noteBox(c),
      c.images.length ? h('div', { class: 'sheet-pics' }, ...c.images.map((im, i) => sheetPicture(c, im, i))) : h('p', { class: 'hint', text: t('No images yet.') }),
      h('div', { class: 'sheet-foot' },
        c.images.length < MAX_SHEET_PICTURES ? h('button', { type: 'button', class: 'btn ghost small', onclick: () => addSheetPictures(c) }, icon('gallery'), h('span', { text: t('From the gallery') })) : null,
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => prefillChat(comicSpace() ? t('Make an image for {name}\'s adaptation sheet, in the comics style: ', { name: c.name }) : t('Make an image for {name}\'s sheet: ', { name: c.name })) }, icon('feather'), h('span', { text: t('Ask the writer') }))));
  }
  function noteBox(c) {
    let box = castUi.notes.get(c.id);
    if (!box) {
      box = h('textarea', { class: 'sheet-note', rows: 2, maxlength: 300, 'aria-label': t('Note about {name}', { name: c.name }),
        placeholder: c.kind === 'place' ? t('What this place is like (optional)') : t('Who they are, what they look like, what changes along the story (optional)'),
        onchange: e => { const now = castOf()?.characters.find(x => x.id === c.id); if (now) castChange(now, { note: e.target.value }); } });
      castUi.notes.set(c.id, box);
    }
    if (document.activeElement !== box) box.value = c.note || '';
    return box;
  }
  function sheetPicture(c, im, i) {
    if (!im.image) {
      const job = st.pending.get(im.job), failed = job && !ACTIVE.has(job.status);
      return h('figure', { class: `sheet-pic pending${failed ? ' failed' : ''}` },
        h('div', { class: 'sheet-wait' }, failed ? icon('alert') : h('span', { class: 'spinner small' }),
          h('span', { text: failed ? (job.error || t('The image did not come out.')) : job?.status === 'running' ? t('Making…') : t('Queued…') })),
        im.caption ? h('figcaption', { text: im.caption }) : null,
        failed ? h('div', { class: 'sheet-actions' },
          h('button', { type: 'button', class: 'link-btn', onclick: () => retrySheetPicture(im.job) }, t('Try again')),
          h('button', { type: 'button', class: 'link-btn', onclick: () => castChange(c, { remove: [im.job] }) }, t('Remove', { ctx: 'take' }))) : null);
    }
    // A new version the writer asked for: the picture stays until it is ready.
    const changing = Object.values(st.story.editing || {}).some(e => e.image === im.image && !e.failed);
    return h('figure', { class: 'sheet-pic' },
      h('button', { type: 'button', class: 'sheet-open', title: t('Open the image'), onclick: () => openViewer(im.image) }, picture('cast', im.image, true, im.caption || c.name),
        i === 0 && c.images.length > 1 ? h('span', { class: 'sheet-badge', text: t('Main') }) : null,
        changing ? h('span', { class: 'sheet-badge busy', title: t('New version being made') }, h('span', { class: 'spinner small' })) : null),
      h('figcaption', {}, h('button', { type: 'button', class: 'link-btn sheet-caption', title: t('Change the caption'), onclick: () => editCaption(c, im) }, im.caption || t('No caption'))),
      h('div', { class: 'sheet-actions' },
        i > 0 ? h('button', { type: 'button', class: 'icon-btn tiny', title: t('Make it the main one (the one sent as reference when none is named)'), 'aria-label': t('Make it the main one'),
          onclick: () => castChange(c, { main: im.image }) }, icon('star')) : null,
        h('button', { type: 'button', class: 'icon-btn tiny danger', title: t('Remove from the sheet (it stays in the gallery)'), 'aria-label': t('Remove from the sheet'), onclick: () => castChange(c, { remove: [im.image] }) }, icon('close'))));
  }
  /** A change to one sheet (c names it by id); the window and the contents show it at once. */
  async function castChange(c, change) {
    try {
      const { cast } = await api('story-cast', { story: st.story.id, space: castUi.space, character: c.id, ...change });
      setCast(cast); renderCast(); renderSide();
      // The pages that waited for a sheet (or lost one) say so once the story comes back.
      if (comicSpace()) { comics.refresh(); reloadStory(); }
      return true;
    } catch (e) { toast(e.message, { tone: 'error' }); return false; }
  }
  async function saveStyle(change) {
    try { const { cast } = await api('story-cast', { story: st.story.id, space: castUi.space, style: change }); setCast(cast); renderCast(); renderSide(); if (comicSpace()) comics.refresh(); return true; }
    catch (e) { toast(e.message, { tone: 'error' }); return false; }
  }
  async function newSheet(kind) {
    const name = await askName({ title: kind === 'place' ? t('New place') : t('New character'), confirm: t('Create') });
    if (!name) return;
    const same = castOf().characters.find(c => c.name.toLocaleLowerCase(locale) === name.replace(/\s+/g, ' ').toLocaleLowerCase(locale));
    if (same) { toast(t('There is already a sheet called “{name}”.', { name: same.name })); return; }
    await castChange({ id: name }, { kind });
  }
  async function renameSheet(c) {
    const name = await askName({ title: t('Rename “{name}”', { name: c.name }), value: c.name });
    if (name && name !== c.name) castChange(c, { new_name: name });
  }
  async function deleteSheet(c) {
    const ok = await confirmAction({ title: (comicSpace() ? t('Delete the adaptation sheet of “{name}”?', { name: c.name }) : t('Delete the sheet of “{name}”?', { name: c.name })), message: comicSpace() ? t('Only the comics sheet: the book\'s stays as it is, and the images stay in the gallery. The writer finds out at the next message.') : t('The images stay in the gallery. The writer finds out at the next message.'), confirm: t('Delete'), danger: true });
    if (ok) castChange(c, { delete: true });
  }
  async function editCaption(c, im) {
    const caption = await askName({ title: t('Image caption'), value: im.caption || '' });
    if (caption !== null && caption !== im.caption) castChange(c, { captions: { [im.image]: caption } });
  }
  function addSheetPictures(c) {
    const list = [];
    ctx.pickImages(list, MAX_SHEET_PICTURES - c.images.length, () => { if (list.length) castChange(c, { add: list.map(r => ({ image: r.id })) }); }, t('Images for {name}\'s sheet', { name: c.name }));
  }
  function addStylePictures() {
    const list = [];
    ctx.pickImages(list, MAX_STYLE_PICTURES - st.story.cast.style.images.length, () => { if (list.length) saveStyle({ add: list.map(r => ({ image: r.id })) }); }, t('Style images'));
  }
  async function retrySheetPicture(jobId) {
    try {
      const { jobs, cast } = await api('story-cast', { story: st.story.id, space: castUi.space, retry: jobId });
      for (const job of jobs) trackJob(job);
      launchQueued(); setCast(cast); await reloadStory(); schedule(1500);
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }
  /** A message to the writer, written in the chat box for the person to finish and send. */
  function prefillChat(text) {
    for (const d of ['#story-cast-dialog', '#story-cover-dialog', '#comic-dialog', '#comic-reader']) if ($(d).open) $(d).close();
    st.tab = 'chat'; $('#story').dataset.tab = 'chat'; $$tabs();
    const input = $('#chat-input'); if (!input) return;
    input.value = input.value.trim() ? `${input.value.trim()}\n\n${text}` : text;
    input.focus(); input.setSelectionRange(input.value.length, input.value.length);
    input.dispatchEvent(new Event('input'));
  }

  // ---------------------------------------------------------------------------------------------------------------
  // Illustrating chapters already written: a message to the writer, with the cost in view
  // ---------------------------------------------------------------------------------------------------------------
  function openIllustrate(chapter = null) {
    const n = st.story.chapters.length; if (!n) return;
    const options = () => st.story.chapters.map((c, i) => h('option', { value: i + 1 }, `${i + 1}. ${c.title || t('Untitled')}`));
    fill($('#illustrate-from'), ...options()); fill($('#illustrate-to'), ...options());
    $('#illustrate-from').value = String(chapter === null ? 1 : chapter + 1);
    $('#illustrate-to').value = String(chapter === null ? n : chapter + 1);
    $('#illustrate-per').value = '1'; $('#illustrate-cover').checked = false; $('#illustrate-notes').value = '';
    $('#illustrate-error').textContent = '';
    illustrateCost();
    $('#illustrate-dialog').showModal();
  }
  function illustrateCount() {
    const from = Number($('#illustrate-from').value), to = Number($('#illustrate-to').value);
    // The cover is a set of three to choose from (Writer::coverStyles).
    return { from, to, images: Math.max(0, to - from + 1) * Number($('#illustrate-per').value) + ($('#illustrate-cover').checked ? 3 : 0) };
  }
  function illustrateCost() {
    const { from, to, images } = illustrateCount();
    const credit = ctx.credit(writerOf()); const el = $('#illustrate-cost');
    if (to < from) { el.textContent = t('The last chapter comes after the first.'); el.className = 'error'; return; }
    const cost = credit.image !== null ? images * credit.image : null;
    const parts = [plural(images, t('{n} image'), t('{n} images')) + (cost !== null ? t(', about {cost}', { cost: formatUsd(cost) }) : '')];
    if (credit.balance !== null) parts.push(t('estimated {provider} balance: {balance}', { provider: credit.of, balance: formatUsd(credit.balance) }));
    if (!ctx.hasKey()) parts.push(t('the OpenAI key is missing in Settings'));
    el.textContent = parts.join(' · ') + '.';
    el.className = (cost !== null && credit.balance !== null && cost > credit.balance) || images > 30 || !ctx.hasKey() ? 'error' : 'hint';
  }
  for (const s of ['#illustrate-from', '#illustrate-to', '#illustrate-per', '#illustrate-cover']) $(s).addEventListener('change', illustrateCost);
  $('#illustrate-form').onsubmit = async e => {
    e.preventDefault();
    const { from, to, images } = illustrateCount();
    const per = Number($('#illustrate-per').value), cover = $('#illustrate-cover').checked, notes = $('#illustrate-notes').value.trim();
    if (to < from) { $('#illustrate-error').textContent = t('Choose the first chapter before the last.'); return; }
    if (images > 30) { $('#illustrate-error').textContent = t('At most 30 images per request: ask in parts.'); return; }
    const which = from === to ? t('chapter {n}, already written', { n: from }) : t('chapters {from} to {to}, already written', { from, to });
    const text = (per === 1 ? t('Illustrate {chapters}: one illustration per chapter, at its most striking moment.', { chapters: which }) : t('Illustrate {chapters}: {n} illustrations per chapter, at different moments.', { chapters: which, n: per }))
      + (cover ? ` ${t('Also make the story\'s cover (3 options for me to choose from).')}` : '') + (notes ? `\n\n${notes}` : '');
    $('#illustrate-dialog').close();
    st.tab = 'chat'; $('#story').dataset.tab = 'chat'; $$tabs();
    await send(text);
  };

  // ---------------------------------------------------------------------------------------------------------------
  // The bible and the plan
  // ---------------------------------------------------------------------------------------------------------------
  // name: the book's (notes, plan) or the comic adaptation's own (comic_notes, comic_plan).
  async function openDocument(name) {
    const dialog = $('#story-doc-dialog');
    const comic = name.startsWith('comic_'), plan = name.endsWith('plan'), game = isVn();
    if (comic && !st.story.comic?.settings?.started) return;
    if (plan && !game) {
      const ok = await confirmAction(comic
        ? { title: t('See the adaptation\'s plan?'), message: t('It is the writer\'s guide for the comics: what changes from the book and why, how the chapters become pages and what comes next. It can show spoilers of the story. You can change its course by editing it; the writer follows your changes.'), confirm: t('Show anyway') }
        : { title: t('See the plan?'), message: t('The plan is the writer\'s secret guide: the whole arc, the secrets and the ending. Opening it shows spoilers. You can change its course by editing it; the writer follows your changes.'), confirm: t('Show anyway') });
      if (!ok) return;
    }
    try {
      const doc = plan ? (await api('story', undefined, { id: st.story.id, plan: '1' })).story[comic ? 'comic' : 'plan'] : comic ? st.story.comic.notes : st.story.notes;
      const text = comic && plan ? doc.plan : doc;
      $('#story-doc-title').textContent = comic ? (plan ? t('The adaptation\'s plan (spoilers)') : t('The adaptation\'s bible')) : plan ? (game ? t('Planning') : t('Plan (spoilers)')) : game ? t('Design document') : t('Story bible');
      $('#story-doc-hint').textContent = comic ? (plan
          ? t('The writer\'s guide for the comics, hidden by default: the approach, what changes from the book and why, how the story becomes pages and where it is. Change what you like: at the next message it reads your changes.')
          : t('What the comics have already established: what changed from the book, how each character looks, the places, the recurring devices and the page conventions (flashbacks, narration, thoughts). The book\'s bible stays as it is.'))
        : plan ? (game ? t('The writer\'s guide for the project: arcs, routes, secrets and endings. Change what you like: at the next message it reads your changes.') : t('The writer\'s guide, hidden by default. Change what you like: at the next message it reads your changes and goes on from them.'))
        : game ? t('The project\'s bible: premise, world, characters, routes, rules and tone. The writer checks it before writing and keeps it up to date; imported documents go here.')
        : t('What is already established in the story: characters, places, rules, timeline and style. The writer keeps this up to date and checks it before writing; you can edit it too.');
      $('#story-doc-text').value = text.text || '';
      $('#story-doc-meta').textContent = text.at ? t('Revision {rev}, {by}, {at}', { rev: text.rev, by: text.by === 'agent' ? t('by the writer') : text.by === 'mcp' ? t('by an assistant via MCP') : t('by you'), at: dateTime(text.at) }) : t('Still empty.');
      $('#story-doc-error').textContent = '';
      dialog.dataset.name = name; dialog.dataset.rev = String(text.rev || 0);
      dialog.showModal();
    } catch (e) { toast(e.message, { tone: 'error' }); }
  }
  $('#story-doc-form').onsubmit = async e => {
    e.preventDefault();
    const dialog = $('#story-doc-dialog');
    try {
      const { document } = await api('story-document', { story: st.story.id, name: dialog.dataset.name, text: $('#story-doc-text').value, rev: Number(dialog.dataset.rev) });
      dialog.close(); $('#story-doc-text').value = '';
      const name = dialog.dataset.name, meta = { words: S.wordCount(document.text), rev: document.rev, by: document.by, at: document.at };
      if (name === 'notes') st.story.notes = document;
      else if (name === 'plan') st.story.plan = { ...st.story.plan, ...meta };
      else if (st.story.comic) { st.story.comic[name === 'comic_notes' ? 'notes' : 'plan'] = name === 'comic_notes' ? { ...meta, text: document.text } : meta; comics.refresh(); }
      renderSide(); toast(t('Saved. The writer sees the change at the next message.'));
    } catch (err) { $('#story-doc-error').textContent = err.message; }
  };
  // The plan's text leaves the page when its window closes.
  $('#story-doc-dialog').addEventListener('close', () => { $('#story-doc-text').value = ''; });

  // ---------------------------------------------------------------------------------------------------------------
  // The writer's settings, the story's details
  // ---------------------------------------------------------------------------------------------------------------
  function fillModels(provider, model) {
    const names = writerModels(provider);
    $('#writer-model').replaceChildren(...Object.entries(names).map(([id, label]) => h('option', { value: id, selected: id === model }, label)));
  }
  /** The reasoning levels of a writer: each Gemini model has its own (Neon up to Extra alto, the others up to Alto). */
  function fillEfforts(provider, effort, model = $('#writer-model').value) {
    const allowed = provider === 'gemini' ? (st.writers?.gemini_efforts?.[model] || ['low', 'medium', 'high']) : null;
    const list = allowed ? S.EFFORTS.filter(([id]) => allowed.includes(id)) : S.EFFORTS;
    const chosen = list.some(([id]) => id === effort) ? effort : list[list.length - 1][0];
    $('#writer-effort').replaceChildren(...list.map(([id, label]) => h('option', { value: id, selected: id === chosen }, label)));
  }
  function chosenProvider() { return document.querySelector('#writer-provider button[aria-pressed=true]').dataset.provider; }
  function setConversationChoice(choice) {
    for (const b of document.querySelectorAll('#writer-conversation button')) b.setAttribute('aria-pressed', String(b.dataset.conversation === choice));
    $('#writer-switch-hint').textContent = choice === 'new'
      ? t('The new writer rereads the story, the bible, the plan and the latest messages, and forgets the rest of the chat. In a long conversation, it costs less.')
      : t('The new writer gets the whole conversation and goes on from it. The first message after the switch rereads everything without cache, once.');
  }
  /** The choice shows when the writer changes in a story that already has a conversation. */
  function updateSwitch() {
    const w = writerOf();
    const talked = st.messages.some(m => m.role === 'user' || m.role === 'assistant');
    $('#writer-switch').hidden = !talked || (chosenProvider() === w.provider && $('#writer-model').value === w.model);
  }
  for (const b of document.querySelectorAll('#writer-conversation button')) b.onclick = () => setConversationChoice(b.dataset.conversation);
  function setIllustrateChoice(mode) {
    for (const b of document.querySelectorAll('#writer-illustrate button')) b.setAttribute('aria-pressed', String(b.dataset.illustrate === mode));
    $('#writer-illustrate-hint').textContent = S.ILLUSTRATE.find(([id]) => id === mode)[2];
    // Text only: no picture the person didn't ask for, the cover and the sheets included.
    for (const id of ['#writer-cover', '#writer-sheets']) $(id).disabled = mode === 'text';
  }
  function illustrateChoice() { return document.querySelector('#writer-illustrate button[aria-pressed=true]')?.dataset.illustrate || 'ask'; }
  for (const b of document.querySelectorAll('#writer-illustrate button')) b.onclick = () => setIllustrateChoice(b.dataset.illustrate);
  /** Saves only how the writer illustrates (from the start of a new story). */
  async function saveIllustrate(mode) {
    const writer = { ...writerOf(), illustrate: mode };
    try {
      const { story } = await api('story-update', { id: st.story.id, writer });
      st.story.writer = story.writer; savePrefs({ storyWriter: story.writer }); ctx.inView?.();
    } catch (err) { toast(err.message, { tone: 'error' }); }
    renderChat();
  }
  $('#writer-model').addEventListener('change', () => { fillEfforts(chosenProvider(), $('#writer-effort').value); updateSwitch(); });
  function openWriter() {
    const w = writerOf();
    for (const b of document.querySelectorAll('#writer-provider button')) b.setAttribute('aria-pressed', String(b.dataset.provider === w.provider));
    fillModels(w.provider, w.model); setConversationChoice('same'); updateSwitch();
    fillEfforts(w.provider, w.effort, w.model);
    $('#writer-chapter-words').value = w.chapter_words;
    setIllustrateChoice(S.illustrateMode(w.illustrate));
    $('#writer-image-model').replaceChildren(...Object.entries(models()).map(([id, m]) => h('option', { value: id, selected: id === w.image_model }, m.label)));
    $('#writer-image-quality').value = w.image_quality;
    $('#writer-cover').checked = w.cover !== 'ask'; $('#writer-sheets').checked = w.sheets !== 'ask';
    $('#writer-image-scale').value = String(w.image_scale || '1');
    updateSizeHint();
    $('#writer-error').textContent = '';
    updateKeyHint(w.provider);
    $('#writer-dialog').showModal();
  }
  /** The sizes the pictures come out at with the model and size chosen, and what one costs. */
  function updateSizeHint() {
    const w = { image_model: $('#writer-image-model').value, image_quality: $('#writer-image-quality').value, image_scale: $('#writer-image-scale').value };
    const flex = models()[w.image_model]?.flex;
    const sizes = [['3:2', 'illustrations'], ['2:3', 'covers']].map(([aspect, what]) => [ctx.imageSize(w, aspect), what, ctx.credit(w, aspect).image]).filter(([size]) => size);
    const gemini = models()[w.image_model]?.provider === 'gemini';
    const size = ([size, what, cost]) => {
      const vars = { size: size.replace('x', ' × '), cost: cost !== null ? formatUsd(cost) : '' };
      return what === 'covers' ? (cost !== null ? t('Covers {size} (about {cost})', vars) : t('Covers {size}', vars)) : cost !== null ? t('Illustrations {size} (about {cost})', vars) : t('Illustrations {size}', vars);
    };
    const hint = [gemini && !ctx.hasGeminiKey() ? t('The Gemini key is missing to generate with Nano Banana: add it in Settings.') : '',
      gemini ? t('Paid with the Gemini key, outside the OpenAI balance.') : '',
      sizes.length && flex === false && w.image_scale !== '1' ? t('This model only makes the standard size.') : '',
      sizes.length ? `${sizes.map(size).join(' · ')}. ${t('The sheets come out in the format the writer chooses, at the same size.')}` : ''].filter(Boolean);
    $('#writer-image-size-hint').textContent = hint.join(' ');
  }
  for (const s of ['#writer-image-model', '#writer-image-quality', '#writer-image-scale']) $(s).addEventListener('change', updateSizeHint);
  function updateKeyHint(provider) {
    const missing = provider === 'claude' ? !ctx.hasClaudeKey() : provider === 'gemini' ? !ctx.hasGeminiKey() : !ctx.hasKey();
    $('#writer-key-hint').hidden = !missing;
    $('#writer-key-hint').textContent = { claude: t('The Claude API key is missing: add it in Settings.'), gemini: t('The Gemini API key is missing: add it in Settings.') }[provider]
      || t('The OpenAI API key is missing: add it in Settings.');
  }
  for (const b of document.querySelectorAll('#writer-provider button')) b.onclick = () => {
    for (const x of document.querySelectorAll('#writer-provider button')) x.setAttribute('aria-pressed', String(x === b));
    fillModels(b.dataset.provider, null); fillEfforts(b.dataset.provider, $('#writer-effort').value); updateKeyHint(b.dataset.provider); updateSwitch();
  };
  $('#writer-form').onsubmit = async e => {
    e.preventDefault();
    const fresh = !$('#writer-switch').hidden && document.querySelector('#writer-conversation button[aria-pressed=true]')?.dataset.conversation === 'new';
    const writer = { provider: chosenProvider(), model: $('#writer-model').value, effort: $('#writer-effort').value,
      chapter_words: Number($('#writer-chapter-words').value), illustrate: illustrateChoice(), image_model: $('#writer-image-model').value, image_quality: $('#writer-image-quality').value,
      cover: $('#writer-cover').checked ? 'auto' : 'ask', sheets: $('#writer-sheets').checked ? 'auto' : 'ask', image_scale: $('#writer-image-scale').value };
    try {
      const { story } = await api('story-update', { id: st.story.id, writer });
      st.story.writer = story.writer; savePrefs({ storyWriter: story.writer }); ctx.inView?.();
      if (fresh) { await api('story-new-conversation', { story: st.story.id }); addLocalNotice(t('New conversation. The writer rereads the story at the next message.')); }
      $('#writer-dialog').close(); renderChatHead(); scheduleWarm(null);
    } catch (err) { $('#writer-error').textContent = err.message; }
  };

  function openDetails() {
    const s = st.story;
    $('#story-title-input').value = s.title; $('#story-series-input').value = s.series; $('#story-synopsis-input').value = s.synopsis;
    $('#story-details-error').textContent = '';
    showDetailsCover(s);
    $('#story-details-dialog').showModal();
  }
  /**
   * The cover in a column at the window's side, whole: the box takes the cover's shape (coverShape: the size the server
   * sends, checked against the picture once it loads), so nothing is cut and no bar is left around it. The thumbnail
   * shows until the full picture has loaded over it. Kept from one opening to the next while the cover is the same: the
   * server sends images with no-store, so a new picture would load again. A click shows it on the whole screen.
   */
  function showDetailsCover(s) {
    const box = $('#story-details-cover'), cover = s.cover;
    $('#story-details-dialog').classList.toggle('with-cover', Boolean(cover));
    box.hidden = !cover;
    if (!cover) { box.replaceChildren(); delete box.dataset.cover; return; }
    if (box.dataset.cover === cover) return;
    box.dataset.cover = cover;
    box.style.setProperty('--shape', coverShape(s).toFixed(4));
    const fit = img => { if (box.dataset.cover === cover && img.naturalWidth > 0 && img.naturalHeight > 0) box.style.setProperty('--shape', coverShape({ cover, cover_size: [img.naturalWidth, img.naturalHeight] }).toFixed(4)); };
    const thumb = h('img', { class: 'details-cover-thumb', alt: '', src: imageUrl(cover, true) });
    const full = h('img', { class: 'details-cover-full', alt: t('Cover'), src: imageUrl(cover) });
    thumb.onload = () => { if (!full.classList.contains('loaded')) fit(thumb); };
    full.onload = () => { fit(full); full.classList.add('loaded'); };
    box.replaceChildren(thumb, full, h('span', { class: 'details-cover-zoom', 'aria-hidden': 'true' }, icon('fullscreen')));
  }
  $('#story-details-cover').onclick = () => { const id = $('#story-details-cover').dataset.cover; if (id) openViewer(id, { immersive: true }); };
  $('#story-details-form').onsubmit = async e => {
    e.preventDefault();
    try {
      const { story } = await api('story-update', { id: st.story.id, title: $('#story-title-input').value, series: $('#story-series-input').value, synopsis: $('#story-synopsis-input').value });
      Object.assign(st.story, { title: story.title, series: story.series, synopsis: story.synopsis });
      $('#story-details-dialog').close(); renderSide(); renderDoc();
    } catch (err) { $('#story-details-error').textContent = err.message; }
  };

  async function deleteStory() {
    const s = st.story;
    const ok = await confirmAction({ title: t('Delete “{title}”?', { title: s.title || t('Untitled') }), confirm: t('Delete'), danger: true,
      message: isVn(s) ? t('The scenes, the tree, the variables, the routes, the gameplay notes, the chapters, the design document and the conversation with the writer are deleted for good. The sprites, backgrounds and CGs stay in the gallery.')
        : t('The chapters, the bible, the plan and the conversation with the writer are deleted for good. The illustrations stay in the gallery.') });
    if (!ok) return;
    try { await api('story-delete', { id: s.id }); st.list = st.list.filter(x => x.id !== s.id); toLeave(); toast(isVn(s) ? t('Project deleted.') : t('Story deleted.')); }
    catch (e) { toast(e.message, { tone: 'error' }); }
  }

  // ---------------------------------------------------------------------------------------------------------------
  // Downloads
  // ---------------------------------------------------------------------------------------------------------------
  function exportMenu() {
    const game = isVn();
    $('#story-export-title').textContent = game ? t('Download the project') : t('Download the story');
    for (const el of document.querySelectorAll('#story-export-dialog [data-vn]')) el.hidden = !game;
    for (const el of document.querySelectorAll('#story-export-dialog [data-story]')) el.hidden = game;
    $('#story-export-dialog').showModal();
  }
  // The game for Ren'Py (vn.js): the scripts, the characters, the variables and the pictures, in a zip.
  $('#export-renpy').onclick = async () => {
    const button = $('#export-renpy'), status = $('#export-pack-status');
    if (!st.story || button.disabled) return;
    const say = text => { status.textContent = text; status.hidden = !text; };
    button.disabled = true;
    try { await vn.exportRenpy(say); $('#story-export-dialog').close(); }
    catch (e) { say(''); toast(e.message || t('Could not build the zip.'), { tone: 'error' }); }
    finally { button.disabled = false; }
  };
  function download(name, type, content) {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const a = h('a', { href: url, download: name }); document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
  }
  $('#export-md').onclick = () => { download(S.fileName(st.story.title, 'md'), 'text/markdown;charset=utf-8', S.storyMarkdown(st.story)); $('#story-export-dialog').close(); };
  $('#export-html').onclick = async () => {
    const button = $('#export-html'); button.disabled = true;
    try {
      const ids = new Set(st.story.cover ? [st.story.cover] : []);
      for (const c of st.story.chapters) for (const b of S.parseBlocks(c.text)) if (b.type === 'figure' && b.kind === 'img') ids.add(b.id);
      const images = new Map();
      for (const id of ids) {
        try {
          const blob = await (await fetch(imageUrl(id), { credentials: 'same-origin' })).blob();
          images.set(id, await new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(r.result); r.onerror = reject; r.readAsDataURL(blob); }));
        } catch { /* a missing image shows its caption */ }
      }
      download(S.fileName(st.story.title, 'html'), 'text/html;charset=utf-8', S.storyHtml(st.story, images));
      $('#story-export-dialog').close();
    } catch (e) { toast(e.message || t('Could not build the file.'), { tone: 'error' }); }
    finally { button.disabled = false; }
  };

  // The story as an EPUB book (story.js), put together here like the zip. Pictures go in as JPEG (PNG and WebP are
  // redrawn on white, big ones brought down to 2560 px on the longest side), which every e-reader shows and keeps the
  // book light.
  $('#export-epub').onclick = async () => {
    const button = $('#export-epub'), status = $('#export-pack-status'), s = st.story;
    if (!s || button.disabled) return;
    const say = text => { status.textContent = text; status.hidden = !text; };
    button.disabled = true;
    try {
      const ids = new Set(s.cover ? [s.cover] : []);
      for (const c of s.chapters) for (const b of S.parseBlocks(c.text)) if (b.type === 'figure' && b.kind === 'img') ids.add(b.id);
      const pictures = new Map();
      let missing = 0;
      for (const [i, id] of [...ids].entries()) {
        say(t('Downloading the images… {n} of {total}', { n: i + 1, total: ids.size }));
        try {
          const response = await fetch(imageUrl(id), { credentials: 'same-origin' });
          if (!response.ok) throw new Error(String(response.status));
          pictures.set(id, await bookPicture(await response.blob()));
        } catch { missing++; }
      }
      say(t('Building the book…'));
      const { files } = S.storyEpub(s, pictures);
      download(S.fileName(s.title, 'epub'), 'application/epub+zip', ctx.zip(files));
      say('');
      $('#story-export-dialog').close();
      if (missing) toast(plural(missing, t('{n} image did not come from the server and was left out of the book.'), t('{n} images did not come from the server and were left out of the book.')), { tone: 'error' });
    } catch (e) { say(''); toast(e.message || t('Could not build the book.'), { tone: 'error' }); }
    finally { button.disabled = false; }
  };
  /** A picture for the book: a JPEG no bigger than 2560 px on its longest side, with its size. */
  async function bookPicture(blob) {
    const MAX = 2560, bitmap = await createImageBitmap(blob);
    try {
      const { width, height } = bitmap, scale = Math.min(1, MAX / Math.max(width, height));
      if (blob.type === 'image/jpeg' && scale === 1) return { bytes: new Uint8Array(await blob.arrayBuffer()), type: 'image/jpeg', width, height };
      const w = Math.max(1, Math.round(width * scale)), hh = Math.max(1, Math.round(height * scale));
      const canvas = h('canvas', { width: w, height: hh }), g = canvas.getContext('2d');
      g.fillStyle = '#fff'; g.fillRect(0, 0, w, hh); g.drawImage(bitmap, 0, 0, w, hh);
      const jpeg = await new Promise((resolve, reject) => canvas.toBlob(b => b ? resolve(b) : reject(new Error(t('The image could not be converted.'))), 'image/jpeg', 0.9));
      return { bytes: new Uint8Array(await jpeg.arrayBuffer()), type: 'image/jpeg', width: w, height: hh };
    } finally { bitmap.close(); }
  }

  // The whole story in a zip (pack.js): what the server keeps of it, its conversation a page at a time and its pictures,
  // put together here, so no request runs into the hosting's time limit.
  $('#export-pack').onclick = () => exportPack();
  async function exportPack() {
    const button = $('#export-pack'), status = $('#export-pack-status'), s = st.story;
    if (!s || button.disabled) return;
    const say = text => { status.textContent = text; status.hidden = !text; };
    button.disabled = true;
    try {
      say(t('Gathering the story…'));
      const { pack } = await api('story-export', undefined, { id: s.id });
      if (pack.conversation) {
        const total = pack.conversation.messages, messages = [];
        for (let after = 0; ;) {
          const page = await api('story-export-messages', undefined, { id: s.id, after: String(after) });
          messages.push(...page.messages);
          say(t('Gathering the conversation with the writer… {n} of {total} messages', { n: Math.min(messages.length, total), total }));
          if (page.next === null) break;
          after = page.next;
        }
        pack.conversation.messages = messages;
      }
      const ids = Object.keys(pack.images || {}), pictures = new Map();
      let missing = 0;
      for (const [i, id] of ids.entries()) {
        say(t('Downloading the images… {n} of {total}', { n: i + 1, total: ids.length }));
        try {
          const response = await fetch(imageUrl(id), { credentials: 'same-origin' });
          if (!response.ok) throw new Error(String(response.status));
          const blob = await response.blob();
          pictures.set(id, { bytes: new Uint8Array(await blob.arrayBuffer()), type: blob.type });
        } catch { missing++; }
      }
      say(t('Building the zip…'));
      download(S.fileName(s.title, 'zip'), 'application/zip', ctx.zip(P.packFiles(pack, pictures)));
      say('');
      $('#story-export-dialog').close();
      if (missing) toast(plural(missing, t('{n} image did not come from the server and was left out of the zip.'), t('{n} images did not come from the server and were left out of the zip.')), { tone: 'error' });
    } catch (e) { say(''); toast(e.message || t('Could not build the zip.'), { tone: 'error' }); }
    finally { button.disabled = false; }
  }

  // A story from such a zip, as a new one: the pictures go to the gallery first (the ones the account already has stay
  // as they are), then the story with their ids, then its conversation a page at a time.
  $('#story-import-input').onchange = e => { const file = e.target.files?.[0]; e.target.value = ''; if (file) importPack(file); };
  async function importPack(file) {
    const label = $('#story-import');
    if (label.classList.contains('busy')) return;
    label.classList.add('busy'); label.setAttribute('aria-busy', 'true');
    const say = text => toast(text, { duration: 120000 });
    try {
      say(t('Opening the zip…'));
      const { pack, conversation, pictures } = P.readPack(await P.unzip(file), file.name);
      const olds = [...new Set([...pictures.values()].map(p => p.old).filter(Boolean))];
      const have = new Set(olds.length ? (await api('story-import-images', { ids: olds })).have : []);
      const ids = new Map(), images = {};
      let failed = 0, n = 0;
      for (const [path, p] of pictures) {
        n++;
        if (p.old && have.has(p.old)) { ids.set(path, p.old); continue; }
        say(t('Sending the images to the gallery… {n} of {total}', { n, total: pictures.size }));
        try {
          const blob = await ctx.importableImage(new Blob([p.bytes], { type: p.type }));
          const { image } = await api('import', { data: await ctx.blobData(blob), name: p.title });
          ids.set(path, image.id);
          if (p.old) images[p.old] = image.id;
        } catch { failed++; }
      }
      say(t('Creating the story…'));
      P.withIds(pack, ids);
      const talk = conversation && Array.isArray(conversation.messages) && conversation.messages.length ? conversation : null;
      const { story, dropped, batch } = await api('story-import', { pack, images, pictures: ids.size > 0,
        ...(talk ? { conversation: { current: talk.current, turns: talk.turns, seen: talk.seen, inbox: talk.inbox } } : {}) });
      st.list = [story, ...st.list.filter(x => x.id !== story.id)];
      if (!st.story) renderList();
      // The last page says the conversation is whole; until then the story has a new one, so a stop halfway loses
      // only the earlier messages, never the story.
      let lost = '';
      if (talk) {
        const pages = P.messagePages(talk.messages, batch);
        let sent = 0;
        try {
          for (const [i, page] of pages.entries()) {
            say(t('Bringing in the conversation with the writer… {n} of {total} messages', { n: sent, total: talk.messages.length }));
            await api('story-import-messages', { story: story.id, messages: page, done: i === pages.length - 1 });
            sent += page.length;
          }
        } catch (e) { lost = e.message; }
      }
      const problems = [
        failed + dropped ? plural(failed + dropped, t('{n} image left out'), t('{n} images left out')) : '',
        lost ? t('the conversation with the writer did not come whole ({reason}); it starts a new one by rereading the story', { reason: lost }) : '',
      ].filter(Boolean);
      const done = story.kind === 'vn' ? t('Visual novel imported: “{title}”.', { title: story.title }) : t('Story imported: “{title}”.', { title: story.title });
      toast(problems.length ? `${done} ${t('But {problems}.', { problems: problems.join(t(', and ')) })}` : done,
        { action: { label: t('Open'), run: () => openStory(story.id) }, ...(problems.length ? { tone: 'error', duration: 15000 } : {}) });
    } catch (e) { toast(e.message || t('Could not import the zip.'), { tone: 'error' }); }
    finally { label.classList.remove('busy'); label.removeAttribute('aria-busy'); }
  }

  // Ctrl+Enter sends from anywhere in the story page. In the reader, its keys; T opens it.
  function key(e) {
    if (isVn()) {
      if (vn.key(e)) return true;
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !document.querySelector('dialog[open]') && !e.target.closest?.('.vn-root')) { e.preventDefault(); send(); return true; }
      if ((e.ctrlKey || e.metaKey) && e.key === 's' && st.editing) { e.preventDefault(); saveEdit(); return true; }
      return false;
    }
    if (reader.key(e)) return true;
    if (reader.on) return false;
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && st.story && !st.editing && !document.querySelector('dialog[open]')) { e.preventDefault(); send(); return true; }
    const typing = e.target instanceof HTMLElement && (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable);
    if ((e.key === 't' || e.key === 'T') && !typing && !e.ctrlKey && !e.metaKey && !e.altKey && st.story?.chapters[st.chapter] && !st.editing && !document.querySelector('dialog[open]')) { e.preventDefault(); enterReader(); return true; }
    if ((e.ctrlKey || e.metaKey) && e.key === 's' && st.editing) { e.preventDefault(); saveEdit(); return true; }
    return false;
  }

  /** The address of what the section shows: the story open, or the list. */
  function address() { return st.story ? hashOf(st.story.id) : homeOf().hash; }
  /** From the history: opens a story (the section is already showing), and with text writes it in the chat box to send again. */
  async function openFromHistory(id, text = null) {
    if (st.story?.id !== id) await openStory(id);
    if (st.story?.id !== id) return;
    if (reader.on) exitReader();
    if (text !== null) prefillChat(text);
  }

  return { show, hide, reset, setWriters, setReaderPrefs: saved => reader.setPrefs(saved), setStoryPrefs, key, route, address, openFromHistory, get open() { return st.open; }, get kind() { return st.kind; },
    // The story open, and its writer's settings (in the list, those a new story starts with): the balances at the top follow them.
    get story() { return st.open ? st.story : null; }, get writer() { return !st.open ? null : st.story ? writerOf() : newWriter(); } };
}
