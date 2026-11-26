import { t, number } from './i18n.js';
// Videos and pictures made on a GPU worker (Gpu.php, gpu.php, colab/): the window that asks for a video, the state of the
// GPU (header chip, Settings › GPU), the account's LoRAs for the pictures and how a GPU request reads in the feed. The
// worker is a notebook in Google Colab that takes the requests from the server; this page only talks to the server.

/** "16:9" → 16 / 9. */
export function aspectRatio(aspect) {
  const [w, h] = String(aspect || '16:9').split(':').map(Number);
  return w > 0 && h > 0 ? w / h : 16 / 9;
}

/** What a GPU request in the feed (a video or a picture) is doing, from its phase and the steps the worker reported. */
export function videoPhase(job, worker) {
  if (job.status === 'cancelling') return t('Cancelling…');
  if (job.status === 'queued') return worker ? t('Waiting its turn on the GPU') : t('Waiting for the GPU to turn on');
  const picture = job.kind === 'image';
  const p = job.progress || {}, steps = p.total > 0 ? { done: number(p.done || 0), total: number(p.total) } : null;
  switch (job.phase) {
    case 'claimed': return t('Starting on the GPU');
    case 'download': return steps && p.total > 1 ? t('Downloading the model: {done} of {total} MB', steps) : t('Downloading the model');
    case 'preparing': return picture ? t('Preparing the pictures') : t('Preparing the videos');
    case 'planning': return t('Planning the prompt (MiniMax\'s expander)');
    case 'loading': return t('Loading the model');
    case 'encoding': return picture ? t('Reading the prompt and the pictures') : t('Reading the prompt');
    case 'generating': {
      if (!steps) return picture ? t('Generating the picture') : t('Generating the video');
      const left = p.eta > 0 ? t('about {time} left', { time: p.eta >= 90 ? t('{n} min', { n: number(Math.round(p.eta / 60)) }) : t('{n} s', { n: number(p.eta) }) }) : '';
      return [t('Generating: step {done} of {total}', steps), left].filter(Boolean).join(' · ');
    }
    case 'decoding': return picture ? t('Decoding the picture') : t('Decoding the picture and the sound');
    case 'saving': case 'finishing': return picture ? t('Finishing the picture') : t('Saving the video');
    case 'uploading': return p.total > 1 ? t('Sending to the library: {percent}%', { percent: number(Math.floor(100 * (p.done || 0) / p.total)) }) : t('Sending to the library');
    default: return picture ? t('Making the picture') : t('Making the video');
  }
}

