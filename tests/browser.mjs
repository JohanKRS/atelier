import { chromium } from '@playwright/test';
import { mkdir, readFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import assert from 'node:assert/strict';

// Runs against the isolated instance prepared by tests/http_checks.py. The OpenAI API is simulated by
// tests/complete_fixture_job.php in a separate PHP process per request, so several requests really overlap.
const runFixture = promisify(execFile);
await mkdir('tests/artifacts', { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ locale: 'pt-BR', viewport: { width: 1440, height: 900 } });
// The model lists come from what the account kept, never from the providers' APIs: no real calls in tests.
await context.route('**/index.php?action=models', route => route.continue({ postData: JSON.stringify({ refresh: false, force: false }) }));
const page = await context.newPage();
await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: 'http://127.0.0.1:8099' });
const errors = [];
page.on('pageerror', e => errors.push(e.message));
const base = 'http://127.0.0.1:8099';
const creations = [];
page.on('request', request => { if (request.url().endsWith('index.php?action=create')) creations.push(request.postDataJSON()); });
// With "Limpar antes de enviar" on (it starts on, at Média), the image an edit starts from goes cleaned in the browser:
// as data, linked to the image it came from and marked as cleaned at its level, so the model is told the blur is on purpose.
const cleanedSource = (ref, id) => ref?.source_id === id && ref.cleaned === true && ref.clean === 'media' && !('id' in ref) && /^data:image\/png;base64,/.test(ref.data);
const CLEANED_NOTE = /^Limpeza média\. O prompt avisou que o desfoque foi intencional e pediu o foco de volta\.$/;
let fixtureSeconds = '0', fixturePlanning = '';
await page.route('**/index.php?action=run', async route => {
  const { id } = route.request().postDataJSON();
  let response;
  try {
    const { stdout } = await runFixture('php', ['tests/complete_fixture_job.php', id], { env: { ...process.env, FIXTURE_SECONDS: fixtureSeconds, FIXTURE_PLANNING_OUTPUT: fixturePlanning } });
    response = { status: 200, body: stdout };
  } catch (e) { response = { status: 500, body: JSON.stringify({ error: 'fixture: ' + String(e.stderr || e.message).slice(0, 300) }) }; }
  await route.fulfill({ ...response, contentType: 'application/json' }).catch(() => {});
});
const shot = async (name, options = {}) => {
  await page.screenshot({ path: `tests/artifacts/${name}`, ...options });
  // Reviewable screenshots contain only synthetic fixtures, never user data.
  console.log(`VISUAL_${name}:` + (await readFile(`tests/artifacts/${name}`)).toString('base64'));
};
const noOverflow = () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const imageTiles = page.locator('#feed .tile[data-id]');
const versionLabel = (text, timeout = 20000) => page.waitForFunction(t => document.querySelector('#viewer-version').textContent === t, text, { timeout });
const activity = pattern => page.waitForFunction(p => new RegExp(p).test(document.querySelector('#activity-text').textContent), pattern, { timeout: 20000 });
const generate = async prompt => { await page.locator('#prompt').fill(prompt); await page.keyboard.press('Control+Enter'); };
const toastSays = text => page.waitForFunction(t => document.querySelector('#toast-text').textContent === t, text, { timeout: 10000 });
// After sending, everything chosen in the edit panel stays; a result that does not open by itself is offered by a message.
// A message from before is forgotten just before sending, so that the wait is for the one this request brings.
const forgetToast = () => page.evaluate(() => { document.querySelector('#toast-text').textContent = ''; });
const openResult = async (text, label, timeout = 20000) => {
  await page.waitForFunction(t => document.querySelector('#toast-text').textContent === t && !document.querySelector('#toast-action').hidden, text, { timeout });
  await page.locator('#toast-action').click();
  await versionLabel(label, timeout);
};
// The library toolbar: the scope menu picks the library, an album or the trash; the view menu, the order and grouping.
const scope = async label => { await page.locator('#scope-button').click(); await page.locator('#scope-menu .menu-item', { hasText: label }).click(); };
const viewMenu = async label => { await page.locator('#view-button').click(); await page.locator('#view-menu .menu-item', { hasText: label }).click(); };
const tileCount = n => page.waitForFunction(n => document.querySelectorAll('#feed .tile[data-id]').length === n, n, { timeout: 10000 });

const signIn = async name => {
  await page.locator('#auth-name').fill(name);
  await page.locator('#auth-password').fill(name === 'dono' ? process.env.TEST_PASSWORD : 'work-password-long-enough');
  await page.locator('#auth-submit').click();
  await page.locator('#application').waitFor();
};
await page.goto(base);
await signIn('dono');
assert.equal(await page.locator('#account-name').textContent(), 'dono');
await page.locator('#feed-empty').getByText('Nenhuma imagem ainda').waitFor();
await shot('studio-desktop.png');
// The new-image column shows everything it has, with the Gerar button in view, without scrolling: on a large screen,
// on a short laptop and on an upright tablet. Its buttons are a grid under the prompt box, not inside it.
const composerFits = () => page.evaluate(() => {
  const body = document.querySelector('.composer-body');
  return document.querySelector('#generate').getBoundingClientRect().bottom <= innerHeight && body.scrollHeight <= body.clientHeight + 1 && scrollY === 0;
});
for (const [width, height] of [[1366, 680], [820, 1180], [1440, 900]]) {
  await page.setViewportSize({ width, height });
  assert.equal(await composerFits(), true, `the whole form is in view at ${width} × ${height}`);
}
assert.deepEqual((await page.locator('.composer .tools .tool').allTextContents()).map(t => t.trim()), ['Arquivo', 'Galeria', 'Variações', 'Vídeo']);
assert.equal(await page.locator('#drop-area button').count(), 0, 'no button inside the prompt box');

// Simple mode: one quality level sets image quality, complexity and prompt planning together.
const usd = text => Number(/US\$ ([\d.,]+)/.exec(text)[1].replace(/\./g, '').replace(',', '.'));
const generateCost = () => page.locator('#generate-cost').textContent();
const pressedLevel = panel => page.evaluate(s => document.querySelector(`${s} .preset[aria-pressed="true"]`)?.dataset.preset ?? null, panel);
const qualityFields = () => page.evaluate(() => ['#model', '#quality', '#complexity', '#thinking'].map(s => document.querySelector(s).value));
assert.equal(await page.locator('#model').isHidden(), true, 'the simple mode hides the detailed fields');
assert.equal(await pressedLevel('#presets'), 'high', 'new installs start on Alta');
assert.deepEqual(await qualityFields(), ['gpt-image-2.6-flare', 'high', 'high', 'medium']);
assert.equal(await page.locator('#preset-summary').textContent(), 'GPT Image 2.6 · Flare, qualidade alta, complexidade alta, planejamento do prompt com esforço médio.');
assert.match(await generateCost(), /\+ planejamento$/, 'Alta plans the prompt');
const altaCost = usd(await generateCost());
await page.locator('#presets [data-preset="max"]').click();
assert.deepEqual(await qualityFields(), ['gpt-image-2.6-flare', 'max', 'high', 'high']);
assert.ok(usd(await generateCost()) > altaCost, 'Máxima costs more than Alta');
await page.locator('#presets [data-preset="fast"]').click();
assert.deepEqual(await qualityFields(), ['gpt-image-2.6-flare', 'low', 'low', 'off']);
assert.match(await generateCost(), /^Custo estimado: ≈ US\$ 0,\d{3}$/, 'Rápida does not plan the prompt');
await page.locator('#more-options summary').click();
assert.equal(await page.locator('#thinking').isHidden(), true, 'planning follows the level in the simple mode');
await page.locator('#more-options summary').click();
// The advanced mode shows the same fields; a combination set by hand shows as custom in the simple mode.
await page.locator('#advanced-mode').check({ force: true });
assert.equal(await page.locator('#presets').isHidden(), true);
assert.equal(await page.locator('#quality-title').textContent(), 'Modelo e qualidade');
assert.equal(await page.locator('#quality').inputValue(), 'low');
await page.locator('#more-options summary').click();
await page.locator('#thinking').selectOption('xhigh');
await page.locator('#advanced-mode').uncheck({ force: true });
assert.equal(await pressedLevel('#presets'), null);
assert.match(await page.locator('#preset-summary').textContent(), /^Personalizada no modo avançado: .*esforço extra alto\.$/);
// Levels adapt to the model: GPT Image 2 has neither the extended qualities nor complexity.
await page.locator('#advanced-mode').check({ force: true });
await page.locator('#model').selectOption('gpt-image-2');
await page.locator('#advanced-mode').uncheck({ force: true });
await page.locator('#presets [data-preset="max"]').click();
assert.deepEqual(await qualityFields(), ['gpt-image-2', 'high', 'high', 'high']);
assert.equal(await pressedLevel('#presets'), 'max');
assert.equal(await page.locator('#preset-summary').textContent(), 'GPT Image 2, qualidade alta, planejamento do prompt com esforço alto.');
// The checks below use the advanced mode, without prompt planning.
await page.locator('#advanced-mode').check({ force: true });
await page.locator('#model').selectOption('gpt-image-2.6-flare');
await page.locator('#thinking').selectOption('off');
await page.locator('#more-options summary').click();

// Composer: ratios follow the model's capabilities; incompatible combinations correct themselves.
for (const ratio of ['2:3', '3:2', '4:3', '3:4', '16:9', '9:16', '4:5', '5:4', '3:1', '1:3', '1:1']) {
  const button = page.locator(`[data-ratio="${ratio}"]`); await button.click();
  assert.equal(await button.getAttribute('aria-pressed'), 'true');
}
await page.locator('[data-ratio="4:3"]').click();
await page.locator('#model').selectOption('gpt-image-1-mini');
assert.equal(await page.locator('[data-ratio="4:3"]').isDisabled(), true);
assert.equal(await page.locator('[data-ratio="1:1"]').getAttribute('aria-pressed'), 'true');
assert.equal(await page.locator('#complexity').isDisabled(), true);
await page.locator('#model').selectOption('gpt-image-2.6-flare');
await page.locator('#more-options summary').click();
await page.locator('#background').selectOption('transparent');
await page.locator('#format').selectOption('jpeg');
assert.equal(await page.locator('#background').inputValue(), 'opaque');
await page.locator('#format').selectOption('png');
await page.locator('#more-options summary').click();

// The cost estimate follows the options.
assert.match(await generateCost(), /^Custo estimado: ≈ US\$ 0,\d{3}$/);
const highCost = usd(await generateCost());
await page.locator('#quality').selectOption('low');
assert.ok(usd(await generateCost()) < highCost, 'lower quality costs less');
await page.locator('#quantity').selectOption('3');
assert.match(await generateCost(), /\(3 imagens\) \+ planejamento$/, 'varied batches are planned');
await page.locator('#vary-box [data-vary="same"]').click();
assert.match(await generateCost(), /\(3 imagens\)$/);
await page.locator('#vary-box [data-vary="auto"]').click();
await page.locator('#quantity').selectOption('1');
await page.locator('#quality').selectOption('high');
assert.equal(usd(await generateCost()), highCost);

// Several generations at once, each with partial previews, none waiting for another.
fixtureSeconds = '5';
for (const color of ['verde', 'azul', 'rosa']) await generate(`Fixture de teste: círculo ${color}, fundo escuro.`);
await activity('3 gerando');
await page.locator('#feed .tile.job.has-preview').first().waitFor({ timeout: 15000 });
// Progress updates touch only what changed: no tile or day section is taken out and put back (that restarts
// the fade-in animation and made running requests blink on every poll).
await page.evaluate(() => {
  const seen = new WeakSet(document.querySelectorAll('#feed .tile, #feed section'));
  window.reinserted = [];
  new MutationObserver(records => {
    const added = records.flatMap(r => [...r.addedNodes]).filter(n => n.nodeType === 1);
    for (const node of added.flatMap(n => [n, ...n.querySelectorAll('.tile, section')])) {
      if (!node.matches('.tile, section')) continue;
      if (seen.has(node)) window.reinserted.push(`${node.className} ${node.dataset.id || node.dataset.job || ''}`.trim());
      seen.add(node);
    }
  }).observe(document.querySelector('#feed'), { childList: true, subtree: true });
});
await shot('parallel-desktop.png');
await page.waitForFunction(() => !document.querySelector('#feed .tile.job') && document.querySelectorAll('#feed .tile[data-id]').length === 3, null, { timeout: 30000 });
assert.deepEqual(await page.evaluate(() => window.reinserted), [], 'tiles must stay in place while requests run');
assert.deepEqual(creations.map(c => [c.options.n, c.options.previews, c.parent_id]), [[1, 2, undefined], [1, 2, undefined], [1, 2, undefined]]);
fixtureSeconds = '0';

// Editing straight from the gallery: each result becomes a linked version.
const v1 = await imageTiles.first().getAttribute('data-id');
await imageTiles.first().locator('.tile-open').click();
await page.locator('#viewer[open]').waitFor();
assert.equal(new URL(page.url()).hash, `#v/${v1}`);
// Real cost from the reported usage (fixture: 1,756 output and 44 text tokens at GPT Image 2.6 rates), and an estimate for the edit.
await page.locator('#info-details dd').filter({ hasText: /^US\$ 0,053$/ }).waitFor();
assert.match(await page.locator('#edit-cost').textContent(), /^Custo estimado: ≈ US\$ 0,\d{3}$/);
fixtureSeconds = '4';
await page.locator('#edit-prompt').fill('Deixe o céu roxo');
await page.locator('#edit-submit').click();
// A running edit opens its details from the version panel: the instruction can be read and copied while it runs.
await page.locator('#versions-list .vnode.pending').click();
await page.locator('#job-dialog[open]').waitFor();
assert.equal(await page.locator('#job-title').textContent(), 'Edição');
assert.equal(await page.locator('#job-prompt-heading').textContent(), 'Alteração pedida');
assert.equal(await page.locator('#job-prompt').textContent(), 'Deixe o céu roxo');
assert.equal(await page.locator('#job-typed-wrap').isHidden(), true, 'a plain edit has no separate text: its prompt is what was written');
await page.locator('#job-visual img.preview').waitFor();
await page.locator('#job-actions').getByRole('button', { name: 'Usar na edição' }).waitFor();
await page.locator('#job-details dd').filter({ hasText: /^1024 × 1024$/ }).waitFor();
await page.locator('#job-copy').click();
assert.equal(await page.evaluate(() => navigator.clipboard.readText()), 'Deixe o céu roxo');
// The details follow the request to its end and lead to the new version.
await page.locator('#job-phase').filter({ hasText: /^Pronta$/ }).waitFor({ timeout: 20000 });
await page.locator('#job-details dd').filter({ hasText: CLEANED_NOTE }).waitFor();
await page.locator('#job-actions').getByRole('button', { name: 'Ver imagem' }).click();
await page.locator('#job-dialog').waitFor({ state: 'hidden' });
await versionLabel('v2 de 2');
fixtureSeconds = '0';
let request = creations.at(-1);
assert.equal(request.parent_id, v1); assert.equal(request.operation, 'edit');
assert.ok(request.references.length === 1 && cleanedSource(request.references[0], v1), 'the edited image goes cleaned in the browser, linked to the image it came from');
assert.equal(request.options.size, '1024x1024');
assert.equal(await page.locator('#edit-prompt').inputValue(), 'Deixe o céu roxo', 'the instruction stays in the box after it is sent');
const v2 = new URL(page.url()).hash.slice(3);
// The details of the new version say that the image went cleaned and that the prompt asked for the focus back.
await page.locator('#info-details dd').filter({ hasText: CLEANED_NOTE }).waitFor();
// The prompt as it went to the API is in the details too: it opens with the notice and closes with the reminder.
assert.equal(await page.locator('#sent-wrap').isVisible(), true);
const sentPrompt = await page.locator('#info-sent').textContent();
assert.ok(sentPrompt.startsWith('About the input image, before anything else: it was blurred on purpose. Before sending it, we ran a medium smoothing filter'), 'the prompt sent opens with the notice');
assert.ok(sentPrompt.includes('\n\nDeixe o céu roxo\n\nThe rules below apply only to what the request above does not ask to change.') && sentPrompt.includes('\n\nReminder about the input image: its softness comes from the smoothing filter'), 'the request, the guard and the reminder follow');

// Zoom and pan: the wheel zooms around the pointer, dragging moves the picture, 0 and double-click fit it again.
const frameRatio = (x, y) => page.locator('#stage-frame').evaluate((el, [x, y]) => { const r = el.getBoundingClientRect(); return [(x - r.left) / r.width, (y - r.top) / r.height]; }, [x, y]);
const frameMatrix = () => page.locator('#stage-frame').evaluate(el => { const m = new DOMMatrix(getComputedStyle(el).transform); return { scale: m.a, x: m.e, y: m.f }; });
const stageBox = await page.locator('#stage').boundingBox();
const pointer = { x: stageBox.x + stageBox.width / 2 + 90, y: stageBox.y + stageBox.height / 2 + 30 };
const under = await frameRatio(pointer.x, pointer.y);
await page.mouse.move(pointer.x, pointer.y);
await page.mouse.wheel(0, -500);
await page.locator('#zoom-reset').waitFor();
let zoomState = await frameMatrix();
assert.ok(zoomState.scale > 1.8, `wheel zooms in (${zoomState.scale})`);
const stillUnder = await frameRatio(pointer.x, pointer.y);
assert.ok(Math.abs(stillUnder[0] - under[0]) < 0.01 && Math.abs(stillUnder[1] - under[1]) < 0.01, 'the point under the pointer stays put');
await page.mouse.down(); await page.mouse.move(pointer.x - 40, pointer.y - 20, { steps: 4 }); await page.mouse.up();
const moved = await frameMatrix();
assert.ok(Math.abs(moved.x - zoomState.x + 40) < 1 && Math.abs(moved.y - zoomState.y + 20) < 1, 'dragging pans the picture');
await page.keyboard.press('0');
await page.locator('#zoom-reset').waitFor({ state: 'hidden' });
await page.mouse.dblclick(pointer.x, pointer.y);
await page.locator('#zoom-reset').waitFor();
await page.locator('#zoom-reset').click();
await page.locator('#zoom-reset').waitFor({ state: 'hidden' });
await page.mouse.move(pointer.x, pointer.y);
await page.mouse.wheel(0, -300);
await page.locator('#zoom-reset').waitFor();

// Masked edit: paint over the image itself, here while zoomed in.
await page.locator('#brush-toggle').click();
const canvas = await page.locator('#mask-canvas').boundingBox();
// The brush's circle: on the stage only while the brush is, under the pointer, as wide as the stroke (screen pixels at any zoom).
const brushCircle = () => page.evaluate(() => { const c = document.querySelector('#brush-cursor'), b = c.getBoundingClientRect(); return { shown: !c.hidden, x: b.left + b.width / 2, y: b.top + b.height / 2, size: b.width }; });
assert.equal((await brushCircle()).shown, false, 'no circle before the pointer is over the picture');
await page.mouse.move(canvas.x + canvas.width / 2, canvas.y + canvas.height / 2);
let circle = await brushCircle();
assert.equal(circle.shown, true, 'the circle of the brush follows the pointer');
assert.ok(Math.abs(circle.x - (canvas.x + canvas.width / 2)) <= 1 && Math.abs(circle.y - (canvas.y + canvas.height / 2)) <= 1, 'it is centered on the pointer');
assert.equal(circle.size, Number(await page.locator('#brush-size').inputValue()), 'and is as wide as the brush');
await page.mouse.down();
await page.mouse.move(canvas.x + canvas.width / 2 + 60, canvas.y + canvas.height / 2 + 40, { steps: 8 }); await page.mouse.up();
circle = await brushCircle();
assert.ok(circle.shown && Math.abs(circle.x - (canvas.x + canvas.width / 2 + 60)) <= 1 && Math.abs(circle.y - (canvas.y + canvas.height / 2 + 40)) <= 1, 'it goes along with the stroke');
// Changing the size shows the new circle in the middle of the picture for a moment.
await page.locator('#brush-size').fill('120');
circle = await brushCircle();
assert.deepEqual([circle.shown, circle.size], [true, 120]);
await page.locator('#brush-size').fill('56');
// The stroke lands under the pointer: sample the mask where the stroke began.
const strokeStart = await page.evaluate(([x, y]) => {
  const c = document.querySelector('#mask-canvas'), r = c.getBoundingClientRect();
  return c.getContext('2d').getImageData(Math.floor((x - r.left) / r.width * c.width), Math.floor((y - r.top) / r.height * c.height), 1, 1).data[3];
}, [canvas.x + canvas.width / 2, canvas.y + canvas.height / 2]);
assert.ok(strokeStart > 0, 'the brush paints under the pointer while zoomed');
await page.locator('#mask-done').click();
await page.locator('#mask-chip').waitFor();
assert.equal((await brushCircle()).shown, false, 'the circle leaves with the brush');
assert.equal(await page.locator('#edit-submit-label').textContent(), 'Refazer área', 'with an area marked the button redoes that area');
await page.locator('#edit-prompt').fill('Troque só a área marcada por estrelas');
await forgetToast();
await page.keyboard.press('Control+Enter');
// The painted area stays after sending, to send again; the result is offered instead of opening.
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Edição pronta.', null, { timeout: 20000 });
assert.deepEqual([await page.locator('#mask-chip').isVisible(), await page.locator('#edit-submit-label').textContent(), new URL(page.url()).hash.slice(3)], [true, 'Refazer área', v2], 'the painted area stays after sending');
await openResult('Edição pronta.', 'v3 de 3');
request = creations.at(-1);
assert.equal(request.parent_id, v2); assert.match(request.mask, /^data:image\/png;base64,/);
assert.equal(request.prompt, 'Troque só a área marcada por estrelas', 'what was written says what the area becomes');
// "Limpar antes de enviar" is on here, yet the image goes as it is: what is outside the area comes back as it was sent.
assert.deepEqual(request.references, [{ id: v2 }], 'with an area marked the image is not cleaned before it is sent');
assert.equal(await page.locator('#info-details dt').filter({ hasText: /^Antes de enviar$/ }).count(), 0, 'nor do its details say so');
assert.equal((await page.locator('#info-sent').textContent()).includes('blurred on purpose'), false, 'and the prompt sent has no notice');
assert.equal(await page.locator('#mask-chip').isHidden(), true);
assert.equal(await page.locator('#edit-submit-label').textContent(), 'Gerar edição');

