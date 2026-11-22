import { chromium } from '@playwright/test';
import { mkdir, readFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import assert from 'node:assert/strict';

// The comic adaptation of a story in a browser, against the isolated instance prepared by tests/http_checks.py (run
// after tests/stories-browser.mjs or alone). tests/comics_fixture.php prepares the story; the drawings run in
// tests/complete_fixture_job.php, the simulated image API of the other browser tests: no real API calls.
const runFixture = promisify(execFile);
await mkdir('tests/artifacts', { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ locale: 'pt-BR', viewport: { width: 1440, height: 900 }, acceptDownloads: true });
// The model lists come from what the account kept, never from the providers' APIs: no real calls in tests.
await context.route('**/index.php?action=models', route => route.continue({ postData: JSON.stringify({ refresh: false, force: false }) }));
const page = await context.newPage();
const errors = [];
page.on('pageerror', e => errors.push(e.message));
const base = 'http://127.0.0.1:8099';
await page.route('**/index.php?action=run', async route => {
  const { id } = route.request().postDataJSON();
  let response;
  try {
    const { stdout } = await runFixture('php', ['tests/complete_fixture_job.php', id], { env: { ...process.env, FIXTURE_SECONDS: '1' } });
    response = { status: 200, body: stdout };
  } catch (e) { response = { status: 500, body: JSON.stringify({ error: 'fixture: ' + String(e.stderr || e.message).slice(0, 300) }) }; }
  await route.fulfill({ ...response, contentType: 'application/json' }).catch(() => {});
});
const shot = async name => {
  await page.screenshot({ path: `tests/artifacts/${name}` });
  console.log(`VISUAL_${name}:` + (await readFile(`tests/artifacts/${name}`)).toString('base64'));
};
const toastSays = text => page.waitForFunction(t => document.querySelector('#toast-text').textContent === t, text, { timeout: 15000 });
const loaded = locator => locator.evaluate(img => img.complete && img.naturalWidth > 0 ? true : new Promise(resolve => { img.addEventListener('load', () => resolve(img.naturalWidth > 0), { once: true }); img.addEventListener('error', () => resolve(false), { once: true }); }));
const card = n => page.locator('.comic-card').nth(n - 1);
const pass = message => console.log(`PASS ${message}`);

const { stdout: storyId } = await runFixture('php', ['tests/comics_fixture.php'], { env: process.env });
assert.match(storyId, /^[a-f0-9]{32}$/);

await page.goto(`${base}/#historias/${storyId}`);
await page.locator('#auth-name').fill('dono');
await page.locator('#auth-password').fill(process.env.TEST_PASSWORD);
await page.locator('#auth-submit').click();
await page.locator('#application').waitFor();
await page.goto(`${base}/#historias/${storyId}`);
await page.locator('#side-comic').waitFor({ timeout: 15000 });
assert.equal(await page.locator('#side-comic small').textContent(), 'Mangá · 3 páginas · 0 desenhadas');
pass('the story shows its adaptation on its side');

// The pages, with what each one still needs
await page.locator('#side-comic').click();
await page.locator('#comic-dialog[open] .comic-card').first().waitFor();
assert.equal(await page.locator('#comic-title').textContent(), 'Mangá: O Farol em Quadrinhos');
assert.equal(await page.locator('.comic-card').count(), 3);
assert.equal(await card(2).locator('.comic-badge').textContent(), 'Faltam fichas');
assert.equal(await card(1).locator('.comic-badge').textContent(), 'Só roteiro');
assert.ok(await loaded(card(1).locator('img.sketch')), 'the storyboard of a page that isn\'t drawn shows in its place');
assert.match(await page.locator('.comic-warn').first().textContent(), /A página 2 espera fichas da adaptação com imagem de: Carla/);
assert.equal(await page.locator('.comic-warn', { hasText: 'amostra das letras' }).count(), 0, 'the lettering has its sample');
assert.deepEqual(await page.locator('.comic-chips .pill').allTextContents(), ['Mangá', 'Preto e branco', 'Lê da direita para a esquerda', 'Páginas 2:3', 'Tamanho 1×']);
await shot('comics-pages.png');
pass('the pages show with their storyboards, and what is missing');

// One page: its storyboard, its script, the cost, and the drawing
await card(1).click();
await page.locator('.comic-page').waitFor();
assert.equal(await page.locator('.comic-count').textContent(), 'Página 1 de 3');
assert.ok(await loaded(page.locator('.comic-figure.sketch img')), 'the writer\'s storyboard shows');
assert.equal(await page.locator('.comic-figure.sketch figcaption').textContent(), 'Storyboard desenhado pelo escritor');
const lines = await page.locator('.comic-line').allTextContents();
assert.deepEqual(lines, ['B1 Narração“Naquela noite, ninguém dormiu.”', 'Onomatopeia (the rain)“SHAAA”', 'B2 Fala · Ana“Tem alguém aí?”'], String(lines));
assert.match(await page.locator('.comic-meta').textContent(), /Adapta o capítulo 1: A chegada · 2 quadros · fileiras 1 · 1 · roteiro do escritor/);
assert.match(await page.locator('#comic-cost').textContent(), /^1024 × 1536 · cerca de US\$ /);
assert.equal(await page.locator('.comic-draw-options .switch').count(), 0, 'the first page has no previous page to follow');
await shot('comics-page.png');
await page.locator('#comic-instruction').fill('Mais chuva no primeiro quadro.');
await page.locator('.comic-actions button.primary', { hasText: 'Desenhar' }).click();
await toastSays('Desenhando a página 1.');
await page.locator('.comic-figure:not(.sketch) .figure-open img').waitFor({ timeout: 30000 });
assert.ok(await loaded(page.locator('.comic-figure:not(.sketch) .figure-open img')), 'the drawing takes the page\'s place when it is ready');
assert.equal(await page.locator('.comic-actions h3', { hasText: 'Corrigir o desenho' }).count(), 1);
assert.equal(await page.locator('#comic-instruction').inputValue(), '', 'the notes for one drawing are not kept for the next');
pass('the person draws a page and it shows when ready');

// A page waiting for a sheet can't be drawn, and says why
await page.locator('.comic-nav [aria-label="Próxima página"]').click();
assert.equal(await page.locator('.comic-count').textContent(), 'Página 2 de 3');
assert.match(await page.locator('.comic-script .comic-warn').textContent(), /Falta a ficha da adaptação com imagem de: Carla/);
assert.equal(await page.locator('.comic-actions button.primary').isDisabled(), true);
assert.equal(await page.locator('.comic-draw-options .switch input').isChecked(), true, 'the previous page goes along by default');
pass('a page with a character without a sheet waits for it');

// Page 3, drawn without the previous page; then its script changed by hand
await page.locator('.comic-nav [aria-label="Próxima página"]').click();
assert.equal(await page.locator('.comic-figure.sketch figcaption').textContent(), 'Storyboard montado pelo app a partir do roteiro');
await page.locator('.comic-draw-options .switch').click();
await page.locator('.comic-actions button.primary', { hasText: 'Desenhar' }).click();
await page.locator('.comic-figure:not(.sketch) .figure-open img').waitFor({ timeout: 30000 });
await page.locator('.page-tools button', { hasText: 'Editar o roteiro' }).click();
await page.locator('.comic-editor').waitFor();
assert.equal(await page.locator('.comic-panel-edit').count(), 1);
await page.locator('.comic-editor > .btn', { hasText: 'Quadro' }).click();
const second = page.locator('.comic-panel-edit').nth(1);
await second.locator('textarea').first().fill('Gulls over the water.');
await second.locator('.link-btn', { hasText: '+ Texto' }).click();
await second.locator('.comic-row.text select').selectOption('sfx');
await second.locator('.comic-row.text input').first().fill('the gulls');
await second.locator('.comic-row.text textarea').fill('KYAA KYAA');
await second.locator('.link-btn', { hasText: '+ Personagem' }).click();
await second.locator('.comic-row:not(.text) input').first().fill('Ana');
await second.locator('.comic-row:not(.text) input').first().blur();
await page.locator('.comic-editor input[inputmode=numeric]').fill('1, 1');
await shot('comics-editor.png');
await page.locator('.comic-editor button[type=submit]').click();
await toastSays('Roteiro salvo.');
await page.locator('.comic-page').waitFor();
assert.equal(await page.locator('.comic-panels > li').count(), 2);
assert.match(await page.locator('.comic-panels > li').nth(1).textContent(), /Ana.*Gulls over the water\..*Onomatopeia \(the gulls\)“KYAA KYAA”/);
assert.match(await page.locator('.comic-script').textContent(), /O roteiro mudou depois deste desenho/);
assert.equal(await page.locator('.comic-meta').textContent().then(t => t.includes('roteiro seu')), true);
pass('the person changes a page\'s script by hand, and the page says its drawing is older');

// A page written by hand
await page.locator('.comic-nav .btn', { hasText: 'Páginas' }).click();
assert.equal(await card(3).locator('.comic-badge').textContent(), 'Roteiro mudou');
await page.locator('.comic-foot .btn', { hasText: 'Página escrita por você' }).click();
await page.locator('.comic-editor button[type=submit]').click();
await page.locator('.comic-editor .error', { hasText: 'descreva o que ele mostra' }).waitFor();
await page.locator('.comic-editor > label input').first().fill('Ana sobe a escada.');
await page.locator('.comic-panel-edit textarea').first().fill('A spiral staircase, seen from below.');
await page.locator('.comic-editor button[type=submit]').click();
await toastSays('Página criada.');
assert.equal(await page.locator('.comic-count').textContent(), 'Página 4 de 4');
// The arrows that change the order only show in "Reordenar", on the pages, so they aren't pressed by mistake
assert.equal(await page.locator('.page-tools .icon-btn').count(), 0, 'no arrows among the page\'s tools');
await page.locator('.comic-nav .btn', { hasText: 'Páginas' }).click();
assert.equal(await page.locator('.comic-card-move').count(), 0, 'no arrows on the pages in plain view');
await page.locator('#comic-reorder', { hasText: 'Reordenar' }).click();
assert.equal(await page.locator('.comic-card-move').count(), 4);
assert.equal(await page.locator('[aria-label="Página 1 para antes"]').isDisabled(), true);
await shot('comics-reorder.png');
await page.locator('[aria-label="Página 4 para antes"]').click();
// The moved page keeps its arrow focused, so it can go on moving
await page.waitForFunction(() => document.activeElement?.getAttribute('aria-label') === 'Página 3 para antes');
await page.locator('#comic-reorder', { hasText: 'Pronto' }).click();
assert.equal(await page.locator('.comic-card-move').count(), 0);
assert.match(await card(3).locator('.comic-card-text').textContent(), /^Página 3Ana sobe a escada\./);
await card(3).click();
assert.equal(await page.locator('.comic-count').textContent(), 'Página 3 de 4');
await page.locator('.page-tools button', { hasText: 'Excluir' }).click();
await page.locator('#confirm-yes').click();
await toastSays('Página excluída.');
assert.equal(await page.locator('.comic-card').count(), 3);
pass('a page is added by hand, moved and deleted');

// The settings: a comic book reads left to right
await page.locator('.comic-top .btn', { hasText: 'Configurar' }).click();
await page.locator('.comic-settings [role=group][aria-label=Tipo] button', { hasText: 'HQ' }).click();
await page.waitForFunction(() => document.querySelector('.comic-chips .pill')?.textContent === 'HQ');
assert.equal(await page.locator('.comic-chips .pill').nth(2).textContent(), 'Lê da esquerda para a direita');
assert.equal(await page.locator('#side-comic small').textContent(), 'HQ · 3 páginas · 2 desenhadas');
assert.match(await page.locator('.comic-kind-hint').textContent(), /^HQ: como uma revista de série/);
// A graphic novel is a kind of its own, with its own language
await page.locator('.comic-settings [role=group][aria-label=Tipo] button', { hasText: 'Graphic novel' }).click();
await page.waitForFunction(() => document.querySelector('.comic-chips .pill')?.textContent === 'Graphic novel');
assert.equal(await page.locator('.comic-chips .pill').nth(2).textContent(), 'Lê da esquerda para a direita');
assert.equal(await page.locator('#side-comic small').textContent(), 'Graphic novel · 3 páginas · 2 desenhadas');
assert.match(await page.locator('.comic-kind-hint').textContent(), /^Graphic novel: um livro lido de uma vez, com ritmo de romance/);
assert.equal(await page.locator('#comic-title').textContent(), 'Graphic novel: O Farol em Quadrinhos');
{ const group = await page.locator('.comic-settings [role=group][aria-label=Tipo]').boundingBox(), grid = await page.locator('.comic-settings-grid').boundingBox();
  assert.ok(group.x + group.width <= grid.x + grid.width + 1, 'the three kinds fit in the settings'); }
await shot('comic-graphic-novel-settings.png');
await page.locator('.comic-settings [role=group][aria-label=Tipo] button', { hasText: 'Mangá' }).click();
await page.waitForFunction(() => document.querySelector('.comic-chips .pill')?.textContent === 'Mangá');
assert.equal(await page.locator('.comic-chips .pill').nth(2).textContent(), 'Lê da direita para a esquerda');
assert.match(await page.locator('.comic-kind-hint').textContent(), /flashbacks com fundo preto/);
await page.locator('.comic-top .btn', { hasText: 'Configurar' }).click();
pass('the comic\'s settings change from its window: manga, comic book or graphic novel, each with what sets it apart');

// Asking the writer to go on follows the plan of the adaptation
await page.locator('.comic-foot .btn', { hasText: 'Pedir ao escritor' }).click();
await page.waitForFunction(() => !document.querySelector('#comic-dialog').open);
assert.equal(await page.locator('#chat-input').inputValue(), 'Continue a adaptação em mangá, seguindo o plano da adaptação: roteirize as próximas páginas com storyboard, confira as fichas que faltam e desenhe.');
await page.locator('#chat-input').fill('');
await page.locator('#side-comic').click();
await page.locator('#comic-dialog[open] .comic-card').first().waitFor();
pass('asking the writer for more pages follows the plan of the adaptation');

// The reader: two pages side by side on a wide screen, right to left
await page.locator('.comic-top .btn', { hasText: 'Ler' }).click();
await page.locator('#comic-reader[open] .comic-stage img').first().waitFor();
assert.equal(await page.locator('.comic-bar-count').textContent(), '1–3 de 3', 'the two drawn pages side by side on a wide screen (page 2 isn\'t drawn)');
const order = await page.locator('.comic-stage img').evaluateAll(imgs => imgs.map(i => i.alt));
assert.deepEqual(order, ['Página 3', 'Página 1'], 'right to left, the first page is on the right');
await page.waitForFunction(() => [...document.querySelectorAll('.comic-stage img')].every(i => i.complete && i.naturalWidth > 0));
await shot('comics-reader-spread.png');
await page.locator('.comic-bar [aria-label="Duas páginas lado a lado"]').click();
assert.equal(await page.locator('.comic-bar-count').textContent(), '1 de 3');
const saved = page.waitForRequest(r => r.url().endsWith('action=story-comic') && r.postDataJSON()?.mode === 'reading', { timeout: 10000 });
await page.keyboard.press('ArrowLeft');
assert.equal(await page.locator('.comic-bar-count').textContent(), '3 de 3', 'in a manga, the left arrow turns to the next page');
assert.equal((await saved).postDataJSON().page.length, 32, 'where the person stopped is kept');
await page.keyboard.press('ArrowLeft');
await toastSays('Última página.');
await page.keyboard.press('ArrowRight');
assert.equal(await page.locator('.comic-bar-count').textContent(), '1 de 3');
// A tap in the middle hides the bars; on the left, the next page
const box = await page.locator('.comic-stage').boundingBox();
await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
assert.equal(await page.locator('.comic-bar.top').isHidden(), true);
await page.mouse.click(box.x + box.width * 0.1, box.y + box.height / 2);
await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
assert.equal(await page.locator('.comic-bar-count').textContent(), '3 de 3');
await shot('comics-reader.png');
await page.keyboard.press('Escape');
await page.locator('#comic-reader').waitFor({ state: 'hidden' });
assert.equal(await page.locator('#comic-dialog').evaluate(d => d.open), true, 'closing the reader goes back to the pages');
pass('the reader turns pages right to left, by keys and taps, and keeps the place');

// The downloads: a PDF that reads right to left, and a CBZ with ComicInfo.xml
const pdfDownload = page.waitForEvent('download');
await page.locator('.comic-top [data-export=pdf]').click();
const pdf = await readFile(await (await pdfDownload).path());
const pdfText = pdf.toString('latin1');
assert.ok(pdfText.startsWith('%PDF-1.7') && pdfText.includes('/Direction /R2L') && (pdfText.match(/\/Type \/Page /g) || []).length === 2 && pdfText.trimEnd().endsWith('%%EOF'), 'a PDF of the drawn pages, read right to left');
assert.equal((await pdfDownload).suggestedFilename(), 'o-farol-em-quadrinhos.pdf');
const cbzDownload = page.waitForEvent('download');
await page.locator('.comic-top [data-export=cbz]').click();
const cbz = await readFile(await (await cbzDownload).path());
assert.ok(cbz.subarray(0, 2).toString() === 'PK' && cbz.includes('001.jpg') && cbz.includes('002.jpg') && cbz.includes('<Manga>YesAndRightToLeft</Manga>'), 'a CBZ with the pages in order and ComicInfo.xml');
pass('the comic downloads as PDF and CBZ');

// The adaptation's own workspace, apart from the book's: its bible, its plan, its sheets and letters
assert.deepEqual(await page.locator('.comic-space strong').allTextContents(), ['Planejamento da adaptação', 'Bíblia da adaptação', 'Fichas e letras da adaptação']);
assert.equal(await page.locator('[data-comic-space=cast] small').textContent(), '1 personagem · 1 lugar · letras com amostra');
await page.locator('[data-comic-space=comic_notes]').click();
await page.locator('#story-doc-dialog[open]').waitFor();
assert.equal(await page.locator('#story-doc-title').textContent(), 'Bíblia da adaptação');
await page.locator('#story-doc-text').fill('Nos quadrinhos, Carla conta a história.');
await page.locator('#story-doc-form button[type=submit]').click();
await toastSays('Salvo. O escritor vê a mudança na próxima mensagem.');
assert.equal(await page.locator('.side-doc', { hasText: 'Bíblia da história' }).locator('small').textContent(), 'Vazia: o escritor preenche', 'the book\'s bible stays as it was');
await page.locator('#side-comic').click();
await page.locator('#comic-dialog[open] .comic-space').waitFor();
assert.match(await page.locator('[data-comic-space=comic_notes] small').textContent(), /^6 palavras · /);
await page.locator('[data-comic-space=comic_plan]').click();
await page.locator('#confirm-yes').click();
await page.locator('#story-doc-dialog[open]').waitFor();
assert.equal(await page.locator('#story-doc-title').textContent(), 'Planejamento da adaptação (spoilers)');
assert.equal(await page.locator('#story-doc-text').inputValue(), '');
await page.locator('#story-doc-text').fill('Abrir no farol, de noite.');
await page.locator('#story-doc-form button[type=submit]').click();
await toastSays('Salvo. O escritor vê a mudança na próxima mensagem.');
pass('the adaptation has a bible and a plan of its own');
await page.locator('#side-comic').click();
await page.locator('#comic-dialog[open] [data-comic-space=cast]').click();
await page.locator('#story-cast-dialog[open]').waitFor();
assert.equal(await page.locator('#story-cast-title').textContent(), 'Fichas e letras da adaptação');
assert.equal(await page.locator('#story-cast-body #cast-style').count(), 0, 'the adaptation\'s style is in its settings, not here');
assert.equal(await page.locator('#cast-lettering').inputValue(), 'Hand-lettered manga capitals.');
assert.deepEqual(await page.locator('#story-cast-body .sheet-card h4').allTextContents(), ['Ana', 'O Farol']);
const bookCarla = page.locator('.book-sheet', { hasText: 'Carla' });
assert.equal(await page.locator('.book-sheet', { hasText: 'Ana' }).locator('small').textContent(), 'personagem · 1 imagem · já na adaptação');
await page.locator('.book-sheets').scrollIntoViewIfNeeded();
await shot('comic-space-cast.png');
await bookCarla.locator('button', { hasText: 'Trazer' }).click();
await toastSays('A ficha de Carla está na adaptação.');
assert.deepEqual(await page.locator('#story-cast-body .sheet-card h4').allTextContents(), ['Ana', 'Carla', 'O Farol']);
assert.equal(await bookCarla.locator('.btn').count(), 0, 'a sheet already in the adaptation has nothing to bring');
await page.locator('#story-cast-dialog [data-close=story-cast-dialog]').first().click();
await page.locator('#side-cast').click();
await page.locator('#story-cast-dialog[open]').waitFor();
assert.equal(await page.locator('#story-cast-title').textContent(), 'Fichas e estilo');
assert.equal(await page.locator('#cast-lettering').inputValue(), 'Elegant serif for the book.', 'the book keeps its own letters');
assert.equal(await page.locator('#story-cast-body .book-sheet').count(), 0);
await page.locator('#story-cast-dialog [data-close=story-cast-dialog]').first().click();
await page.locator('#side-comic').click();
await page.locator('#comic-dialog[open] .comic-card').first().waitFor();
assert.equal(await card(2).locator('.comic-badge').textContent(), 'Só roteiro', 'with Carla\'s sheet in the adaptation, page 2 can be drawn');
pass('the adaptation has sheets and letters of its own, and brings the book\'s in');

// On a phone: the window fits, the page stacks
await page.setViewportSize({ width: 390, height: 844 });
await card(1).click();
await page.locator('.comic-page').waitFor();
assert.equal(await page.locator('#comic-dialog').evaluate(d => d.scrollWidth <= d.clientWidth + 1), true, 'nothing overflows the window on a phone');
await shot('comics-phone.png');
await page.locator('#comic-dialog [data-close=comic-dialog]').click();
pass('the window fits a phone');

assert.deepEqual(errors, []);
await browser.close();
console.log('comic browser checks passed');
