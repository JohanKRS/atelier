import './i18n-setup.mjs';
import { chromium } from '@playwright/test';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import assert from 'node:assert/strict';
import { unzip } from '../assets/pack.js';
import { zip } from '../assets/comics.js';

// The visual novel section in a browser, against the isolated instance prepared by tests/http_checks.py: a project from
// the person's documents (pasted text and a .docx), scenes written by hand, the tree with its warnings, the variables and
// routes, a background made from the page (with the simulated image API), the preview played to an ending and the game
// for Ren'Py. No real API calls.
const runFixture = promisify(execFile);
await mkdir('tests/artifacts', { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ locale: 'pt-BR', viewport: { width: 1440, height: 900 }, acceptDownloads: true });
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
  if (process.env.VISUAL_BASE64) console.log(`VISUAL_${name}:` + (await readFile(`tests/artifacts/${name}`)).toString('base64'));
};
const noOverflow = () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const toastSays = text => page.waitForFunction(t => document.querySelector('#toast-text').textContent.startsWith(t), text, { timeout: 15000 });
const askName = async value => { await page.locator('#name-dialog').waitFor(); await page.locator('#name-input').fill(value); await page.locator('#name-ok').click(); };
const confirm = async () => { await page.locator('#confirm-dialog').waitFor(); await page.locator('#confirm-yes').click(); };
const pass = message => console.log(`PASS ${message}`);

await page.goto(base);
const named = await page.locator('#auth-name').isVisible();
if (named) await page.locator('#auth-name').fill('dono');
await page.locator('#auth-password').fill(process.env.TEST_PASSWORD);
await page.locator('#auth-submit').click();
await page.locator('#application').waitFor();
await page.locator('#settings-dialog[open]').waitFor({ timeout: 3000 }).then(() => page.keyboard.press('Escape')).catch(() => {});