// A second edit of v1 forks the tree.
await page.locator('#versions-list .vnode').filter({ has: page.locator('.vnum', { hasText: /^v1$/ }) }).click();
await page.waitForFunction(id => location.hash === `#v/${id}`, v1);
await page.locator('#edit-prompt').fill('Versão noturna');
await page.locator('#edit-submit').click();
await versionLabel('v4 de 4');
assert.equal(await page.locator('#versions-list .tree .fork').count(), 2);
await page.locator('[data-versions="time"]').click();
assert.deepEqual(await page.locator('#versions-list .timeline .vnum').allTextContents(), ['v1', 'v2', 'v3', 'v4']);
assert.match(await page.locator('#versions-list .timeline .vnode').nth(3).locator('.vop').textContent(), /de v1$/);
// The chronological list stays in the versions panel (it once took the style of the gallery's date rail).
const chronoBox = await page.locator('#versions-list .timeline').boundingBox(), versionsBox = await page.locator('#versions-aside').boundingBox();
assert.ok(chronoBox.x >= versionsBox.x - 1 && chronoBox.x + chronoBox.width <= versionsBox.x + versionsBox.width + 1, 'the chronological list stays in the versions panel');
await page.locator('[data-versions="tree"]').click();
const v4 = new URL(page.url()).hash.slice(3);

// Compare with the version it came from, and edit the saved text. Names are never shown.
await page.locator('#compare').click();
assert.equal(await page.locator('#compare').getAttribute('aria-pressed'), 'true');
await page.locator('#compare').click();
assert.equal(await page.locator('#viewer-title').count(), 0);
await page.locator('#prompt-edit').click();
await page.locator('#detail-prompt').fill('Texto revisado');
await page.locator('#prompt-save').click();
await page.locator('#info-prompt').getByText('Texto revisado').waitFor();

// Expansion: drag a corner, then type exact dimensions. Every request of the grid under the box uses the same box
// and the same button: the panel under the grid says what the text will do, here with the text of the last edit.
await page.locator('#expand-open').click();
await page.locator('#expand-options').waitFor();
assert.deepEqual([await page.locator('#action-title').textContent(), await page.locator('#edit-submit-label').textContent()], ['Recortar e expandir', 'Expandir']);
assert.equal(await page.locator('#expand-open').getAttribute('aria-pressed'), 'true');
assert.equal(await page.locator('#edit-prompt').inputValue(), 'Versão noturna');
assert.equal(await page.locator('#action-note').textContent(), 'O texto da caixa diz o que deve aparecer nas áreas novas.');
assert.deepEqual([await page.locator('#edit-quality-block').isHidden(), await page.locator('#edit-quantity').isHidden(), await page.locator('#brush-toggle').isDisabled()], [true, true, true], 'only what the expansion uses is shown');
await page.locator('#edit-clear').click();
assert.equal(await page.locator('#edit-prompt').inputValue(), '');
assert.equal(await page.locator('#action-note').isHidden(), true);
assert.equal(await page.locator('#edit-prompt').getAttribute('placeholder'), 'O que deve aparecer nas áreas novas (opcional). Em branco, a cena continua.');
assert.equal(await page.locator('#expand-dimensions').textContent(), 'A área quadriculada é gerada.');
const startWidth = Number(await page.locator('#expand-width').inputValue());
const corner = await page.locator('[data-corner="se"]').boundingBox();
await page.mouse.move(corner.x + corner.width / 2, corner.y + corner.height / 2); await page.mouse.down();
await page.mouse.move(corner.x + corner.width / 2 + 30, corner.y + corner.height / 2 + 20, { steps: 5 }); await page.mouse.up();
assert(Number(await page.locator('#expand-width').inputValue()) > startWidth);
await page.locator('#expand-width').fill('2048'); await page.locator('#expand-width').blur();
await page.locator('#expand-height').fill('1536'); await page.locator('#expand-height').blur();
await page.locator('#expand-center').click();
await shot('expand-desktop.png');
await forgetToast();
await page.locator('#edit-submit').click();
// Sent, the frame stays on the image it was drawn on, to send again; the result is offered by a message.
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Expansão pronta.', null, { timeout: 20000 });
assert.deepEqual([await page.locator('#action-panel').isVisible(), await page.locator('#expand-stage').isVisible(), await page.locator('#expand-width').inputValue(), await page.locator('#expand-height').inputValue()], [true, true, '2048', '1536'], 'once sent, the frame stays as it was');
await openResult('Expansão pronta.', 'v5 de 5');
assert.equal(await page.locator('#action-panel').isHidden(), true, 'opening the result leaves the frame, which was about the image before');
assert.equal(await page.locator('#expand-stage').isHidden(), true);
request = creations.at(-1);
assert.equal(request.operation, 'expand'); assert.equal(request.parent_id, v4); assert.equal(request.references[0].source_id, v4);
assert.equal(request.options.size, '2048x1536'); assert.equal(request.options.n, 1);
assert.match(request.prompt, /Continue the scene, lighting/); assert.equal(request.prompt.includes('Instructions for the new areas:'), false);
assert.match(request.mask, /^data:image\/png;base64,/);
await shot('viewer-desktop.png');

// Downloads need the session.
const download = new URL(await page.locator('#viewer-download').getAttribute('href'), base + '/').href;
assert.equal((await context.request.get(download)).status(), 200);
const anonymous = await browser.newContext({ locale: 'pt-BR' }); assert.equal((await anonymous.request.get(download)).status(), 401); await anonymous.close();

// Upscale: pick a larger size; the result is another version.
const v5 = new URL(page.url()).hash.slice(3);
await page.locator('#upscale-open').click();
await page.locator('#upscale-options').waitFor();
assert.deepEqual([await page.locator('#action-title').textContent(), await page.locator('#edit-submit-label').textContent()], ['Ampliar', 'Ampliar']);
assert.deepEqual(await page.locator('#upscale-sizes .size-option strong').allTextContents(), ['1,5×', 'Máxima']);
await page.locator('#upscale-sizes .size-option').filter({ hasText: '1,5×' }).click();
assert.equal(await page.locator('#upscale-sizes .size-option[aria-pressed="true"]').textContent(), '1,5×3072 × 2304 · 7,1 MP');
await page.locator('#edit-prompt').fill('mais nitidez nas bordas');
assert.equal(await page.locator('#action-note').textContent(), 'O texto da caixa vai junto, como melhoria pedida.');
// The Melhorar cleanup, or the Fotorrealista prompt, can go along with the upscale.
const upscaleWith = () => page.locator('#upscale-with button[aria-pressed="true"]').textContent();
assert.equal(await upscaleWith(), 'Só ampliar');
const plainUpscaleCost = await page.locator('#edit-cost').textContent();
await page.locator('#upscale-with button').filter({ hasText: 'Fotorrealista' }).click();
assert.equal(await page.locator('#action-note').textContent(), 'O texto da caixa vai junto como observação do Fotorrealista e tem prioridade, mesmo que mude algo.');
// Fotorrealista at the larger size: its own prompt, opened by the upscale line, with the box as notes that have priority.
// The server refuses this one here, so it makes no version.
await page.route('**/index.php?action=create', route => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ error: 'Pedido de teste recusado.' }) }));
const createdBefore = creations.length;
await page.locator('#edit-submit').click();
await page.locator('#edit-error').filter({ hasText: 'Pedido de teste recusado.' }).waitFor({ timeout: 90000 });
await page.unroute('**/index.php?action=create');
assert.equal(creations.length, createdBefore + 1);
request = creations.at(-1);
assert.equal(request.operation, 'upscale'); assert.equal(request.options.size, '3072x2304'); assert.equal(request.options.thinking, 'off');
assert.equal(request.label, 'Ampliada para 3072 × 2304, fotorrealista: mais nitidez nas bordas');
assert.equal(request.typed, 'mais nitidez nas bordas', 'what was written goes apart from the whole prompt');
assert.ok(request.references.length === 1 && cleanedSource(request.references[0], v5));
assert.match(request.prompt, /^The user's own request for this image\. It has priority over everything else in this prompt:\nmais nitidez nas bordas\n\n/);
assert.match(request.prompt, /\n\nThis is also an upscale: the result is larger than the attached image\. Use the extra resolution for a clean, natural photograph at that size.*\n\nTurn this image into an ultra-realistic photograph/s);
assert.match(request.prompt, /\nPreserve exactly, apart from what the user's request above changes:\n/);
assert.match(request.prompt, /\n\nReminder: the user's request comes first and must be clearly visible in the result:\nmais nitidez nas bordas$/);
assert.equal(request.prompt.includes('Recreate this same image'), false, 'the plain upscale instruction is not mixed in');
await page.locator('#upscale-with button').filter({ hasText: 'Melhorar' }).click();
assert.equal(await upscaleWith(), 'Melhorar');
assert.equal(await page.locator('#action-note').textContent(), 'O texto da caixa vai junto, como melhoria pedida.');
assert.match(await page.locator('#edit-cost').textContent(), /^Custo estimado: ≈ US\$ 0,\d{3}$/);
await shot('upscale-desktop.png');
await forgetToast();
await page.keyboard.press('Control+Enter');
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Ampliação pronta.', null, { timeout: 90000 });
assert.deepEqual([await page.locator('#action-title').textContent(), await upscaleWith(), await page.locator('#upscale-sizes .size-option[aria-pressed="true"] strong').textContent()], ['Ampliar', 'Melhorar', '1,5×'], 'once sent, Ampliar stays chosen with its size');
await openResult('Ampliação pronta.', 'v6 de 6');
request = creations.at(-1);
assert.equal(request.operation, 'upscale'); assert.equal(request.parent_id, v5);
assert.ok(request.references.length === 1 && cleanedSource(request.references[0], v5), 'an upscale starts from the cleaned image too');
assert.equal(request.options.size, '3072x2304'); assert.equal(request.options.thinking, 'off'); assert.equal(request.options.n, 1);
assert.match(request.prompt, /higher resolution.*\n\nAlso clean and improve it:\n- Do not reproduce the damage in the original: noise and grain.*Skin: smooth, even and natural.*Requested improvements: mais nitidez nas bordas$/s);
assert.equal(request.label, 'Ampliada para 3072 × 2304 e melhorada: mais nitidez nas bordas');
assert.equal(request.typed, 'mais nitidez nas bordas');
// The details show what was written above the rest, with its own Copiar.
assert.equal(await page.locator('#info-typed').textContent(), 'mais nitidez nas bordas');
assert.equal(await page.locator('#typed-wrap').isVisible(), true);
assert.equal(await page.locator('#prompt-heading').textContent(), 'Ampliação');
assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('imagegen.prefs.v1')).upscale.with), 'enhance', 'the choice is remembered');
assert.ok(plainUpscaleCost.length > 0);
assert.equal(await page.locator('#stage-meta').textContent(), '3072 × 2304');
// ↑ and ↓ go through the versions in the order the panel shows them; T shows only the picture, with the same keys.
await page.evaluate(() => document.activeElement?.blur());
await page.keyboard.press('ArrowUp');
await versionLabel('v5 de 6');
await page.keyboard.press('ArrowDown');
await versionLabel('v6 de 6');
await page.keyboard.press('t');
await page.waitForFunction(() => document.querySelector('#viewer').classList.contains('immersive'));
const chromeShown = edge => page.evaluate(e => document.querySelector('#viewer').classList.contains(`show-${e}`), edge);
const fullBox = await page.locator('#stage').boundingBox();
assert.deepEqual([fullBox.x, fullBox.y, fullBox.width, fullBox.height], [0, 0, 1440, 900], 'the picture has the whole screen');
await page.mouse.move(720, 450);
// The bar fades out over .22 s: wait for the end of the fade (a slow runner can still be mid-way after a fixed pause).
await page.waitForFunction(() => getComputedStyle(document.querySelector('.viewer-bar')).opacity === '0', null, { timeout: 5000 });
await shot('viewer-fullscreen.png');
await page.mouse.move(720, 4, { steps: 4 });
assert.equal(await chromeShown('top'), true, 'the bar comes back near the top edge');
await page.mouse.move(6, 450, { steps: 4 });
assert.equal(await chromeShown('left'), true, 'the edit panel comes back near the left edge');
await page.mouse.move(720, 450, { steps: 4 });
assert.equal(await chromeShown('left'), false);
await page.mouse.move(1434, 450, { steps: 4 });
assert.equal(await chromeShown('right'), true, 'the versions come back near the right edge');
await page.mouse.move(720, 450, { steps: 4 });
assert.equal(await chromeShown('right'), false);
await page.keyboard.press('ArrowUp');
await versionLabel('v5 de 6');
assert.equal(await page.evaluate(() => document.querySelector('#viewer').classList.contains('immersive')), true, 'full screen stays while moving');
// On a touch screen, swiping up brings the next version and swiping down the previous one; a sideways swipe moves
// through the gallery. Short or diagonal drags do nothing.
const touchSwipe = (dx, dy) => page.evaluate(([dx, dy]) => {
  const target = document.querySelector('#stage-frame'), r = target.getBoundingClientRect();
  const x = r.left + r.width / 2, y = r.top + r.height / 2, base = { pointerId: 7, pointerType: 'touch', isPrimary: true, bubbles: true, cancelable: true, composed: true };
  target.dispatchEvent(new PointerEvent('pointerdown', { ...base, clientX: x, clientY: y, button: 0, buttons: 1 }));
  target.dispatchEvent(new PointerEvent('pointermove', { ...base, clientX: x + dx / 2, clientY: y + dy / 2, buttons: 1 }));
  target.dispatchEvent(new PointerEvent('pointerup', { ...base, clientX: x + dx, clientY: y + dy, button: 0 }));
}, [dx, dy]);
await touchSwipe(0, -140);
await versionLabel('v6 de 6');
await touchSwipe(0, 140);
await versionLabel('v5 de 6');
await touchSwipe(30, -30);
await page.waitForTimeout(200);
await versionLabel('v5 de 6');
const currentHash = () => page.evaluate(() => location.hash);
const beforeSwipe = await currentHash();
await touchSwipe(-160, 10);
await page.waitForTimeout(500);
let swiped = await currentHash();
if (swiped === beforeSwipe) { await touchSwipe(160, 0); await page.waitForTimeout(500); swiped = await currentHash(); }
assert.notEqual(swiped, beforeSwipe, `a sideways swipe moves to another item (${await page.locator('#gallery .tile, .justified .tile').count()} tiles)`);
assert.equal(await page.evaluate(() => document.querySelector('#viewer').classList.contains('immersive')), true, 'full screen stays while swiping');
await page.keyboard.press('t');
await page.waitForFunction(() => !document.querySelector('#viewer').classList.contains('immersive'));
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.waitForFunction(() => location.hash === '');

// Configurações: the sections in groups on the left, one at a time, the keys first. The three keys are the same card
// with the same three buttons, whatever the provider: Remover and Testar need a saved key, Salvar a typed one.
await page.locator('#settings-open').click();
// At the top, the version the server runs (the newest one in CHANGELOG.md), linked to its notes on the Releases page.
const version = (await readFile('CHANGELOG.md', 'utf8')).match(/^## \[(\d+\.\d+\.\d+)\]/m)[1];
assert.deepEqual(await page.locator('#settings-version').evaluate(a => [a.textContent, a.href, a.target, a.title]),
  [`Versão ${version}`, `https://github.com/JohanKRS/atelier/releases/tag/v${version}`, '_blank', 'O que mudou nesta versão']);
assert.deepEqual(await page.locator('.settings-tab:not([hidden])').allTextContents(), ['Chaves de API', 'Saldos', 'Nome e senha', 'Acesso MCP', 'GPU', 'Sobre mim como leitor', 'Idioma', 'Usuários', 'Teste da hospedagem', 'Privacidade']);
assert.deepEqual(await page.locator('.settings-pane').evaluateAll(panes => panes.filter(p => !p.hidden).map(p => p.id)), ['settings-keys']);
assert.deepEqual(await page.locator('#settings-keys .key-card').evaluateAll(cards => cards.map(card => [card.querySelector('h4').textContent, ...[...card.querySelectorAll('.key-actions button')].map(b => b.textContent)])),
  [['OpenAI', 'Remover', 'Testar', 'Salvar'], ['Claude', 'Remover', 'Testar', 'Salvar'], ['Gemini', 'Remover', 'Testar', 'Salvar']]);
assert.deepEqual(await page.locator('#settings-keys .key-state').allTextContents(), ['Salva', 'Sem chave', 'Sem chave']);
assert.deepEqual(await page.locator('#settings-keys .key-actions button').evaluateAll(buttons => buttons.map(b => b.disabled)), [false, false, true, true, true, true, true, true, true]);
await page.locator('#api-key').fill('sk-typed');
assert.equal(await page.locator('#key-form [type=submit]').isDisabled(), false, 'a typed key can be saved');
await page.locator('#api-key').fill('');
// Testar: the key and its models, then a tiny request (simulated: no real API calls), each step with what it found.
await page.route('**/index.php?action=openai-test', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: false, steps: [
  { name: 'Chave e modelos', ok: true, detail: 'Chave aceita, com todos os modelos que o app usa.' },
  { name: 'Pedido de teste', ok: false, detail: 'Sem crédito na OpenAI: recarregue em platform.openai.com.' }] }) }));
await page.locator('#key-test').click();
await page.locator('#key-test-result p.bad').waitFor();
assert.deepEqual(await page.locator('#key-test-result p').evaluateAll(ps => ps.map(p => [p.className, p.textContent])),
  [['ok', 'Chave e modelos: Chave aceita, com todos os modelos que o app usa.'], ['bad', 'Pedido de teste: Sem crédito na OpenAI: recarregue em platform.openai.com.']]);
await shot('settings-keys.png');
await page.unroute('**/index.php?action=openai-test');

// API balance: entered in the settings, shown at the top, and lowered by each request at the cost of its tokens.
assert.equal(await page.locator('.balance-chip:not([hidden])').count(), 0, 'no balance until one is entered');
await page.locator('.settings-tab[data-pane=balance]').click();
// The three services, each with the same card: the balance, the monthly top-up, the day, Desligar and Salvar.
assert.deepEqual(await page.locator('#settings-balance .balance-card h4').allTextContents(), ['OpenAI', 'Claude', 'Gemini']);
assert.deepEqual(await page.locator('#settings-balance .balance-card').evaluateAll(cards => cards.map(c => [c.querySelectorAll('input').length, [...c.querySelectorAll('.key-actions button')].map(b => [b.textContent, b.disabled])])),
  Array(3).fill([3, [['Desligar', true], ['Salvar saldo', false]]]), 'the same fields and buttons, Desligar only for a balance that is on');