export function createGpu(ctx) {
  const { api, h, icon, toast, confirmAction, trackJob, newId, $, $$, dateTime, pickImages, pickVideos, showSettings, imageUrl, activeJobs, formatUsd } = ctx;
  const gpu = { status: null, fetched: 0, timer: null, busy: false };
  const dialog = $('#video-dialog');

  // The state of the GPU ---------------------------------------------------------------------------------------------

  const engine = () => {
    const engines = gpu.status?.engines || {}; return engines.h3 || Object.values(engines)[0] || null;
  };
  const worker = () => gpu.status?.worker || null;
  function phaseLine(w) {
    if (!w) return '';
    if (w.phase === 'download') return w.progress?.total ? t('Downloading the model: {done} of {total} GB', { done: number(Math.round((w.progress.done || 0) / 102.4) / 10), total: number(Math.round(w.progress.total / 102.4) / 10) }) : t('Downloading the model');
    if (w.phase === 'setup') return t('Getting ready: {message}', { message: w.message || '…' });
    if (w.phase === 'busy') return w.making === 'picture' ? t('Making a picture') : t('Making a video');
    return t('Ready for requests');
  }
  function gpuName(w) { return [w.gpu, w.vram ? `${number(Math.round(w.vram / 1024))} GB` : ''].filter(Boolean).join(' · '); }
  /** One line for the chip, the window and the settings: off, getting ready, ready, busy, and when it turns off. */
  function summary() {
    const w = worker(), s = gpu.status;
    if (!s) return { on: false, text: t('Checking the GPU…') };
    // The last notebook stopped at once because Colab gave it a smaller GPU than the A100 or G4 the models need.
    if (!w && s.last?.why === 'gpu') return { on: false, wrong: true, text: t('The notebook turned itself off: Colab gave it a GPU too small for the models{gpu}. In Colab choose Runtime › Change runtime type › A100 or G4 and run it again; if it keeps giving another GPU, those are busy, so try the other one or again later.', { gpu: gpuName(s.last) ? ` (${gpuName(s.last)})` : '' }) };
    if (!w) return { on: false, text: s.token ? t('The GPU is off. Requests wait until you turn it on in Colab.') : t('The GPU is not set up yet. Open Settings › GPU.') };
    const off = w.phase === 'ready' && w.off_at ? t('turns off at {time} without requests', { time: new Date(w.off_at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) : '';
    return { on: true, text: [gpuName(w), phaseLine(w), w.stopping ? t('turning off') : off].filter(Boolean).join(' · ') };
  }

  /** Asks the server how the GPU is. A call while another one is on its way waits for that one's answer. */
  async function refresh({ quiet = true } = {}) {
    if (gpu.busy) { await gpu.busy; if (gpu.status || quiet) return gpu.status; }
    gpu.busy = (async () => {
      try { gpu.status = await api('gpu'); gpu.fetched = Date.now(); render(); }
      catch (err) { if (!quiet) toast(err.message, { tone: 'error' }); }
    })();
    try { await gpu.busy; } finally { gpu.busy = null; }
    return gpu.status;
  }
  /** Asks again every few seconds while something on screen shows the GPU, or while GPU requests wait or run. */
  function schedule() {
    clearTimeout(gpu.timer);
    const watching = dialog.open || !$('#settings-gpu').hidden && $('#settings-dialog').open;
    const videos = activeJobs().some(j => j.runner === 'gpu');
    const delay = watching ? 4000 : videos || worker() ? 15000 : 0;
    if (!delay) return;
    gpu.timer = setTimeout(async () => { if (!document.hidden) await refresh(); schedule(); }, delay);
  }

  function render() {
    const w = worker(), s = summary();
    const chip = $('#gpu-chip');
    chip.hidden = !w && !activeJobs().some(j => j.runner === 'gpu');
    chip.classList.toggle('on', !!w); chip.classList.toggle('busy', w?.phase === 'busy' || w?.phase === 'download' || w?.phase === 'setup');
    $('#gpu-chip-text').textContent = !w ? t('off') : w.phase === 'busy' ? (w.making === 'picture' ? t('making a picture') : t('making a video')) : w.phase === 'ready' ? t('on') : t('getting ready');
    chip.title = s.text;
    if (dialog.open) renderDialogGpu();
    if (!$('#settings-gpu').hidden) renderPane();
    ctx.changed?.();
  }

  // Settings › GPU ------------------------------------------------------------------------------------------------------

  function renderPane() {
    const status = gpu.status; if (!status) return;
    const w = worker(), s = summary(), card = $('#gpu-card');
    card.classList.toggle('on', !!w);
    $('#gpu-state').textContent = w ? t('On: {gpu}', { gpu: gpuName(w) || 'GPU' }) : t('Off');
    const lines = w ? [phaseLine(w)] : [s.text];
    if (w?.phase === 'ready' && w.off_at) lines.push(t('It turns off by itself at {time} if no request comes.', { time: new Date(w.off_at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }));
    if (w?.stopping) lines.push(t('Turning off: it finishes what it is making first.'));
    if (status.queued || status.running) lines.push(t('{queued} waiting, {running} being made.', { queued: number(status.queued), running: number(status.running) }));
    if (w?.started) lines.push(t('On since {time}.', { time: dateTime(w.started) }));
    $('#gpu-detail').textContent = lines.filter(Boolean).join(' ');
    $('#gpu-detail').classList.toggle('warn', !!s.wrong);
    $('#gpu-stop').hidden = !w || !!w.stopping;
    $('#gpu-notebook').href = status.notebook;
    const token = status.token;
    $('#gpu-token-status').textContent = token ? t('Active token, made on {created}. {used}', { created: dateTime(token.created), used: token.used ? t('Last used on {used}.', { used: dateTime(token.used) }) : t('Not used yet.') }) : t('No token yet: make one to connect the notebook.');
    $('#gpu-revoke').hidden = !token;
    $('#gpu-token').textContent = token ? t('Make a new token') : t('Make a token');
    const idle = $('#gpu-idle');
    if (idle.options.length !== status.idle_choices.length) idle.replaceChildren(...status.idle_choices.map(m => h('option', { value: String(m) }, t('{n} minutes without requests', { n: number(m) }))));
    if (document.activeElement !== idle) idle.value = String(status.idle_minutes);
    const pictures = status.pictures || { jpeg: false, quality: 85 }, quality = $('#gpu-quality');
    $('#gpu-jpeg').checked = !!pictures.jpeg; $('#gpu-quality-wrap').hidden = !pictures.jpeg;
    if (pictures.range) { quality.min = String(pictures.range[0]); quality.max = String(pictures.range[1]); }
    if (document.activeElement !== quality) { quality.value = String(pictures.quality); $('#gpu-quality-value').textContent = number(pictures.quality); }
    $('#gpu-license').textContent = [engine()?.license, ...Object.values(status.image_engines || {}).map(e => e.license)].filter(Boolean).join(' ');
    renderLoras();
  }
  function tokenRows(url, token) {
    const row = (label, value) => {
      const input = h('input', { readonly: true, value, spellcheck: 'false', autocomplete: 'off' });
      input.addEventListener('focus', () => input.select());
      const copy = h('button', { type: 'button', class: 'icon-btn small', title: t('Copy'), 'aria-label': t('Copy: {label}', { label }), onclick: async () => {
        try { await navigator.clipboard.writeText(value); toast(t('Copied.')); } catch { input.focus(); input.select(); }
      } }, icon('copy'));
      return h('label', {}, label, h('span', { class: 'copy-row' }, input, copy));
    };
    return [h('p', { class: 'hint attention' }, t('Copy it now: the token is not shown again. Whoever has it can read this account\'s GPU requests and add videos and pictures to its library.')),
      row('ATELIER_URL', url), row('ATELIER_TOKEN', token)];
  }
  $('#gpu-token').onclick = async () => {
    if (gpu.status?.token && !await confirmAction({ title: t('Make a new token?'), message: t('The current token stops working: a notebook that is on turns off. Put the new one in Colab\'s Secrets.'), confirm: t('Make new') })) return;
    try {
      const result = await api('gpu-token', {});
      gpu.status = result; render();
      $('#gpu-token-output').replaceChildren(...tokenRows(result.url, result.token)); $('#gpu-token-output').hidden = false;
    } catch (err) { toast(err.message, { tone: 'error' }); }
  };
  $('#gpu-revoke').onclick = async () => {
    if (!await confirmAction({ title: t('Revoke the worker token?'), message: t('The notebook loses access to this account and turns off.'), confirm: t('Revoke'), danger: true })) return;
    try { gpu.status = await api('gpu-revoke', {}); render(); $('#gpu-token-output').replaceChildren(); $('#gpu-token-output').hidden = true; toast(t('Worker token revoked.')); }
    catch (err) { toast(err.message, { tone: 'error' }); }
  };
  $('#gpu-stop').onclick = stop;
  $('#gpu-idle').onchange = async e => {
    try { gpu.status = await api('gpu-idle', { minutes: Number(e.target.value) }); render(); toast(t('Saved.')); }
    catch (err) { toast(err.message, { tone: 'error' }); }
  };
  // How the notebook saves the pictures: as JPEG at this quality instead of PNG, or as each request asks.
  async function savePictures() {
    try { gpu.status = await api('gpu-pictures', { jpeg: $('#gpu-jpeg').checked, quality: Number($('#gpu-quality').value) }); render(); toast(t('Saved.')); }
    catch (err) { toast(err.message, { tone: 'error' }); render(); }
  }
  $('#gpu-jpeg').onchange = savePictures;
  $('#gpu-quality').oninput = e => { $('#gpu-quality-value').textContent = number(Number(e.target.value)); };
  $('#gpu-quality').onchange = savePictures;
  $('#gpu-chip').onclick = () => showSettings('gpu');
  $('#settings-dialog').addEventListener('close', () => { $('#gpu-token-output').replaceChildren(); $('#gpu-token-output').hidden = true; });
  async function stop() {
    if (!await confirmAction({ title: t('Turn the GPU off now?'), message: t('The runtime turns off after what it is making, if anything. Requests that wait stay in line for the next time.'), confirm: t('Turn off') })) return;
    try { gpu.status = await api('gpu-stop', {}); render(); toast(t('The GPU turns off in a few seconds.')); }
    catch (err) { toast(err.message, { tone: 'error' }); }
  }

  // LoRAs for the pictures (Settings › GPU) ------------------------------------------------------------------------------
  // The account's list: a link to the .safetensors file (Hugging Face or Civitai), a name, the words that call it up and
  // the strength it starts at. The notebook downloads each one the first time a request uses it in a session.

  const loras = { list: null, loading: null, editing: null };
  /** The account's LoRAs, asked once (and again after a change). */
  async function loadLoras(force = false) {
    if (loras.list && !force) return loras.list;
    if (!loras.loading) loras.loading = api('gpu-loras').then(r => { loras.list = r.loras || []; return loras.list; }).finally(() => { loras.loading = null; });
    const list = await loras.loading;
    renderLoras(); ctx.lorasChanged?.();
    return list;
  }
  const strengthText = value => number(Math.round(Number(value) * 100) / 100);
  function renderLoras() {
    const list = $('#lora-list'); if (!list) return;
    if (!loras.list) { list.replaceChildren(); return; }
    list.replaceChildren(...(loras.list.length ? loras.list.map(l => h('li', { class: 'lora-row' },
      h('div', { class: 'lora-text' }, h('strong', {}, l.name), h('span', { class: 'hint' }, [l.trigger ? t('Words: {words}', { words: l.trigger }) : t('No trigger words'), t('strength {n}', { n: strengthText(l.strength) })].join(' · ')),
        h('a', { class: 'hint lora-link', href: l.url, target: '_blank', rel: 'noopener noreferrer' }, l.url.replace(/^https:\/\//, ''))),
      h('div', { class: 'lora-actions' },
        h('button', { type: 'button', class: 'btn ghost small', onclick: () => editLora(l) }, t('Edit', { ctx: 'action' })),
        h('button', { type: 'button', class: 'icon-btn small', 'aria-label': t('Remove {label}', { label: l.name }), title: t('Remove'), onclick: () => removeLora(l) }, icon('trash')))))
      : [h('li', { class: 'hint' }, t('No LoRAs yet.'))]));
  }
  function editLora(l) {
    loras.editing = l?.id || null;
    $('#lora-url').value = l?.url || ''; $('#lora-name').value = l?.name || ''; $('#lora-trigger').value = l?.trigger || '';
    $('#lora-strength').value = String(l?.strength ?? 1); $('#lora-strength-value').textContent = strengthText(l?.strength ?? 1);
    $('#lora-save').textContent = l ? t('Save LoRA') : t('Add LoRA'); $('#lora-cancel').hidden = !l; $('#lora-error').textContent = '';
    if (l) $('#lora-url').focus();
  }
  async function removeLora(l) {
    if (!await confirmAction({ title: t('Remove this LoRA?'), message: t('{name} leaves the list. Pictures already made keep it in their details.', { name: l.name }), confirm: t('Remove'), danger: true })) return;
    try { loras.list = (await api('gpu-lora-delete', { id: l.id })).loras || []; if (loras.editing === l.id) editLora(null); renderLoras(); ctx.lorasChanged?.(); }
    catch (err) { toast(err.message, { tone: 'error' }); }
  }
  $('#lora-strength')?.addEventListener('input', e => { $('#lora-strength-value').textContent = strengthText(e.target.value); });
  $('#lora-cancel')?.addEventListener('click', () => editLora(null));
  $('#lora-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const button = $('#lora-save'); button.disabled = true; $('#lora-error').textContent = '';
    try {
      loras.list = (await api('gpu-lora', { ...(loras.editing ? { id: loras.editing } : {}), url: $('#lora-url').value.trim(), name: $('#lora-name').value.trim(),
        trigger: $('#lora-trigger').value.trim(), strength: Number($('#lora-strength').value) })).loras || [];
      toast(loras.editing ? t('LoRA saved.') : t('LoRA added.'));
      editLora(null); renderLoras(); ctx.lorasChanged?.();
    } catch (err) { $('#lora-error').textContent = err.message; }
    finally { button.disabled = false; }
  });

  /**
   * The LoRAs of a request, in the composer or the edit panel: the ones chosen, each with its strength, and a list to add
   * one from the account's. get() and set(list) read and change the choice ([{ id, strength }]).
   */
  function loraPicker(container, { get, set, max = 4 }) {
    const render = () => {
      const chosen = get().filter(c => !loras.list || loras.list.some(l => l.id === c.id));
      const head = h('div', { class: 'field-head' }, h('span', { class: 'field-title' }, 'LoRAs'),
        h('button', { type: 'button', class: 'link-btn', onclick: () => showSettings('gpu') }, t('Manage')));
      if (!loras.list) { container.replaceChildren(head, h('p', { class: 'hint' }, t('Loading…'))); loadLoras().catch(() => {}); return; }
      if (!loras.list.length) { container.replaceChildren(head, h('p', { class: 'hint' }, t('No LoRAs yet. Add them in Settings › GPU with the link of their file on Hugging Face or Civitai.'))); return; }
      const rows = chosen.map(c => {
        const l = loras.list.find(x => x.id === c.id);
        const value = h('output', {}, strengthText(c.strength));
        const range = h('input', { type: 'range', min: '-2', max: '2', step: '0.05', value: String(c.strength), 'aria-label': t('Strength of {name}', { name: l.name }) });
        range.addEventListener('input', () => { value.textContent = strengthText(range.value); });
        range.addEventListener('change', () => set(get().map(x => x.id === c.id ? { ...x, strength: Number(range.value) } : x)));
        return h('div', { class: 'lora-chosen' }, h('span', { class: 'lora-name', title: l.trigger ? t('Words: {words}', { words: l.trigger }) : l.name }, l.name),
          h('span', { class: 'range-row' }, range, value),
          h('button', { type: 'button', class: 'icon-btn tiny', 'aria-label': t('Remove {label}', { label: l.name }), onclick: () => set(get().filter(x => x.id !== c.id)) }, icon('close')));
      });
      const left = loras.list.filter(l => !chosen.some(c => c.id === l.id));
      const add = h('select', { 'aria-label': t('Add a LoRA'), disabled: chosen.length >= max || !left.length },
        h('option', { value: '' }, chosen.length >= max ? t('Up to {n} LoRAs', { n: max }) : t('Add a LoRA…')), ...left.map(l => h('option', { value: l.id }, l.name)));
      add.onchange = () => { const l = loras.list.find(x => x.id === add.value); if (l) set([...get(), { id: l.id, strength: l.strength }]); };
      container.replaceChildren(head, ...rows, add);
    };
    render();
    return render;
  }

  // The window that asks for a video -----------------------------------------------------------------------------------
  // Two kinds. A new video: from the prompt, with pictures pinned at moments of it (the first frame, the last one, any
  // moment in between) and references (pictures of people, places and things, videos whose movement, camera or style it
  // follows, sounds whose voices it takes or that play at a moment). From a video of the library: continue it, redo it
  // following its movement (pose, depth or outlines), or replace a part of it. The pictures are numbered as the worker
  // numbers them (references first, then the pinned ones by moment): @1, @2…; the videos @v1…, the sounds @s1….

  const OPS = {
    continue: { label: () => t('Continue it'), hint: () => t('A new video that starts where this one ends: the same people, place, light and sound. Say what happens next.') },
    pose: { label: () => t('Follow the movement of the people (pose)'), hint: () => t('The people of the new video move exactly like the ones in this video: body, hands and face. Describe who they are, where, and the look.') },
    depth: { label: () => t('Follow the shapes and the camera (depth)'), hint: () => t('The new video keeps the shapes, the distances and the camera movement of this one. Describe the new look: people, place, light, style.') },
    edges: { label: () => t('Follow the outlines (edges)'), hint: () => t('The new video keeps the outlines of this one, frame by frame: the closest to the original, good to change its style. Describe the new look.') },
    replace: { label: () => t('Replace a part of it'), hint: () => t('Finds the part you name in every frame and redraws only it, as the prompt says. The rest of the video stays as it is.') },
  };
  const SOUND_TYPES = { mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', oga: 'audio/ogg', m4a: 'audio/mp4', mp4: 'audio/mp4', aac: 'audio/aac', flac: 'audio/flac', webm: 'audio/webm' };
  const SOUND_MIME = /^data:audio\/(mpeg|mp3|wav|x-wav|wave|vnd\.wave|ogg|mp4|x-m4a|m4a|aac|flac|x-flac|webm);base64,/;
  const form = { kind: 'new', keys: [], refs: [], videos: [], sounds: [], source: null, album: null, before: null, improving: false };

  const seconds = () => Number($('#video-seconds').value);
  const limits = () => engine()?.limits || { images: 9, videos: 3, audios: 3, audio_mb: 5, video_seconds: [2, 15] };
  const order = at => at === 'end' ? Infinity : Number(at);
  const sortedKeys = () => [...form.keys].sort((a, b) => order(a.at) - order(b.at));
  const pictureCount = () => form.refs.length + (form.kind === 'new' ? form.keys.length : 0);
  const thumb = p => p.src || imageUrl(p.id, true);
  const op = () => $('#video-op').value || 'continue';
  const short = sec => t('{n} s', { n: number(Math.round((sec || 0) * 10) / 10) });
  const momentText = at => at === 'end' ? t('End') : Number(at) === 0 ? t('Start') : short(at);
  /** The @ number of each picture: the references in order, then the pinned pictures by moment. */
  function pictureLabels() {
    const labels = new Map(); let n = 0;
    for (const r of form.refs) labels.set(r, `@${++n}`);
    if (form.kind === 'new') for (const k of sortedKeys()) labels.set(k, `@${++n}`);
    return labels;
  }
  const removeButton = (label, remove) => h('button', { type: 'button', class: 'icon-btn tiny', 'aria-label': t('Remove {label}', { label }), onclick: () => { remove(); changed(); } }, icon('close'));
  const posterTile = (video, label, remove) => h('figure', { class: 'video-frame is-video' }, h('img', { alt: '', src: imageUrl(video.id, true) }), h('figcaption', {}, label),
    h('span', { class: 'video-frame-time' }, short(video.duration)), remove ? removeButton(label, remove) : '');

  function renderDialogGpu() {
    const s = summary();
    $('#video-gpu').classList.toggle('on', s.on);
    $('#video-gpu-text').textContent = s.text;
  }
  const fill = (select, entries, value) => {
    if (select.dataset.filled !== JSON.stringify(entries)) { select.replaceChildren(...entries.map(([v, label]) => h('option', { value: v }, label))); select.dataset.filled = JSON.stringify(entries); }
    if (value !== undefined && [...select.options].some(o => o.value === value)) select.value = value;
  };
  function renderOptions() {
    const e = engine(); if (!e) return;
    fill($('#video-aspect'), e.aspects.map(a => [a, a]));
    fill($('#video-resolution'), Object.entries(e.resolutions));
    fill($('#video-quality'), Object.entries(e.qualities));
    fill($('#video-op'), (e.ops || []).filter(o => OPS[o]).map(o => [o, OPS[o].label()]));
    const range = $('#video-seconds'); range.min = String(e.seconds[0]); range.max = String(e.seconds[1]);
  }

  /** Everything that depends on what was chosen: the kind, the pinned pictures, the references, the video and its use. */
  function changed() {
    const video = form.kind === 'video';
    for (const b of $$('[data-video-kind]')) b.setAttribute('aria-pressed', String(b.dataset.videoKind === form.kind));
    $('#video-keys-part').hidden = video;
    $('#video-source-part').hidden = !video;
    // The pinned pictures and the video give the shape; the aspect ratio only counts without them.
    $('#video-aspect-label').hidden = video || form.keys.length > 0;
    if (video) renderSource(); else renderKeys();
    renderRefs(); renderImprove(); note();
  }

  function keyTile(key, at, labels) {
    const name = momentText(at);
    if (!key) return h('div', { class: 'video-key' },
      h('button', { type: 'button', class: 'video-frame empty', onclick: () => chooseKey(at) }, icon('image-plus'), h('span', {}, at === 0 ? t('First frame') : t('Last frame'))),
      h('span', { class: 'video-key-at' }, name));
    const label = labels.get(key);
    const frame = h('figure', { class: 'video-frame' }, h('img', { alt: '', src: thumb(key) }), h('figcaption', {}, label),
      removeButton(`${label} (${name})`, () => form.keys.splice(form.keys.indexOf(key), 1)));
    if (key.at === 0 || key.at === 'end') return h('div', { class: 'video-key' }, frame, h('span', { class: 'video-key-at' }, name));
    const input = h('input', { type: 'number', min: '0.1', max: String(Math.max(0.1, seconds() - 0.1)), step: '0.1', value: String(key.at), inputmode: 'decimal', 'aria-label': t('Moment of {label}, in seconds', { label }) });
    input.onchange = () => { const v = Math.round(Number(input.value) * 10) / 10; if (Number.isFinite(v) && v > 0) key.at = v; changed(); };
    return h('div', { class: 'video-key' }, frame, h('label', { class: 'video-key-at' }, input, h('span', {}, t('s', { ctx: 'seconds' }))));
  }
  function renderKeys() {
    const labels = pictureLabels(), start = form.keys.find(k => k.at === 0), end = form.keys.find(k => k.at === 'end');
    const middle = sortedKeys().filter(k => k !== start && k !== end);
    $('#video-keys').replaceChildren(keyTile(start, 0, labels), ...middle.map(k => keyTile(k, k.at, labels)),
      h('div', { class: 'video-key' }, h('button', { type: 'button', class: 'video-frame empty', onclick: () => chooseKey(null) }, icon('plus'), h('span', {}, t('A moment in between'))),
        h('span', { class: 'video-key-at' }, '')),
      keyTile(end, 'end', labels));
  }
  /** A moment no other picture has, for a new picture in between: the middle, else a quarter, else three quarters… */
  function freeMoment() {
    const length = seconds(), taken = new Set(form.keys.map(k => String(k.at)));
    for (const f of [0.5, 0.25, 0.75, 0.375, 0.625, 0.125, 0.875]) { const v = Math.round(length * f * 10) / 10; if (!taken.has(String(v))) return v; }
    return Math.round(length * 10) / 20 + 0.1;
  }
  /** A library picture pinned at a moment: 0 (the first frame), 'end' (the last) or null for a new moment in between. */
  function chooseKey(at) {
    const replacing = at !== null && form.keys.some(k => k.at === at);
    if (!replacing && pictureCount() >= limits().images) { toast(t('Use up to {n} pictures.', { n: limits().images })); return; }
    const list = [];
    pickImages(list, 1, () => {
      const p = list[0]; if (!p) return;
      const moment = at ?? freeMoment();
      form.keys = form.keys.filter(k => k.at !== moment);
      form.keys.push({ at: moment, id: p.id, width: p.width, height: p.height });
      changed();
    }, at === 0 ? t('First frame') : at === 'end' ? t('Last frame') : t('Picture at a moment of the video'));
  }

  function soundTile(sound, index) {
    const label = `@s${index + 1}`;
    const role = h('select', { 'aria-label': t('How {label} is used', { label }) },
      h('option', { value: 'ref' }, t('Voice or sound to follow')), h('option', { value: 'key' }, t('Plays at a moment')));
    role.value = sound.role;
    role.onchange = () => { sound.role = role.value; changed(); };
    const parts = [h('div', { class: 'video-sound-head' }, icon('music'), h('strong', {}, label), removeButton(label, () => form.sounds.splice(index, 1))),
      h('span', { class: 'video-sound-name', title: sound.name }, sound.name), role];
    if (sound.role === 'key') {
      const at = h('input', { type: 'number', min: '0', max: String(seconds()), step: '0.1', value: String(sound.at ?? 0), inputmode: 'decimal', 'aria-label': t('Moment of {label}, in seconds', { label }) });
      at.onchange = () => { const v = Math.round(Number(at.value) * 10) / 10; sound.at = Number.isFinite(v) && v >= 0 ? v : 0; };
      parts.push(h('label', { class: 'video-key-at' }, h('span', {}, t('from')), at, h('span', {}, t('s', { ctx: 'seconds' }))));
    }
    return h('div', { class: 'video-sound' }, ...parts);
  }
  function renderRefs() {
    const labels = pictureLabels(), l = limits(), fresh = form.kind === 'new', tiles = [];
    form.refs.forEach((r, i) => tiles.push(h('figure', { class: 'video-frame' }, h('img', { alt: '', src: thumb(r) }), h('figcaption', {}, labels.get(r)),
      removeButton(labels.get(r), () => form.refs.splice(i, 1)))));
    if (fresh) {
      form.videos.forEach((v, i) => tiles.push(posterTile(v, `@v${i + 1}`, () => form.videos.splice(i, 1))));
      form.sounds.forEach((a, i) => tiles.push(soundTile(a, i)));
    }
    $('#video-refs').replaceChildren(...tiles);
    $('#video-refs').hidden = !tiles.length;
    $('#video-add-picture').disabled = pictureCount() >= l.images;
    $('#video-add-video').hidden = $('#video-add-sound').hidden = !fresh;
    $('#video-add-video').disabled = form.videos.length >= l.videos;
    $('#video-add-sound').disabled = form.sounds.length >= l.audios;
    $('#video-refs-hint').textContent = fresh
      ? t('Pictures of people, places and things to keep; videos (2 to 15 s) whose movement, camera or style to follow; sounds (up to {mb} MB) with the voices to use or to play at a moment. In the prompt: @1 for the pictures, @v1 for the videos, @s1 for the sounds.', { mb: number(l.audio_mb) })
      : t('Pictures of people, places and things for the new video. In the prompt: @1, @2…');
  }
  $('#video-add-picture').onclick = () => {
    const library = form.refs.filter(r => r.id), others = form.refs.filter(r => !r.id);
    const list = library.map(r => ({ ...r }));
    const room = limits().images - (form.kind === 'new' ? form.keys.length : 0) - others.length;
    if (room <= 0) { toast(t('Use up to {n} pictures.', { n: limits().images })); return; }
    pickImages(list, room, () => {
      const known = new Map(library.map(r => [r.id, r]));
      form.refs = [...list.map(r => known.get(r.id) || { id: r.id, width: r.width, height: r.height }), ...others];
      changed();
    }, t('Pictures for the video'));
  };
  $('#video-add-video').onclick = () => {
    const list = form.videos.map(v => ({ ...v })), min = limits().video_seconds[0];
    pickVideos(list, limits().videos, () => {
      if (list.some(v => (v.duration || 0) < min)) toast(t('Reference videos need at least {n} seconds.', { n: number(min) }));
      form.videos = list.filter(v => (v.duration || 0) >= min).slice(0, limits().videos);
      changed();
    }, t('Videos for the video'));
  };
  $('#video-add-sound').onclick = () => $('#video-sound-file').click();
  $('#video-sound-file').onchange = async e => {
    const file = e.target.files?.[0]; e.target.value = '';
    if (!file) return;
    const l = limits();
    if (file.size > l.audio_mb * 1048576) { toast(t('Use sounds of up to {n} MB.', { n: number(l.audio_mb) }), { tone: 'error' }); return; }
    let data = await new Promise((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(String(r.result)); r.onerror = () => reject(r.error); r.readAsDataURL(file); });
    // Browsers name some sounds by another type (or none): the extension says what it is.
    if (!SOUND_MIME.test(data)) {
      const type = SOUND_TYPES[(file.name.split('.').pop() || '').toLowerCase()];
      if (!type) { toast(t('Use a sound in MP3, WAV, OGG, M4A, AAC or FLAC.'), { tone: 'error' }); return; }
      data = `data:${type};base64,${data.slice(data.indexOf(',') + 1)}`;
    }
    if (form.sounds.length >= l.audios) return;
    form.sounds.push({ data, name: file.name, role: 'ref' });
    changed();
  };

  function renderSource() {
    const source = form.source, current = op();
    const label = ['continue', 'replace'].includes(current) ? '@v1' : t('Video');
    $('#video-source').replaceChildren(...(source ? [posterTile(source, label, null)] : []),
      h('button', { type: 'button', class: 'video-frame empty', onclick: chooseSource }, icon('video'), h('span', {}, source ? t('Choose another video') : t('Choose the video'))));
    const extra = engine()?.extra_gb?.[current];
    $('#video-op-hint').textContent = [OPS[current]?.hint() || '', extra ? t('The first time in each session, it also downloads {n} GB.', { n: number(extra) }) : ''].filter(Boolean).join(' ');
    $('#video-target-label').hidden = current !== 'replace';
    $('#video-join-label').hidden = current !== 'continue';
    $('#video-keep-audio-label').hidden = current === 'continue';
  }
  /** A video that follows another one is as long as it (up to the model's most): the length starts there. */
  function fitLength() {
    const e = engine(); if (!e || !form.source || op() === 'continue') return;
    $('#video-seconds').value = String(Math.min(e.seconds[1], Math.max(e.seconds[0], Math.floor(form.source.duration || 0))));
  }
  function chooseSource() {
    const list = [];
    pickVideos(list, 1, () => { if (list[0]) { form.source = { ...list[0] }; fitLength(); } changed(); }, t('The video to start from'));
  }
  $('#video-op').onchange = () => { $('#video-error').textContent = ''; fitLength(); changed(); };
  $$('[data-video-kind]').forEach(b => b.onclick = () => { $('#video-error').textContent = ''; form.kind = b.dataset.videoKind; changed(); });

  // The prompt helper: a text model rewrites the idea in the form H3 was trained on (VideoPrompt.php).
  function helpers() {
    const k = ctx.keys();
    return [k.claude && ['claude', 'Claude'], k.openai && ['gpt', 'GPT'], k.gemini && ['gemini', 'Gemini']].filter(Boolean);
  }
  function renderImprove() {
    const list = helpers(), select = $('#video-improve-with');
    fill(select, list, ctx.prefs()?.video_helper);
    select.hidden = list.length < 2;
    const button = $('#video-improve');
    button.disabled = form.improving;
    button.title = list.length ? t('A text model rewrites the prompt the way H3 understands it best: the moments, the camera, the voices, the sound. It keeps your idea.') : t('Add a Claude, OpenAI or Gemini key in Settings to improve prompts.');
    $('#video-improve-undo').hidden = form.before === null || form.improving;
  }
  $('#video-improve').onclick = async () => {
    const error = $('#video-error'), out = $('#video-improve-note'); error.textContent = '';
    if (!helpers().length) { error.textContent = t('Add a Claude, OpenAI or Gemini key in Settings to improve prompts.'); return; }
    const prompt = $('#video-prompt').value.trim();
    if (!prompt) { error.textContent = t('Write your idea first: the helper improves it.'); $('#video-prompt').focus(); return; }
    const built = build();
    if (built.error) { error.textContent = built.error; return; }
    const provider = $('#video-improve-with').value || helpers()[0][0];
    form.improving = true; renderImprove();
    out.textContent = t('Improving the prompt with {model}… up to a minute.', { model: helpers().find(([v]) => v === provider)?.[1] || provider });
    try {
      const result = await api('video-prompt', { prompt, provider, options: built.options, inputs: built.inputs.map(({ data, ...rest }) => rest.kind === 'audio' ? rest : { ...rest, ...(data ? { data } : {}) }) });
      form.before = $('#video-prompt').value;
      $('#video-prompt').value = result.prompt;
      ctx.savePrefs?.({ video_helper: result.provider });
      out.textContent = t('Rewritten by {model}{cost}. Read it and change what you want: it is in English, as H3 understands best.', { model: result.model, cost: result.cost > 0 ? ` (${formatUsd(result.cost)})` : '' });
    } catch (err) { out.textContent = ''; error.textContent = err.message; }
    finally { form.improving = false; renderImprove(); }
  };
  $('#video-improve-undo').onclick = () => {
    if (form.before === null) return;
    $('#video-prompt').value = form.before; form.before = null;
    $('#video-improve-note').textContent = ''; renderImprove();
  };

  function note() {
    const e = engine(); if (!e) return;
    const length = seconds();
    $('#video-seconds-out').textContent = t('{n} s', { n: number(length) });
    for (const input of $$('#video-keys input[type=number]')) input.max = String(Math.max(0.1, length - 0.1));
    for (const input of $$('#video-refs input[type=number]')) input.max = String(length);
    const w = worker(), parts = [];
    if (form.kind === 'video' && form.source && op() !== 'continue' && (form.source.duration || 0) < length) parts.push(t('The new video is as long as the original: {n}.', { n: short(form.source.duration) }));
    if (form.kind === 'video' && op() === 'continue') parts.push(t('The length is of the new part.'));
    if (!w || !w.loaded) parts.push(t('The first video of each session waits for the model to load, a few minutes.'));
    parts.push(e.note);
    $('#video-note').textContent = parts.join(' ');
  }
  $('#video-seconds').oninput = note;
  $('#video-gpu').onclick = () => { dialog.close(); showSettings('gpu'); };

  /** What the server gets: the options and the inputs, in the order the worker numbers them. Or the first problem. */
  function build() {
    const length = seconds(), seedText = $('#video-seed').value.trim();
    const options = { engine: 'h3', mode: form.kind, seconds: length, aspect: $('#video-aspect').value, resolution: $('#video-resolution').value, quality: $('#video-quality').value,
      seed: seedText === '' ? null : Number(seedText) };
    const picture = p => p.id ? { id: p.id } : { data: p.data };
    const inputs = form.refs.map(r => ({ kind: 'image', role: 'ref', ...picture(r) }));
    if (form.kind === 'new') {
      const moments = new Set();
      for (const k of sortedKeys()) {
        if (k.at !== 0 && k.at !== 'end' && !(k.at > 0 && k.at < length - 0.05)) return { error: t('Choose a moment between 0 and {max} seconds.', { max: number(length) }) };
        if (moments.has(String(k.at))) return { error: t('Two pictures can\'t be at the same moment.') };
        moments.add(String(k.at));
        inputs.push({ kind: 'image', role: 'key', at: k.at, ...picture(k) });
      }
      for (const v of form.videos) inputs.push({ kind: 'video', role: 'ref', id: v.id });
      for (const a of form.sounds) {
        if (a.role === 'key' && (a.at ?? 0) > length) return { error: t('Choose a moment between 0 and {max} seconds.', { max: number(length) }) };
        inputs.push({ kind: 'audio', role: a.role, ...(a.role === 'key' ? { at: a.at ?? 0 } : {}), data: a.data, name: a.name });
      }
    } else {
      if (!form.source) return { error: t('Choose the video to start from.') };
      const current = op();
      inputs.push({ kind: 'video', role: 'source', id: form.source.id });
      options.op = current;
      if (current === 'replace') {
        const target = $('#video-target').value.trim().replace(/\s+/g, ' ');
        if (!target) { $('#video-target').focus(); return { error: t('Say what to replace in the video, in up to 120 characters.') }; }
        options.target = target;
      }
      if (current === 'continue') options.join = $('#video-join').checked; else options.keep_audio = $('#video-keep-audio').checked;
    }
    return { options, inputs };
  }

  /**
   * Opens the window. pictures: references ({ id } from the library, or { data } as a data URL, with width, height and
   * src for the thumbnail); keys: pictures pinned at a moment ({ at: 0 | 'end' | seconds, id, width, height }); source:
   * a video of the library to start from ({ id, width, height, duration }), with op; options: those of a request to
   * repeat; videos: reference videos.
   */
  async function open({ prompt = '', pictures = [], keys = [], videos = [], source = null, op: chosenOp = null, options = null, album = null } = {}) {
    if (!gpu.status) await refresh({ quiet: false });
    if (!engine()) return;
    const prefs = ctx.prefs()?.video || {}, e = engine(), o = options || {};
    Object.assign(form, { kind: source ? 'video' : 'new', refs: pictures.slice(0, 9), keys: keys.slice(0, 9), videos: videos.slice(0, 3), sounds: [], source, album, before: null, improving: false });
    renderOptions();
    $('#video-prompt').value = prompt;
    $('#video-seconds').value = String(o.seconds || prefs.seconds || e.default_seconds);
    $('#video-aspect').value = o.aspect || prefs.aspect || e.aspects[0];
    $('#video-resolution').value = o.resolution || prefs.resolution || e.default_resolution;
    $('#video-quality').value = o.quality || prefs.quality || e.default_quality;
    $('#video-op').value = chosenOp || o.op || prefs.op || 'continue';
    $('#video-target').value = o.target || '';
    $('#video-join').checked = o.join !== false;
    $('#video-keep-audio').checked = o.keep_audio !== false;
    $('#video-seed').value = Number.isInteger(o.seed) ? String(o.seed) : '';
    $('#video-error').textContent = ''; $('#video-improve-note').textContent = '';
    if (source && !options) fitLength();
    changed(); renderDialogGpu();
    if (!dialog.open) dialog.showModal();
    $('#video-prompt').focus();
    schedule();
  }
  dialog.addEventListener('close', schedule);

  $('#video-form').onsubmit = async e => {
    e.preventDefault();
    const error = $('#video-error'); error.textContent = '';
    if (form.improving) return;
    const prompt = $('#video-prompt').value.trim();
    if (!prompt) { error.textContent = t('Describe the video.'); $('#video-prompt').focus(); return; }
    const built = build();
    if (built.error) { error.textContent = built.error; return; }
    const { options, inputs } = built;
    const submit = $('#video-submit'); submit.disabled = true;
    try {
      const { job } = await api('video-create', { id: newId(), prompt, options, inputs, ...(form.album ? { album: form.album } : {}) });
      ctx.savePrefs?.({ video: { seconds: options.seconds, aspect: options.aspect, resolution: options.resolution, quality: options.quality, ...(options.op ? { op: options.op } : { op: ctx.prefs()?.video?.op }) } });
      trackJob(job);
      dialog.close();
      const w = worker();
      toast(w ? t('Video requested. It shows in the feed while the GPU makes it.') : t('Video requested. It waits until you turn the GPU on in Colab.'),
        w ? {} : { action: { label: t('How to turn it on'), run: () => showSettings('gpu') } });
      refresh(); schedule();
    } catch (err) { error.textContent = err.message; }
    finally { submit.disabled = false; }
  };

  /** The rows of a video request's details: what it was made from and how. */
  function rows(job) {
    const o = job.options || {}, e = engine(), inputs = job.inputs || [], list = [];
    list.push([t('Model'), e?.label || 'MiniMax H3']);
    list.push([t('Kind'), o.mode === 'video' ? t('From a video: {what}', { what: OPS[o.op]?.label() || o.op || '' }) : t('New video')]);
    if (o.op === 'replace' && o.target) list.push([t('What to replace'), o.target]);
    if (o.op === 'continue') list.push([t('Original video'), o.join === false ? t('Separate') : t('Joined to the new part')]);
    else if (o.mode === 'video') list.push([t('Sound'), o.keep_audio === false ? t('New') : t('Of the original video')]);
    const keys = inputs.filter(i => i.kind === 'image' && i.role === 'key').sort((a, b) => order(a.at) - order(b.at));
    if (keys.length) list.push([t('Pinned pictures'), keys.map(k => momentText(k.at)).join(', ')]);
    const count = (kind, role) => inputs.filter(i => i.kind === kind && i.role === role).length;
    const refs = [[count('image', 'ref'), t('{n} picture'), t('{n} pictures')], [count('video', 'ref'), t('{n} video'), t('{n} videos')], [count('audio', 'ref') + count('audio', 'key'), t('{n} sound'), t('{n} sounds')]]
      .filter(([n]) => n).map(([n, one, many]) => (n === 1 ? one : many).replace('{n}', number(n)));
    if (refs.length) list.push([t('References'), refs.join(', ')]);
    list.push([t('Length'), t('{n} s', { n: number(o.seconds || 0) })]);
    if (o.mode !== 'video' && !keys.length) list.push([t('Aspect ratio'), o.aspect || '']);
    list.push([t('Resolution'), o.resolution || ''], [t('Quality'), e?.qualities?.[o.quality] || o.quality || '']);
    return list;
  }

  return {
    open, refresh, render, schedule, stop,
    /** The pane of the settings was shown. */
    showPane: () => { renderPane(); refresh(); schedule(); loadLoras(true).catch(err => toast(err.message, { tone: 'error' })); editLora(null); },
    loadLoras, loraPicker, loraList: () => loras.list,
    phase: job => videoPhase(job, worker()),
    /** What a video request asked for, as rows of its details (before the seed, the GPU and the time). */
    rows,
    worker, summary, engine,
    /** How the notebook saves the pictures (Settings › GPU): jpeg and its quality. Null until the GPU's state is known. */
    pictures: () => gpu.status?.pictures || null,
  };
}