// The section
await page.locator('.section-switch [data-section=vn]').click();
await page.waitForFunction(() => location.hash === '#vn');
assert.equal(await page.locator('#stories-title').textContent(), 'Visual Novel');
await page.locator('#stories-empty-title', { hasText: 'Nenhuma visual novel ainda' }).waitFor();
pass('the Visual Novel section has its own list and address');
await page.locator('#story-new').click();
await page.locator('.vn-bar').waitFor();
assert.match(new URL(page.url()).hash, /^#vn\/[a-f0-9]{32}$/);
const projectId = new URL(page.url()).hash.slice(4);
assert.equal(await page.locator('.story-title strong').textContent(), 'Nova visual novel');
assert.equal(await page.locator('#chat-replies').count(), 0, 'the writer\'s replies are always shown in a visual novel');
await page.locator('.chat-empty', { hasText: 'visual novel' }).waitFor();
await page.locator('.vn-empty', { hasText: 'Nenhuma cena ainda' }).waitFor();
pass('a new project opens with the tree, empty, and the writer ready');

// The person's documents: a pasted text split at its headings, and a Word file
await page.locator('.vn-empty button', { hasText: 'Importar documentos' }).click();
await page.locator('.vn-import[open]').waitFor();
await page.locator('.vn-import .switch', { hasText: 'Separar por títulos' }).click();
await page.locator('.vn-import textarea').fill('# Ana\nBarista do Café Central, 24 anos, ri alto.\n\nTem medo de ficar sozinha.\n\n# Café Central\nUm café pequeno, de madeira escura, perto da estação.');
await page.locator('.vn-import button', { hasText: 'Adicionar o texto colado' }).click();
assert.equal(await page.locator('.vn-import-list li').count(), 2);
await page.locator('.vn-import-list li').nth(0).locator('select').selectOption('character');
await page.locator('.vn-import-list li').nth(1).locator('select').selectOption('place');
const xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
  + '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Capítulo 1</w:t></w:r></w:p><w:p><w:r><w:t>Você chega ao café numa noite de chuva.</w:t></w:r></w:p>'
  + '<w:p><w:r><w:t xml:space="preserve">A Ana </w:t></w:r><w:r><w:t>está fechando.</w:t></w:r></w:p></w:body></w:document>';
const docx = zip([{ name: '[Content_Types].xml', bytes: new TextEncoder().encode('<?xml version="1.0"?><Types/>') }, { name: 'word/document.xml', bytes: new TextEncoder().encode(xml) }]);
await writeFile('tests/artifacts/capitulo-1.docx', Buffer.from(await docx.arrayBuffer()));
await page.locator('.vn-import input[type=file]').setInputFiles('tests/artifacts/capitulo-1.docx');
await page.locator('.vn-import-list li').nth(2).waitFor();
assert.equal(await page.locator('.vn-import-list li').nth(2).locator('select').inputValue(), 'chapter', 'a chapter\'s file goes to a chapter by its name');
assert.match(await page.locator('.vn-import-list li').nth(2).locator('.vn-import-preview').textContent(), /Você chega ao café numa noite de chuva\.\nA Ana está fechando\./);
await shot('vn-import.png');
await page.locator('.vn-import button', { hasText: 'Importar e pedir ao escritor' }).click();
await toastSays('Importado: 1 capítulo, 1 ficha de personagem, 1 ficha de lugar');
assert.match(await page.locator('#chat-input').inputValue(), /^Importei meus documentos .*organize o projeto/);
await page.locator('#chat-input').fill('');
await page.locator('.vn-side-chapter', { hasText: 'Capítulo 1' }).waitFor();
assert.match(await page.locator('#side-cast small').textContent(), /1 personagem · 1 lugar/);
pass('documents go to a chapter, a character sheet and a place sheet, and the writer is asked to organize them');

// A scene by hand: the script checked as it is typed, a choice to scenes that don't exist yet
await page.locator('.vn-toolbar button', { hasText: 'Cena' }).first().click();
await askName('A chegada');
await page.locator('.vn-script').waitFor();
assert.equal(await page.locator('.vn-scene-grid input.mono').inputValue(), 'a_chegada');
await page.locator('.vn-script').fill('@bg Café Central, noite, fade\n@show Ana, feliz\nAna: Você veio!\n@voar');
await page.locator('.vn-lint li.error', { hasText: 'Linha 4' }).waitFor();
await page.locator('.vn-script').fill('@bg Café Central, noite, fade\n@show Ana, feliz\n@music tema_do_cafe\nAna: Você veio!\n> Ela sorri e puxa uma cadeira.\n@set afinidade_ana += 1');
await page.locator('.vn-lint .vn-ok', { hasText: 'Roteiro legível' }).waitFor();
await page.locator('.vn-scene-grid select').first().selectOption({ label: '1. Capítulo 1' });
await page.locator('.vn-exit-types button', { hasText: 'Escolha' }).click();
const options = page.locator('.vn-option');
await options.nth(0).locator('input[aria-label="Opção 1"]').fill('Ficar e ajudar');
await options.nth(0).locator('.vn-target input').fill('final_bom');
await options.nth(0).locator('input[placeholder^="afinidade_ana +="]').fill('afinidade_ana += 1');
await options.nth(1).locator('input[aria-label="Opção 2"]').fill('Ir embora');
await options.nth(1).locator('.vn-target input').fill('final_ruim');
await options.nth(1).locator('input[placeholder="afinidade_ana >= 2"]').fill('afinidade_ana < 5');
assert.match(await options.nth(0).locator('.vn-target-warn').textContent(), /ainda não existe/);
await shot('vn-scene.png');
await page.keyboard.press('Control+s');
await toastSays('Cena salva.');
await page.locator('.vn-scene-problems', { hasText: 'Ainda não existe' }).waitFor();
assert.match(await page.locator('.vn-scene-problems').textContent(), /sprite Ana \(feliz\).*cenário Café Central \(noite\).*música tema_do_cafe/);
pass('a scene is written, checked as it is typed, and saved with its choice');

// The tree: the scene, the scenes its choice leads to as ghosts to create
await page.locator('.vn-scene .vn-toolbar button', { hasText: 'Árvore' }).click();
await page.locator('.vn-node').first().waitFor();
assert.equal(await page.locator('.vn-node').count(), 1);
assert.equal(await page.locator('.vn-ghost').count(), 2);
assert.equal(await page.locator('.vn-edge').count(), 2);
assert.equal(await page.locator('.vn-node.start .vn-node-title').textContent(), 'A chegada');
await page.locator('.vn-ghost', { hasText: 'final_bom' }).locator('button').click();
await page.locator('.vn-script').waitFor();
assert.equal(await page.locator('.vn-scene-grid input.mono').inputValue(), 'final_bom');
await page.locator('.vn-script').fill('@cg juntos\nAna (feliz): Fica.');
await page.locator('.vn-exit-types button', { hasText: 'Final' }).click();
await page.locator('.vn-exit label', { hasText: 'Nome do final' }).locator('input').fill('Juntos');
await page.locator('.vn-exit select').selectOption('good');
await page.locator('.vn-toolbar button', { hasText: 'Salvar' }).click();
await toastSays('Cena salva.');
await page.locator('.vn-bar .vn-tab', { hasText: 'Árvore' }).click();
await page.locator('.vn-ghost', { hasText: 'final_ruim' }).locator('button').click();
await page.locator('.vn-script').waitFor();
await page.locator('.vn-script').fill('> Você sai na chuva.');
await page.locator('.vn-exit-types button', { hasText: 'Final' }).click();
await page.locator('.vn-exit label', { hasText: 'Nome do final' }).locator('input').fill('Sozinho');
await page.locator('.vn-exit select').selectOption('bad');
await page.locator('.vn-toolbar button', { hasText: 'Salvar' }).click();
await toastSays('Cena salva.');
await page.locator('.vn-bar .vn-tab', { hasText: 'Árvore' }).click();
await page.waitForFunction(() => document.querySelectorAll('.vn-node').length === 3 && !document.querySelector('.vn-ghost'));
assert.equal(await page.locator('.vn-node.exit-end').count(), 2);
assert.equal(await page.locator('.vn-edge-label').count(), 2);
// A node dragged keeps its place
const node = page.locator('.vn-node', { hasText: 'Sozinho' });
const box = await node.boundingBox();
const positionSaved = page.waitForResponse(r => r.url().includes('action=vn-scene') && r.request().method() === 'POST');
await page.mouse.move(box.x + 60, box.y + 30); await page.mouse.down(); await page.mouse.move(box.x + 60, box.y + 160, { steps: 6 }); await page.mouse.up();
assert.equal((await positionSaved).status(), 200);
assert.equal(await page.locator('.vn-script').count(), 0, 'a drag does not open the scene');
const kept = await page.evaluate(async id => (await (await fetch(`index.php?action=vn&id=${id}`, { credentials: 'same-origin' })).json()).vn.scenes.find(s => s.label === 'final_ruim').pos, projectId);
assert.ok(kept && Number.isInteger(kept.x) && Number.isInteger(kept.y), `a dragged scene keeps its place (${JSON.stringify(kept)})`);
// The warnings: the variable used without being created, the art that doesn't exist yet
await page.locator('.vn-problems-toggle').click();
await page.locator('.vn-problems li', { hasText: 'afinidade_ana' }).first().waitFor();
await shot('vn-tree.png');
pass('the tree shows the scenes, their exits, the ghosts to create and the warnings; a dragged node keeps its place');

// Variables, routes and audio
await page.locator('.vn-bar .vn-tab', { hasText: 'Variáveis e jogo' }).click();
await page.locator('input[aria-label="Nome da variável nova"]').fill('afinidade_ana');
await page.locator('select[aria-label="Tipo da variável nova"]').selectOption('number');
await page.locator('.vn-section button', { hasText: 'Variável' }).click();
await page.locator('.vn-table input[aria-label="Nome da variável"]').waitFor();
await page.locator('input[aria-label="Nome da faixa nova"]').fill('tema_do_cafe');
await page.locator('.vn-section button', { hasText: 'Faixa' }).click();
await page.locator('.vn-table code', { hasText: 'tema_do_cafe' }).waitFor();
await page.locator('.vn-bar .vn-tab', { hasText: 'Rotas e finais' }).click();
await page.locator('input[aria-label="Nome da rota nova"]').fill('Rota da Ana');
await page.locator('.vn-section button', { hasText: 'Rota' }).click();
await page.locator('.vn-table input[aria-label="Nome da rota"]').waitFor();
assert.equal(await page.locator('.vn-endings li').count(), 2);
assert.equal(await page.locator('.vn-endings .vn-ok').count(), 2, 'both endings can be reached');
assert.equal(await page.locator('.vn-problem-list li', { hasText: 'afinidade_ana' }).count(), 0, 'the variable created, its warning is gone');
await shot('vn-routes.png');
pass('variables, audio and routes are created on the page; the endings show as reachable');

// The art: what the script uses, a background made from the page
await page.locator('.vn-bar .vn-tab', { hasText: 'Arte' }).click();
await page.locator('.vn-missing li', { hasText: 'Cenário Café Central (noite)' }).waitFor();
assert.equal(await page.locator('.vn-missing li', { hasText: 'Sprite Ana (feliz)' }).count(), 1);
assert.equal(await page.locator('.vn-missing li', { hasText: 'Música' }).count(), 0, 'the music in the list is no longer missing');
await page.locator('.vn-missing li', { hasText: 'Sprite Ana (feliz)' }).locator('button').click();
await page.waitForFunction(() => /ficha de Ana ainda não tem imagem/.test(document.querySelector('#toast-text').textContent), null, { timeout: 15000 });
await page.locator('.vn-missing li', { hasText: 'Cenário Café Central (noite)' }).locator('button').click();
await page.locator('.vn-art-card.background .vn-art-state').waitFor({ timeout: 15000 });
await page.locator('.vn-art-card.background img').waitFor({ timeout: 40000 });
assert.equal(await page.locator('.vn-missing li', { hasText: 'Cenário Café Central' }).count(), 0);
await shot('vn-art.png');
pass('a sprite needs a sheet picture; a background is made from the page and takes its place in the art');

// The preview, played to an ending
await page.locator('.vn-bar .vn-tab', { hasText: 'Jogar' }).click();
await page.locator('.vn-textbox', { hasText: 'Você veio!' }).waitFor();
assert.equal(await page.locator('.vn-play-stage img.vn-layer.bg').count(), 1, 'the background made shows in the preview');
assert.equal(await page.locator('.vn-sprite.missing', { hasText: 'Ana' }).count(), 1, 'a sprite without a picture shows as a placeholder');
assert.equal(await page.locator('.vn-music').textContent(), 'tema_do_cafe');
await page.locator('.vn-play-stage').click({ position: { x: 300, y: 100 } });
await page.locator('.vn-textbox p.narration', { hasText: 'Ela sorri' }).waitFor();
await page.locator('.vn-play-stage').focus(); await page.keyboard.press('Space');
await page.locator('.vn-choice').first().waitFor();
assert.equal(await page.locator('.vn-play-var input[aria-label="afinidade_ana"]').inputValue(), '1', 'the @set ran');
await shot('vn-play.png');
await page.keyboard.press('ArrowLeft');
await page.locator('.vn-textbox p.narration', { hasText: 'Ela sorri' }).waitFor();
await page.keyboard.press('Enter');
await page.locator('.vn-choice', { hasText: 'Ficar e ajudar' }).click();
await page.locator('.vn-layer.cg.missing', { hasText: 'CG juntos' }).waitFor();
await page.locator('.vn-play-stage').click({ position: { x: 300, y: 100 } });
await page.locator('.vn-end strong', { hasText: 'Juntos' }).waitFor();
assert.equal(await page.locator('.vn-play-var input[aria-label="afinidade_ana"]').inputValue(), '2', 'the choice changed the variable');
// A condition tried with another value: with afinidade 5, "Ir embora" is locked
await page.locator('.vn-end button', { hasText: 'Jogar de novo' }).click();
await page.locator('.vn-textbox', { hasText: 'Você veio!' }).waitFor();
await page.locator('.vn-play-var input[aria-label="afinidade_ana"]').fill('9');
await page.locator('.vn-play-var input[aria-label="afinidade_ana"]').press('Enter');
await page.locator('.vn-play-stage').click({ position: { x: 300, y: 100 } });
await page.locator('.vn-play-stage').click({ position: { x: 300, y: 100 } });
await page.locator('.vn-choice.locked', { hasText: 'Ir embora' }).waitFor();
assert.match(await page.locator('.vn-choice.locked small').textContent(), /afinidade_ana < 5/);
pass('the preview plays the script with its art, variables, choices, conditions and endings, and goes back a line');

// The game for Ren'Py
await page.locator('#story-side [aria-label=Baixar]').click();
await page.locator('#export-renpy').waitFor();
assert.equal(await page.locator('#export-html').isHidden(), true);
const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#export-renpy').click()]);
assert.match(download.suggestedFilename(), /renpy\.zip$/);
const files = await unzip(await readFile(await download.path()));
const script = new TextDecoder().decode(files.get('game/script.rpy'));
assert.ok(script.includes('label a_chegada:') && script.includes('menu:') && script.includes('"Ficar e ajudar":') && script.includes('"Ir embora" if afinidade_ana < 5:') && script.includes('$ afinidade_ana += 1'), 'the script has the scenes, the menu and the conditions');
assert.ok(new TextDecoder().decode(files.get('game/atelier_variaveis.rpy')).includes('default afinidade_ana = 0'));
assert.ok([...files.keys()].some(name => /^game\/images\/bg\/cafe_central_noite\.(png|jpg|webp)$/.test(name)), `the background goes with the game (${[...files.keys()].join(', ')})`);
pass('the game for Ren\'Py downloads with the script, the variables and the pictures');

// On a phone: the panes as tabs, the project's views scroll sideways
await page.setViewportSize({ width: 390, height: 844 });
await page.locator('.story-tabs button[data-tab=doc]').click();
assert.equal(await page.locator('.story-tabs button[data-tab=doc]').textContent(), 'Projeto');
await page.locator('.vn-bar .vn-tab', { hasText: 'Árvore' }).click();
await page.locator('.vn-node').first().waitFor();
assert.ok(await noOverflow(), 'no sideways scrolling on a phone');
await shot('vn-phone.png');
await page.setViewportSize({ width: 1440, height: 900 });

// Back to the list; the stories don't list the project
await page.locator('#story-side [aria-label="Todas as visual novels"]').click();
// (the list loads again; the card shows the scenes and the chapters)
await page.waitForFunction(() => [...document.querySelectorAll('.story-card')].some(c => c.textContent.includes('Nova visual novel') && /3 cenas · 1 capítulo/.test(c.textContent)));
await page.locator('.section-switch [data-section=stories]').click();
await page.waitForFunction(() => location.hash === '#historias');
assert.equal(await page.locator('.story-card', { hasText: 'Nova visual novel' }).count(), 0, 'the stories don\'t list a visual novel');
await page.goBack();
await page.waitForFunction(() => location.hash === '#vn');
await page.locator('.story-card', { hasText: 'Nova visual novel' }).waitFor();
pass('each section lists its own kind; going back returns to the visual novels');

assert.deepEqual(errors, []);
await browser.close();
console.log('Visual novel browser checks passed. No real API calls.');