assert.deepEqual(await page.locator('#settings-balance .key-state').allTextContents(), ['Desligado', 'Desligado', 'Desligado']);
assert.deepEqual([await page.locator('#balance-amount').inputValue(), await page.locator('#balance-topup').inputValue(), await page.locator('#balance-day').inputValue()], ['', '100', '24']);
await page.locator('#balance-amount').fill('50');
await page.locator('#balance-form button[type=submit]').click();
await page.waitForFunction(() => document.querySelector('#balance-chip-openai .balance-text').textContent === 'US$ 50,00');
assert.match(await page.locator('#balance-status').textContent(), /: US\$ 50,00\. Desde então, US\$ 0,00 gastos aqui\. Saldo estimado agora: US\$ 50,00\.$/);
assert.deepEqual([await page.locator('#balance-state').textContent(), await page.locator('#balance-off').isEnabled()], ['US$ 50,00', true]);
await shot('settings-balance.png');
// Sobre mim como leitor: what the writer reads in every story (the administrator's first text is checked in
// http_checks.py, which leaves it empty). A change is saved with the account, and the text leaves the page when the
// window closes.
await page.locator('.settings-tab[data-pane=about]').click();
await page.waitForFunction(() => !document.querySelector('#about-text').disabled && document.querySelector('#about-status').textContent === '');
// Below it, the writer's own notes on the person's taste, which the person can correct.
const aboutSaved = await (await context.request.get(`${base}/index.php?action=reader-about`)).json();
assert.deepEqual([await page.locator('#about-text').inputValue(), await page.locator('#about-notes').inputValue()], [aboutSaved.text, aboutSaved.notes]);
assert.equal(await page.locator('#about-text').getAttribute('maxlength'), '2000');
await page.locator('#about-text').fill('Gosto de finais abertos.\nNada de epílogos.');
await page.locator('#about-notes').fill('- Gosta de diálogos secos.');
await page.locator('#about-form [type=submit]').click();
await toastSays('Salvo. O escritor lê isto a partir da próxima mensagem.');
assert.deepEqual(await (await context.request.get(`${base}/index.php?action=reader-about`)).json(), { text: 'Gosto de finais abertos.\nNada de epílogos.', notes: '- Gosta de diálogos secos.', max: 2000 });
await shot('settings-about.png');
await page.locator('#settings-dialog [data-close]').click();
await page.waitForFunction(() => document.querySelector('#about-text').value === '' && document.querySelector('#about-notes').value === '');

