import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Videos on the GPU in a browser: Settings › GPU, the window that asks for a video, the request in the feed and
// the video in the library. The GPU is the worker of colab/ with its test pattern engine (ffmpeg), started here as the
// notebook would start it. Runs on the instance tests/http_checks.py (or tests/gpu_checks.py) left, with TEST_PASSWORD,
// served by several PHP processes (PHP_CLI_SERVER_WORKERS): the worker's long poll holds one of them.
const base = 'http://127.0.0.1:8099';
const shots = process.env.SCREENSHOTS || '';
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ locale: 'pt-BR', viewport: { width: 1440, height: 900 }, deviceScaleFactor: shots ? 2 : 1 });
await context.route('**/index.php?action=models', route => route.continue({ postData: JSON.stringify({ refresh: false, force: false }) }));
const page = await context.newPage();
const errors = [];
page.on('pageerror', e => { errors.push(e.message); console.error('pageerror:', e.message); });
const shot = async (name, locator = page) => { if (shots) await locator.screenshot({ path: join(shots, `${name}.png`) }); };

await page.goto(base);
await page.locator('#auth-name').waitFor();
await page.locator('#auth-name').fill('dono');
await page.locator('#auth-password').fill(process.env.TEST_PASSWORD || 'new-test-password-long-enough');
await page.locator('#auth-submit').click();
await page.locator('#application').waitFor();
const call = (action, data) => page.evaluate(async ([action, data]) => {
  const state = await (await fetch('index.php?action=state')).json();
  const response = await fetch(`index.php?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf }, body: JSON.stringify(data) });
  return response.json();
}, [action, data]);
// Two pictures in the library, for the first frame and the references.
const png = (r, g, b) => page.evaluate(([r, g, b]) => { const c = document.createElement('canvas'); c.width = 96; c.height = 64; const x = c.getContext('2d'); x.fillStyle = `rgb(${r},${g},${b})`; x.fillRect(0, 0, 96, 64); return c.toDataURL('image/png'); }, [r, g, b]);
await call('settings', { api_key: 'sk-test-placeholder-for-unit-tests' });
const first = (await call('import', { data: await png(200, 90, 40), name: 'Praia' })).image;
await call('import', { data: await png(40, 90, 200), name: 'Mar' });
await call('gpu-revoke', {});
// Requests that ended without a result in earlier runs leave the feed, so the feed shows only this run's.
for (const job of (await page.evaluate(async () => (await (await fetch('index.php?action=jobs')).json()).jobs)) || []) if (!['queued', 'running', 'completed'].includes(job.status)) await call('dismiss', { id: job.id });
await page.reload(); await page.locator('#application').waitFor();

// Settings › GPU: off, without a token; a token shown once with the address.
await page.locator('#settings-open').click();
await page.locator('.settings-tab[data-pane=gpu]').click();
await page.locator('#settings-gpu').waitFor();
await page.waitForFunction(() => document.querySelector('#gpu-state').textContent !== '');
assert.equal(await page.locator('#gpu-state').innerText(), 'Desligado');
assert.match(await page.locator('#gpu-token-status').innerText(), /Ainda não há token/);
assert.ok(await page.locator('#gpu-revoke').isHidden(), 'nothing to revoke without a token');
assert.ok((await page.locator('#gpu-notebook').getAttribute('href')).includes('colab.research.google.com'), 'the notebook opens in Colab');
await page.locator('#gpu-token').click();
await page.locator('#gpu-token-output input').first().waitFor();
const [url, token] = await page.locator('#gpu-token-output input').evaluateAll(inputs => inputs.map(i => i.value));
assert.equal(url.replace(/\/$/, ''), base); assert.match(token, /^igv_[a-f0-9]{24}_[\w-]{43}$/);
assert.equal(await page.locator('#gpu-idle option').count(), 7);
await page.locator('#gpu-idle').selectOption('30');
await page.waitForFunction(() => document.querySelector('#gpu-idle').value === '30');
await shot('settings-gpu', page.locator('#settings-dialog'));

// The worker, as the notebook runs it: the page sees it on.
const work = mkdtempSync(join(tmpdir(), 'atelier-gpu-'));
const worker = spawn('python3', ['colab/atelier_worker.py', '--engine', 'fake', '--keep-runtime'], { cwd: process.cwd(), env: { ...process.env, ATELIER_URL: url, ATELIER_TOKEN: token, HOME: work }, stdio: ['ignore', 'pipe', 'pipe'] });
let log = ''; worker.stdout.on('data', d => { log += d; }); worker.stderr.on('data', d => { log += d; });
process.on('exit', () => { if (worker.exitCode === null) worker.kill(); });
await page.waitForFunction(() => /Ligada/.test(document.querySelector('#gpu-state').textContent), null, { timeout: 30000 });
assert.match(await page.locator('#gpu-detail').innerText(), /Pronta para pedidos|desliga sozinha/i);
assert.ok(await page.locator('#gpu-stop').isVisible(), 'a GPU that is on can be turned off from here');
await page.keyboard.press('Escape');
await page.locator('#gpu-chip').waitFor();
assert.match(await page.locator('#gpu-chip').innerText(), /GPU/);

// This run's video requests, as the worker ends them (requests of earlier runs may still be in the feed).
const started = Math.floor(Date.now() / 1000) - 5;
const history = () => page.evaluate(async () => (await (await fetch('index.php?action=jobs&scope=history')).json()).jobs);
async function madeVideo(what, timeout = 60000) {
  for (const end = Date.now() + timeout; ;) {
    const job = (await history()).find(j => j.kind === 'video' && j.created >= started && what(j));
    if (job?.status === 'completed') return job;
    if (job && !['queued', 'running'].includes(job.status)) throw new Error(`the video request ended ${job.status}: ${job.error}\n${log.slice(-1500)}`);
    if (Date.now() > end) throw new Error(`no video in time\n${log.slice(-1500)}`);
    await page.waitForTimeout(500);
  }
}

// A video from the text alone, from the composer.
await page.locator('#prompt').fill('Ondas quebrando numa praia ao pôr do sol, gaivotas, som do mar');
await page.locator('#video-open').click();
await page.locator('#video-dialog').waitFor();
assert.equal(await page.locator('#video-prompt').inputValue(), 'Ondas quebrando numa praia ao pôr do sol, gaivotas, som do mar', 'the composer\'s prompt comes along');
assert.equal(await page.locator('[data-video-kind=new]').getAttribute('aria-pressed'), 'true');
assert.equal(await page.locator('#video-keys .video-frame.empty').count(), 3, 'first frame, a moment in between and the last frame, all empty');
assert.ok(await page.locator('#video-aspect-label').isVisible(), 'without pinned pictures, the shape is chosen');
assert.match(await page.locator('#video-gpu-text').innerText(), /Pronta para pedidos/);
assert.equal(await page.locator('#video-quality option').count(), 3);
await page.locator('#video-seconds').fill('8');
assert.match(await page.locator('#video-seconds-out').innerText(), /8 s/);
await page.locator('#video-submit').click();
await page.locator('#video-dialog').waitFor({ state: 'hidden' });
await page.locator('.tile.job', { hasText: /GPU|Vídeo/ }).first().waitFor();
// The worker makes it; the video arrives in the library.
const made = await madeVideo(j => !j.inputs?.length);
assert.ok(made.options.seconds === 8 && made.options.mode === 'new', 'the request kept its options');
const library = await page.evaluate(async () => (await (await fetch('index.php?action=library')).json()).items);
assert.ok(library.some(row => row[0] === made.image_ids[0] && row[4] & 2), 'the video is in the library');

// From an image in the viewer: Animar pins it as the first frame.
await page.goto(`${base}/#v/${first.id}`);
await page.locator('#viewer[open]').waitFor();
await page.locator(`#viewer-download[href*="${first.id}"]`).waitFor({ state: 'attached' });
await page.locator('#animate-open').click();
await page.locator('#video-dialog').waitFor();
assert.equal(await page.locator('#video-keys .video-frame img').count(), 1, 'the image is the first frame');
assert.equal(await page.locator('#video-keys .video-key').first().locator('figcaption').innerText(), '@1');
assert.ok(await page.locator('#video-aspect-label').isHidden(), 'a pinned picture sets the shape');
await page.locator('#video-seconds').fill('5');
// The last frame and a moment in between, from the gallery.
const pick = async (opener, n = 1) => {
  await opener.click();
  await page.locator('#picker[open]').waitFor();
  await page.locator('#picker-grid .pick').first().waitFor();
  for (let i = 0; i < n; i++) await page.locator('#picker-grid .pick[aria-pressed=false]').nth(0).click();
  await page.locator('#picker-done').click();
  await page.locator('#picker').waitFor({ state: 'hidden' });
};
await pick(page.locator('#video-keys .video-key').last().locator('.video-frame.empty'));
await pick(page.locator('#video-keys .video-frame.empty'));
assert.equal(await page.locator('#video-keys .video-frame img').count(), 3, 'first, middle and last frame');
const middle = page.locator('#video-keys input[type=number]');
assert.equal(await middle.inputValue(), '2.5', 'the moment in between starts at the middle of the video');
await middle.fill('20'); await middle.blur();
await page.locator('#video-prompt').fill('A câmera passa por @1, @2 e @3');
await page.locator('#video-submit').click();
assert.match(await page.locator('#video-error').innerText(), /momento entre 0 e 5/);
await middle.fill('1.5'); await middle.blur();
// A reference picture comes first in the numbering; the pinned ones follow by moment.
await pick(page.locator('#video-add-picture'));
assert.deepEqual(await page.locator('#video-refs figcaption').allInnerTexts(), ['@1']);
assert.deepEqual(await page.locator('#video-keys figcaption').allInnerTexts(), ['@2', '@3', '@4']);
// A sound from the device, playing from a moment.
const wav = (() => { const rate = 8000, n = rate, b = Buffer.alloc(44 + n * 2); b.write('RIFF', 0); b.writeUInt32LE(36 + n * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22); b.writeUInt32LE(rate, 24); b.writeUInt32LE(rate * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(n * 2, 40); for (let i = 0; i < n; i++) b.writeInt16LE(Math.round(8000 * Math.sin(i / 8)), 44 + i * 2); return b; })();
await page.locator('#video-sound-file').setInputFiles({ name: 'ondas.wav', mimeType: 'audio/wav', buffer: wav });
await page.locator('#video-refs .video-sound').waitFor();
assert.match(await page.locator('#video-refs .video-sound').innerText(), /@s1[\s\S]*ondas\.wav/);
await page.locator('#video-refs .video-sound select').selectOption('key');
await page.locator('#video-refs .video-sound input[type=number]').fill('1');
// The prompt helper rewrites the idea (here a stand-in for the model) and can be undone.
let asked = null;
await page.route('**/index.php?action=video-prompt', route => { asked = JSON.parse(route.request().postData()); route.fulfill({ contentType: 'application/json', body: JSON.stringify({ prompt: 'subject_definitions:\n<Picture 1> is a reference picture.\n\nsummary:\n[keyframe completion] The camera glides.\n\ndetailed_description:\n[Shot 1] The camera glides past <Picture 2>.', provider: 'gpt', model: 'gpt-6.2-sol', cost: 0.004 }) }); });
// With one key there is nothing to choose; with more, GPT is picked here.
const helpers = await page.locator('#video-improve-with option').count();
assert.equal(await page.locator('#video-improve-with').isVisible(), helpers > 1, 'the helper is chosen only when there are several');
if (helpers > 1) await page.locator('#video-improve-with').selectOption('gpt');
await page.locator('#video-improve').click();
await page.waitForFunction(() => document.querySelector('#video-prompt').value.startsWith('subject_definitions'));
assert.equal(asked.provider, 'gpt');
assert.deepEqual(asked.inputs.map(i => `${i.kind}:${i.role}:${i.at ?? ''}`), ['image:ref:', 'image:key:0', 'image:key:1.5', 'image:key:end', 'audio:key:1']);
assert.ok(!('data' in asked.inputs[4]), 'the helper gets the name of a sound, not the sound');
assert.match(await page.locator('#video-improve-note').innerText(), /gpt-6\.2-sol/);
await page.locator('#video-improve-undo').click();
assert.equal(await page.locator('#video-prompt').inputValue(), 'A câmera passa por @1, @2 e @3');
await page.locator('#video-improve').click();
await page.waitForFunction(() => document.querySelector('#video-prompt').value.startsWith('subject_definitions'));
await shot('video-dialog', page.locator('#video-dialog'));
await page.locator('#video-submit').click();
await page.locator('#video-dialog').waitFor({ state: 'hidden' });
const pinned = await madeVideo(j => j.inputs?.length === 5);
assert.deepEqual(pinned.inputs.map(i => i.at ?? null), [null, 0, 1.5, 'end', 1]);
assert.equal(pinned.inputs[4].name, 'ondas.wav');

// From a video of the library: the viewer offers a new video from it, to continue it, follow it or replace a part.
const video = made.image_ids[0];
await page.goto(`${base}/#v/${video}`);
await page.locator('#viewer[open]').waitFor();
await page.locator('#video-from').waitFor();
await page.locator('#video-from').click();
await page.locator('#video-dialog').waitFor();
assert.equal(await page.locator('[data-video-kind=video]').getAttribute('aria-pressed'), 'true');
assert.ok(await page.locator('#video-keys-part').isHidden(), 'no pinned pictures in a video from a video');
assert.equal(await page.locator('#video-source .video-frame img').count(), 1, 'the video on screen is the one it starts from');
assert.ok(await page.locator('#video-add-video').isHidden() && await page.locator('#video-add-sound').isHidden(), 'only pictures as references');
await page.locator('#video-op').selectOption('replace');
assert.ok(await page.locator('#video-target-label').isVisible() && await page.locator('#video-keep-audio-label').isVisible());
assert.match(await page.locator('#video-op-hint').innerText(), /baixa mais 3,8 GB/);
assert.equal(await page.locator('#video-seconds').inputValue(), '8', 'following a video, the length starts at its own');
await page.locator('#video-prompt').fill('The waves turn into molten gold');
await page.locator('#video-submit').click();
assert.match(await page.locator('#video-error').innerText(), /o que trocar/i);
await page.locator('#video-op').selectOption('continue');
assert.ok(await page.locator('#video-join-label').isVisible() && await page.locator('#video-target-label').isHidden());
await page.locator('#video-seconds').fill('5');
await page.locator('#video-prompt').fill('A gaivota pousa na areia');
await shot('video-dialog-from-video', page.locator('#video-dialog'));
await page.locator('#video-submit').click();
await page.locator('#video-dialog').waitFor({ state: 'hidden' });
const continued = await madeVideo(j => j.options.op === 'continue');
assert.ok(continued.options.join === true && continued.parent_ids[0] === video, 'the continuation comes from the video, joined to it');
// Its details say what it was made from; Use the prompt opens the window with the same video.
await page.goto(`${base}/#v/${continued.image_ids[0]}`);
await page.locator('#viewer[open]').waitFor();
await page.locator('#video-from').waitFor();
const joined = await page.evaluate(async id => (await (await fetch('index.php?action=library')).json()).items.find(r => r[0] === id), continued.image_ids[0]);
assert.ok(joined, 'the joined video is in the library');

// Pictures with MiniMax H3-Image on the GPU, with LoRAs from Settings › GPU.
async function madePicture(what, timeout = 60000) {
  for (const end = Date.now() + timeout; ;) {
    const job = (await history()).find(j => j.kind === 'image' && j.runner === 'gpu' && j.created >= started && what(j));
    if (job?.status === 'completed') return job;
    if (job && !['queued', 'running'].includes(job.status)) throw new Error(`the picture request ended ${job.status}: ${job.error}\n${log.slice(-1500)}`);
    if (Date.now() > end) throw new Error(`no picture in time\n${log.slice(-1500)}`);
    await page.waitForTimeout(500);
  }
}
for (const l of (await call('gpu-loras', {})).loras || []) await call('gpu-lora-delete', { id: l.id });
await page.goto(base); await page.locator('#application').waitFor();
await page.locator('#gpu-chip').click();
await page.locator('#settings-gpu').waitFor();
await page.locator('#lora-card').scrollIntoViewIfNeeded();
await page.locator('#lora-list li').first().waitFor();
assert.match(await page.locator('#lora-list').innerText(), /Ainda não há LoRAs/);
// A link that is not a .safetensors file is refused; a Hugging Face page of the file is turned into its download.
await page.locator('#lora-url').fill('https://huggingface.co/someone/watercolor/tree/main');
await page.locator('#lora-save').click();
await page.waitForFunction(() => document.querySelector('#lora-error').textContent !== '');
assert.match(await page.locator('#lora-error').innerText(), /Hugging Face/);
await page.locator('#lora-url').fill('https://huggingface.co/someone/watercolor/blob/main/watercolor_style_v2.safetensors');
await page.locator('#lora-trigger').fill('wtrclr');
await page.locator('#lora-strength').fill('0.8');
await page.locator('#lora-save').click();
await page.locator('#lora-list .lora-row').first().waitFor();
assert.match(await page.locator('#lora-list .lora-row').innerText(), /watercolor style v2[\s\S]*Palavras: wtrclr · força 0,8[\s\S]*huggingface\.co\/someone\/watercolor\/resolve\/main\/watercolor_style_v2\.safetensors/);
await page.locator('#lora-url').fill('https://civitai.com/models/12345/ink?modelVersionId=67890');
await page.locator('#lora-name').fill('Nanquim');
await page.locator('#lora-save').click();
await page.waitForFunction(() => document.querySelectorAll('#lora-list .lora-row').length === 2);
// Changing one keeps its place.
await page.locator('#lora-list .lora-row').nth(1).getByRole('button', { name: 'Editar' }).click();
assert.equal(await page.locator('#lora-save').innerText(), 'Salvar LoRA');
await page.locator('#lora-strength').fill('0.5');
await page.locator('#lora-save').click();
await page.waitForFunction(() => /força 0,5/.test(document.querySelectorAll('#lora-list .lora-row')[1]?.textContent || ''));
assert.match(await page.locator('#lora-list .lora-row').nth(1).innerText(), /Nanquim[\s\S]*civitai\.com\/api\/download\/models\/67890/);
await shot('settings-gpu-loras', page.locator('#settings-dialog'));
await page.keyboard.press('Escape');

// The composer: H3-Image is one more model, with its megapixels, MiniMax's expander and the LoRAs.
const composerModel = await page.locator('#model').inputValue();
const wasAdvanced = await page.locator('#advanced-mode').isChecked();
await page.locator('#advanced-mode').check({ force: true });
await page.locator('#model').selectOption('minimax-h3-image');
assert.deepEqual(await page.locator('#quality option').allInnerTexts(), ['Baixa · 4 MP', 'Média · 8 MP', 'Alta · 16 MP']);
await page.locator('#quality').selectOption('low');
assert.deepEqual(await page.locator('#thinking option').allTextContents(), ['Desligado', 'Expansor da MiniMax']);
await page.locator('#thinking').selectOption('medium', { force: true });
assert.match(await page.locator('#generate-cost').innerText(), /Sem custo de API/);
assert.ok(await page.locator('#background option[value=transparent]').evaluate(o => o.disabled), 'no transparent background');
await page.locator('#loras').waitFor();
assert.deepEqual(await page.locator('#loras select option').allInnerTexts(), ['Adicionar uma LoRA…', 'watercolor style v2', 'Nanquim']);
await page.locator('#loras select').selectOption({ label: 'watercolor style v2' });
await page.locator('#loras .lora-chosen').waitFor();
assert.equal(await page.locator('#loras .lora-chosen input[type=range]').inputValue(), '0.8', 'a LoRA starts at its own strength');
await page.locator('#loras .lora-chosen input[type=range]').fill('0.6');
await shot('composer-h3-image', page.locator('.composer'));
await page.locator('#prompt').fill('Um gato malhado de chapéu vermelho, aquarela');
const composerCount = await page.locator('#quantity').inputValue();
await page.locator('#quantity').selectOption('1');
await page.locator('#generate').click();
const picture = await madePicture(j => j.operation !== 'edit');
assert.equal(picture.options.model, 'minimax-h3-image');
assert.deepEqual(picture.options.loras.map(l => [l.name, l.strength]), [['watercolor style v2', 0.6]], 'the LoRA went with its strength');
const pictureId = picture.image_ids[0];
await page.goto(`${base}/#v/${pictureId}`);
await page.locator('#viewer[open]').waitFor();
await page.locator('#info-details dt', { hasText: 'LoRAs' }).waitFor();
const details = await page.locator('#info-details').innerText();
assert.match(details, /MiniMax H3-Image · GPU/);
assert.match(details, /Expansor da MiniMax/);
assert.match(details, /watercolor style v2 \(0,6\)/);
assert.match(await page.locator('#info-planned').textContent(), /^Expanded \(t2i\)/, 'the expander\'s prompt is shown');

// The edit panel: the same model, with its own LoRAs, makes a new version of the picture.
await page.locator('#edit-advanced-mode').check({ force: true });
await page.locator('#edit-model').selectOption('minimax-h3-image');
await page.locator('#edit-loras').waitFor();
// Until the panel has its own choice, it starts from the composer's, as for the model.
await page.locator('#edit-loras .lora-chosen').first().waitFor();
assert.deepEqual(await page.locator('#edit-loras .lora-name').allInnerTexts(), ['watercolor style v2']);
await page.locator('#edit-loras .lora-chosen button').click();
await page.waitForFunction(() => !document.querySelector('#edit-loras .lora-chosen'));
await page.locator('#edit-loras select').selectOption({ label: 'Nanquim' });
await page.locator('#edit-loras .lora-chosen').waitFor();
assert.equal(await page.locator('#edit-loras .lora-chosen input[type=range]').inputValue(), '0.5');
assert.match(await page.locator('#edit-cost').innerText(), /Sem custo de API/);
await page.locator('#edit-prompt').fill('Deixe o chapéu azul');
const editCount = await page.locator('#edit-quantity').inputValue();
await page.locator('#edit-quantity').selectOption('1');
await page.locator('#edit-submit').click();
const edited = await madePicture(j => j.operation === 'edit');
assert.equal(edited.parent_ids[0], pictureId, 'the edit comes from the picture');
assert.deepEqual(edited.options.loras.map(l => l.name), ['Nanquim']);
const version = await page.evaluate(async id => (await (await fetch(`index.php?action=detail&id=${id}`)).json()).image, edited.image_ids[0]);
assert.equal(version.parent_id, pictureId, 'a new version of the picture');
await page.locator('#edit-quantity').selectOption(editCount);
await page.locator('#edit-model').selectOption(composerModel);
await page.locator('#edit-advanced-mode').setChecked(wasAdvanced, { force: true });

// Turning it off from Atelier.
await page.goto(base); await page.locator('#application').waitFor();
await page.locator('#model').selectOption(composerModel, { force: true }).catch(() => {});
await page.locator('#quantity').selectOption(composerCount).catch(() => {});
if (!wasAdvanced) await page.locator('#advanced-mode').uncheck({ force: true });
await page.locator('#gpu-chip').click();
await page.locator('#settings-gpu').waitFor();
await page.locator('#gpu-stop').click();
await page.locator('#confirm-yes').click();
const exit = await new Promise(resolve => { const timer = setTimeout(() => resolve(null), 30000); worker.on('exit', code => { clearTimeout(timer); resolve(code); }); });
assert.equal(exit, 0, 'the worker turned off: ' + log.slice(-600));
await page.waitForFunction(() => document.querySelector('#gpu-state').textContent === 'Desligado', null, { timeout: 20000 });
assert.deepEqual(errors, []);
await browser.close();
console.log('GPU browser checks passed.');