// Fotorrealista: a click chooses it and says, under the grid, what it does; the notes are the text of the same box, and
// nothing is sent until the panel's button is pressed. The ready-made instruction goes as written (no planning); the
// result is a version.
await imageTiles.first().locator('.tile-open').click();
await versionLabel('v6 de 6');
const v6 = new URL(page.url()).hash.slice(3);
const sentBefore = creations.length;
await page.locator('#photoreal').click();
assert.equal(await page.locator('#action-panel').isVisible(), true);
assert.equal(await page.locator('#action-title').textContent(), 'Fotorrealista');
assert.equal(await page.locator('#edit-submit-label').textContent(), 'Transformar em foto');
assert.equal(await page.locator('#photoreal').getAttribute('aria-pressed'), 'true');
assert.equal(await page.locator('#edit-prompt').evaluate(el => el === document.activeElement), true, 'the box is ready to type in');
assert.deepEqual([await page.locator('#preset-before').isVisible(), await page.locator('#preset-levels').isHidden()], [true, true]);
assert.equal(await page.locator('#preset-before [aria-pressed="true"]').textContent(), 'Média', 'cleaning before sending starts on, at the middle level');
// The box still has what the last request sent, and the panel says that it would go along.
assert.equal(await page.locator('#edit-prompt').inputValue(), 'mais nitidez nas bordas');
assert.match(await page.locator('#action-note').textContent(), /^O texto da caixa vai junto como observação e tem prioridade/);
assert.equal(await page.locator('#edit-form textarea:visible').count(), 1, 'one box of text for every request');
assert.equal(await page.locator('#edit-form button[type=submit]:visible').count(), 1, 'and one button that sends it');
await page.locator('#edit-prompt').fill('foto de celular');
await shot('preset-panel.png');
// Escape goes back to a plain edit, not out of the viewer, and nothing was sent. The text stays in the box.
await page.keyboard.press('Escape');
assert.equal(await page.locator('#action-panel').isHidden(), true);
assert.equal(await page.locator('#viewer').evaluate(dialog => dialog.open), true);
assert.equal(await page.locator('#photoreal').getAttribute('aria-pressed'), 'false');
assert.equal(await page.locator('#edit-submit-label').textContent(), 'Gerar edição');
assert.equal(creations.length, sentBefore, 'choosing the request sends nothing');
await page.locator('#photoreal').click();
assert.equal(await page.locator('#edit-prompt').inputValue(), 'foto de celular');
await page.locator('#edit-clear').click();
assert.match(await page.locator('#action-note').textContent(), /^Se quiser, escreva observações na caixa acima\./);
await page.locator('#edit-submit').click();
// v6 is the 3072 × 2304 upscale: cleaning it in the browser takes a few seconds before the request leaves.
await versionLabel('v7 de 7', 90000);
request = creations.at(-1);
assert.equal(creations.length, sentBefore + 1);
assert.equal(request.operation, 'edit'); assert.equal(request.label, 'Fotorrealista'); assert.equal(request.parent_id, v6);
assert.equal(request.typed, '', 'nothing written goes as an empty text');
const v7 = new URL(page.url()).hash.slice(3);
assert.ok(request.references.length === 1 && cleanedSource(request.references[0], v6));
assert.equal(request.options.thinking, 'off'); assert.equal(request.options.vary, false);
assert.match(request.prompt, /^Turn this image into an ultra-realistic photograph: photograph this exact scene again with a real camera/);
assert.match(request.prompt, /not as a surface to copy.*The forms that the shading describes \(every muscle, fold, vein and contour\) stay exactly as they are/s);
assert.match(request.prompt, /Every person's identity: face, facial features/);
assert.match(request.prompt, /Anatomy, exactly as in the original:.*the musculature \(the size, shape, position and definition of every muscle\)/);
assert.match(request.prompt, /Real light: it comes from the sources the scene has/);
// Straight out of the camera, with a camera and lens that fit the framing, and hair as a camera records it.
assert.match(request.prompt, /The result is a straight-out-of-camera photo: a single unedited frame, exactly as the camera recorded it/);
assert.match(request.prompt, /\n- Camera: a professional full-frame camera with a prime lens that fits the framing, such as an 85mm for a close portrait, a 50mm for half or full body and a 35mm for a wider scene.*If the user's request asks for a phone photo or another camera, use that instead\./);
assert.match(request.prompt, /\n- Real hair, as a camera records it: a mass made of many individual strands.*Keep the same style, length, volume and color as the original\./);
// One skin for each person: a face that doesn't match the body is brought to match, with the face as the reference.
assert.match(request.prompt, /\n- One skin for each person: their face, neck, shoulders, arms, hands, torso and legs show the same skin tone, undertone, finish and quality/);
assert.match(request.prompt, /so that the face looks pasted onto another body\), make them match: the whole person takes the skin tone of their face/);
assert.match(request.prompt, /darker skin, a face whose tone, shine or texture differs from the body's,/);
assert.match(request.prompt, /skin that looks clean, freshly washed and dry, with a matte finish: no oil, sweat or wet shine.*unless the user's request asks for wet, sweaty or oiled skin/);
assert.match(request.prompt, /with one even matte finish and the same quality everywhere/);
// Pores only where the camera is close enough for real pores to show.
assert.match(request.prompt, /\n- Skin texture that fits the distance: pores show only when the camera is close enough for real pores to be seen \(a close-up of a face or of a detail\).*From a normal or wider distance \(half body, full body, a whole scene\) the skin reads smooth and even, without pores/s);
assert.match(request.prompt, /dots or pits on the skin, pores at a distance where they could not be seen,/);
assert.equal(/beach|sand|sunset|bikini/i.test(request.prompt), false, 'the instruction names no particular scene');
assert.match(await page.locator('#versions-list .vnode.current .vsub').textContent(), /^Fotorrealista/);
assert.match(request.prompt, /Remove all of the original's noise and grain, color speckles, compression and JPEG artifacts, scattered light or dark dots/);
// Correctly exposed, impossible to tell from a photo, and only the faint, even grain of a camera at low ISO.
assert.match(request.prompt, /Nobody should be able to tell it apart from a real photograph\./);
assert.match(request.prompt, /\n- Exposure: a correctly exposed photograph, as a good photographer sets it for the main subject/);
assert.match(request.prompt, /The only grain is the very faint, fine and even grain a real camera leaves at low ISO: the same over the whole frame and noticeable only when zoomed in, never dots, specks, blotches or a texture on the skin\./);
assert.match(request.prompt, /webs of fine lines/);
assert.equal(/visible pores|crisp|\bsharpen/i.test(request.prompt), false, 'no wording that invites extra texture');
assert.equal(request.prompt.endsWith('cinematic color grading, and any change to the anatomy, the musculature, the people or the layout.'), true, 'without notes the instruction goes as it is');
assert.match(request.prompt, /\nPreserve exactly, without exceptions:\n/);
assert.deepEqual([await page.locator('#action-panel').isVisible(), await page.locator('#photoreal').getAttribute('aria-pressed')], [true, 'true'], 'once sent, Fotorrealista stays chosen, also on the result that opened');
// Melhorar: the same picture cleaned up and at its best, in the same style. What is written in the box goes as notes,
// and Ctrl+Enter in the box sends it.
await page.locator('#enhance').click();
assert.equal(await page.locator('#action-title').textContent(), 'Melhorar');
assert.equal(await page.locator('#edit-submit-label').textContent(), 'Melhorar');
await page.locator('#edit-prompt').fill('olhos  mais definidos\n');
await page.keyboard.press('Control+Enter');
await versionLabel('v8 de 8', 90000);
request = creations.at(-1);
assert.equal(request.operation, 'edit'); assert.equal(request.label, 'Melhorada: olhos mais definidos'); assert.equal(request.options.thinking, 'off');
// What was written in the notes has priority over the ready-made instruction: it goes first, and again at the end.
assert.match(request.prompt, /^The user's own request for this image\. It has priority over everything else in this prompt:\nolhos mais definidos\n\nCarry that request out fully/);
assert.match(request.prompt, /the request wins for that part.*\n\nRestore and enhance this image without changing what it shows/s);
assert.match(request.prompt, /\nKeep exactly, apart from what the user's request above changes:\n/);
assert.match(request.prompt, /and any change to the people or the layout other than what the user's request asks for\.\n\nReminder:/);
assert.equal(request.prompt.includes('without exceptions'), false);
assert.match(request.prompt, /a photo stays a photo, an illustration stays an illustration/);
assert.match(request.prompt, /recreate the same picture from scratch.*not as a surface to copy/s);
assert.match(request.prompt, /Skin: smooth, even and natural.*never darker; no oily, wet or sweaty shine/s);
assert.match(request.prompt, /Moles, freckles, scars, veins, muscle definition and every real feature stay as they are/);
assert.match(request.prompt, /texture that fits the distance: pores only when the view is close enough for real pores to show \(a close-up of a face or of a detail\), and then fine and natural; from farther away the skin reads smooth and even, without pores;/);
assert.match(request.prompt, /\n- One skin for each person: the face, neck, body and limbs show the same skin tone, undertone, finish and quality.*the whole person takes the skin tone of their face, with one even matte finish/);
assert.match(request.prompt, /Skin: smooth, even and natural, clean, freshly washed and dry, with a matte finish \(only a faint, soft sheen where the light hits the highest points, unless the user's request asks for wet, sweaty or oiled skin\)/);
assert.match(request.prompt, /Hair: clean, natural strands with soft highlights, free of specks and flakes/);
assert.match(request.prompt, /\n\nReminder: the user's request comes first and must be clearly visible in the result:\nolhos mais definidos$/);
assert.equal(request.prompt.split('olhos mais definidos').length, 3, 'the notes are in the prompt twice');
assert.equal(request.prompt.includes('${'), false, 'the cleanup steps are written into the prompt');
assert.match(await page.locator('#versions-list .vnode.current .vsub').textContent(), /^Melhorada: olhos mais definidos/);
// "Limpar antes de enviar" is one setting, in the panel and under Mais opções. Off, the image goes by its id.
const v8 = new URL(page.url()).hash.slice(3);
// What was written in the box is kept apart from the whole prompt and shown above it, with its own Copiar.
assert.equal(request.typed, 'olhos mais definidos');
await page.locator('#typed-wrap').waitFor();
assert.equal(await page.locator('#info-typed').textContent(), 'olhos mais definidos');
assert.equal(await page.locator('#prompt-heading').textContent(), 'Prompt completo');
assert.match(await page.locator('#info-prompt').textContent(), /^The user's own request for this image/);
await page.evaluate(() => navigator.clipboard.writeText(''));
await page.locator('#typed-copy').click();
await page.waitForFunction(async () => await navigator.clipboard.readText() === 'olhos mais definidos');
await shot('typed-copy.png');
// Images from before it was kept apart: it is read back from the prompt, where the app put it.
await page.route('**/index.php?action=detail&*', async route => {
  const response = await route.fetch(); const body = await response.json(); delete body.image.typed;
  await route.fulfill({ response, json: body });
});
const versionAt = async (n, id) => {
  await page.locator('#versions-list .vnode').filter({ has: page.locator('.vnum', { hasText: new RegExp(`^v${n}$`) }) }).click();
  await page.waitForFunction(id => location.hash === `#v/${id}` && document.querySelector('#stage-loading').hidden, id);
};
await versionAt(6, v6);
assert.deepEqual([await page.locator('#prompt-heading').textContent(), await page.locator('#info-typed').textContent(), await page.locator('#typed-wrap').isVisible()], ['Ampliação', 'mais nitidez nas bordas', true]);
await versionAt(7, v7);
assert.deepEqual([await page.locator('#prompt-heading').textContent(), await page.locator('#typed-wrap').isHidden()], ['Prompt completo', true], 'Fotorrealista without notes has nothing written to copy');
await versionAt(8, v8);
assert.deepEqual([await page.locator('#prompt-heading').textContent(), await page.locator('#info-typed').textContent()], ['Prompt completo', 'olhos mais definidos']);
await page.unroute('**/index.php?action=detail&*');
assert.equal(await page.locator('#enhance').getAttribute('aria-pressed'), 'true', 'Melhorar stays chosen after sending');
await page.locator('#preset-before [data-before="off"]').click();
assert.equal(await page.locator('#edit-clean').inputValue(), 'off');
await page.route('**/index.php?action=create', route => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ error: 'Parado pelo teste.' }) }));
await page.locator('#edit-submit').click();
await page.locator('#edit-error').getByText('Parado pelo teste.').waitFor();
await page.unroute('**/index.php?action=create');
assert.deepEqual(creations.at(-1).references, [{ id: v8 }]);
assert.equal(await page.locator('#action-panel').isVisible(), true, 'a request that fails stays chosen');
await page.locator('#action-close').click();
assert.equal(await page.locator('#action-panel').isHidden(), true);
// Without text and without an area marked, a plain edit has nothing to send.
await page.locator('#edit-clear').click();
await page.locator('#edit-submit').click();
await page.locator('#edit-error').getByText('Descreva o que deve mudar, ou pinte uma área para refazer só ela.').waitFor();
await page.locator('#edit-form details.more summary').click();
await page.locator('#edit-clean').selectOption('media');
assert.equal(await page.locator('#preset-before [aria-pressed="true"]').getAttribute('data-before'), 'media');
await page.locator('#edit-form details.more summary').click();
// Both requests are paid from the balance: 1,756 output and 44 text tokens each at GPT Image 2.6 rates.
await page.waitForFunction(() => document.querySelector('#balance-chip-openai .balance-text').textContent === 'US$ 49,89');

// Cover: any version can stand for the image in the gallery; until one is chosen, the newest does.
assert.equal(await page.locator('#cover-note').textContent(), 'Na galeria aparece a versão mais recente (v8).');
await page.locator('#versions-list .vnode').filter({ has: page.locator('.vnum', { hasText: /^v2$/ }) }).click();
await page.waitForFunction(id => location.hash === `#v/${id}`, v2);
await page.locator('#cover-toggle').click();
await page.locator('#versions-list .vnode.current .vcover').waitFor();
assert.equal(await page.locator('#versions-list .vcover').count(), 1);
assert.equal(await page.locator('#cover-note').textContent(), 'Esta versão é a capa: é ela que aparece na galeria.');
await shot('viewer-cover.png');
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.waitForFunction(id => document.querySelector('#feed .tile[data-id]')?.dataset.id === id, v2);
await imageTiles.first().locator('.tile-open').click();
await page.waitForFunction(id => location.hash === `#v/${id}`, v2);
assert.equal(await page.locator('#cover-toggle').textContent(), 'Voltar para a mais recente');
await page.locator('#cover-toggle').click();
await page.waitForFunction(() => document.querySelector('#cover-note').textContent === 'Na galeria aparece a versão mais recente (v8).');
assert.equal(await page.locator('#versions-list .vcover').count(), 0);
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.waitForFunction(id => document.querySelector('#feed .tile[data-id]')?.dataset.id !== id, v2);

// Gallery: grouped versions, search, favorites.
assert.equal(await imageTiles.count(), 3);
assert.equal(await page.locator('#feed .tile-badge').filter({ hasText: '8' }).count(), 1);
await viewMenu('Agrupar versões');
await tileCount(10);
await viewMenu('Agrupar versões');
await tileCount(3);
await page.locator('#search').fill('roxo');
await page.waitForFunction(() => document.querySelector('#gallery-count').textContent === '1 resultado');
await page.locator('#search').fill('');
await page.waitForFunction(() => document.querySelectorAll('#feed .tile[data-id]').length === 3);
await imageTiles.first().locator('.tile-star').click();
await page.locator('#favorites-only').click();
await tileCount(1);
await page.locator('#favorites-only').click();
await tileCount(3);
// Favorites show each favorite version of an image on its own, and each one opens that version.
await imageTiles.first().locator('.tile-open').click();
await page.locator('#viewer[open]').waitFor();
await page.locator('#versions-list .vnode').filter({ has: page.locator('.vnum', { hasText: /^v1$/ }) }).click();
await page.waitForFunction(id => location.hash === `#v/${id}`, v1);
await page.waitForFunction(() => /^v1 de /.test(document.querySelector('#viewer-version').textContent));
await page.locator('#viewer-favorite').click();
await page.waitForFunction(() => document.querySelector('#viewer-favorite').getAttribute('aria-pressed') === 'true');
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.locator('#favorites-only').click();
await tileCount(2);
await page.locator(`#feed .tile[data-id="${v1}"] .tile-open`).click();
await page.waitForFunction(id => location.hash === `#v/${id}`, v1);
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.locator('#favorites-only').click();
await tileCount(3);

// Import, trash, restore, delete for good.
const imported = await (await context.request.get(download)).body();
await page.locator('#import-input').setInputFiles({ name: 'praia.png', mimeType: 'image/png', buffer: imported });
// A single imported image opens ready to edit.
await page.locator('#viewer[open]').waitFor();
await page.locator('#info-prompt').getByText('Imagem importada do computador.').waitFor();
assert.equal(await imageTiles.count(), 4);
await page.locator('#viewer-trash').click();
await page.locator('#viewer').waitFor({ state: 'hidden' });
await scope('Lixeira');
await page.locator('#feed .tile.trashed').waitFor();
await page.locator('#feed .tile.trashed').getByRole('button', { name: 'Restaurar' }).click();
await page.locator('#feed-empty').getByText('A lixeira está vazia').waitFor();
await scope('Biblioteca');
await tileCount(4);
await imageTiles.first().locator('.tile-open').click();
await page.locator('#viewer-trash').click();
await page.locator('#viewer').waitFor({ state: 'hidden' });
await scope('Lixeira');
await page.locator('#feed .tile.trashed').getByRole('button', { name: 'Excluir' }).click();
await page.locator('#confirm-yes').click();
await page.locator('#feed-empty').getByText('A lixeira está vazia').waitFor();
await scope('Biblioteca');
await tileCount(3);

// Ctrl+V: an image pasted on the page is imported; pasted into the prompt it becomes a reference.
const paste = (selector, bytes) => page.evaluate(([target, data]) => {
  const clipboard = new DataTransfer();
  clipboard.items.add(new File([Uint8Array.from(atob(data), c => c.charCodeAt(0))], 'image.png', { type: 'image/png' }));
  document.querySelector(target).dispatchEvent(new ClipboardEvent('paste', { clipboardData: clipboard, bubbles: true, cancelable: true }));
}, [selector, bytes.toString('base64')]);
await paste('#prompt', imported);
await page.locator('#reference-list .ref').waitFor();
assert.equal(await imageTiles.count(), 3);
await page.locator('#reference-list .ref .ref-remove').click();
await paste('body', imported);
await page.locator('#viewer[open]').waitFor();
assert.equal(await imageTiles.count(), 4);
await page.locator('#viewer-trash').click();
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.waitForFunction(() => document.querySelectorAll('#feed .tile[data-id]').length === 3);

// Clicking a running request's card opens its details, previews or not: prompt, options and status, then cancel.
await page.locator('#more-options summary').click();
await page.locator('#previews').selectOption('0');
await generate('Pedido LENTO sem prévias, aberto no cartão');
await activity('1 gerando');
await page.locator('#feed .tile.job .job-prompt').click();
await page.locator('#job-dialog[open]').waitFor();
assert.equal(await page.locator('#job-title').textContent(), 'Nova imagem');
assert.equal(await page.locator('#job-prompt').textContent(), 'Pedido LENTO sem prévias, aberto no cartão');
assert.match(await page.locator('#job-phase').textContent(), /^(Na fila|Iniciando|Planejando o prompt|Gerando)$/);
assert.equal(await page.locator('#job-visual img').count(), 0, 'no preview, no picture');
assert.equal(await page.locator('#job-visual .spinner').isVisible(), true);
await page.locator('#job-details dd').filter({ hasText: /^Desligadas$/ }).waitFor();
await page.locator('#job-phase').filter({ hasText: /^Gerando$/ }).waitFor({ timeout: 10000 });
await page.locator('#job-dialog').evaluate(d => Promise.all(d.getAnimations().map(a => a.finished)));
await shot('request-details.png');
await page.locator('#job-actions').getByRole('button', { name: 'Cancelar geração' }).click();
await page.locator('#confirm-yes').click();
await page.locator('#job-phase').filter({ hasText: /^Cancelado$/ }).waitFor({ timeout: 20000 });
assert.match(await page.locator('#job-error').textContent(), /^Cancelado\./);
await page.keyboard.press('Escape');
await page.locator('#job-dialog').waitFor({ state: 'hidden' });
// Closing takes the request off the page.
await page.waitForFunction(() => document.querySelector('#job-prompt').textContent === '' && !document.querySelector('#job-details').childElementCount);
await page.locator('#previews').selectOption('2');
await page.locator('#more-options summary').click();

// Cancelling a running request from its card, and a failure that can be dismissed.
await generate('Pedido LENTO para cancelar');
await activity('1 gerando');
await page.locator('#feed .tile.job').getByRole('button', { name: 'Cancelar pedido' }).click();
await page.locator('#confirm-yes').click();
await page.waitForFunction(() => !document.querySelector('#feed .tile.job'), null, { timeout: 20000 });
await page.locator('#requests-open').click();
await page.locator('#requests-list .request').first().getByText('Cancelado', { exact: true }).waitFor();
await page.locator('#requests-dialog [data-close]').click();
await generate('Isto vai FALHAR de propósito');
const failedCard = page.locator('#feed .tile.job.failed');
await failedCard.getByText('Falhou').waitFor({ timeout: 20000 });
const failedAt = Date.now();
// The × that cancels a running request dismisses a failed one: it is in the same place, and there is no other button for it.
assert.equal(await failedCard.locator('.job-status button.icon-btn.leave[aria-label="Dispensar pedido"]').count(), 1);
assert.deepEqual((await failedCard.locator('.job-actions button').allTextContents()).map(t => t.trim()), ['Repetir', 'Usar prompt']);
// The request leaves the gallery by itself after about ten seconds in front of the person. The ring around the × runs
// out with that time; the count waits while the pointer is on the card and while a window is over the gallery.
const timeLeft = () => failedCard.locator('[data-leaves]').evaluate(el => Number(el.style.getPropertyValue('--left')));
await page.waitForFunction(() => Number(document.querySelector('#feed .tile.job.failed [data-leaves]').style.getPropertyValue('--left')) < 0.95);
await failedCard.locator('.job-error').hover();
let heldAt = await timeLeft();
await page.waitForTimeout(1500);
assert.equal(await timeLeft(), heldAt, 'the count waits while the pointer is on the card');
await page.mouse.move(700, 20);
await page.locator('#requests-open').click();
await page.locator('#requests-list .request').first().getByText('Falhou', { exact: true }).waitFor();
heldAt = await timeLeft();
await page.waitForTimeout(1500);
assert.equal(await timeLeft(), heldAt, 'and while a window is over the gallery');
await page.locator('#requests-dialog [data-close]').click();
await page.waitForFunction(() => !document.querySelector('#feed .tile.job'), null, { timeout: 20000 });
assert.ok(Date.now() - failedAt >= 9000, 'a failed request stays about ten seconds before it leaves');
// It left the gallery, not the history: Pedidos still lists it, and it can be repeated from there.
assert.equal((await (await context.request.get(`${base}/index.php?action=jobs&scope=feed`)).json()).jobs.length, 0);
await page.locator('#requests-open').click();
const kept = page.locator('#requests-list .request').first();
await kept.getByText('Falhou', { exact: true }).waitFor();
assert.equal(await kept.locator('.request-prompt').textContent(), 'Isto vai FALHAR de propósito');
assert.equal(await kept.getByRole('button', { name: 'Repetir' }).count(), 1);
// The history searches the prompts (case and accents aside), and Escritor shows only the messages to the writer.
assert.equal(await page.locator('#requests-title').textContent(), 'Histórico');
await page.locator('#history-search').fill('falhar de PROPOSITO');
await page.waitForFunction(() => { const r = [...document.querySelectorAll('#requests-list .request-prompt')]; return r.length === 1 && r[0].textContent === 'Isto vai FALHAR de propósito'; });
// Melhorar: what was written is copied apart from the ready-made instruction, here and in the details of the request.
await page.locator('#history-search').fill('olhos mais definidos');
await page.waitForFunction(() => { const r = [...document.querySelectorAll('#requests-list .request')]; return r.length === 1 && r[0].querySelector('.request-label')?.textContent === 'Melhorada: olhos mais definidos'; });
await page.evaluate(() => navigator.clipboard.writeText(''));
await page.locator('#requests-list .request').getByRole('button', { name: 'Copiar meu texto' }).click();
await page.waitForFunction(async () => await navigator.clipboard.readText() === 'olhos mais definidos');
await page.locator('#requests-list .request .request-thumb').click();
await page.locator('#job-dialog[open]').waitFor();
assert.deepEqual([await page.locator('#job-prompt-heading').textContent(), await page.locator('#job-typed').textContent()], ['Prompt completo', 'olhos mais definidos']);
assert.match(await page.locator('#job-prompt').textContent(), /^The user's own request for this image/);
await page.evaluate(() => navigator.clipboard.writeText(''));
await page.locator('#job-typed-copy').click();
await page.waitForFunction(async () => await navigator.clipboard.readText() === 'olhos mais definidos');
await page.locator('#job-dialog [data-close]').click();
await page.locator('#job-dialog').waitFor({ state: 'hidden' });
await page.locator('#history-kind button[data-kind="writer"]').click();
await page.locator('#history-foot').getByText('Nada encontrado').waitFor();
await page.locator('#history-kind button[data-kind="all"]').click();
await page.locator('#history-search').fill('');
await page.waitForFunction(() => document.querySelectorAll('#requests-list .request').length > 1 && document.querySelector('#history-foot').textContent.includes('Fim do histórico'));
await page.locator('#requests-dialog [data-close]').click();
// The × dismisses at once.
await generate('Isto também vai FALHAR');
await failedCard.getByText('Falhou').waitFor({ timeout: 20000 });
const dismissedAt = Date.now();
await failedCard.locator('.job-status .leave').click();
await page.waitForFunction(() => !document.querySelector('#feed .tile.job'));
assert.ok(Date.now() - dismissedAt < 5000);
// No credit left at OpenAI: the request says so plainly, and a notice shown wherever the person is leads to the top-up page.
await generate('Isto vai dar SEMCREDITO');
await failedCard.getByText('Falhou').waitFor({ timeout: 20000 });
await toastSays('Sem crédito na OpenAI. Recarregue a conta da chave da API para voltar a gerar.');
assert.match(await failedCard.locator('.job-error').textContent(), /^Sem crédito na OpenAI: .*Settings → Billing/);
await context.route('https://platform.openai.com/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Billing</title>' }));
const [billingPage] = await Promise.all([context.waitForEvent('page'), page.locator('#toast-action').filter({ hasText: 'Recarregar' }).click()]);
await billingPage.waitForLoadState();
assert.equal(billingPage.url(), 'https://platform.openai.com/settings/organization/billing/overview');
await billingPage.close();
await context.unroute('https://platform.openai.com/**');
await failedCard.locator('.job-status .leave').click();
await page.waitForFunction(() => !document.querySelector('#feed .tile.job'));
// An edit that fails is listed among the versions of the image, with the same ×, and leaves that list by itself too.
// With the viewer open a notice tells about it, and opens the details of the request.
// Sent before the image's details arrive (they are held back here), the edit waits for them instead of going nowhere.
const slowDetail = url => url.searchParams.get('action') === 'detail';
await page.route(slowDetail, async route => { await new Promise(resolve => setTimeout(resolve, 1200)); await route.continue(); });
await imageTiles.first().locator('.tile-open').click();
await page.locator('#viewer[open]').waitFor();
await page.locator('#edit-prompt').fill('Esta edição vai FALHAR');
const editSent = page.waitForRequest(r => new URL(r.url()).searchParams.get('action') === 'create', { timeout: 60000 });
await page.keyboard.press('Control+Enter');
await page.unroute(slowDetail);
// The image is cleaned on this device before it goes ("Limpar antes de enviar"): on a slow machine that alone takes
// most of the 20 s the failure gets below (17 s with the processor slowed down ten times), so the wait starts once it left.
await editSent;
const failedEntry = page.locator('#versions-list .vnode.pending');
await failedEntry.locator('.vop').filter({ hasText: /^Falhou$/ }).waitFor({ timeout: 20000 });
assert.equal(await failedEntry.locator('button.icon-btn.leave[aria-label="Dispensar pedido"]').isVisible(), true);
await toastSays('Um pedido falhou.');
await page.locator('#toast-action').filter({ hasText: 'Detalhes' }).click();
await page.locator('#job-phase').filter({ hasText: /^Falhou$/ }).waitFor();
assert.equal(await page.locator('#job-prompt').textContent(), 'Esta edição vai FALHAR');
await page.locator('#job-dialog [data-close]').click();
await page.locator('#job-dialog').waitFor({ state: 'hidden' });
await page.waitForFunction(() => !document.querySelector('#versions-list .vnode.pending'), null, { timeout: 20000 });
await page.locator('#edit-prompt').fill('');
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
assert.equal(await page.locator('#feed .tile.job').count(), 0, 'it left the gallery with the list of versions');

// Several images: one request per image, each with its own card and previews.
fixtureSeconds = '3';
const tilesBefore = await imageTiles.count();
assert.equal(await page.locator('#vary-box').isHidden(), true, 'one image has nothing to vary');
// The Variações chip turns on four images varied by the app; the number can then be changed.
await page.locator('#vary-open').click();
assert.equal(await page.locator('#quantity').inputValue(), '4');
assert.equal(await page.locator('#vary-open').getAttribute('aria-pressed'), 'true');
assert.equal(await page.locator('#vary-box [data-vary="auto"]').getAttribute('aria-pressed'), 'true', 'batches vary by default');
assert.match(await page.locator('#vary-box [data-vary-hint]').textContent(), /direção diferente para cada uma das 4 imagens/);
await page.locator('#quantity').selectOption('2');
await generate('Duas imagens independentes');
await page.waitForFunction(() => document.querySelectorAll('#feed .tile.job').length === 2);
await activity('2 gerando');
await page.waitForFunction(n => !document.querySelector('#feed .tile.job') && document.querySelectorAll('#feed .tile[data-id]').length === n, tilesBefore + 2, { timeout: 30000 });
assert.equal(creations.at(-1).options.n, 2); assert.equal(creations.at(-1).options.vary, true);
await imageTiles.first().locator('.tile-open').click();
await page.locator('#info-details dd').filter({ hasText: /^[12] de 2$/ }).waitFor();
assert.match(await page.locator('#info-variation').textContent(), /^Cena [12]: variação simulada$/);
// Edits can be varied too, also from the Variações chip, up to 10 images.
assert.equal(await page.locator('#edit-vary-box').isHidden(), true);
await page.locator('#edit-vary-open').click();
assert.equal(await page.locator('#edit-quantity').inputValue(), '4');
assert.equal(await page.locator('#edit-vary-box [data-vary="auto"]').getAttribute('aria-pressed'), 'true');
assert.match(await page.locator('#edit-cost').textContent(), /\(4 imagens\) \+ planejamento$/);
await page.locator('#edit-quantity').selectOption('10');
// One line per image: each line becomes one image, and the number follows the lines.
assert.equal(await page.locator('#edit-vary-lines').isHidden(), true);
await page.locator('#edit-vary-box [data-vary="lines"]').click();
// The list has its own box, right under the prompt box and above the buttons.
const [promptEdge, linesEdge, toolsEdge] = await page.evaluate(() => ['#edit-drop', '#edit-vary-lines-box', '#edit-form .tools'].map(s => { const r = document.querySelector(s).getBoundingClientRect(); return [r.top, r.bottom]; }));
assert.ok(linesEdge[0] >= promptEdge[1] && linesEdge[0] - promptEdge[1] <= 16 && toolsEdge[0] >= linesEdge[1], 'the list of lines sits right under the prompt box');
await page.locator('#edit-vary-lines').fill('sentada na praia\n\n  correndo   na areia \nde costas');
assert.equal(await page.locator('#edit-quantity').inputValue(), '3');
assert.equal(await page.locator('#edit-quantity').isDisabled(), true, 'the number follows the lines');
assert.match(await page.locator('#edit-cost').textContent(), /\(3 imagens\)/, 'the cost follows the lines');
await page.route('**/index.php?action=create', route => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ error: 'Parado pelo teste.' }) }));
await page.locator('#edit-prompt').fill('Esta personagem');
await page.locator('#edit-submit').click();
await page.locator('#edit-error').getByText('Parado pelo teste.').waitFor();
await page.unroute('**/index.php?action=create');
assert.deepEqual(creations.at(-1).lines, ['sentada na praia', 'correndo na areia', 'de costas']);
assert.equal(creations.at(-1).options.n, 3);
await shot('variations-lines.png');
await page.locator('#edit-vary-lines').fill('só uma');
await page.locator('#edit-submit').click();
await page.locator('#edit-error').getByText('Escreva pelo menos 2 linhas, uma por imagem.').waitFor();
// The chip goes back to one image.
await page.locator('#edit-vary-open').click();
assert.equal(await page.locator('#edit-vary-box').isHidden(), true);
assert.equal(await page.locator('#edit-vary-lines').isHidden(), true);
assert.equal(await page.locator('#edit-quantity').inputValue(), '1');
assert.equal(await page.locator('#edit-quantity').isDisabled(), false);
await page.locator('#edit-prompt').fill('');
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.locator('#quantity').selectOption('1');
fixtureSeconds = '0';

// References from the gallery: several at once, cited in the prompt as @1, @2…
const picks = page.locator('#picker-grid .pick');
const pickMarks = () => picks.evaluateAll(els => els.map(el => el.querySelector('.pick-mark').textContent));
await page.locator('#gallery-refs').click();
await page.locator('#picker[open]').waitFor();
await page.waitForFunction(() => document.querySelectorAll('#picker-grid .pick').length === 5);
await picks.nth(2).click(); await picks.nth(0).click();
assert.deepEqual(await pickMarks(), ['@2', '', '@1', '', '']);
assert.equal(await page.locator('#picker-status').textContent(), '2 selecionadas. No prompt: @1 e @2.');
await page.locator('#picker-search').fill('roxo');
await page.waitForFunction(() => document.querySelectorAll('#picker-grid .pick').length === 1);
await page.locator('#picker-search').fill('');
await page.locator('label.switch:has(#picker-group)').click();
await page.waitForFunction(() => document.querySelectorAll('#picker-grid .pick').length === 12);
assert.equal((await pickMarks()).filter(Boolean).length, 2, 'the selection survives filtering');
await shot('picker-desktop.png');
await page.locator('#picker-done').click();
await page.locator('#picker').waitFor({ state: 'hidden' });
assert.deepEqual(await page.locator('#reference-list .ref-tag').allTextContents(), ['@1', '@2']);
// Typing @ lists the references; Enter or Tab cites the highlighted one.
const mentionMenu = page.locator('#prompt-mentions'), mentionOptions = mentionMenu.locator('.mention-option');
await page.locator('#prompt').fill('');
await page.locator('#prompt').pressSequentially('Personagem de @');
await mentionMenu.waitFor();
assert.deepEqual(await mentionOptions.locator('strong').allTextContents(), ['@1', '@2']);
assert.equal(await mentionOptions.count(), 4, 'the menu also offers the gallery and the computer');
await shot('mentions-desktop.png');
await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
await page.locator('#prompt').pressSequentially('no cenário de @1');
assert.equal(await mentionOptions.count(), 1);
await page.keyboard.press('Tab');
await mentionMenu.waitFor({ state: 'hidden' });
assert.equal(await page.locator('#prompt').inputValue(), 'Personagem de @2 no cenário de @1 ');
// Removing a reference renumbers the prompt; a mention of the removed image must be fixed before sending.
await page.locator('#reference-list .ref').first().locator('.ref-remove').click();
assert.equal(await page.locator('#prompt').inputValue(), 'Personagem de @1 no cenário de @? ');
await page.keyboard.press('Control+Enter');
assert.match(await page.locator('#generate-error').textContent(), /sem imagem \(@\?\)/);
await page.locator('#prompt').fill('Personagem de @1 no cenário de @3');
await page.keyboard.press('Control+Enter');
assert.equal(await page.locator('#generate-error').textContent(), 'Não há imagem @3. A única imagem é @1.');
// Choosing the gallery from the @ menu cites the images picked there.
await page.locator('#prompt').fill('Personagem de @1 no cenário de ');
await page.locator('#prompt').press('End');
await page.locator('#prompt').pressSequentially('@');
await mentionOptions.filter({ hasText: 'Escolher da galeria' }).click();
await page.locator('#picker[open]').waitFor();
await page.waitForFunction(() => document.querySelectorAll('#picker-grid .pick').length === 5);
assert.deepEqual(await pickMarks(), ['@1', '', '', '', ''], 'references already in the prompt start selected');
await picks.nth(1).click();
await page.locator('#picker-done').click();
assert.equal(await page.locator('#prompt').inputValue(), 'Personagem de @1 no cenário de @2 ');
const lineages = await imageTiles.count();
await page.keyboard.press('Control+Enter');
await page.waitForFunction(n => !document.querySelector('#feed .tile.job') && document.querySelectorAll('#feed .tile[data-id]').length === n, lineages + 1, { timeout: 30000 });
assert.equal(creations.at(-1).prompt, 'Personagem de @1 no cenário de @2');
assert.equal(creations.at(-1).references.length, 2);
assert.ok(creations.at(-1).references.every(ref => /^[a-f0-9]{32}$/.test(ref.id)), 'gallery references are sent by id');
await page.locator('#reset-composer').click();
// In an edit, @1 is the image being edited and the gallery references follow from @2.
await imageTiles.nth(1).locator('.tile-open').click();
await page.locator('#viewer[open]').waitFor();
await page.locator('#edit-gallery-refs').click();
await page.waitForFunction(() => document.querySelectorAll('#picker-grid .pick').length === 6);
const editedTile = page.locator('#picker-grid .pick.base');
assert.equal(await editedTile.isDisabled(), true);
assert.equal(await editedTile.locator('.pick-mark').textContent(), '@1');
await page.locator('#picker-grid .pick:not(.base)').first().click();
assert.equal(await page.locator('#picker-status').textContent(), '1 selecionada. No prompt: @2.');
await page.locator('#picker-done').click();
assert.deepEqual(await page.locator('#edit-refs .ref-tag').allTextContents(), ['@1', '@2']);
await page.locator('#edit-prompt').fill('');
await page.locator('#edit-prompt').pressSequentially('Luz de @');
await page.locator('#edit-prompt-mentions').waitFor();
await shot('edit-mentions-desktop.png');
assert.deepEqual(await page.locator('#edit-prompt-mentions .mention-option strong').allTextContents(), ['@1', '@2']);
await page.keyboard.press('Escape');
await page.locator('#edit-prompt-mentions').waitFor({ state: 'hidden' });
assert.equal(await page.locator('#viewer').evaluate(d => d.open), true, 'Escape closes only the menu');
await page.locator('#edit-prompt').fill('Luz de ');
await page.locator('#edit-refs .ref').nth(1).locator('.ref-cite').click();
assert.equal(await page.locator('#edit-prompt').inputValue(), 'Luz de @2 ');
await page.keyboard.press('Control+Enter');
await versionLabel('v2 de 2');
assert.equal(creations.at(-1).operation, 'edit');
assert.equal(creations.at(-1).references.length, 2, 'the edited image and one reference');
await page.locator('#edit-refs .ref').nth(1).locator('.ref-remove').click();
assert.equal(await page.locator('#edit-refs .ref').count(), 0);
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });

// Options survive a reload; prompts never reach browser storage.
await page.locator('[data-ratio="16:9"]').click();
await page.locator('#quantity').selectOption('2');
await page.locator('#prompt').fill('Texto que não pode ficar no navegador');
await page.reload();
await page.locator('#application').waitFor();
// The page shows before the saved options are put back (they wait for the model lists of the keys).
await page.waitForFunction(() => document.querySelector('[data-ratio="16:9"]')?.getAttribute('aria-pressed') === 'true', null, { timeout: 5000 });
assert.equal(await page.locator('#quantity').inputValue(), '2');
const stored = await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }));
for (const text of ['não pode ficar', 'roxo', 'estrelas', 'Texto revisado']) assert.equal(stored.includes(text), false, `“${text}” must not be stored in the browser`);
await page.locator('#more-options summary').click();
await page.locator('#restore-defaults').click();
assert.equal(await page.locator('[data-ratio="1:1"]').getAttribute('aria-pressed'), 'true');
assert.equal(await page.locator('#advanced-mode').isChecked(), true, 'the mode survives a reload');
assert.deepEqual(await qualityFields(), ['gpt-image-2.6-flare', 'high', 'high', 'medium'], 'the defaults are the Alta level');
await page.locator('#more-options summary').click();

// Generating in the simple mode sends the level's options and plans the prompt.
await page.locator('#advanced-mode').uncheck({ force: true });
assert.equal(await pressedLevel('#presets'), 'high');
await page.locator('#presets [data-preset="max"]').click();
let tilesNow = await imageTiles.count();
await generate('Fixture de teste: triângulo dourado');
await page.waitForFunction(n => !document.querySelector('#feed .tile.job') && document.querySelectorAll('#feed .tile[data-id]').length === n, tilesNow + 1, { timeout: 30000 });
assert.deepEqual(['quality', 'complexity', 'thinking'].map(k => creations.at(-1).options[k]), ['max', 'high', 'high']);
await imageTiles.first().locator('.tile-open').click();
await page.locator('#info-details dd').filter({ hasText: /^Alto$/ }).waitFor();
assert.match(await page.locator('#info-planned').textContent(), /^Prompt planejado: Fixture de teste: triângulo dourado/);
// The edit panel has the same levels, and its switch changes the mode everywhere.
assert.equal(await page.locator('#edit-model').isHidden(), true);
await page.locator('#edit-presets [data-preset="fast"]').click();
assert.equal(await pressedLevel('#edit-presets'), 'fast');
assert.match(await page.locator('#edit-cost').textContent(), /^Custo estimado: ≈ US\$ 0,\d{3}$/);
await page.locator('#edit-advanced-mode').check({ force: true });
assert.equal(await page.locator('#edit-quality').inputValue(), 'low');
assert.equal(await page.locator('#advanced-mode').isChecked(), true);
await page.locator('#edit-advanced-mode').uncheck({ force: true });
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
// When the planning model refuses, the image is made from the prompt as written and the details say so.
await page.locator('#presets [data-preset="high"]').click();
tilesNow = await imageTiles.count();
await generate('Fixture de teste: RECUSAR o planejamento');
await page.waitForFunction(n => !document.querySelector('#feed .tile.job') && document.querySelectorAll('#feed .tile[data-id]').length === n, tilesNow + 1, { timeout: 30000 });
await imageTiles.first().locator('.tile-open').click();
await page.locator('#info-details dd').filter({ hasText: /^Médio: recusado pelo GPT-6 Sol, feita com o prompt como escrito$/ }).waitFor();
assert.equal(await page.locator('#planned-wrap').isHidden(), true);
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });

// Planning is paid apart from the image and leaves the balance with it. Until now it reported no tokens (like the
// oldest records) and was only mentioned; from here each planning call reports 320 tokens in and 90,000 out.
const balanceStatus = () => page.locator('#balance-status').textContent();
const balanceShown = async () => Number((await page.locator('#balance-chip-openai .balance-text').textContent()).replace('US$ ', '').replace('.', '').replace(',', '.'));
assert.match(await generateCost(), /^Custo estimado: ≈ US\$ 0,\d{3} \+ planejamento$/, 'no planning to go by yet');
assert.doesNotMatch(await balanceStatus(), /planejamento/);
fixturePlanning = '90000';
const beforePlanning = await balanceShown(), planCost = (320 * 2 + 90000 * 10) / 1e6, imageCost = (1756 * 30 + 44 * 5) / 1e6;
for (let i = 1; i <= 3; i++) {
  tilesNow = await imageTiles.count();
  await generate(`Fixture de teste: planejamento pago ${i}`);
  await page.waitForFunction(n => !document.querySelector('#feed .tile.job') && document.querySelectorAll('#feed .tile[data-id]').length === n, tilesNow + 1, { timeout: 30000 });
}
await page.waitForFunction(() => /; planejamento, US\$ 2,70\)/.test(document.querySelector('#balance-status').textContent), null, { timeout: 10000 });
assert.ok(Math.abs(await balanceShown() - (beforePlanning - 3 * (planCost + imageCost))) < 0.011, 'each request leaves the balance with its planning');
// With three calls to go by, the estimate counts the planning by their average.
await page.waitForFunction(() => /, com o planejamento \(≈ US\$ 0,901\)$/.test(document.querySelector('#generate-cost').textContent));
assert.match(await generateCost(), /^Custo estimado: ≈ US\$ (0,9\d\d|1,\d\d), com o planejamento \(≈ US\$ 0,901\)$/);
await imageTiles.first().locator('.tile-open').click();
await page.locator('#info-details dd').filter({ hasText: /^US\$ 0,954, com US\$ 0,901 de planejamento$/ }).waitFor();
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
// A request whose image fails after the planning paid for the planning: it is counted, without being a request made.
const requestsCounted = async () => Number(/\((\d+) pedidos? de imagem, /.exec(await balanceStatus())[1]);
const countedBefore = await requestsCounted();
await generate('Fixture de teste: planejado e depois vai FALHAR');
await failedCard.getByText('Falhou').waitFor({ timeout: 20000 });
await page.waitForFunction(() => /; planejamento, US\$ 3,60\)/.test(document.querySelector('#balance-status').textContent), null, { timeout: 10000 });
assert.equal(await requestsCounted(), countedBefore);
await failedCard.locator('.job-status .leave').click({ timeout: 3000 }).catch(() => {}); // it may have left by itself
await page.waitForFunction(() => !document.querySelector('#feed .tile.job'), null, { timeout: 15000 });
fixturePlanning = '';
// Correcting the balance: the amount at the top opens its form with the amount selected; the real one is typed over
// it. The app keeps what it showed and learns from the difference how much more than it counted is really spent.
// (Times are kept in seconds, and a request recorded in the second the amount is entered counts as made after it:
// the amount is entered more than a second after the last request.)
await page.waitForTimeout(1200);
const shownBefore = await balanceShown(), real = Math.round((shownBefore - 0.5) * 100) / 100;
await page.locator('#balance-chip-openai').click();
await page.locator('#settings-dialog[open]').waitFor();
assert.equal(await page.evaluate(() => document.activeElement.id), 'balance-amount');
assert.equal(Number(await page.locator('#balance-amount').inputValue()), shownBefore);
await page.keyboard.type(real.toFixed(2));
await page.keyboard.press('Enter');
await page.waitForFunction(() => /^Saldo da OpenAI acertado: o app marcava US\$ \d+,\d{2}\. Os próximos gastos entram com \+\d+(,\d)?%\.$/.test(document.querySelector('#toast-text').textContent), null, { timeout: 10000 });
assert.equal(await balanceShown(), real);
assert.match(await balanceStatus(), new RegExp(`\\(o app marcava US\\$ ${shownBefore.toFixed(2).replace('.', ',')}\\)\\. Desde então, US\\$ 0,00 gastos aqui\\. Gastos contados com \\+\\d+(,\\d)?%, pela última correção\\.`));
// Saving again without changing the amount keeps the factor and what the app had shown.
await page.locator('#balance-day').fill('25');
await page.locator('#balance-form button[type=submit]').click();
await toastSays('Saldo da OpenAI salvo.');
assert.match(await balanceStatus(), /\(o app marcava US\$ \d+,\d{2}\)\. Desde então, US\$ 0,00 gastos aqui\. Gastos contados com \+\d+(,\d)?%, pela última correção\./);
await page.locator('#balance-day').fill('24');
await page.locator('#balance-form button[type=submit]').click();
await page.keyboard.press('Escape');
await page.locator('#settings-dialog').waitFor({ state: 'hidden' });

// A version link opens the viewer directly. Closing the viewer went back in history; that lands first.
await page.waitForFunction(() => location.hash === '');
await page.goto(`${base}/#v/${v2}`);
await page.locator('#viewer[open]').waitFor();
await versionLabel('v2 de 8');
await page.keyboard.press('Escape');

// Videos: sent in pieces, stepped frame by frame, and a frame saved as an image for the edit, upscale and reference
// flows. The fixture is 2 s of VP9 at 12 fps whose top-left block is gray 10.48 × the frame index.
const frameShown = n => page.waitForFunction(n => {
  const video = document.querySelector('#stage-video');
  if (document.querySelector('#video-frame').textContent !== `Frame ${n} de 24` || video.seeking || video.readyState < 2) return false;
  const canvas = document.createElement('canvas'); canvas.width = video.videoWidth; canvas.height = video.videoHeight;
  const context = canvas.getContext('2d'); context.drawImage(video, 0, 0);
  return Math.abs(context.getImageData(24, 24, 1, 1).data[0] - (n - 1) * 10.48) < 6;
}, n, { timeout: 10000 });
const playing = () => page.waitForFunction(() => {
  const video = document.querySelector('#stage-video');
  return !video.paused && video.muted && video.loop && video.currentTime > 0 && document.querySelector('#video-play').dataset.state === 'true';
}, null, { timeout: 10000 });
await page.locator('#import-input').setInputFiles('tests/fixtures/frames.mp4');
await page.locator('#viewer[open][data-kind=video]').waitFor();
// Videos start playing at once, muted and in a loop; a step or Home stops on a frame.
await playing();
// The video plays clean: its resolution is in the side panel, not on the picture.
assert.equal(await page.locator('#stage-meta').isHidden(), true);
await page.locator('#video-details dd').filter({ hasText: /^320 × 180$/ }).waitFor();
await page.keyboard.press('Home');
await frameShown(1);
assert.equal(await page.locator('#video-fps').inputValue(), '12');
assert.equal(await page.locator('#video-fps-hint').textContent(), 'Lido do arquivo.');
await page.waitForFunction(() => /^#v\/[0-9a-f]{32}$/.test(location.hash), null, { timeout: 20000 });
const videoId = await page.evaluate(() => location.hash.slice(3));
assert.equal(await page.locator('#edit-panel').isHidden(), true, 'a video has no edit panel');
for (let i = 0; i < 7; i++) await page.keyboard.press('Period');
await frameShown(8);
await page.keyboard.press('Shift+Period');
await frameShown(18);
await page.keyboard.press('Comma');
await frameShown(17);
await page.keyboard.press('End');
await frameShown(24);
await page.keyboard.press('Home');
await frameShown(1);
for (let i = 0; i < 3; i++) await page.locator('#video-forward').click();
await frameShown(4);
await page.locator('#video-scrub').evaluate(el => { el.value = '15'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
await frameShown(16);
await page.locator('#video-back10').click();
await frameShown(6);
await page.locator('#video-back').click();
await page.locator('#video-back').click();
await frameShown(4);
await shot('video-viewer.png');
// The saved frame is the decoded picture at the video's own resolution.
await page.locator('#frame-save').click();
await toastSays('Frame 4 salvo na galeria.');
await page.waitForFunction(() => document.querySelectorAll('#frames-list .frame-node').length === 1);
const [savedFrame] = (await (await context.request.get(`${base}/index.php?action=frames&id=${videoId}`)).json()).frames;
assert.deepEqual(savedFrame.source, { video_id: videoId, frame: 3, time: 0.25 });
const pixel = await page.evaluate(async url => {
  const bitmap = await createImageBitmap(await (await fetch(url)).blob());
  const canvas = new OffscreenCanvas(bitmap.width, bitmap.height), context = canvas.getContext('2d');
  context.drawImage(bitmap, 0, 0);
  return [bitmap.width, bitmap.height, context.getImageData(24, 24, 1, 1).data[0]];
}, `index.php?action=image&id=${savedFrame.id}`);
assert.deepEqual(pixel.slice(0, 2), [320, 180]);
assert.ok(Math.abs(pixel[2] - 3 * 10.48) < 6, `saved frame 4 has the gray of frame 4 (got ${pixel[2]})`);
await page.locator('#frame-save').click();
await toastSays('O frame 4 já estava na galeria.');
assert.equal(await page.locator('#frames-list .frame-node').count(), 1, 'saving the same frame twice keeps one image');
// Upscale: the frame is saved and opens in the upscale panel; it links back to its place in the video.
for (let i = 0; i < 6; i++) await page.keyboard.press('Period');
await frameShown(10);
await page.locator('#frame-upscale').click();
await page.locator('#viewer[open][data-kind=image]').waitFor();
await page.locator('#upscale-options').waitFor();
assert.equal(await page.locator('#upscale-open').getAttribute('aria-pressed'), 'true');
assert.match(await page.locator('#info-prompt').textContent(), /^Frame 10 \(0:00\.750\) salvo de um vídeo/);
await shot('video-frame-upscale.png');
await page.locator('#action-close').click();
assert.equal(await page.locator('#upscale-options').isHidden(), true);
await page.locator('#open-video').click();
await page.locator('#viewer[open][data-kind=video]').waitFor();
await frameShown(10);
assert.equal(await page.locator('#frames-list .frame-node').count(), 2);
await page.locator('#frames-list .frame-node .frame-seek').first().click();
await frameShown(4);
// Edit: the frame already saved is reused and its edit prompt takes the focus.
await page.locator('#frame-edit').click();
await page.locator('#viewer[open][data-kind=image]').waitFor();
await page.waitForFunction(() => document.activeElement?.id === 'edit-prompt');
assert.match(await page.locator('#info-prompt').textContent(), /^Frame 4 \(/);
await page.locator('#open-video').click();
await page.locator('#viewer[open][data-kind=video]').waitFor();
await frameShown(4);
// Reference: the frame joins the composer's references.
await page.locator('#frame-reference').click();
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.locator('#reference-list .ref').waitFor();
assert.equal(await page.locator('#reference-list .ref').count(), 1);
await page.locator('#reference-list .ref .ref-remove').click();
// The gallery shows the video with its length; the Videos filter shows only videos.
await page.locator('[data-origin="video"]').click();
await page.waitForFunction(id => { const tiles = document.querySelectorAll('#feed .tile[data-id]'); return tiles.length === 1 && tiles[0].dataset.id === id; }, videoId);
assert.match(await page.locator('#feed .tile.video').textContent(), /0:02/);
// Resting the pointer on a video in the gallery plays it there, muted and in a loop.
await page.locator('#feed .tile.video').hover();
await page.waitForFunction(() => { const video = document.querySelector('#feed .tile.video video'); return video && !video.paused && video.muted && video.loop; }, null, { timeout: 10000 });
await page.locator('#feed .tile.video .tile-open').click();
await page.locator('#viewer[open][data-kind=video]').waitFor();
assert.equal(await page.locator('#feed .tile.video video').count(), 0, 'the gallery stops its preview when the viewer opens');
await playing();
assert.equal(await page.evaluate(() => document.activeElement.id), 'video-play', 'the video opens on its play button, where Space pauses');
await page.keyboard.press('Home');
await frameShown(1);
await page.setViewportSize({ width: 390, height: 844 });
assert.equal(await page.locator('.viewer-shell').evaluate(el => el.scrollWidth <= el.clientWidth), true);
assert.equal(await page.locator('#frame-edit').isVisible(), true);
// On a phone the video goes from one edge of the screen to the other, as tall as its proportions make it, and its
// controls come right under it.
await page.waitForFunction(() => Math.abs(document.querySelector('#stage-frame').getBoundingClientRect().width - innerWidth) <= 1);
const phoneVideo = await page.evaluate(() => {
  const box = s => document.querySelector(s).getBoundingClientRect(), video = document.querySelector('#stage-video');
  return { frame: box('#stage-frame'), bar: box('#video-bar'), top: box('.viewer-bar').bottom, ratio: video.videoHeight / video.videoWidth, width: innerWidth };
});
assert.ok(Math.abs(phoneVideo.frame.height - phoneVideo.width * phoneVideo.ratio) <= 1.5, 'the whole video, nothing cut off');
assert.ok(Math.abs(phoneVideo.frame.top - phoneVideo.top) <= 1.5 && Math.abs(phoneVideo.bar.top - phoneVideo.frame.bottom) <= 1.5, 'the video right under the bar, its controls right under the video');
await shot('video-mobile.png');
await page.setViewportSize({ width: 1440, height: 900 });
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.locator('[data-origin="all"]').click();
await page.locator('#feed .tile.video').waitFor();
// WebM states no frame rate: the browser measures it from the frames' timestamps, kept only to the millisecond.
await page.locator('#import-input').setInputFiles('tests/fixtures/frames.webm');
await page.locator('#viewer[open][data-kind=video]').waitFor();
await page.waitForFunction(() => document.querySelector('#video-fps-hint').textContent === 'Medido no navegador.', null, { timeout: 15000 });
assert.equal(await page.locator('#video-fps').inputValue(), '12');
await playing();
await page.keyboard.press('Home');
await frameShown(1);
for (let i = 0; i < 5; i++) await page.keyboard.press('Period');
await frameShown(6);
await page.waitForFunction(() => /^#v\/[0-9a-f]{32}$/.test(location.hash), null, { timeout: 20000 });
await page.locator('#viewer-trash').click();
await page.locator('#viewer').waitFor({ state: 'hidden' });

// Library: a big import arrives whole (the gallery used to stop at its first page on large screens), the timeline
// reaches the oldest item, and many items are selected, put in an album, reordered, trashed and deleted at once.
const libraryView = async (params = '') => (await context.request.get(`${base}/index.php?action=library&group=1${params}`)).json();
const shades = await page.evaluate(async n => Promise.all(Array.from({ length: n }, async (_, i) => {
  const canvas = new OffscreenCanvas(96 + (i % 4) * 48, 96 + (i % 3) * 40), context = canvas.getContext('2d');
  context.fillStyle = `hsl(${i * 9} 70% 50%)`; context.fillRect(0, 0, canvas.width, canvas.height);
  let text = ''; for (const byte of new Uint8Array(await (await canvas.convertToBlob({ type: 'image/png' })).arrayBuffer())) text += String.fromCharCode(byte);
  return btoa(text);
})), 40);
const libraryBefore = await libraryView();
await page.locator('#import-input').setInputFiles(shades.map((data, i) => ({ name: `lote ${i + 1}.png`, mimeType: 'image/png', buffer: Buffer.from(data, 'base64') })));
await toastSays('40 imagens importadas.');
const library = await libraryView();
assert.equal(library.total, libraryBefore.total + 40);
assert.equal(library.items.length, library.total, 'the library sends every item of the view');
await page.waitForFunction(n => document.querySelector('#gallery-count').textContent === `${n} itens`, library.total);
const inView = id => page.waitForFunction(id => {
  const r = document.querySelector(`#feed .tile[data-id="${id}"]`)?.getBoundingClientRect();
  return r && r.bottom > 0 && r.top < innerHeight;
}, id, { timeout: 10000 });
await inView(library.items[0][0]);
assert.ok(await imageTiles.count() < library.total, 'only the rows near the screen are in the page');
// Dragging the timeline to its end reaches the oldest item; Home on it goes back to the newest.
const oldest = library.items.at(-1)[0];
await page.locator('#timeline').waitFor();
const rail = await page.locator('#timeline').boundingBox();
await page.mouse.move(rail.x + rail.width / 2, rail.y + 30); await page.mouse.down();
await page.mouse.move(rail.x + rail.width / 2, rail.y + rail.height / 2, { steps: 4 });
// While dragging, the bubble says which month that part of the library is from.
assert.equal(await page.locator('.timeline-thumb .timeline-bubble').textContent(), new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(new Date()));
await page.mouse.move(rail.x + rail.width / 2, rail.y + rail.height - 1, { steps: 6 }); await page.mouse.up();
await inView(oldest);
await shot('library-timeline.png');
await page.locator('#timeline').focus();
await page.keyboard.press('Home');
await inView(library.items[0][0]);
// Imported and generated images are apart.
await page.locator('[data-origin="imported"]').click();
await page.waitForFunction(n => document.querySelector('#gallery-count').textContent === `${n} itens`, (await libraryView('&origin=imported')).total);
const generated = await libraryView('&origin=generated');
await page.locator('[data-origin="generated"]').click();
await tileCount(generated.total);
assert.ok(generated.items.every(([id]) => !library.items.slice(0, 40).some(row => row[0] === id)), 'imports are not among the generated images');
await page.locator('[data-origin="all"]').click();
// Other orders: the largest image comes first.
await viewMenu('Maior resolução');
assert.equal(await page.locator('#sort-label').textContent(), 'Maior resolução');
await page.waitForFunction(id => document.querySelector('#feed .tile[data-id]')?.dataset.id === id, (await libraryView('&sort=largest')).items[0][0]);
await viewMenu('Mais recentes');
await inView(library.items[0][0]);
// Several at once: select three, put them in a new album.
const firstThree = library.items.slice(0, 3).map(([id]) => id);
await page.locator('#select-mode').click();
for (const id of firstThree) await page.locator(`#feed .tile[data-id="${id}"] .tile-open`).click();
assert.equal(await page.locator('#selection-count').textContent(), '3 selecionados');
await shot('library-selection.png');
await page.locator('[data-bulk="album"]').click();
await page.locator('#album-name').fill('Lote');
await page.locator('#album-new button[type=submit]').click();
await toastSays('3 itens adicionados ao novo álbum Lote.');
assert.equal(await page.locator('#selection-bar').isHidden(), true);
await scope('Lote');
await tileCount(3);
assert.equal(await page.locator('#scope-label').textContent(), 'Lote');
// Custom order: a tile dragged past another keeps its new place.
const [album] = (await (await context.request.get(`${base}/index.php?action=albums`)).json()).albums;
await viewMenu('Ordem personalizada');
const albumOrder = async () => (await libraryView(`&album=${album.id}&sort=custom`)).items.map(([id]) => id);
const [a, b, c] = await albumOrder();
await page.waitForFunction(id => document.querySelector('#feed .tile[data-id]')?.dataset.id === id, a);
const from = await page.locator(`#feed .tile[data-id="${a}"]`).boundingBox(), to = await page.locator(`#feed .tile[data-id="${c}"]`).boundingBox();
await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2); await page.mouse.down();
await page.mouse.move(from.x + from.width / 2 + 20, from.y + from.height / 2 + 4, { steps: 3 });
await page.mouse.move(to.x + to.width * 0.8, to.y + to.height / 2, { steps: 8 });
await page.locator('.drop-marker').waitFor();
await page.mouse.up();
await page.waitForFunction(async ([album, order]) => {
  const { items } = await (await fetch(`index.php?action=library&group=1&album=${album}&sort=custom`)).json();
  return items.map(([id]) => id).join() === order;
}, [album.id, [b, c, a].join()], { timeout: 10000 });
await viewMenu('Mais recentes');
// In selection mode, Ctrl+A and Delete move the whole album to the trash; there, everything is deleted for good at once.
await tileCount(3);
await page.locator('#select-mode').click();
await page.keyboard.press('Control+a');
assert.equal(await page.locator('#selection-count').textContent(), '3 selecionados');
await page.keyboard.press('Delete');
await toastSays('3 itens movidos para a lixeira.');
await page.locator('#feed-empty').waitFor();
await scope('Lixeira');
await page.locator('#feed .tile.trashed').first().waitFor();
await page.locator('#select-mode').click();
await page.locator('#select-all').click();
await page.locator('[data-bulk="purge"]').click();
await page.locator('#confirm-yes').click();
await page.locator('#feed-empty').getByText('A lixeira está vazia').waitFor();
await scope('Lote');
await page.locator('#scope-button').click();
// An album left with nothing in it still lists like the others: its square without a cover, at the size of a cover
// (it once took the look of the empty screens and stretched the whole menu).
const emptyAlbum = await page.locator('#scope-menu .menu-item', { hasText: 'Lote' }).evaluate(item => {
  const thumb = item.querySelector('.menu-thumb').getBoundingClientRect();
  return { item: Math.round(item.getBoundingClientRect().height), thumb: [Math.round(thumb.width), Math.round(thumb.height)] };
});
assert.deepEqual(emptyAlbum, { item: 36, thumb: [26, 26] }, `an empty album keeps the menu in shape (${JSON.stringify(emptyAlbum)})`);
await page.locator('#scope-menu .menu-item', { hasText: 'Excluir álbum' }).click();
await page.locator('#confirm-yes').click();
await page.waitForFunction(() => document.querySelector('#scope-label').textContent === 'Biblioteca');
assert.deepEqual((await (await context.request.get(`${base}/index.php?action=albums`)).json()).albums, []);
assert.equal((await libraryView()).total, library.total - 3);

// Stacks and hidden items. A stack is made by hand from any items, videos too, and shows as one tile, like the versions
// of an image; hidden items leave the library for the Ocultas filter, and the reference picker still lists them.
const count = n => page.waitForFunction(n => document.querySelector('#gallery-count').textContent === `${n} itens`, n, { timeout: 10000 });
const scrollToTile = async id => {
  await page.evaluate(() => scrollTo(0, 0));
  for (let i = 0; i < 80 && !(await page.locator(`#feed .tile[data-id="${id}"]`).count()); i++) { await page.mouse.wheel(0, 500); await page.waitForTimeout(80); }
  await page.locator(`#feed .tile[data-id="${id}"]`).scrollIntoViewIfNeeded();
};
const beforeStacks = await libraryView();
const [s1, s2] = beforeStacks.items.slice(0, 2).map(([id]) => id);
await page.locator('#select-mode').click();
for (const id of [s1, s2]) await page.locator(`#feed .tile[data-id="${id}"] .tile-open`).click();
assert.equal(await page.locator('[data-bulk="unstack"]').isHidden(), true, 'Desagrupar shows only for items in a stack');
await page.locator('[data-bulk="stack"]').click();
await toastSays('Pilha com 2 itens.');
await count(beforeStacks.total - 1);
const stackRow = (await libraryView()).items[0];
assert.ok((stackRow[4] & 16) && stackRow[5] === 2 && [s1, s2].includes(stackRow[0]), 'two items make one stacked tile');
await page.waitForFunction(id => document.querySelector(`#feed .tile[data-id="${id}"] .tile-badge`)?.textContent === '2', stackRow[0]);
// A video joins the stack: select the stack, then the video further down.
await page.locator('#select-mode').click();
await page.locator(`#feed .tile[data-id="${stackRow[0]}"] .tile-open`).click();
await scrollToTile(videoId);
await page.locator(`#feed .tile[data-id="${videoId}"] .tile-open`).click();
assert.equal(await page.locator('#selection-count').textContent(), '2 selecionados');
assert.equal(await page.locator('[data-bulk="unstack"]').isVisible(), true);
await page.locator('[data-bulk="stack"]').click();
await toastSays('Pilha com 3 itens.');
await count(beforeStacks.total - 2);
await page.evaluate(() => scrollTo(0, 0));
await page.locator(`#feed .tile[data-id="${stackRow[0]}"] .tile-open`).click();
await page.locator('#viewer[open]').waitFor();
await page.waitForFunction(() => document.querySelector('#versions-title').textContent === 'Pilha' && document.querySelectorAll('#versions-list button.vnode').length === 3);
assert.equal(await page.locator('#stack-bar').isVisible(), true);
assert.match(await page.locator('#stack-note').textContent(), /3 itens/);
assert.equal(await page.locator('#versions-list .tree > ol').count(), 3, 'each image of the stack is a tree of its own');
await shot('stack-viewer.png');
// The video of the stack: its items show above its frames, and ↑ and ↓ go through them.
await page.locator(`#versions-list button.vnode[data-id="${videoId}"]`).click();
await page.locator('#viewer[open][data-kind=video]').waitFor();
await page.waitForFunction(() => !document.querySelector('#stack-list').hidden && document.querySelectorAll('#stack-list button.vnode').length === 3);
assert.equal(await page.locator('#stack-list .vnode.current').getAttribute('data-id'), videoId);
await page.keyboard.press('ArrowDown');
await page.locator('#viewer[open]:not([data-kind=video])').waitFor({ timeout: 10000 });
// Ocultar in the viewer: grouped, the whole stack goes, and the viewer goes on to the next item of the library.
const hiddenFrom = await page.evaluate(() => location.hash.slice(3));
assert.ok([s1, s2].includes(hiddenFrom));
await page.locator('#viewer-hide').click();
await toastSays('Oculta. Fica no filtro Ocultas (o olho riscado).');
// On a laptop screen the toolbar keeps to one line with the fifth filter: Importar stays beside the rest.
assert.equal(await page.evaluate(() => { const mid = el => { const r = el.getBoundingClientRect(); return r.top + r.height / 2; }; return Math.abs(mid(document.querySelector('.toolbar-main .search')) - mid(document.querySelector('#import-input').closest('label'))) < 4; }), true, 'the toolbar fits one line at 1440 px');
await page.waitForFunction(ids => { const id = location.hash.slice(3); return /^[0-9a-f]{32}$/.test(id) && !ids.includes(id); }, [s1, s2, videoId], { timeout: 10000 });
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await count(beforeStacks.total - 3);
const pickerSees = (await (await context.request.get(`${base}/index.php?action=gallery&limit=300`)).json()).items.map(item => item.id);
assert.ok([s1, s2, videoId].every(id => pickerSees.includes(id)), 'the reference picker still lists hidden items');
await page.locator('[data-origin="hidden"]').click();
await tileCount(1);
await page.waitForFunction(() => document.querySelector('#feed .tile[data-id] .tile-badge')?.textContent.startsWith('3'));
await shot('library-hidden.png');
// In Ocultas, the same button of the selection brings items back.
await page.locator('#select-mode').click();
await page.locator('#feed .tile[data-id] .tile-open').first().click();
assert.equal(await page.locator('[data-bulk="hide"]').textContent(), 'Mostrar');
await page.locator('[data-bulk="hide"]').click();
await toastSays('1 item voltou para a biblioteca.');
await page.locator('#feed-empty').getByText('Nada oculto').waitFor();
await page.locator('[data-origin="all"]').click();
await count(beforeStacks.total - 2);
// The stack is undone from its bar in the viewer, with a way back.
await page.locator(`#feed .tile[data-id="${stackRow[0]}"] .tile-open`).click();
await page.locator('#viewer[open]').waitFor();
await page.locator('#stack-undo').click();
await toastSays('Pilha desfeita. Cada item voltou a ter o próprio cartão.');
await page.waitForFunction(() => document.querySelector('#stack-bar').hidden && document.querySelector('#versions-title').textContent === 'Versões');
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await count(beforeStacks.total);
assert.ok((await libraryView()).items.every(row => !(row[4] & 48)), 'nothing is left in a stack or hidden');

// Phone layout.
await page.setViewportSize({ width: 390, height: 844 });
assert.equal(await noOverflow(), true);
assert.equal(await page.locator('#origin-filter').evaluate(el => el.getBoundingClientRect().right <= innerWidth - 8), true, 'the five filters fit a phone');
await page.locator('#toast').waitFor({ state: 'hidden' });
await shot('studio-mobile.png', { fullPage: true });
assert.equal(await page.evaluate(() => scrollY === 0 && document.querySelector('#generate').getBoundingClientRect().bottom <= innerHeight), true, 'on a phone the Gerar button is in view without scrolling');
// A failed request on a phone: its card is small, and the × that dismisses it and Repetir are inside it.
await generate('Isto vai FALHAR no celular');
await failedCard.getByText('Falhou').waitFor({ timeout: 20000 });
assert.deepEqual(await failedCard.evaluate(tile => {
  const t = tile.getBoundingClientRect();
  return ['.job-status .leave', '.job-actions .btn'].map(s => { const b = tile.querySelector(s).getBoundingClientRect(); return b.width > 0 && b.left >= t.left && b.right <= t.right && b.top >= t.top && b.bottom <= t.bottom; });
}), [true, true], 'the × and Repetir fit the card on a phone');
await shot('failed-mobile.png');
await failedCard.locator('.job-status .leave').click();
await page.waitForFunction(() => !document.querySelector('#feed .tile.job'));
await page.evaluate(() => scrollTo(0, 0));
// The viewer on a phone: the picture goes from one edge of the screen to the other and is as tall as its proportions
// make it, right under the bar; what comes under it starts right after it. Nothing is cut off, whatever the proportions.
await imageTiles.first().locator('.tile-open').click();
await page.locator('#viewer[open]').waitFor();
const phonePicture = () => page.evaluate(() => {
  const box = s => document.querySelector(s).getBoundingClientRect(), image = document.querySelector('#stage-image');
  return { frame: box('#stage-frame'), stage: box('#stage'), versions: box('.versions'), top: box('.viewer-bar').bottom, ratio: image.naturalHeight / image.naturalWidth, width: innerWidth,
    video: document.querySelector('#viewer').dataset.kind === 'video', id: location.hash };
});
const proportions = new Set();
for (let i = 0; i < 12 && proportions.size < 3; i++) {
  await page.waitForFunction(() => document.querySelector('#stage-loading').hidden);
  await page.waitForFunction(() => document.querySelector('#viewer').dataset.kind === 'video' || Math.abs(document.querySelector('#stage-frame').getBoundingClientRect().width - innerWidth) <= 1);
  const picture = await phonePicture();
  if (!picture.video) {
    assert.ok(picture.frame.left <= 0.5 && Math.abs(picture.frame.width - picture.width) <= 1, `the picture takes the whole width (${picture.id})`);
    assert.ok(Math.abs(picture.frame.height - picture.width * picture.ratio) <= 1.5, `and is as tall as its proportions make it (${picture.id})`);
    assert.ok(Math.abs(picture.frame.top - picture.top) <= 1.5, 'right under the bar');
    assert.ok(picture.versions.top - picture.frame.bottom <= 48 && Math.abs(picture.versions.top - picture.stage.bottom) <= 1, 'what comes under the picture starts right after it');
    proportions.add(picture.ratio.toFixed(2));
  }
  const before = picture.id;
  await page.keyboard.press('ArrowRight');
  await page.waitForFunction(id => location.hash !== id, before, { timeout: 5000 }).catch(() => {});
  if ((await page.evaluate(() => location.hash)) === before) break;
}
assert.ok(proportions.size >= 2, 'pictures of different proportions were checked');
// One finger on the picture scrolls the page (the picture takes the screen); two fingers zoom the picture; zoomed in,
// one finger moves the picture.
const touchSession = await context.newCDPSession(page);
await touchSession.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 2 });
const touch = (type, points) => touchSession.send('Input.dispatchTouchEvent', { type, touchPoints: points.map(([x, y], id) => ({ x, y, id })) });
const shellScroll = () => page.locator('.viewer-shell').evaluate(el => el.scrollTop);
const frameBox = await page.locator('#stage-frame').boundingBox();
const midX = frameBox.x + frameBox.width / 2, low = frameBox.y + Math.min(frameBox.height - 20, 300);
await touch('touchStart', [[midX, low]]);
for (let y = low - 20; y >= low - 200; y -= 20) { await touch('touchMove', [[midX, y]]); await page.waitForTimeout(16); }
await touch('touchEnd', []);
await page.waitForFunction(() => document.querySelector('.viewer-shell').scrollTop > 60, null, { timeout: 5000 });
// The page goes on by itself for a moment after the finger leaves: the next gesture starts once it has stopped.
const settle = async () => { for (let last = -1, now = await shellScroll(); now !== last; now = await shellScroll()) { last = now; await page.waitForTimeout(150); } };
await settle();
await page.locator('.viewer-shell').evaluate(el => el.scrollTo(0, 0));
await settle();
assert.equal(await shellScroll(), 0);
const midY = frameBox.y + Math.min(frameBox.height / 2, 200);
await touch('touchStart', [[midX - 25, midY]]);
await touch('touchStart', [[midX - 25, midY], [midX + 25, midY]]);
for (let d = 10; d <= 100; d += 10) { await touch('touchMove', [[midX - 25 - d, midY - d / 4], [midX + 25 + d, midY + d / 4]]); await page.waitForTimeout(16); }
await touch('touchEnd', []);
await page.locator('#zoom-reset').waitFor();
assert.equal(await shellScroll(), 0, 'a pinch zooms the picture and leaves the page where it is');
const zoomedAt = await page.locator('#stage-frame').evaluate(el => el.style.transform);
await touch('touchStart', [[midX, midY]]);
for (let d = 15; d <= 60; d += 15) { await touch('touchMove', [[midX - d, midY - d]]); await page.waitForTimeout(16); }
await touch('touchEnd', []);
assert.notEqual(await page.locator('#stage-frame').evaluate(el => el.style.transform), zoomedAt, 'zoomed in, a finger moves the picture');
assert.equal(await shellScroll(), 0);
await touchSession.send('Emulation.setTouchEmulationEnabled', { enabled: false });
await touchSession.detach();
await page.locator('#zoom-reset').click();
await page.locator('#zoom-reset').waitFor({ state: 'hidden' });
// While an area is painted the brush's bar comes under the picture, whole, over the strip with the picture's size.
await page.locator('#stage-frame:not(.easing)').waitFor();
await page.locator('#brush-toggle').click();
await page.locator('#brush-bar').waitFor();
assert.deepEqual(await page.evaluate(() => {
  const box = s => document.querySelector(s).getBoundingClientRect(), bar = box('#brush-bar'), frame = box('#stage-frame'), strip = box('#stage-bar');
  return [bar.left >= 0 && bar.right <= innerWidth, bar.top >= frame.bottom - 1, bar.bottom <= strip.top + 1, document.querySelector('#brush-bar').scrollWidth <= document.querySelector('#brush-bar').clientWidth];
}), [true, true, true, true], 'the brush bar fits a phone, under the picture');
// The button is under the picture, further down the page: the picture comes back to the screen, whole, to be painted.
assert.deepEqual(await page.evaluate(() => {
  const frame = document.querySelector('#stage-frame').getBoundingClientRect(), bar = document.querySelector('#brush-bar').getBoundingClientRect();
  return [document.querySelector('.viewer-shell').scrollTop, frame.top >= document.querySelector('.viewer-bar').getBoundingClientRect().bottom - 1, bar.bottom <= innerHeight];
}), [0, true, true], 'the picture and the brush bar are on the screen');
await page.locator('#mask-done').click();
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await imageTiles.first().locator('.tile-open').click();
await page.locator('#viewer[open]').waitFor();
assert.equal(await page.locator('.viewer-shell').evaluate(el => el.scrollWidth <= el.clientWidth), true);
assert.equal(await page.locator('#edit-presets').isVisible(), true);
await shot('viewer-mobile.png');
// The several-images box fits a phone too.
await page.locator('#edit-vary-open').click();
await page.locator('#edit-vary-box [data-vary="lines"]').click();
assert.equal(await page.locator('.viewer-shell').evaluate(el => el.scrollWidth <= el.clientWidth), true);
assert.equal(await page.locator('#edit-vary-box').evaluate(el => el.scrollWidth <= el.clientWidth), true);
await page.locator('#edit-vary-lines').scrollIntoViewIfNeeded();
await shot('variations-mobile.png');
await page.locator('#edit-vary-open').click();
assert.equal(await page.locator('#edit-vary-box').isHidden(), true);
// Limpar: dots and fine lines are removed in the browser, at a chosen level, and the result is a new version. No
// request goes to the image API and nothing is charged.
const beforeClean = await page.locator('#viewer-version').textContent(), countBefore = Number((beforeClean.match(/de (\d+)$/) || [0, 1])[1]);   // an image with one version shows no number
const sentBeforeClean = creations.length, balanceBeforeClean = await page.locator('#balance-chip-openai .balance-text').textContent();
const cleanedOf = new URL(page.url()).hash.slice(3);
const imports = [];
const noteImport = request => { if (request.url().endsWith('index.php?action=import')) imports.push(request.postDataJSON()); };
page.on('request', noteImport);
await page.locator('#clean').click();
assert.equal(await page.locator('#action-title').textContent(), 'Limpar');
assert.deepEqual([await page.locator('#preset-levels').isVisible(), await page.locator('#edit-prompt').isDisabled(), await page.locator('#preset-before').isHidden()], [true, true, true]);
assert.equal(await page.locator('#edit-cost').textContent(), 'Sem custo: a limpeza roda neste aparelho.');
assert.equal(await page.locator('#preset-levels [aria-pressed="true"]').textContent(), 'Média');
await page.locator('#preset-levels [data-level="forte"]').click();
assert.equal(await page.locator('#preset-levels [aria-pressed="true"]').textContent(), 'Forte');
assert.equal(await page.locator('.viewer-shell').evaluate(el => el.scrollWidth <= el.clientWidth), true);
await page.locator('#edit-submit').scrollIntoViewIfNeeded();
await shot('clean-mobile.png');
await page.locator('#edit-submit').click();
await versionLabel(`v${countBefore + 1} de ${countBefore + 1}`, 90000);
page.off('request', noteImport);
assert.equal(imports.length, 1);
assert.deepEqual([imports[0].parent_id, imports[0].clean, /^data:image\/png;base64,/.test(imports[0].data)], [cleanedOf, 'forte', true]);
assert.equal(creations.length, sentBeforeClean, 'cleaning sends nothing to the image API');
assert.equal(await page.locator('#balance-chip-openai .balance-text').textContent(), balanceBeforeClean, 'and costs nothing');
assert.deepEqual([await page.locator('#clean').getAttribute('aria-pressed'), await page.locator('#preset-levels [aria-pressed="true"]').textContent()], ['true', 'Forte'], 'Limpar stays chosen, at its level');
assert.match(await page.locator('#versions-list .vnode.current .vsub').textContent(), /^Limpeza forte/);
assert.match(await page.locator('#versions-list .vnode.current .vop').textContent(), /^Limpeza/);
assert.equal(await page.locator('#prompt-heading').textContent(), 'Limpeza');
assert.match(await page.locator('#info-prompt').textContent(), /^Limpeza forte: pontinhos e linhas finas removidos neste aparelho, sem usar a API\.$/);
assert.equal(await page.locator('#prompt-edit').isHidden(), true);
// Limpar is still chosen: the first Esc goes back to a plain edit, the second leaves the viewer.
await page.keyboard.press('Escape');
assert.equal(await page.locator('#edit-prompt').isDisabled(), false);
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
await page.locator('#settings-open').click();
await page.locator('.settings-tab[data-pane=privacy]').click();
await page.locator('#settings-privacy').waitFor();
assert.equal(await noOverflow(), true);
await page.locator('#settings-dialog [data-close]').click();
await page.locator('#gallery-refs').click();
await page.waitForFunction(() => document.querySelectorAll('#picker-grid .pick').length > 0);
await picks.first().click();
assert.equal(await noOverflow(), true);
assert.equal(await page.locator('#picker-done').isVisible(), true);
await shot('picker-mobile.png');
await page.keyboard.press('Escape');
await page.locator('#picker').waitFor({ state: 'hidden' });
assert.equal(await page.locator('#reference-list .ref').count(), 0, 'closing the picker without confirming changes nothing');

// Locking clears the screen and revokes access.
await page.locator('#lock').click();
await page.locator('#gate').waitFor();
assert.equal(await page.locator('#prompt').inputValue(), '');
assert.equal((await context.request.get(download)).status(), 401);

// Accounts: the administrator creates one with MCP access; it has a library of its own.
await page.setViewportSize({ width: 1440, height: 900 });
await signIn('dono');
await imageTiles.first().waitFor();
const galleryTotal = async () => (await (await context.request.get(`${base}/index.php?action=gallery`)).json()).total;
const ownImages = await galleryTotal();
assert.ok(ownImages > 0);
await page.locator('#account-open').click();
assert.equal(await page.locator('#settings-user').textContent(), 'dono');
await page.locator('.settings-tab[data-pane=users]').click();
await page.locator('#users-list .user-row').first().waitFor();
await page.locator('#user-name').fill('trabalho');
await page.locator('#user-password').fill('work-password-long-enough');
await page.locator('#user-confirm').fill('work-password-long-enough');
await page.locator('#user-mcp').check({ force: true });
await page.locator('#user-form button[type=submit]').click();
await page.locator('#user-created input').first().waitFor();
const connector = await page.locator('#user-created input').first().inputValue();
assert.match(connector, /\/mcp\.php\?token=igm_[a-f0-9]{24}_[A-Za-z0-9_-]{43}$/);
assert.match(await page.locator('#user-created input').nth(1).inputValue(), /^claude mcp add --transport http atelier http\S+\/mcp\.php --header "Authorization: Bearer igm_/);
await page.locator('#users-list .user-row').nth(1).locator('.user-name', { hasText: 'trabalho' }).waitFor();
await shot('settings-users.png');
// "Limpar antes de enviar" is remembered for each account. This one picks Forte (the control is in the viewer, closed
// here, so the choice is made on the control itself).
const cleanBeforeValue = () => page.locator('#edit-clean').inputValue();
const pickCleanBefore = value => page.locator('#edit-clean').evaluate((el, v) => { el.value = v; el.dispatchEvent(new Event('change')); }, value);
assert.equal(await cleanBeforeValue(), 'media');
await pickCleanBefore('forte');
const listed = await context.request.post(connector, { headers: { Accept: 'application/json, text/event-stream' }, data: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'list_images', arguments: {} } } });
assert.equal(JSON.parse((await listed.json()).result.content[0].text).total, 0, 'the MCP token opens the new, empty library');
await page.locator('#settings-dialog [data-close]').click();
// The dialog's close event is dispatched asynchronously; the token is removed when it arrives.
await page.waitForFunction(() => !document.querySelector('#user-created input, #mcp-output input'), null, { timeout: 5000 });
await page.locator('#lock').click();
await page.locator('#gate').waitFor();
await signIn('trabalho');
await page.locator('#feed-empty').getByText('Nenhuma imagem ainda').waitFor();
assert.equal(await page.locator('#account-name').textContent(), 'trabalho');
assert.equal(await galleryTotal(), 0);
// The other account starts with the default, whatever the first one chose, and turns the cleaning off for itself.
assert.equal(await cleanBeforeValue(), 'media', 'the choice of one account is not the choice of another');
await pickCleanBefore('off');
assert.equal(await cleanBeforeValue(), 'off');
await page.locator('#settings-open').click();
assert.equal(await page.locator('#settings-tab-users').isHidden(), true, 'only the administrator manages accounts');
await page.locator('.settings-tab[data-pane=about]').click();
await page.waitForFunction(() => !document.querySelector('#about-text').disabled && document.querySelector('#about-status').textContent === '');
assert.deepEqual([await page.locator('#about-text').inputValue(), await page.locator('#about-notes').inputValue()], ['', ''], 'another account starts with nothing about itself as a reader');
assert.equal(await noOverflow(), true);
await page.locator('#settings-dialog [data-close]').click();
await page.locator('#lock').click();
await page.locator('#gate').waitFor();
await signIn('dono');
await imageTiles.first().waitFor();
assert.equal(await galleryTotal(), ownImages, 'the first library is unchanged');
assert.equal(await cleanBeforeValue(), 'forte', 'each account gets its own choice back');
assert.deepEqual(await page.evaluate(() => Object.values(JSON.parse(localStorage.getItem('imagegen.prefs.v1')).clean.by).sort()), ['forte', 'off']);

// The edit panel and the columns. The box to type in is in the same place on both screens: the new-image column and
// the edit column are the same column, on the left, with the same width. The versions are on the right.
await pickCleanBefore('off');
fixtureSeconds = '0';
const rect = selector => page.locator(selector).evaluate(el => { const r = el.getBoundingClientRect(); return { x: r.left, y: r.top, width: r.width, height: r.height, right: r.right, bottom: r.bottom }; });
const composerPrompt = await rect('#drop-area');
const tilesAtEnd = await imageTiles.count();
await generate('Fixture de teste: quadrado branco, para o painel de edição');
await page.waitForFunction(n => !document.querySelector('#feed .tile.job') && document.querySelectorAll('#feed .tile[data-id]').length === n, tilesAtEnd + 1, { timeout: 30000 });
await imageTiles.first().locator('.tile-open').click();
await page.locator('#viewer[open][data-kind=image]').waitFor();
const root = new URL(page.url()).hash.slice(3);
const editPrompt = await rect('#edit-drop'), inspector = await rect('.inspector'), stage = await rect('#stage'), versions = await rect('#versions-aside');
assert.ok(Math.abs(editPrompt.x - composerPrompt.x) < 1 && Math.abs(editPrompt.width - composerPrompt.width) < 1, 'the prompt box is in the same place, with the same width, on both screens');
assert.ok(inspector.x === 0 && inspector.right <= stage.x + 1 && versions.x >= stage.right - 1 && Math.abs(versions.right - 1440) < 1, 'edit panel on the left, picture in the middle, versions on the right');
// The buttons are a grid under the box, in two groups: the four helpers of a plain edit on top, smaller, then the
// requests in two rows of three. Every row is full.
const tools = page.locator('#edit-form .tools .tool');
assert.deepEqual((await tools.allTextContents()).map(t => t.trim()), ['Área', 'Arquivo', 'Galeria', 'Variações', 'Recortar', 'Expandir', 'Ampliar', 'Fotorrealista', 'Melhorar', 'Limpar']);
const cells = await tools.evaluateAll(list => list.map(b => { const r = b.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; }));
const toolRows = [cells.slice(0, 4), cells.slice(4, 7), cells.slice(7)];
assert.deepEqual([...toolRows.map(r => new Set(r.map(c => c[1])).size), new Set(cells.map(c => c[1])).size], [1, 1, 1, 3], 'three rows: 4, 3 and 3 buttons');
assert.deepEqual([new Set(cells.slice(0, 4).map(c => `${c[2]} × ${c[3]}`)).size, new Set(cells.slice(4).map(c => `${c[2]} × ${c[3]}`)).size], [1, 1], 'the helpers are one size, the requests another');
assert.ok(cells[0][2] < cells[4][2] && cells[0][3] === cells[4][3], 'the four helpers are narrower, and as tall');
assert.ok(toolRows.every(r => Math.abs(r[0][0] - cells[0][0]) <= 1 && Math.abs(r.at(-1)[0] + r.at(-1)[2] - (cells[3][0] + cells[3][2])) <= 1), 'every row is full, edge to edge');
assert.ok(cells[0][1] >= Math.floor(editPrompt.bottom), 'the buttons are under the box');
assert.equal(await page.locator('#edit-drop button').count(), 0, 'no button inside the prompt box');
// Nothing is drawn over the picture while it is only being looked at; its size is written under it.
const overPicture = () => page.evaluate(() => ['#mask-canvas', '#brush-bar', '#brush-cursor', '#expand-stage', '#compare-label'].map(s => document.querySelector(s).hidden));
assert.deepEqual(await overPicture(), [true, true, true, true, true]);
assert.ok((await rect('#stage-meta')).y >= (await rect('#stage-frame')).bottom, 'the size is written under the picture, not over it');

// An area painted and nothing written: the button of the brush bar sends it, and only that area is made again.
await page.locator('#edit-prompt').fill('');
await page.locator('#edit-quantity').selectOption('1');
await page.locator('#brush-toggle').click();
await page.locator('#brush-bar').waitFor();
const sentBeforeArea = creations.length;
await page.locator('#mask-go').click();
await toastSays('Pinte primeiro a área que deve ser refeita.');
assert.equal(creations.length, sentBeforeArea, 'nothing is sent while no area is painted');
const area = await page.locator('#mask-canvas').boundingBox();
await page.mouse.move(area.x + area.width / 2, area.y + area.height / 2); await page.mouse.down();
await page.mouse.move(area.x + area.width / 2 + 60, area.y + area.height / 2 + 40, { steps: 8 }); await page.mouse.up();
assert.equal(await page.locator('#edit-prompt').getAttribute('placeholder'), 'O que deve aparecer na área marcada (opcional). Em branco, o trecho é refeito.');
assert.equal(await page.locator('#edit-submit-label').textContent(), 'Refazer área');
assert.match(await page.locator('#edit-cost').textContent(), /^Custo estimado: ≈ US\$ 0,\d{3}$/, 'an area made again is not planned');
await shot('inpaint-desktop.png');
await forgetToast();
await page.locator('#mask-go').click();
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Edição pronta.', null, { timeout: 20000 });
assert.equal(await page.locator('#brush-bar').isVisible(), true, 'the brush and the area stay after sending');
await openResult('Edição pronta.', 'v2 de 2');
request = creations.at(-1);
assert.equal(creations.length, sentBeforeArea + 1);
assert.deepEqual([request.operation, request.parent_id, request.label, request.options.thinking, request.options.vary, request.options.n], ['edit', root, 'Área refeita', 'off', false, 1]);
assert.match(request.mask, /^data:image\/png;base64,/);
assert.match(request.prompt, /^Redo only the area marked by the mask, and nothing else\..*Everything outside the marked area stays exactly as it is\.$/s);
assert.deepEqual(request.references, [{ id: root }]);
assert.match(await page.locator('#versions-list .vnode.current .vsub').textContent(), /^Área refeita/);
assert.deepEqual(await overPicture(), [true, true, true, true, true], 'the brush goes away once the result is opened');
assert.equal(await page.locator('#edit-submit-label').textContent(), 'Gerar edição');

// The edge of a column can be dragged. The edit column and the new-image column are one width; the versions have
// their own. A double click on the edge goes back to the default.
const widthOf = selector => page.locator(selector).evaluate(el => Math.round(el.getBoundingClientRect().width));
const columns = () => page.evaluate(() => JSON.parse(localStorage.getItem('imagegen.prefs.v1')).columns);
const dragEdge = async (selector, dx) => {
  const edge = await page.locator(selector).boundingBox();
  await page.mouse.move(edge.x + edge.width / 2, edge.y + 300); await page.mouse.down();
  await page.mouse.move(edge.x + edge.width / 2 + dx, edge.y + 300, { steps: 5 }); await page.mouse.up();
};
assert.deepEqual([await widthOf('.inspector'), await widthOf('.versions')], [340, 264]);
await dragEdge('.viewer-shell > .col-handle.at-end', -120);
await dragEdge('.viewer-shell > .col-handle.at-start', 60);
assert.deepEqual([await widthOf('.inspector'), await widthOf('.versions')], [400, 384]);
assert.deepEqual(await columns(), { side: 400, versions: 384 });
assert.equal(await page.locator('#viewer').evaluate(dialog => dialog.open), true, 'dragging an edge is not a click outside the picture');

// A deep tree: each round makes two versions of the newest one. However many levels there are, and however narrow
// the column is, every version stays inside it.
await page.locator('#edit-quantity').selectOption('2');
await page.locator('#edit-vary-box [data-vary="same"]').click();
const finished = () => page.locator('#versions-list .vnode:not(.pending)').count();
for (let round = 1; round <= 5; round++) {
  const before = await finished();
  await page.locator('#edit-prompt').fill(`Rodada ${round}`);
  await page.locator('#edit-submit').click();
  await page.waitForFunction(n => document.querySelectorAll('#versions-list .vnode:not(.pending)').length === n + 2 && !document.querySelector('#versions-list .vnode.pending'), before, { timeout: 30000 });
  const newest = await page.evaluate(() => [...document.querySelectorAll('#versions-list .vnode[data-id]')].at(-1).dataset.id);
  await page.locator(`#versions-list .vnode[data-id="${newest}"]`).click();
  await page.waitForFunction(id => location.hash === `#v/${id}`, newest);
}
await page.locator('#edit-quantity').selectOption('1');
assert.equal(await finished(), 12);
assert.equal(await page.locator('#versions-list .tree').getAttribute('data-depth'), '5');
const treeFits = () => page.waitForFunction(() => {
  const list = document.querySelector('#versions-list'), box = list.getBoundingClientRect();
  return list.scrollWidth <= list.clientWidth && [...list.querySelectorAll('.vnode')].every(node => { const r = node.getBoundingClientRect(); return r.left >= box.left && r.right <= box.right + 0.5 && r.width >= 150; });
}, null, { timeout: 5000 }).then(() => true, () => false);
const step = () => page.locator('#versions-list .tree').evaluate(el => parseInt(el.style.getPropertyValue('--step'), 10));
assert.equal(await treeFits(), true, 'a deep tree fits a wide column');
assert.equal(await step(), 40);
await shot('tree-deep.png');
await page.locator('.viewer-shell > .col-handle.at-end').dblclick();
await page.waitForFunction(() => Math.round(document.querySelector('.versions').getBoundingClientRect().width) === 264);
assert.equal(await treeFits(), true, 'a deep tree fits the column at its usual width');
assert.ok(await step() < 40, 'the levels step in less when the column has less room');
await dragEdge('.viewer-shell > .col-handle.at-end', 300);
assert.equal(await widthOf('.versions'), 180, 'the column stops at its narrowest');
assert.equal(await treeFits(), true, 'a deep tree fits the narrowest column');
await shot('tree-narrow.png');
// The chronological list fits too.
await page.locator('[data-versions="time"]').click();
assert.equal(await page.locator('#versions-list').evaluate(el => el.scrollWidth <= el.clientWidth), true);
await page.locator('[data-versions="tree"]').click();
// The arrow keys move a focused edge; Home and a double click go back to the default.
await page.locator('.viewer-shell > .col-handle.at-end').focus();
await page.keyboard.press('ArrowLeft');
assert.equal(await widthOf('.versions'), 196);
await page.keyboard.press('Home');
await page.locator('.viewer-shell > .col-handle.at-start').dblclick();
await page.waitForFunction(() => Math.round(document.querySelector('.inspector').getBoundingClientRect().width) === 340);
assert.deepEqual([await widthOf('.inspector'), await widthOf('.versions')], [340, 264]);
assert.deepEqual(await columns(), {});

// The frame of Expandir crops too. Pulled inwards it cuts the image, on this device and without the image API; the
// result is a new version, and the version it was cut from stays as it is.
const frameState = () => page.evaluate(() => ({ width: Number(document.querySelector('#expand-width').value), height: Number(document.querySelector('#expand-height').value),
  go: document.querySelector('#edit-submit-label').textContent, cost: document.querySelector('#edit-cost').textContent, off: document.querySelector('#edit-submit').disabled, says: document.querySelector('#expand-dimensions').textContent }));
const dragHandle = async (handle, dx, dy) => {
  const grip = await page.locator(`#expand-frame [data-corner="${handle}"]`).boundingBox();
  await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2); await page.mouse.down();
  await page.mouse.move(grip.x + grip.width / 2 + dx, grip.y + grip.height / 2 + dy, { steps: 6 }); await page.mouse.up();
  await page.waitForTimeout(250);   // let go, the frame is fitted to the stage again
};
const uncropped = new URL(page.url()).hash.slice(3);
await page.locator('#edit-prompt').fill('');
await page.locator('#expand-open').click();
await page.locator('#expand-options').waitFor();
assert.equal(await page.locator('#expand-frame [data-corner]').count(), 8, 'a handle on every corner and every side');
await page.locator('#expand-fit').click();
await page.waitForTimeout(250);
let frame = await frameState();
assert.deepEqual([frame.width, frame.height, frame.off, frame.says], [1024, 1024, true, 'A moldura está do tamanho da imagem: puxe uma alça para dentro ou para fora.']);
assert.equal(await page.locator('#edit-error').textContent(), '', 'a frame at the size of the image is where a crop starts, not a mistake');
await dragHandle('w', 160, 0);
frame = await frameState();
assert.ok(frame.width < 1024 && frame.width > 700 && frame.height === 1024, `the left side is cut off (${frame.width} × ${frame.height})`);
assert.deepEqual([frame.go, frame.cost, frame.off, frame.says], ['Recortar', 'Sem custo: o recorte é feito neste aparelho.', false, 'Só recorte: o que fica fora da moldura sai, e nada é gerado.']);
assert.equal(await page.locator('#expand-engine').isHidden(), true, 'a crop has no model to choose');
await shot('crop-desktop.png');
const cropWidth = frame.width, cropsSent = [], sentBeforeCrop = creations.length;
const noteCrop = sent => { if (sent.url().endsWith('index.php?action=import')) cropsSent.push(sent.postDataJSON()); };
page.on('request', noteCrop);
await forgetToast();
await page.locator('#edit-submit').click();
// The frame stays where it was drawn, to crop again; the new version is one tap away.
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Recorte salvo como nova versão.', null, { timeout: 20000 });
assert.deepEqual([await page.locator('#action-panel').isVisible(), (await frameState()).width], [true, cropWidth], 'once saved, the crop frame stays as it was');
await openResult('Recorte salvo como nova versão.', 'v13 de 13');
page.off('request', noteCrop);
assert.deepEqual([cropsSent.length, cropsSent[0].parent_id, cropsSent[0].crop, /^data:image\/png;base64,/.test(cropsSent[0].data)], [1, uncropped, true, true]);
assert.equal(creations.length, sentBeforeCrop, 'a crop sends nothing to the image API');
assert.equal(await page.locator('#stage-meta').textContent(), `${cropWidth} × 1024`);
assert.match(await page.locator('#versions-list .vnode.current .vop').textContent(), /^Recorte/);
assert.equal(await page.locator('#versions-list .vnode.current .vsub').textContent(), `Recorte para ${cropWidth} × 1024`);
assert.equal(await page.locator('#prompt-heading').textContent(), 'Recorte');
assert.equal(await page.locator('#info-prompt').textContent(), `Recorte para ${cropWidth} × 1024: feito neste aparelho, sem usar a API. A versão de onde ele saiu continua salva.`);
assert.equal(await page.locator('#action-panel').isHidden(), true);
assert.deepEqual(await overPicture(), [true, true, true, true, true], 'the frame leaves the picture with the tool');
assert.equal(await finished(), 13, 'the version it was cut from is still there');
const cropId = new URL(page.url()).hash.slice(3);
// From the crop, one side is pulled in and another pushed out: what is outside the frame is cut off and the new
// area is generated, in one request. The frame is drawn from the open version, even while that version is being
// compared with the one it came from.
await page.locator('#compare').click();
await page.waitForFunction(() => document.querySelector('#stage-image').naturalWidth === 1024);
await page.locator('#expand-open').click();
await page.locator('#expand-options').waitFor();
assert.equal(await page.locator('#compare').getAttribute('aria-pressed'), 'false');
await page.locator('#expand-fit').click();
await page.waitForTimeout(250);
frame = await frameState();
assert.deepEqual([frame.width, frame.height], [cropWidth, 1024], 'the frame starts from the open version, not from the one it was compared with');
await dragHandle('n', 0, 80);
await dragHandle('e', 150, 0);
frame = await frameState();
assert.deepEqual([frame.go, frame.off, frame.width % 16, frame.height % 16, frame.says], ['Recortar e expandir', false, 0, 0, 'O que fica fora da moldura sai, e a área quadriculada é gerada.'], 'with a new area the frame takes sizes the image API generates');
assert.ok(frame.width > cropWidth && frame.height < 1024, `cut at the top, extended to the right (${frame.width} × ${frame.height})`);
assert.match(frame.cost, /^Custo estimado: ≈ US\$ 0,\d{3}$/);
assert.equal(await page.locator('#expand-engine').isVisible(), true);
await page.locator('#edit-prompt').fill('mais céu');
assert.equal(await page.locator('#action-note').textContent(), 'O texto da caixa diz o que deve aparecer nas áreas novas.');
await shot('crop-expand-desktop.png');
await forgetToast();
await page.keyboard.press('Control+Enter');
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Expansão pronta.', null, { timeout: 20000 });
assert.equal((await frameState()).width, frame.width, 'the frame stays after sending');
await openResult('Expansão pronta.', 'v14 de 14');
request = creations.at(-1);
assert.deepEqual([request.operation, request.parent_id, request.references[0].source_id, request.options.size, request.label], ['expand', cropId, cropId, `${frame.width}x${frame.height}`, `Recorte e expansão para ${frame.width} × ${frame.height}: mais céu`]);
assert.match(request.prompt, /^Expand the image by filling only the transparent areas.* Instructions for the new areas: mais céu$/);
assert.match(request.mask, /^data:image\/png;base64,/);
assert.equal(await page.locator('#stage-meta').textContent(), `${frame.width} × ${frame.height}`);
// Recortar: a step of its own. Its frame stays inside the image (pulled outward, a side stops at the edge), a
// proportion takes the largest frame of that shape, nothing goes to the image API, and the cropped image opens, ready
// to be edited or expanded (an expansion then never sees what was cut off).
const expanded = new URL(page.url()).hash.slice(3), [ew, eh] = [frame.width, frame.height];
await page.locator('#edit-prompt').fill('');
await page.locator('#crop-open').click();
await page.locator('#expand-options').waitFor();
await page.waitForTimeout(250);
assert.deepEqual([await page.locator('#action-title').textContent(), await page.locator('#expand-ratio-field').isVisible(), await page.locator('#expand-engine').isHidden(), await page.locator('#edit-prompt').isDisabled(), await page.locator('#edit-more').isHidden()],
  ['Recortar', true, true, true, true]);
frame = await frameState();
assert.deepEqual([frame.width, frame.height, frame.off, frame.go, frame.cost, frame.says], [ew, eh, true, 'Recortar', 'Sem custo: o recorte é feito neste aparelho.', 'A moldura pega a imagem inteira: puxe as alças para dentro ou escolha uma proporção.'], 'Recortar starts on the whole image');
await dragHandle('w', -120, 0);
frame = await frameState();
assert.deepEqual([frame.width, frame.height, frame.off], [ew, eh, true], 'pulled outward, a side stays on the edge of the image');
await dragHandle('e', -150, 0);
frame = await frameState();
assert.ok(frame.width < ew, `the right side is cut off (${frame.width} × ${frame.height})`);
assert.deepEqual([frame.height, frame.go, frame.off, frame.says], [eh, 'Recortar', false, 'O que fica fora da moldura sai. A imagem recortada abre como nova versão.']);
const cropFrameBox = await rect('#expand-frame'), cropStageBox = await rect('#expand-stage');
assert.ok(cropFrameBox.width > cropStageBox.width * 0.4 || cropFrameBox.height > cropStageBox.height * 0.6, 'let go, the frame is fitted to the stage again');
await page.locator('#expand-ratios [data-crop="1:1"]').click();
await page.waitForTimeout(250);
frame = await frameState();
const side = Math.min(ew, eh);
assert.deepEqual([frame.width, frame.height, await page.locator('#expand-ratios [aria-pressed="true"]').textContent()], [side, side, '1:1']);
await shot('crop-tool-desktop.png');
const sentBeforeTool = creations.length;
await page.locator('#edit-submit').click();
await versionLabel('v15 de 15');
assert.equal(creations.length, sentBeforeTool, 'a crop sends nothing to the image API');
assert.equal(await page.locator('#stage-meta').textContent(), `${side} × ${side}`);
assert.notEqual(new URL(page.url()).hash.slice(3), expanded, 'the cropped image is open');
assert.deepEqual([await page.locator('#action-panel').isHidden(), await page.locator('#crop-open').getAttribute('aria-pressed'), await page.locator('#edit-prompt').isDisabled()], [true, 'false', false], 'the crop is done: the image is ready to be edited');
assert.equal(await page.locator('#versions-list .vnode.current .vsub').textContent(), `Recorte para ${side} × ${side}`);
// A proportion locks the frame's shape, in Expandir as in Recortar: the handles and the typed sizes keep it until
// Livre. In Expandir it starts as the smallest frame of that shape around the whole image, with sides in multiples of
// 16 (so the shape is within 16 px of exact); Original is the image's own shape, a quarter larger all around.
const near = (f, rw, rh, by = 16) => Math.abs(f.width * rh - f.height * rw) <= by * Math.max(rw, rh);
const pressedRatio = () => page.locator('#expand-ratios [aria-pressed="true"]').textContent();
await page.locator('#expand-open').click();
await page.locator('#expand-options').waitFor();
assert.deepEqual([await page.locator('#expand-ratio-field').isVisible(), await page.locator('#expand-ratios button').count(), await pressedRatio()], [true, 10, 'Livre'], 'Expandir has the proportions of Recortar, and starts free');
await page.locator('#expand-ratios [data-crop="16:9"]').click();
await page.waitForTimeout(250);
frame = await frameState();
assert.ok(near(frame, 16, 9) && frame.width % 16 === 0 && frame.height % 16 === 0 && frame.width >= side && frame.height >= side, `16:9 around the whole image (${frame.width} × ${frame.height})`);
assert.deepEqual([await pressedRatio(), frame.go, frame.off, frame.says], ['16:9', 'Expandir', false, 'A área quadriculada é gerada.']);
await shot('expand-ratio-desktop.png');
let before = frame;
await dragHandle('se', 140, 10);
frame = await frameState();
assert.ok(frame.width > before.width && near(frame, 16, 9), `a corner keeps 16:9 (${frame.width} × ${frame.height})`);
before = frame;
await dragHandle('e', -90, 0);
frame = await frameState();
assert.ok(frame.width < before.width && frame.height < before.height && near(frame, 16, 9), `a side keeps 16:9 (${frame.width} × ${frame.height})`);
await page.locator('#expand-width').fill('2048'); await page.locator('#expand-width').blur();
frame = await frameState();
assert.deepEqual([frame.width, frame.height, await pressedRatio()], [2048, 1152, '16:9'], 'a typed side takes the other with it');
await page.locator('#expand-ratios [data-crop="free"]').click();
assert.equal(await pressedRatio(), 'Livre');
await dragHandle('e', 100, 0);
assert.deepEqual([(await frameState()).height, (await frameState()).width > 2048], [1152, true], 'Livre: a side moves on its own');
await page.locator('#expand-ratios [data-crop="original"]').click();
await page.waitForTimeout(250);
frame = await frameState();
assert.deepEqual([frame.width, frame.height, frame.width > side, await pressedRatio()], [frame.height, frame.height, true, 'Original'], 'Original: the image\'s own shape, larger all around');
await dragHandle('n', 0, -70);
frame = await frameState();
assert.equal(frame.width, frame.height, 'a side keeps the image\'s own shape');
await page.keyboard.press('Escape');
assert.equal(await page.locator('#action-panel').isHidden(), true);
// Recortar: the proportion stays while its handles are dragged and its sizes typed, to the pixel and inside the image.
await page.locator('#crop-open').click();
await page.locator('#expand-options').waitFor();
assert.equal(await pressedRatio(), 'Livre', 'each tool starts free');
await page.locator('#expand-ratios [data-crop="16:9"]').click();
await page.waitForTimeout(250);
before = await frameState();
assert.deepEqual([before.width, near(before, 16, 9, 1)], [side, true]);
await dragHandle('sw', 90, 0);
frame = await frameState();
assert.ok(frame.width < before.width && frame.height < before.height && near(frame, 16, 9, 1), `a corner keeps 16:9 (${frame.width} × ${frame.height})`);
await dragHandle('e', 400, 0);
frame = await frameState();
assert.ok(frame.width <= side && near(frame, 16, 9, 1), `pulled outward, it stops at the image (${frame.width} × ${frame.height})`);
await page.locator('#expand-width').fill('400'); await page.locator('#expand-width').blur();
frame = await frameState();
assert.deepEqual([frame.width, frame.height, frame.go, frame.off], [400, 225, 'Recortar', false]);
await page.keyboard.press('Escape');
assert.deepEqual(await overPicture(), [true, true, true, true, true]);
// Cobrir: the painted area goes erased, so the model can't see what was there. Without text it is filled with what
// surrounds it; with text, it is drawn from the text. On the picture a covered area is solid, not a veil.
const beforeCover = new URL(page.url()).hash.slice(3);
await page.locator('#brush-toggle').click();
await page.locator('#brush-bar').waitFor();
assert.equal(await page.locator('#mask-cover').getAttribute('aria-pressed'), 'false', 'the brush starts by marking');
const coverArea = await page.locator('#mask-canvas').boundingBox();
await page.mouse.move(coverArea.x + coverArea.width * 0.3, coverArea.y + coverArea.height * 0.3); await page.mouse.down();
await page.mouse.move(coverArea.x + coverArea.width * 0.42, coverArea.y + coverArea.height * 0.38, { steps: 8 }); await page.mouse.up();
assert.equal(await page.locator('#mask-canvas').evaluate(c => getComputedStyle(c).opacity), '0.55', 'a marked area is a veil');
await page.locator('#mask-cover').click();
assert.deepEqual([await page.locator('#mask-cover').getAttribute('aria-pressed'), await page.locator('#mask-go').textContent(), await page.locator('#brush-hint').textContent()], ['true', 'Preencher área', 'Pinte o que a IA não deve ver']);
assert.equal(await page.locator('#mask-canvas').evaluate(c => getComputedStyle(c).opacity), '1', 'a covered area hides what is under it');
await shot('cover-desktop.png');
await page.locator('#mask-done').click();
assert.deepEqual([await page.locator('#mask-chip-text').textContent(), await page.locator('#edit-submit-label').textContent(), await page.locator('#edit-prompt').getAttribute('placeholder')],
  ['Só a área coberta', 'Preencher área', 'O que deve aparecer na área coberta (opcional). Em branco, ela é preenchida com o que está em volta.']);
assert.equal(await page.locator('#mask-canvas').evaluate(c => getComputedStyle(c).opacity), '1', 'and stays hidden while the request is written');
// The alpha of the image sent, inside the painted area and far from it.
const sentAlpha = data => page.evaluate(async data => {
  const image = new Image(); image.src = data; await image.decode();
  const c = document.createElement('canvas'); c.width = image.naturalWidth; c.height = image.naturalHeight;
  const g = c.getContext('2d'); g.drawImage(image, 0, 0);
  return [[0.31, 0.31], [0.9, 0.9]].map(([x, y]) => g.getImageData(Math.floor(x * c.width), Math.floor(y * c.height), 1, 1).data[3]);
}, data);
await forgetToast();
await page.locator('#edit-submit').click();
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Edição pronta.', null, { timeout: 20000 });
request = creations.at(-1);
assert.deepEqual([request.operation, request.parent_id, request.label, request.options.thinking, request.references.length, request.references[0].source_id, 'id' in request.references[0]],
  ['edit', beforeCover, 'Área coberta preenchida', 'off', 1, beforeCover, false]);
assert.match(request.prompt, /^The area marked by the mask was erased on purpose before sending: it is empty .*Fill it with what naturally belongs there/s);
assert.match(request.mask, /^data:image\/png;base64,/);
assert.match(request.references[0].data, /^data:image\/png;base64,/);
assert.deepEqual(await sentAlpha(request.references[0].data), [0, 255], 'the image goes with the painted area erased, and the rest as it is');
await page.locator('#edit-prompt').fill('um gato dormindo');
await forgetToast();
await page.locator('#edit-submit').click();
await page.waitForFunction(() => document.querySelector('#toast-text').textContent === 'Edição pronta.', null, { timeout: 20000 });
request = creations.at(-1);
assert.deepEqual([request.typed, request.label], ['um gato dormindo', 'Área coberta: um gato dormindo']);
assert.match(request.prompt, /^The area marked by the mask was erased on purpose before sending.*\n\nRequest: um gato dormindo$/s);
assert.deepEqual(await sentAlpha(request.references[0].data), [0, 255]);
await openResult('Edição pronta.', 'v17 de 17');
assert.match(await page.locator('#versions-list .vnode.current .vsub').textContent(), /^Área coberta: um gato dormindo/);
// Cobrir is remembered; off again, the brush marks.
await page.locator('#edit-prompt').fill('');
await page.locator('#brush-toggle').click();
assert.equal(await page.locator('#mask-cover').getAttribute('aria-pressed'), 'true', 'Cobrir is remembered');
await page.locator('#mask-cover').click();
assert.deepEqual([await page.locator('#mask-go').textContent(), await page.locator('#brush-hint').textContent()], ['Refazer área', 'Pinte o que deve ser refeito']);
await page.locator('#mask-clear').click();
await page.locator('#brush-toggle').click();
assert.deepEqual(await overPicture(), [true, true, true, true, true]);
// The arrow keys move a focused handle, and Esc leaves the tool without leaving the viewer.
await page.locator('#edit-prompt').fill('');
await page.locator('#expand-open').click();
await page.locator('#expand-options').waitFor();
frame = await frameState();
await page.locator('#expand-frame [data-corner="e"]').focus();
await page.keyboard.press('ArrowRight');
assert.equal((await frameState()).width, frame.width + 16);
await page.keyboard.press('Escape');
assert.deepEqual([await page.locator('#action-panel').isHidden(), await page.locator('#viewer').evaluate(dialog => dialog.open)], [true, true]);
assert.deepEqual(await overPicture(), [true, true, true, true, true]);
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
assert.equal(await widthOf('.composer'), 340);
// The same edge is in the new-image column.
await dragEdge('.composer > .col-handle', 80);
assert.equal(await widthOf('.composer'), 420);
assert.deepEqual(await columns(), { side: 420 });
await page.locator('.composer > .col-handle').dblclick();
await page.waitForFunction(() => Math.round(document.querySelector('.composer').getBoundingClientRect().width) === 340);
assert.equal(await noOverflow(), true);
// Converter para JPEG, in the details: a PNG of the library becomes a JPEG in place; a JPEG has no such button.
const importPicture = type => page.evaluate(async type => {
  const c = document.createElement('canvas'); c.width = 640; c.height = 480; const x = c.getContext('2d'), data = x.createImageData(640, 480);
  for (let i = 0; i < data.data.length; i += 4) { const p = i / 4; data.data[i] = (p % 640) / 2.5; data.data[i + 1] = p / 640 / 2; data.data[i + 2] = 100 + Math.random() * 90; data.data[i + 3] = 255; }
  x.putImageData(data, 0, 0);
  const state = await (await fetch('index.php?action=state')).json();
  const sent = await fetch('index.php?action=import', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf }, body: JSON.stringify({ data: c.toDataURL(type, 0.9), name: 'Praia' }) });
  return (await sent.json()).image;
}, type);
const pngPicture = await importPicture('image/png'), jpegPicture = await importPicture('image/jpeg');
assert.deepEqual([pngPicture.mime, jpegPicture.mime], ['image/png', 'image/jpeg']);
await page.goto(`${base}/#v/${jpegPicture.id}`);
await page.locator('#viewer[open]').waitFor();
await page.locator('#info-details dt').first().waitFor();
assert.ok(await page.locator('#convert-jpeg').isHidden(), 'a JPEG has nothing to convert');
await page.goto(`${base}/#v/${pngPicture.id}`);
await page.locator('#viewer[open]').waitFor();
await page.locator('#convert-jpeg').waitFor();
assert.equal(await page.locator('#convert-jpeg').innerText(), 'Converter para JPEG');
await page.locator('#convert-jpeg').click();
await page.locator('#confirm-dialog[open]').waitFor();
assert.equal(await page.locator('#confirm-title').innerText(), 'Converter para JPEG?');
assert.match(await page.locator('#confirm-message').innerText(), /^Esta imagem vira um JPEG com qualidade 85, várias vezes menor e sem diferença visível\. O PNG é substituído e não dá para trazer de volta\./);
await page.locator('#confirm-yes').click();
await page.waitForFunction(() => /^Convertida para JPEG: .+ viraram .+\.$/.test(document.querySelector('#toast-text').textContent), null, { timeout: 20000 });
await page.locator('#info-details dt', { hasText: /^Convertida$/ }).waitFor();
assert.match(await page.locator('#info-details').innerText(), /De PNG \([\d,]+ MB\) para JPEG com qualidade 85, em /);
assert.ok(await page.locator('#convert-jpeg').isHidden(), 'and then there is nothing more to convert');
const convertedFile = await page.evaluate(async id => { const r = await fetch(`index.php?action=image&id=${id}`); return [r.headers.get('Content-Type'), (await r.arrayBuffer()).byteLength]; }, pngPicture.id);
assert.equal(convertedFile[0], 'image/jpeg', 'the library serves the JPEG');
await page.keyboard.press('Escape');
await page.locator('#viewer').waitFor({ state: 'hidden' });
assert.deepEqual(errors, []);
console.log('PASS browser: simple and advanced modes with quality levels, planning fallback, parallel generations with previews, request details from the card and the version panel, editing from the gallery, zoom and pan, masked edits (the circle of the brush, the image sent as it is), version tree and timeline (a deep tree inside its column, columns resized by dragging), one prompt box and one button for every edit request, an area made again without text, compare, expansion, a crop saved as a version and a crop extended in one request, upscale, cost estimates, photoreal and enhance edits from a panel with notes, cleaning before sending (remembered per account), the local cleanup as a version, upscale with cleanup, API balance (planning counted, corrected from the amount at the top), version cover, grouped gallery, stacks made by hand (videos too), hidden items and the Ocultas filter, search, favorites by version, import, paste, trash/restore/delete, a 40-image import loaded whole with the timeline, origin filters, orders, selection, albums, drag to reorder, bulk trash and delete, cancel, a plain notice when OpenAI has no credit left, dismiss with the × (failed requests leave the gallery by themselves and stay in Pedidos), one request per image, gallery reference picker and @ mentions, persisted options, version links, arrow keys between items and versions, full screen with edge-revealed controls, video upload with autoplay (muted, looping), hover previews, frame-by-frame stepping and saved frames, phone layout (pictures and videos across the whole width, one finger scrolls, two zoom), lock, accounts with separate libraries, MCP token.');
await browser.close();
