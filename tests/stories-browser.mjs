import './i18n-setup.mjs';
import { chromium } from '@playwright/test';
import { mkdir, readFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import assert from 'node:assert/strict';
import { unzip, readPack } from '../assets/pack.js';
import { zip } from '../assets/comics.js';

// The stories section in a browser, against the isolated instance prepared by tests/http_checks.py. The writer's turns
// run in tests/complete_fixture_story.php, which plays Claude and GPT back: no real API calls.
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
const warms = [];
page.on('request', request => { if (request.url().endsWith('action=story-warm')) warms.push(request.postDataJSON()); });
await page.route('**/index.php?action=story-run', async route => {
  const { turn } = route.request().postDataJSON();
  let response;
  try {
    const { stdout } = await runFixture('php', ['tests/complete_fixture_story.php', turn], { env: process.env });
    response = { status: 200, body: stdout };
  } catch (e) { response = { status: 500, body: JSON.stringify({ error: 'fixture: ' + String(e.stderr || e.message).slice(0, 300) }) }; }
  await route.fulfill({ ...response, contentType: 'application/json' }).catch(() => {});
});
// The illustrations run like any image request, with the simulated image API of the main browser test.
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
const noOverflow = () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
// The list: each cover whole (its box has the picture's shape: nothing cut, no bars) and every card of one height.
const listFits = async () => {
  await page.waitForFunction(() => [...document.querySelectorAll('.story-card .story-cover img')].every(i => i.complete && i.naturalWidth > 0), null, { timeout: 10000 });
  const cards = await page.evaluate(() => [...document.querySelectorAll('.story-card')].map(c => {
    const box = c.querySelector('.story-cover').getBoundingClientRect(), img = c.querySelector('.story-cover img');
    return { height: Math.round(c.getBoundingClientRect().height), cover: Math.round(box.height), off: img ? Math.abs(box.width / box.height - img.naturalWidth / img.naturalHeight) : 0 };
  }));
  assert.ok(cards.length > 0 && cards.every(c => c.off < 0.02), `each cover shows whole: ${JSON.stringify(cards)}`);
  assert.equal(new Set(cards.map(c => c.height)).size, 1, `the cards have one height: ${JSON.stringify(cards)}`);
  assert.equal(new Set(cards.map(c => c.cover)).size, 1, 'and the covers too');
};
const toastSays = text => page.waitForFunction(t => document.querySelector('#toast-text').textContent === t, text, { timeout: 10000 });
const agentSays = text => page.locator('#chat-log .msg.agent .bubble', { hasText: text }).waitFor({ timeout: 20000 });
const send = async text => { await page.locator('#chat-input').fill(text); await page.keyboard.press('Control+Enter'); };

await page.goto(base);
await page.locator('#auth-name').fill('dono');
await page.locator('#auth-password').fill(process.env.TEST_PASSWORD);
await page.locator('#auth-submit').click();
await page.locator('#application').waitFor();

// The Claude key, in the settings
await page.locator('#settings-open').click();
assert.match(await page.locator('#claude-key-status').textContent(), /platform\.claude\.com/);
await page.locator('#claude-key').fill('sk-ant-test-placeholder-key-for-browser');
await page.locator('#claude-key-form [type=submit]').click();
await toastSays('Chave do Claude salva. Use Testar para conferir.');
assert.equal(await page.locator('#claude-key-remove').isEnabled(), true);
assert.equal(await page.locator('#claude-key-state').textContent(), 'Salva');
// Claude's balance, with the same card as OpenAI's; the one of OpenAI on too (the images of the stories).
await page.locator('.settings-tab[data-pane=balance]').click();
assert.deepEqual([await page.locator('#claude-balance-state').textContent(), await page.locator('#claude-balance-off').isDisabled()], ['Desligado', true]);
await page.locator('#claude-balance-amount').fill('20');
await page.locator('#claude-balance-form [type=submit]').click();
await toastSays('Saldo do Claude salvo.');
assert.deepEqual([await page.locator('#claude-balance-state').textContent(), await page.locator('#claude-balance-off').isEnabled(), await page.locator('#gemini-balance-state').textContent()], ['US$ 20,00', true, 'Desligado']);
if (await page.locator('#balance-state').textContent() === 'Desligado') {
  await page.locator('#balance-amount').fill('30');
  await page.locator('#balance-form [type=submit]').click();
  await toastSays('Saldo da OpenAI salvo.');
}
await shot('settings-balances.png');
// At the top, the balances of the services the screen in view uses, in order.
const chips = () => page.evaluate(() => [...document.querySelectorAll('.balance-chip:not([hidden])')].sort((a, b) => a.style.order - b.style.order).map(c => c.dataset.account).join(' '));
const chipsAre = (list, why) => page.waitForFunction(l => [...document.querySelectorAll('.balance-chip:not([hidden])')].sort((a, b) => a.style.order - b.style.order).map(c => c.dataset.account).join(' ') === l, list, { timeout: 5000 })
  .catch(async () => assert.fail(`${why}: ${await chips()} instead of ${list}`));
// The hosting test: how long this server lets each kind of process live (five seconds here instead of ten minutes).
// The development server can't answer before finishing, so only the separate process runs.
await page.route('**/index.php?action=host-test-start', route => route.continue({ postData: JSON.stringify({ seconds: 5 }) }));
await page.locator('.settings-tab[data-pane=host]').click();
await page.locator('#host-test-start').click();
await page.locator('#host-test-result li.ok', { hasText: 'chegou ao fim' }).waitFor({ timeout: 30000 });
const hostLines = await page.locator('#host-test-result li').allTextContents();
assert.ok(hostLines.length === 3 && hostLines[0].includes('não começou') && hostLines[2].startsWith('Processo separado') && /chegou ao fim \([5-7] s\)/.test(hostLines[2]), `the hosting test shows how each process ended (${hostLines})`);
assert.match(await page.locator('#host-test-result .hint').textContent(), /max_execution_time/);
await shot('host-test.png');
await page.locator('#settings-dialog [data-close=settings-dialog]').first().click();
await chipsAre('openai', 'in the images, the balance of the model chosen');

// The section switch
await page.locator('.section-switch [data-section=stories]').click();
await page.locator('#stories-empty').waitFor();
await chipsAre('claude openai', 'in the stories, the writer\'s balance and the pictures\'');
assert.match(await page.locator('#balance-chip-claude').getAttribute('title'), /^Saldo estimado do Claude, que paga o escritor de uma história nova\. Informado em /);
assert.equal(await page.locator('.workspace').isHidden(), true);
assert.equal(new URL(page.url()).hash, '#historias');
await shot('stories-empty.png');

// A new story: the writer plans in secret, then writes the first chapter
await page.locator('#story-new').click();
await page.locator('#chat-input').waitFor();
assert.equal(await page.locator('#chat-input').evaluate(el => el === document.activeElement), true);
// Text only, said before the first message
assert.equal(await page.locator('#start-illustrate [aria-pressed=true]').textContent(), 'Quando eu pedir');
await page.locator('#start-illustrate [data-illustrate=text]').click();
await page.locator('#start-illustrate [data-illustrate=text][aria-pressed=true]').waitFor();
assert.match(await page.locator('.chat-empty-choice small').textContent(), /nem oferece imagens/);
await send('Uma história de mistério numa ilha com um farol. DEVAGAR');
await page.locator('#chat-log .msg.live').waitFor({ timeout: 10000 });
assert.equal(await page.locator('#chat-stop').isVisible(), true, 'the writer can be stopped while it works');
// Writing to it while it works: the message waits for its next step, without stopping it
assert.match(await page.locator('#chat-input').getAttribute('placeholder'), /ele lê a sua mensagem no próximo passo/);
assert.equal(await page.locator('#chat-send').isDisabled(), false, 'a message can be sent while the writer works');
await send('Ah, e o farol é vermelho.');
await page.locator('#chat-log .msg.user.waiting', { hasText: 'Ah, e o farol é vermelho.' }).locator('.msg-meta', { hasText: 'Na fila: o escritor lê no próximo passo' }).waitFor({ timeout: 5000 });
await page.locator('#chat-log .msg.live .live-details').waitFor({ timeout: 5000 });
assert.match(await page.locator('#chat-log .msg.live .live-details').textContent(), /\d+ s/, 'the writer shows for how long it has worked');
// The steps it finished show once and in order, and what it is doing now comes after them (the live block used to list
// them all again below itself)
await page.waitForFunction(() => document.querySelector('#chat-log .msg.steps li') && document.querySelector('#chat-log .msg.live'), null, { timeout: 10000 });
const working = await page.evaluate(() => [...document.querySelectorAll('#chat-log .msg.steps li, #chat-log .msg.live')].map(el => el.matches('.msg.live') ? 'LIVE' : el.textContent));
assert.equal(working.at(-1), 'LIVE', `what the writer is doing now comes last: ${working.join(' | ')}`);
assert.equal(new Set(working).size, working.length, `each step shows once: ${working.join(' | ')}`);
await shot('stories-while-working.png');
// The writer's replies start hidden (they can give the story away): what it marked as free of spoilers shows, the
// rest is a line; its steps and "done" show too
await page.locator('#chat-log .chat-done', { hasText: 'Pronto' }).waitFor({ timeout: 20000 });
await page.locator('#chat-log .hidden-reply').waitFor({ timeout: 5000 });
assert.equal(await page.locator('#chat-stop').isHidden(), true);
await agentSays('Planejei a história inteira');
assert.equal(await page.locator('#chat-log .msg.agent .bubble').last().textContent(), 'Planejei a história inteira (o planejamento fica escondido) e escrevi o capítulo 1, A chegada.Quer que eu siga para o capítulo 2?',
  'the part marked free of spoilers, formatted, without the tags');
assert.equal(await page.evaluate(() => ['alguém sabia', 'Vou planejar', 'no_spoilers'].some(t => document.querySelector('#chat-log').textContent.includes(t))), false,
  'nothing else the writer said is in the page, not even hidden');
assert.equal(await page.locator('#chat-log .hidden-reply').count(), 1, 'its notes alone leave no line');
assert.match(await page.locator('#chat-log .hidden-reply').textContent(), /^Mais \d+ palavras escondidasVer$/);
const eye = page.locator('#chat-head [aria-label="Respostas do escritor"]');
assert.equal(await eye.getAttribute('aria-pressed'), 'false');
const eyeLook = () => eye.evaluate(b => [b.querySelectorAll('svg path').length, [...b.querySelectorAll('svg path, svg circle')].every(p => getComputedStyle(p).fill === 'none')]);
assert.deepEqual(await eyeLook(), [2, true], 'hidden: the crossed-out eye, outlined');
await shot('stories-replies-hidden.png');
// One reply opened whole, and closed again
await page.locator('#chat-log .hidden-reply').click();
await agentSays('alguém sabia que ela vinha');
assert.equal(await page.locator('#chat-log .hidden-reply').getAttribute('aria-expanded'), 'true');
await page.locator('#chat-log .hidden-reply').click();
assert.equal(await page.locator('#chat-log .msg.agent', { hasText: 'alguém sabia' }).count(), 0);
// Shown: the replies and the notes, the plain eye (outlined too), and the choice goes to the account
const repliesSaved = page.waitForRequest(r => r.url().endsWith('action=story-prefs'), { timeout: 5000 });
await eye.click();
assert.deepEqual((await repliesSaved).postDataJSON(), { stories: { replies: true } });
await agentSays('alguém sabia que ela vinha');
await page.locator('#chat-log .msg.notes', { hasText: 'Vou planejar o arco inteiro' }).waitFor();
assert.equal(await page.locator('#chat-log .hidden-reply, #chat-log .chat-done').count(), 0);
assert.equal(await page.evaluate(() => document.querySelector('#chat-log').textContent.includes('no_spoilers')), false, 'the marks never show');
assert.equal(await eye.getAttribute('aria-pressed'), 'true');
assert.deepEqual(await eyeLook(), [1, true], 'shown: the plain eye, outlined, never filled');
await page.locator('#chat-log .msg.user:not(.waiting)', { hasText: 'Ah, e o farol é vermelho.' }).locator('.msg-meta', { hasText: 'Enviada enquanto ele trabalhava' }).waitFor({ timeout: 5000 });
assert.equal(await page.locator('#chat-log .msg.user.waiting').count(), 0, 'nothing is left waiting once the writer read it');
assert.equal(await page.locator('#chat-log .msg.user .bubble').first().textContent(), 'Uma história de mistério numa ilha com um farol. DEVAGAR');
const steps = await page.locator('#chat-log .msg.steps li').allTextContents();
assert.ok(steps.includes('Atualizou o planejamento (oculto)') && steps.some(s => s.includes('A chegada')) && steps.includes('Pediu uma ilustração para o capítulo 1 · “A mesa posta para dois”'), `the steps of the turn are listed (${steps})`);
await page.locator('.chapter-list li', { hasText: 'A chegada' }).waitFor();
await page.locator('.doc-title', { hasText: 'A chegada' }).waitFor();
assert.equal(await page.locator('.prose em').first().textContent(), 'baixinho');
assert.equal(await page.locator('.prose strong').first().textContent(), 'aceso');
assert.equal(await page.locator('.prose .scene-break').count(), 1);
assert.equal(await page.locator('.prose p').first().locator('br').count(), 1, 'the lines of a paragraph keep their break');
// The illustration shows where the writer put it while it is made, then the picture
await page.locator('.prose .illustration img').waitFor({ timeout: 30000 });
assert.equal(await page.locator('.prose .illustration figcaption').textContent(), 'A mesa posta para dois');
assert.equal(await page.locator('.prose > *').last().evaluate(el => el.matches('figure.illustration')), true, 'after the paragraph it belongs to');
assert.equal(await page.locator('.side-doc.spoiler small').textContent().then(t => t.includes('escondido (spoilers)')), true);
assert.equal(await page.evaluate(() => document.body.innerText.includes('PLANO SECRETO')), false, 'the plan stays hidden');
assert.match(await page.locator('#story-cost').textContent(), /^US\$ /);
await shot('stories-desktop.png');
// What the cost is made of: the cache read and written, the input and the output
await page.locator('#story-cost').click();
await page.locator('#story-usage-body .usage-table').waitFor();
const usageRows = await page.locator('#story-usage-body .usage-table tbody th').allTextContents();
assert.equal(usageRows.length, 4);
assert.ok(usageRows[0].startsWith('Lido do cache') && usageRows[1].startsWith('Gravado no cache') && usageRows[2].startsWith('Entrada sem cache') && usageRows[3].startsWith('Saída'), String(usageRows));
assert.match(await page.locator('#story-usage-body .usage-part .hint').first().textContent(), /^\d+ pedidos ao modelo\. \d+% da entrada veio do cache\.$/);
assert.equal(await page.locator('#story-usage-body .usage-turn').count() >= 1, true);
assert.equal(await page.locator('#story-usage-dialog').evaluate(el => el.scrollWidth <= el.clientWidth), true);
await shot('stories-usage.png');
await page.locator('#story-usage-dialog [data-close=story-usage-dialog]').click();

// The plan only with a warning, and its text leaves the page when it closes
await page.locator('.side-doc.spoiler').click();
await page.locator('#confirm-dialog').waitFor();
await page.locator('#confirm-yes').click();
await page.locator('#story-doc-dialog').waitFor();
assert.match(await page.locator('#story-doc-text').inputValue(), /^PLANO SECRETO/);
await page.locator('#story-doc-dialog [data-close=story-doc-dialog]').first().click();
await page.waitForFunction(() => document.querySelector('#story-doc-text').value === '', null, { timeout: 5000 });

// Editing a chapter by hand
await page.locator('.doc-head .btn', { hasText: 'Editar' }).click();
const text = await page.locator('#edit-chapter-text').inputValue();
await page.locator('#edit-chapter-text').fill(text + '\n\nUm parágrafo meu.');
await page.locator('.chapter-editor [type=submit]').click();
await toastSays('Capítulo salvo.');
await page.locator('.prose p', { hasText: 'Um parágrafo meu.' }).waitFor();

// Switching to GPT keeps the conversation
await page.locator('.writer-chip').click();
assert.equal(await page.locator('#writer-switch').isHidden(), true);
assert.equal(await page.locator('#writer-illustrate [aria-pressed=true]').textContent(), 'Só texto', 'the writer window shows the choice made at the start');
await page.locator('#writer-provider [data-provider=openai]').click();
assert.equal(await page.locator('#writer-switch').isVisible(), true, 'changing the writer offers to keep the conversation or start a new one');
assert.equal(await page.locator('#writer-conversation [aria-pressed=true]').textContent(), 'Seguir nesta conversa');
assert.equal(await page.locator('#writer-model option:checked').textContent(), 'GPT-6.2 Sol');
await page.locator('#writer-form [type=submit]').click();
await page.locator('.writer-chip', { hasText: 'GPT-6.2 Sol' }).waitFor();
await chipsAre('openai', 'GPT writes and OpenAI makes the pictures: one balance');
assert.match(await page.locator('#balance-chip-openai').getAttribute('title'), /^Saldo estimado da OpenAI, que paga o escritor desta história e as imagens desta história\./);
await send('Continue com o GPT.');
await agentSays('Aqui é o GPT, seguindo a mesma conversa.');
await page.locator('#chat-log .chat-notice', { hasText: 'Agora quem escreve é o GPT-6.2 Sol. A conversa continua.' }).waitFor();
assert.equal(await page.locator('#chat-log .chat-notice', { hasText: 'Conversa nova' }).count(), 0);

// Back to Claude, in a new conversation this time
await page.locator('.writer-chip').click();
await page.locator('#writer-provider [data-provider=claude]').click();
await page.locator('#writer-conversation [data-conversation=new]').click();
assert.match(await page.locator('#writer-switch-hint').textContent(), /esquece o resto do bate-papo/);
await page.locator('#writer-form [type=submit]').click();
await page.locator('#chat-log .chat-notice', { hasText: 'Conversa nova. O escritor relê a história' }).waitFor();
await send('Mais leve, por favor.');
await agentSays('Combinado, sigo nesse tom.');
// What the Claude writer spent leaves Claude's balance; the amount at the top opens its card.
await chipsAre('claude openai', 'back to Claude');
await page.waitForFunction(() => document.querySelector('#balance-chip-claude .balance-text').textContent !== 'US$ 20,00', null, { timeout: 10000 });
await page.locator('#balance-chip-claude').click();
await page.locator('#settings-dialog[open]').waitFor();
assert.equal(await page.evaluate(() => document.activeElement.id), 'claude-balance-amount');
assert.match(await page.locator('#claude-balance-status').textContent(), /: US\$ 20,00\. Desde então, US\$ 0,\d\d gastos aqui \(\d+ passos? do escritor, US\$ 0,\d\d\)\. Saldo estimado agora: US\$ 19,\d\d\.$/);
await page.keyboard.press('Escape');
await page.locator('#settings-dialog').waitFor({ state: 'hidden' });
// Illustrating what is already written: the cost in view, then a message to the writer
await page.locator('.doc-head .btn', { hasText: 'Ilustrar' }).click();
await page.locator('#illustrate-dialog').waitFor();
assert.match(await page.locator('#illustrate-cost').textContent(), /^1 imagem/);
await page.locator('label.switch', { has: page.locator('#illustrate-cover') }).click();
assert.match(await page.locator('#illustrate-cost').textContent(), /^4 imagens/, 'the cover is three to choose from');
await shot('stories-illustrate.png');
await page.locator('#illustrate-form [type=submit]').click();
await page.locator('#chat-log .msg.user .bubble', { hasText: 'Ilustre o capítulo 1, já escrito: uma ilustração por capítulo, no momento mais marcante. Faça também a capa da história (3 opções para eu escolher).' }).waitFor();
await page.waitForFunction(() => [...document.querySelectorAll('#chat-log .msg.agent .bubble')].filter(b => b.textContent.includes('Combinado, sigo nesse tom.')).length === 2, null, { timeout: 20000 });
// The writer makes a new version of an illustration (Fotorrealista): the old one stays until it is ready
const before = await page.locator('.prose .illustration img').first().getAttribute('src');
await send('Transforme a ilustração em foto. FOTO');
await agentSays('Pedi a versão em foto da ilustração.');
{ const steps = await page.locator('#chat-log .msg.steps li').allTextContents(); assert.ok(steps.includes('Pediu a versão fotorrealista da ilustração · “A mesa, em foto” (capítulo 1)'), String(steps)); }
await page.locator('.prose .illustration figcaption', { hasText: 'A mesa, em foto' }).waitFor({ timeout: 30000 });
assert.notEqual(await page.locator('.prose .illustration img').first().getAttribute('src'), before, 'the new version takes the old one\'s place');
assert.equal(await page.locator('.prose .figure-note').count(), 0);
// The cover, from the story: the first illustration shows until one is chosen; covers made from a description (one in
// each style marked) take its place when the first is ready and all stay to choose from; a new version of the cover
// changes the cover alone
await page.locator('.side-head [aria-label=Capa]').click();
await page.locator('#story-cover-dialog').waitFor();
assert.equal(await page.locator('#story-cover-top p.muted').textContent(), 'Nenhuma capa escolhida: a lista mostra a primeira ilustração.');
assert.equal(await page.locator('#story-cover-tabs [aria-pressed=true]').textContent(), 'Gerar');
assert.equal(await page.locator('#story-cover-choices').isHidden(), true, 'no covers to choose from yet');
// The kind of cover: read from the story (it has illustrations), or chosen; no volume unless one is typed, a manga too
assert.equal(await page.locator('#cover-format option').first().textContent(), 'Automático: Livro ilustrado');
assert.match(await page.locator('#cover-format-hint').textContent(), /Escolhido sozinho: a história tem ilustrações\./);
assert.equal(await page.locator('#cover-volume').inputValue(), '');
assert.equal(await page.locator('#cover-lettering').isChecked(), true, 'a real cover has its title');
await page.locator('#cover-format').selectOption('manga');
assert.equal(await page.locator('#cover-volume').inputValue(), '', 'a manga gets no volume of its own');
assert.match(await page.locator('#cover-format-hint').textContent(), /^Capa de volume de mangá/);
await page.locator('#cover-author').fill('M. Duarte');
// The styles: three marked, one of each family (classic or literal, a graphic one, abstract or typographic); up to four
const STYLE_NAMES = { classic: 'Clássica', literal: 'Literal', modern: 'Moderna', minimal: 'Minimalista', retro: 'Retrô', abstract: 'Abstrata', typographic: 'Tipográfica' };
const marked = () => page.locator('#cover-styles .chip[aria-pressed=true]').evaluateAll(b => b.map(x => x.dataset.style));
const families = s => [['classic', 'literal'], ['modern', 'minimal', 'retro'], ['abstract', 'typographic']].map(f => s.filter(x => f.includes(x)).length);
let styles = await marked();
assert.deepEqual(families(styles), [1, 1, 1], `three styles, one of each family: ${styles}`);
assert.equal(await page.locator('#cover-go-text').textContent(), 'Gerar 3 capas');
assert.equal(await page.locator('#story-cover-panel .cover-style-about li').count(), 3, 'what each style marked looks like');
{
  const free = await page.locator('#cover-styles .chip[aria-pressed=false]').evaluateAll(b => b.map(x => x.dataset.style));
  await page.locator(`#cover-styles .chip[data-style=${free[0]}]`).click();
  assert.equal(await page.locator('#cover-go-text').textContent(), 'Gerar 4 capas');
  await page.locator(`#cover-styles .chip[data-style=${free[1]}]`).click();
  assert.equal((await marked()).length, 4, 'four at most');
  await page.locator(`#cover-styles .chip[data-style=${free[0]}]`).click();
  assert.deepEqual(await marked(), styles);
}
await page.locator('#story-cover-panel .cover-styles legend .link-btn', { hasText: 'Sortear' }).click();
styles = await marked();
assert.deepEqual(families(styles), [1, 1, 1], 'Sortear marks three others, one of each family');
const coverAsked = page.waitForRequest(r => r.url().endsWith('action=story-cover') && r.postDataJSON().mode === 'generate');
await page.locator('#cover-prompt').fill('O farol vermelho numa noite de tempestade');
await page.locator('#cover-aspect').selectOption('3:4');
{
  const cost = await page.locator('#cover-cost').textContent();
  assert.match(cost, /\d+ × \d+/, 'the size and the cost of the covers are in view');
  if (cost.includes('cerca de')) assert.match(cost, /pelas 3 capas/);
}
await page.locator('#story-cover-panel [data-cover-go]').click();
{ const body = (await coverAsked).postDataJSON(); assert.deepEqual({ design: body.design, lettering: body.lettering, styles: body.styles }, { design: { format: 'manga', author: 'M. Duarte', volume: '' }, lettering: true, styles }); }
await page.locator('#story-cover-top .cover-note').waitFor({ timeout: 5000 });
assert.equal(await page.locator('#cover-prompt').inputValue(), '', 'the description goes with the request');
assert.equal(await page.locator('#cover-format').inputValue(), 'manga', 'the kind of cover stays chosen');
assert.equal(await page.locator('#story-cover-choices .cover-choice').count(), 3, 'the three covers show while they are made');
assert.deepEqual(await page.locator('#story-cover-choices .cover-choice-name').allTextContents(), styles.map(s => STYLE_NAMES[s]), 'each named by its style');
await page.locator('#story-cover-top p.muted', { hasText: 'Escolhida ou feita por você.' }).waitFor({ timeout: 30000 });
await page.waitForFunction(() => document.querySelectorAll('#story-cover-choices .cover-choice img').length === 3 && !document.querySelector('#story-cover-top .cover-note'), null, { timeout: 40000 });
const idOf = src => new URL(src, base).searchParams.get('id');
{
  const current = await page.locator('#story-cover-choices .cover-choice.current img').getAttribute('src');
  assert.equal(idOf(current), idOf(await page.locator('#story-cover-top img').getAttribute('src')), 'the first one ready is the cover, marked among the three');
  assert.equal(await page.locator('#story-cover-choices [data-cover-use]').count(), 2, 'the other two can be chosen');
}
assert.ok(await noOverflow());
await shot('stories-cover.png');
{
  const other = await page.locator('#story-cover-choices [data-cover-use]').first().getAttribute('data-cover-use');
  await page.locator('#story-cover-choices [data-cover-use]').first().click();
  await page.waitForFunction(id => new URL(document.querySelector('#story-cover-top img').src).searchParams.get('id') === id
    && new URL(document.querySelector('#story-cover-choices .cover-choice.current img').src).searchParams.get('id') === id, other, { timeout: 10000 });
}
const madeCover = await page.locator('#story-cover-top img').getAttribute('src');
assert.equal(idOf(await page.locator('.story-title img').getAttribute('src')), idOf(madeCover), 'the contents show the cover chosen');
await page.locator('#story-cover-tabs button', { hasText: 'Editar' }).click();
await page.locator('#story-cover-panel .btn', { hasText: 'Aplicar' }).click();
assert.equal(await page.locator('#cover-error').textContent(), 'Diga o que mudar na capa.');
await page.locator('#cover-instruction').fill('Mais escuro, com relâmpagos');
await page.locator('#story-cover-panel .btn', { hasText: 'Aplicar' }).click();
await page.locator('#story-cover-top .cover-note').waitFor({ timeout: 5000 });
await page.waitForFunction(src => { const img = document.querySelector('#story-cover-top img'); return img && img.getAttribute('src') !== src && !document.querySelector('#story-cover-top .cover-note'); }, madeCover, { timeout: 30000 });
assert.equal(await page.locator('.prose .illustration figcaption', { hasText: 'A mesa, em foto' }).count(), 1, 'the chapter keeps its illustration');
// Asked of the writer, the covers follow the same kind, words and styles: they are kept, and the message says them
await page.locator('#story-cover-tabs button', { hasText: 'Pedir ao escritor' }).click();
assert.equal(await page.locator('#cover-format').inputValue(), 'manga');
assert.equal(await page.locator('#cover-author').inputValue(), 'M. Duarte');
await page.locator('#cover-volume').fill('2');
await page.locator('#cover-notes').fill('Só a protagonista, de costas para o farol.');
styles = await marked();
assert.equal(styles.length, 3, 'new styles are marked for the next covers');
const replies = await page.locator('#chat-log .msg.agent .bubble').count();
await page.locator('#story-cover-panel .btn', { hasText: 'Pedir ao escritor' }).click();
{
  const names = styles.map(s => STYLE_NAMES[s]);
  await page.locator('#chat-log .msg.user .bubble', { hasText: `Faça 3 capas novas para eu escolher para a história: capa de volume de mangá (tankōbon), uma em cada estilo: ${names[0]}, ${names[1]} e ${names[2]} (cover_styles: ${styles.join(', ')}), com o título e Vol. 2 e autor “M. Duarte”.` }).waitFor();
}
await page.waitForFunction(n => document.querySelectorAll('#chat-log .msg.agent .bubble').length > n && !document.querySelector('#chat-log .msg.agent.live'), replies, { timeout: 20000 });
{
  const design = await page.evaluate(async () => (await (await fetch('index.php?action=stories')).json()).stories.find(s => s.title === 'Nova história')?.cover_design);
  assert.deepEqual({ chosen: design.chosen, author: design.author, volume: design.volume }, { chosen: 'manga', author: 'M. Duarte', volume: '2' }, 'the writer\'s cover is made with them');
}

// The story's window (a click on the title): the whole cover in a column at its side, as tall as the fields and in full
// quality (not the thumbnail); a click shows it on the whole screen, and leaving the whole screen is back in the window.
// On a phone, the cover goes on top of the fields.
{
  const detailsLook = () => page.evaluate(() => {
    const box = document.querySelector('#story-details-cover'), full = box.querySelector('.details-cover-full'), d = document.querySelector('#story-details-dialog');
    const b = box.getBoundingClientRect(), f = document.querySelector('.details-fields').getBoundingClientRect(), dr = d.getBoundingClientRect();
    return { id: box.dataset.cover, src: full.getAttribute('src'), boxRatio: b.width / b.height, picRatio: full.naturalWidth / full.naturalHeight,
      side: b.right <= f.left, above: b.bottom <= f.top, centered: Math.abs((b.left + b.right) / 2 - (dr.left + dr.right) / 2) < 2,
      top: Math.abs(b.top - f.top), bottom: Math.abs(b.bottom - f.bottom), width: b.width, fits: d.scrollWidth <= d.clientWidth && dr.right <= innerWidth };
  });
  // The window's opening and the viewer's panels move for a moment: the pictures are taken once they stand still.
  const settled = selector => page.waitForFunction(sel => document.querySelector(sel).getAnimations({ subtree: true })
    .every(a => a.playState !== 'running' || a.effect?.getComputedTiming().endTime === Infinity), selector, { timeout: 5000 });
  await page.locator('#story .story-title').click();
  await page.locator('#story-details-dialog').waitFor();
  await page.locator('#story-details-cover .details-cover-full.loaded').waitFor({ timeout: 10000 });
  const look = await detailsLook();
  assert.equal(look.id, idOf(await page.locator('#story .story-title img').getAttribute('src')), 'the story\'s cover');
  assert.equal(new URL(look.src, base).searchParams.get('variant'), null, 'the full picture, not the thumbnail');
  assert.ok(Math.abs(look.boxRatio - look.picRatio) < 0.01, `the whole cover, neither cut nor with bars (${look.boxRatio} vs ${look.picRatio})`);
  assert.ok(look.side && look.top < 1.5 && look.bottom < 1.5 && look.width > 250, `a column at the side, as tall as the fields (${JSON.stringify(look)})`);
  assert.ok(look.fits, 'the window fits');
  await settled('#story-details-dialog');
  await shot('stories-details.png');
  await page.locator('#story-details-cover').click();
  await page.locator('#viewer.immersive').waitFor({ timeout: 5000 });
  assert.equal(new URL(page.url()).hash, `#v/${look.id}`);
  await page.waitForFunction(() => { const img = document.querySelector('#stage-image'); return img.complete && img.naturalWidth > 0 && !img.closest('#viewer').querySelector('#stage-loading:not([hidden])'); }, null, { timeout: 10000 });
  await settled('#viewer');
  await shot('stories-details-full-screen.png');
  await page.keyboard.press('Escape');
  await page.locator('#viewer').waitFor({ state: 'hidden', timeout: 5000 });
  assert.equal(await page.locator('#story-details-dialog').isVisible(), true, 'leaving the whole screen is back in the story\'s window');
  await page.waitForFunction(() => !location.hash.startsWith('#v/'), null, { timeout: 5000 }); // back at the story's address
  assert.equal(await page.locator('#story-details-dialog').isVisible(), true, 'the window stays open');
  await page.locator('#story-details-dialog [data-close=story-details-dialog]').first().click();
  await page.setViewportSize({ width: 390, height: 844 });
  const tab = await page.locator('#story').getAttribute('data-tab');
  await page.locator('.story-tabs button[data-tab=side]').click();
  await page.locator('#story .story-title').click();
  await page.locator('#story-details-cover .details-cover-full.loaded').waitFor({ timeout: 10000 });
  const phone = await detailsLook();
  assert.ok(phone.above && phone.centered && phone.fits && Math.abs(phone.boxRatio - phone.picRatio) < 0.01, `on a phone, the whole cover on top (${JSON.stringify(phone)})`);
  await settled('#story-details-dialog');
  await shot('stories-details-phone.png');
  await page.locator('#story-details-dialog [data-close=story-details-dialog]').first().click();
  await page.locator(`.story-tabs button[data-tab=${tab}]`).click();
  await page.setViewportSize({ width: 1440, height: 900 });
}

// The sheets and the visual style: a character with a picture from the gallery, the style and the lettering
await page.locator('#side-cast').click();
await page.locator('#story-cast-dialog').waitFor();
assert.equal(await page.locator('#story-cast-body .cast-group').count(), 2);
await page.locator('#cast-style').fill('Aquarela com contorno a nanquim, cores frias.');
await page.locator('#cast-style').blur();
await page.locator('#side-cast small', { hasText: 'estilo definido' }).waitFor({ timeout: 5000 });
await page.locator('.cast-group-head .btn', { hasText: 'Novo personagem' }).click();
await page.locator('#name-input').fill('Marina');
await page.locator('#name-ok').click();
await page.locator('.sheet-card h4', { hasText: 'Marina' }).waitFor({ timeout: 5000 });
await page.locator('.sheet-card .btn', { hasText: 'Da galeria' }).click();
await page.locator('#picker').waitFor();
assert.equal(await page.locator('#picker-title').textContent(), 'Imagens para a ficha de Marina');
await page.locator('#picker-grid .pick').first().click();
await page.locator('#picker-done').click();
await page.locator('.sheet-card .sheet-pic img').waitFor({ timeout: 5000 });
await page.locator('.sheet-card .sheet-caption').click();
await page.locator('#name-input').fill('Na chegada à ilha');
await page.locator('#name-ok').click();
await page.locator('.sheet-card .sheet-caption', { hasText: 'Na chegada à ilha' }).waitFor({ timeout: 5000 });
await page.locator('.sheet-card .sheet-note').fill('a faroleira, 30 anos');
await page.locator('.sheet-card .sheet-note').blur();
await page.waitForFunction(() => document.querySelector('#side-cast small').textContent.startsWith('1 personagem'), null, { timeout: 5000 });
await page.locator('#cast-lettering').fill('Letreiro de mangá feito à mão, caixa alta, traço preto firme.');
await page.locator('.cast-lettering .btn', { hasText: 'Gerar amostra' }).click();
await page.locator('.lettering-pics img').waitFor({ timeout: 30000 });
assert.match(await page.locator('#side-cast small').textContent(), /letras definidas/);
assert.equal(await page.locator('#story-cast-dialog').evaluate(el => el.scrollWidth <= el.clientWidth), true, 'the window fits');
await shot('stories-cast.png');
// Asking the writer: the message is written in the chat box, to finish and send
await page.locator('.sheet-card .btn', { hasText: 'Pedir ao escritor' }).click();
assert.equal(await page.locator('#story-cast-dialog').isHidden(), true);
assert.equal(await page.locator('#chat-input').inputValue(), 'Faça uma imagem para a ficha de Marina: ');
await page.locator('#chat-input').fill('');
// The history of this story: the messages to its writer and the pictures asked here; one message goes back in the box
await page.locator('#story-history').click();
await page.locator('#history-story', { hasText: 'Só da história' }).waitFor();
await page.locator('#requests-list .request.writer', { hasText: 'Ah, e o farol é vermelho.' }).locator('.status', { hasText: 'Ao escritor' }).waitFor({ timeout: 5000 });
await page.locator('#requests-list .request:not(.writer) .request-story').first().waitFor();
assert.equal(await page.locator('#requests-list .request.writer .request-prompt').last().textContent(), 'Uma história de mistério numa ilha com um farol. DEVAGAR', 'the oldest message comes last');
await shot('stories-history.png');
await page.locator('#requests-list .request.writer', { hasText: 'Ah, e o farol é vermelho.' }).getByRole('button', { name: 'Usar de novo' }).click();
assert.equal(await page.locator('#requests-dialog').isHidden(), true);
assert.equal(await page.locator('#chat-input').inputValue(), 'Ah, e o farol é vermelho.');
await page.locator('#chat-input').fill('');

// Revisar o livro: before anything starts, the page says what the review reads and about what it costs; going back sends nothing
const reviews = [];
page.on('request', request => { if (request.url().endsWith('action=story-review')) reviews.push(request.postDataJSON()); });
await page.locator('#story-review').click();
await page.locator('#confirm-dialog').waitFor();
assert.equal(await page.locator('#confirm-title').textContent(), 'Revisar o livro inteiro?');
assert.match(await page.locator('#confirm-message').textContent(), /lê (o capítulo|os \d+ capítulos) \([\d.]+ palavras\) numa conversa nova, como um editor: .*Custo estimado: US\$ ?\d/);
await shot('stories-review.png');
await page.locator('#confirm-no').click();
assert.equal(reviews.length, 0, 'going back starts no review');

// Gallery images with a message, picked like references
await page.locator('#chat-attach').click();
await page.locator('#picker').waitFor();
assert.equal(await page.locator('#picker-title').textContent(), 'Imagens para o escritor');
await page.locator('#picker-grid .pick').first().click();
assert.equal(await page.locator('#picker-status').textContent(), '1 selecionada.');
await page.locator('#picker-done').click();
await page.locator('#chat-attached .attached').waitFor();
await shot('stories-attach.png');
await send('Esta é a Marina. DEVAGAR');
await page.locator('#chat-log .msg.user', { hasText: 'Esta é a Marina.' }).locator('.msg-images img').waitFor();
// While the writer works the chat and the chapter are redrawn at every poll: their pictures stay the same elements, so
// they don't load again (the server sends images with no-store) and blink.
await page.locator('#chat-log .msg.live').waitFor({ timeout: 5000 });
const pictures = '#chat-log .msg-images img, .prose .illustration img';
await page.evaluate(selector => { for (const img of document.querySelectorAll(selector)) img.dataset.kept = '1'; }, pictures);
await page.waitForTimeout(2600);
assert.deepEqual(await page.evaluate(selector => [...document.querySelectorAll(selector)].map(img => img.dataset.kept || 'new'), pictures), ['1', '1'], 'the pictures are not loaded again at every poll');
assert.equal(await page.locator('#chat-attached').isHidden(), true, 'the attached images go with the message');
await page.waitForFunction(() => [...document.querySelectorAll('#chat-log .msg.agent .bubble')].filter(b => b.textContent.includes('Combinado, sigo nesse tom.')).length === 3, null, { timeout: 20000 });
// Claude's cache is kept warm while the story is open: the page knows when to ask (none was due yet).
assert.deepEqual(warms, []);

// On a phone the panes are tabs
await page.setViewportSize({ width: 390, height: 844 });
await page.locator('.story-tabs button', { hasText: 'Texto' }).click();
assert.deepEqual([await page.locator('.story-doc').isVisible(), await page.locator('.story-chat').isVisible(), await page.locator('.story-side').isVisible()], [true, false, false]);
assert.equal(await noOverflow(), true);
assert.equal(await page.evaluate(() => [...document.querySelectorAll('.topbar > *:not([hidden])')].every(el => el.getBoundingClientRect().right <= innerWidth)), true, 'the two balances fit the top bar of a phone');
await shot('stories-phone.png');
await page.locator('.story-tabs button', { hasText: 'Escritor' }).click();
assert.deepEqual([await page.locator('.story-doc').isVisible(), await page.locator('.story-chat').isVisible()], [false, true]);
assert.equal(await noOverflow(), true);
await page.locator('#story-cost').click();
await page.locator('#story-usage-body .usage-table').nth(1).waitFor();
assert.deepEqual(await page.locator('#story-usage-body .usage-part h3').allTextContents().then(t => t.map(x => x.split(':')[0])), ['Claude', 'GPT'], 'after a switch the cost of each writer is apart');
assert.equal(await page.locator('#story-usage-dialog').evaluate(el => el.scrollWidth <= el.clientWidth && el.getBoundingClientRect().right <= innerWidth), true, 'the cost fits a phone');
await shot('stories-usage-phone.png');
await page.locator('#story-usage-dialog [data-close=story-usage-dialog]').click();
await page.locator('.story-tabs button', { hasText: 'Sumário' }).click();
await page.setViewportSize({ width: 1440, height: 900 });

// Downloading, and the list
await page.locator('.side-head [aria-label=Baixar]').click();
const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#export-md').click()]);
const markdown = await readFile(await download.path(), 'utf8');
assert.ok(markdown.startsWith('# Nova história\n') && markdown.includes('## 1. A chegada') && markdown.includes('Um parágrafo meu.') && !markdown.includes('PLANO'), 'the story downloads as Markdown, without the plan');
// The EPUB book: made on the page with the cover and the illustrations as JPEG, without the plan.
await page.locator('.side-head [aria-label=Baixar]').click();
const [epub] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.locator('#export-epub').click()]);
assert.equal(epub.suggestedFilename(), 'nova-historia.epub');
await page.locator('#story-export-dialog').waitFor({ state: 'hidden' });
const epubPath = await epub.path(), book = await unzip(await readFile(epubPath)), bookText = name => new TextDecoder().decode(book.get(name));
assert.equal([...book.keys()][0], 'mimetype');
assert.equal(bookText('mimetype'), 'application/epub+zip');
const opf = bookText('OEBPS/content.opf');
assert.ok(opf.includes('<dc:title>Nova história</dc:title>') && /<dc:language>[a-z]{2}(-BR)?<\/dc:language>/.test(opf) && opf.includes('properties="cover-image"'), 'the book has its title, language and cover');
const bookPictures = [...book.keys()].filter(n => n.startsWith('OEBPS/images/'));
assert.ok(bookPictures.length > 0 && bookPictures.every(n => n.endsWith('.jpg') && book.get(n)[0] === 0xff && book.get(n)[1] === 0xd8), 'the pictures go in as JPEG');
assert.ok(bookText('OEBPS/chapter-001.xhtml').includes('Um parágrafo meu.') && ![...book.values()].some(b => new TextDecoder().decode(b).includes('PLANO')), 'the chapters, without the plan');
// With EPUBCheck around (EPUBCHECK_JAR), the book is checked against the format.
if (process.env.EPUBCHECK_JAR) {
  await epub.saveAs('tests/artifacts/story.epub'); // EPUBCheck goes by the extension
  const { stdout } = await promisify(execFile)('java', ['-jar', process.env.EPUBCHECK_JAR, 'tests/artifacts/story.epub']).catch(e => assert.fail(`EPUBCheck: ${e.stdout || e.message}`));
  assert.match(stdout, /No errors or warnings detected/);
}

// Where the reading stopped is kept with the story: after a pause in the scrolling, and on the way out
const storyHash = new URL(page.url()).hash;
assert.match(storyHash, /^#historias\/[a-f0-9]{32}$/, 'an open story has its own address');
await page.setViewportSize({ width: 1440, height: 420 });
const doc = page.locator('#story-doc');
const scrollDoc = top => doc.evaluate((el, y) => { el.scrollTop = y; return el.scrollTop; }, top);
const restoredTo = (top, why) => page.waitForFunction(y => {
  const el = document.querySelector('#story-doc');
  return el && el.querySelector('.prose') && Math.abs(el.scrollTop - y) < 40;
}, top, { timeout: 8000 }).catch(async () => assert.fail(`${why} (at ${await doc.evaluate(el => el.scrollTop).catch(() => '?')}, wanted ${top})`));
const bottom = await scrollDoc(1e6);
assert.ok(bottom > 200, 'the chapter is longer than the screen');
const firstSave = await page.waitForRequest(r => r.url().endsWith('action=story-reading'), { timeout: 5000 });
const savedPlace = firstSave.postDataJSON();
assert.ok(savedPlace.block > 0 && savedPlace.chapter, 'the place is saved after a pause in the scrolling');
await firstSave.response();
await page.reload();
await page.locator('#story .doc-title', { hasText: 'A chegada' }).waitFor();
await restoredTo(bottom, 'the story opens where the reading stopped');
assert.equal(await page.locator('#chat-head [aria-label="Respostas do escritor"]').getAttribute('aria-pressed'), 'true', 'the replies stay shown: the choice is kept with the account');
// Leaving the page (pagehide) is dispatched here with the page still open, and the next page loads once the save it
// sent is on the server. A save sent while the page unloads raced the next page's first look at the story on the test
// server, and on the CI runner it sometimes didn't arrive within 5 s.
const leave = () => page.evaluate(() => { dispatchEvent(new PageTransitionEvent('pagehide')); });
const storyId = storyHash.split('/')[1];
const onServer = async (place, why) => {
  for (let i = 0; i < 50; i++) {
    const now = await context.request.get(`${base}/index.php?action=story&id=${storyId}`).then(r => r.json()).then(r => r.story?.reading).catch(() => null);
    if (now && now.chapter === place.chapter && now.block === place.block && Math.abs(now.offset - place.offset) < 0.01) return;
    await page.waitForTimeout(100);
  }
  assert.fail(why);
};
const until = async (check, why) => { for (let i = 0; !check(); i++) { if (i > 100) assert.fail(why); await page.waitForTimeout(50); } };
// A save still on its way when the page is left goes again, as one that goes on after the page closes.
const saves = [];
let release;
const held = new Promise(resolve => { release = resolve; });
await page.route('**/index.php?action=story-reading', async route => {
  saves.push(route.request().postDataJSON());
  if (saves.length === 1) await held;
  await route.continue();
});
const middle = await scrollDoc(Math.round(bottom / 2));
await until(() => saves.length === 1, 'the place is saved after a pause in the scrolling');
await leave();
await until(() => saves.length === 2, 'leaving the page while the save is on its way sends it again');
assert.deepEqual(saves[1], saves[0]);
release();
await onServer(saves[0], 'the place saved on the way out is on the server');
await page.unroute('**/index.php?action=story-reading');
await page.reload();
await page.locator('#story .doc-title', { hasText: 'A chegada' }).waitFor();
await restoredTo(middle, 'the story opens at the place saved on the way out');
// A place not saved yet (no pause in the scrolling) is saved when the page is left
const leftSave = page.waitForRequest(r => r.url().endsWith('action=story-reading'), { timeout: 5000 });
const quarter = await scrollDoc(Math.round(bottom / 4));
await leave();
const leftPlace = (await leftSave).postDataJSON();
assert.notDeepEqual([leftPlace.block, leftPlace.offset], [saves[0].block, saves[0].offset], 'a place not saved yet is saved when the page is left');
await onServer(leftPlace, 'a place not saved yet is saved when the page is left (on the server)');
await page.reload();
await page.locator('#story .doc-title', { hasText: 'A chegada' }).waitFor();
await restoredTo(quarter, 'the story opens at the place saved when the page was left');
assert.equal(new URL(page.url()).hash, storyHash);
await page.setViewportSize({ width: 1440, height: 900 });
// Back from a story goes to the list of stories, and from there to the images
await page.goBack();
await page.locator('.story-card', { hasText: 'Nova história' }).waitFor();
assert.equal(new URL(page.url()).hash, '#historias');
assert.equal(await page.locator('#story').isHidden(), true);
await page.goBack();
await page.locator('.workspace').waitFor();
assert.equal(new URL(page.url()).hash, '');
assert.equal(await page.locator('#stories-view').isHidden(), true);
await page.goForward();
await page.locator('.story-card', { hasText: 'Nova história' }).waitFor();
await page.goForward();
await page.locator('#story .doc-title', { hasText: 'A chegada' }).waitFor();
assert.equal(new URL(page.url()).hash, storyHash);
// The section switch walks back the entries the stories made, and a story left open opens again over the list
await page.locator('.section-switch [data-section=images]').click();
await page.locator('.workspace').waitFor();
assert.equal(new URL(page.url()).hash, '');
await page.locator('.section-switch [data-section=stories]').click();
await page.locator('#story .doc-title', { hasText: 'A chegada' }).waitFor();
assert.equal(new URL(page.url()).hash, storyHash);
await page.goBack();
await page.locator('.story-card', { hasText: 'Nova história' }).waitFor();
await page.goForward();
await page.locator('#story .doc-title', { hasText: 'A chegada' }).waitFor();

await page.locator('.side-head [aria-label="Todas as histórias"]').click();
await page.locator('.story-card', { hasText: 'Nova história' }).filter({ hasText: /1 capítulo · \d+ palavras/ }).waitFor();
assert.equal(new URL(page.url()).hash, '#historias');
// The cover from the list: its button opens the same window, without opening the story
const card = page.locator('.story-card', { hasText: 'Nova história' });
assert.equal(await card.locator('.story-cover img').count(), 1, 'the list shows the cover');
await listFits();
await card.locator('.story-cover-edit').click();
await page.locator('#story-cover-dialog #story-cover-top p.muted', { hasText: 'Escolhida ou feita por você.' }).waitFor();
assert.equal(new URL(page.url()).hash, '#historias', 'the story stays closed');
assert.equal(await page.locator('#story-cover-choices .cover-choice img').count(), 3, 'with the covers made to choose from');
assert.equal(await page.locator('#story-cover-panel .face-pick').count(), 1, 'the characters\' sheets can go as references');
await shot('stories-cover-list.png');
await page.locator('#story-cover-dialog [data-close=story-cover-dialog]').click();
await page.locator('.section-switch [data-section=images]').click();
await page.locator('.workspace').waitFor();
assert.equal(new URL(page.url()).hash, '');
// The illustrations are in the story's album, under Histórias in the menu, and out of the library
await page.locator('#scope-button').click();
assert.ok(await page.locator('#scope-menu .menu-item', { hasText: 'Nova história' }).evaluate(item => {
  const titles = [...item.parentElement.querySelectorAll('.menu-title')].filter(x => x.compareDocumentPosition(item) & Node.DOCUMENT_POSITION_FOLLOWING);
  return titles.at(-1)?.textContent === 'Histórias';
}), 'the story\'s album under its kind');
await page.locator('#scope-menu .menu-item', { hasText: 'Nova história' }).click();
// The illustration and its photographic version, the three covers made from the page and a new version of one, the lettering sample
await page.waitForFunction(() => document.querySelectorAll('#feed .tile[data-id]').length === 7, null, { timeout: 10000 });
const inLibrary = () => page.evaluate(async () => (await (await fetch('index.php?action=library&group=0&sort=newest', { cache: 'no-store' })).json()).items.map(r => r[0]));
const storyPictures = await page.locator('#feed .tile[data-id]').evaluateAll(tiles => tiles.map(t => t.dataset.id));
assert.deepEqual((await inLibrary()).filter(id => storyPictures.includes(id)), [], 'the story\'s pictures are not in the library');
// "Mostrar na biblioteca" brings them into the library, and takes them out again
await page.locator('#scope-button').click();
const showToggle = page.locator('#scope-menu .menu-item[role=menuitemcheckbox]', { hasText: 'Mostrar na biblioteca' });
assert.equal(await showToggle.getAttribute('aria-checked'), 'false');
await shot('stories-album-menu.png');
await showToggle.click();
await toastSays('Os itens de Nova história aparecem também na biblioteca.');
assert.equal((await inLibrary()).filter(id => storyPictures.includes(id)).length, 7, 'shown in the library, they are all there');
await page.locator('#scope-button').click();
assert.equal(await showToggle.getAttribute('aria-checked'), 'true');
assert.equal(await page.locator('#scope-menu .menu-item[aria-checked=true] .menu-note').count(), 1, 'the album is marked as shown in the library');
await showToggle.click();
await toastSays('Os itens de Nova história agora aparecem só no álbum, não na biblioteca.');
assert.deepEqual((await inLibrary()).filter(id => storyPictures.includes(id)), [], 'and out of it again');
console.log('PASS the story\'s pictures go to its album under Histórias, out of the library, and "Mostrar na biblioteca" brings them in and out');
// From the album to its story, and from the story's contents back to its album
await page.locator('#scope-button').click();
await page.locator('#scope-menu .menu-item', { hasText: 'Abrir a história' }).click();
await page.locator('#story-side .story-title strong', { hasText: 'Nova história' }).waitFor();
await page.locator('#story-album').click();
await page.locator('.workspace').waitFor();
await page.waitForFunction(() => document.querySelectorAll('#feed .tile[data-id]').length === 7 && location.hash === '', null, { timeout: 10000 });
assert.match(await page.locator('#scope-button').textContent(), /Nova história/);
console.log('PASS the album opens its story, and the story\'s album button opens the album');

// The reader: the text on the whole screen, as pages or scrolling, with bars that come and go with a tap in the middle,
// and its settings kept with the account. A longer second chapter gives it pages to turn.
await page.evaluate(async () => {
  const { csrf } = await (await fetch('index.php?action=state')).json();
  const { stories } = await (await fetch('index.php?action=stories')).json();
  const words = 'o vento soprava sobre a ilha enquanto Marina olhava o mar escuro e pensava no pai que nunca voltara do farol'.split(' ');
  const text = Array.from({ length: 36 }, (_, i) => { const n = 45 + (i * 17) % 60, p = Array.from({ length: n }, (_, k) => words[(k * 5 + i) % words.length]).join(' '); return p[0].toUpperCase() + p.slice(1) + '.'; });
  text.splice(10, 0, '* * *');
  await fetch('index.php?action=chapter-add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ story: stories[0].id, title: 'O mapa', text: text.join('\n\n') }) });
});
// The browser's own full screen is asked for with the tap that opens the reader. Headless Chromium can't change the
// window's size in full screen, so the test counts the requests instead.
await page.addInitScript(() => { Element.prototype.requestFullscreen = function () { window.screenAsked = (window.screenAsked || 0) + 1; return Promise.resolve(); }; });
await page.setViewportSize({ width: 1180, height: 820 }); // a tablet lying down
await page.reload();
await page.locator('#application').waitFor();
await page.locator('.section-switch [data-section=stories]').click();
await page.locator('.story-card', { hasText: 'Nova história' }).click();
await page.locator('.chapter-link', { hasText: 'O mapa' }).click();
await page.locator('#story-doc .doc-title', { hasText: 'O mapa' }).waitFor();
// The arrows that change the order of the chapters only show in "Reordenar", so they aren't pressed by mistake
{
  const titles = () => page.locator('.chapter-list .chapter-t').allTextContents();
  const orderIs = (list, why) => page.waitForFunction(t => JSON.stringify([...document.querySelectorAll('.chapter-list .chapter-t')].map(x => x.textContent)) === t, JSON.stringify(list), { timeout: 5000 })
    .catch(async () => assert.fail(`${why} (${await titles()})`));
  const order = await titles();
  assert.ok(order.length >= 2 && order.at(-1) === 'O mapa', String(order));
  await page.locator('.chapter-list li', { hasText: 'O mapa' }).hover();
  assert.equal(await page.locator('.chapter-move').count(), 0, 'no arrows in the plain contents, not even on hover or on the current chapter');
  await page.locator('#chapter-reorder', { hasText: 'Reordenar' }).click();
  assert.equal(await page.locator('#chapter-reorder').getAttribute('aria-pressed'), 'true');
  assert.equal(await page.locator('.chapter-list .chapter-move').count(), order.length);
  assert.equal(await page.locator('.chapter-list li').first().locator('[title=Subir]').isDisabled(), true);
  await shot('stories-reorder.png');
  let saved = page.waitForResponse(r => r.url().endsWith('action=chapter-move'));
  await page.locator('.chapter-list li', { hasText: 'O mapa' }).locator('[title=Subir]').click();
  await saved;
  await orderIs([...order.slice(0, -2), 'O mapa', order.at(-2)], 'the chapter goes up');
  assert.equal(await page.evaluate(() => document.activeElement?.closest('li')?.querySelector('.chapter-t')?.textContent), 'O mapa', 'the moved chapter keeps its arrow focused');
  saved = page.waitForResponse(r => r.url().endsWith('action=chapter-move'));
  await page.locator('.chapter-list li', { hasText: 'O mapa' }).locator('[title=Descer]').click();
  await saved;
  await orderIs(order, 'and back down');
  await page.locator('#chapter-reorder', { hasText: 'Pronto' }).click();
  assert.equal(await page.locator('.chapter-move').count(), 0, 'Pronto hides the arrows again');
  await page.locator('#story-doc .doc-title', { hasText: 'O mapa' }).waitFor();
  console.log('PASS the chapters are reordered only in Reordenar');
}
const paneWidth = await page.locator('#story-doc .prose').evaluate(el => el.getBoundingClientRect().width);
const storyAddress = new URL(page.url()).hash;
const foot = () => page.locator('.reader-foot').textContent();
// The bars, shown with a tap in the middle when they are hidden.
const bars = async () => {
  if (await page.locator('#story.chrome').count()) return;
  const { width, height } = page.viewportSize();
  await page.mouse.click(width / 2, height / 2);
  await page.locator('#story.chrome').waitFor();
};
const footSays = (text, why) => page.waitForFunction(t => document.querySelector('.reader-foot')?.textContent.startsWith(t), text, { timeout: 5000 })
  .catch(async () => assert.fail(`${why} (${await foot().catch(() => '?')})`));
await page.locator('.doc-read').click();
await page.locator('#story.reader.paged.chrome').waitFor();
assert.equal(new URL(page.url()).hash, `${storyAddress}/ler`, 'the reader has its own address');
assert.equal(await page.evaluate(() => window.screenAsked), 1, 'the tap that opens the reader asks for the whole screen');
const spread = await page.evaluate(() => {
  const doc = document.querySelector('#story-doc').getBoundingClientRect(), cols = getComputedStyle(document.querySelector('.doc-flow')).columnCount;
  const parts = [...document.querySelectorAll('#story-doc .prose > p')].flatMap(p => [...p.getClientRects()]).filter(r => r.right > 0 && r.left < innerWidth);
  return { width: doc.width, height: doc.height, cols, left: Math.min(...parts.map(r => r.left)), right: Math.max(...parts.map(r => r.right)) };
});
assert.ok(spread.width === 1180 && spread.height === 820 && spread.cols === '2', `the reader takes the whole window, as two pages on a tablet lying down (${JSON.stringify(spread)})`);
assert.ok(spread.right - spread.left > 900, 'the two pages use the width of the screen');
assert.equal(await page.locator('.topbar').evaluate(el => document.elementFromPoint(el.getBoundingClientRect().left + 30, 20)?.closest('.topbar') === null), true, 'nothing of the app shows around the text');
await shot('reader-tablet-bars.png');
// A tap in the middle hides the bars; a tap on the right turns the page, and on the left turns it back; so do the keys
await page.mouse.click(590, 420);
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('chrome'));
await footSays('Página 1 de ', 'the foot shows the page');
const pageCount = Number((await foot()).match(/de (\d+)/)[1]);
assert.ok(pageCount >= 3, `the chapter has pages to turn (${pageCount})`);
await toastSays('Clique no centro da tela para ver os controles.');
await page.waitForTimeout(300);
await shot('reader-tablet.png');
await page.mouse.click(1120, 420);
await footSays('Página 2 de ', 'a tap on the right turns the page');
const placeSaved = page.waitForRequest(r => r.url().endsWith('action=story-reading'), { timeout: 5000 });
await page.keyboard.press('ArrowRight');
await footSays('Página 3 de ', 'the right arrow turns the page');
await page.mouse.click(60, 420);
await footSays('Página 2 de ', 'a tap on the left turns it back');
const pagePlace = (await placeSaved).postDataJSON();
assert.ok(pagePlace.block > 0, `the place on a page is saved like a place in scrolling text (${JSON.stringify(pagePlace)})`);
// The chapters' list in the bars, and back from the start of a chapter to the end of the one before
await bars();
await page.locator('.reader-top [aria-label=Capítulos]').click();
await page.locator('.reader-toc button', { hasText: 'A chegada' }).click();
await page.locator('.reader-titles small', { hasText: 'Capítulo 1 · A chegada' }).waitFor();
await page.keyboard.press('ArrowRight');
await page.locator('.reader-titles small', { hasText: 'Capítulo 2 · O mapa' }).waitFor();
await footSays('Página 1 de ', 'turning the last page goes on to the next chapter');
await page.keyboard.press('ArrowLeft');
await page.locator('.reader-titles small', { hasText: 'Capítulo 1 · A chegada' }).waitFor();
await page.keyboard.press('ArrowRight');
await footSays('Página 1 de ', 'back at the next chapter');
await page.keyboard.press('ArrowRight');
await footSays('Página 2 de ', 'and its second page');
// The settings: a bigger text and the sepia theme, kept with the account
await bars();
await page.locator('#story.chrome .reader-aa').click();
await page.locator('.reader-panel').waitFor();
const prefsSaved = page.waitForRequest(r => r.url().endsWith('action=reader-prefs'), { timeout: 5000 });
await page.locator('.reader-panel [aria-label="Aumentar o texto"]').click();
await page.locator('.reader-theme', { hasText: 'Sépia' }).click();
const savedPrefs = (await prefsSaved).postDataJSON().reader;
assert.ok(savedPrefs.size === 110 && savedPrefs.theme === 'sepia', `the settings go to the account (${JSON.stringify(savedPrefs)})`);
assert.equal(await page.locator('#story').evaluate(el => [el.dataset.theme, getComputedStyle(el).getPropertyValue('--read-scale').trim(), getComputedStyle(document.querySelector('#story-doc')).backgroundColor].join(' ')),
  'sepia 1.1 rgb(245, 236, 217)', 'the theme and the size apply at once');
await shot('reader-settings.png');
await page.keyboard.press('Escape');
assert.equal(await page.locator('.reader-panel').isHidden(), true, 'Esc closes the settings first');
// A reload opens the reader again, with the settings from the account and at the same page; no tap, so no full screen
const readerAt = await foot();
await page.waitForTimeout(1700);
await page.reload();
await page.locator('#story.reader .doc-title', { hasText: 'O mapa' }).waitFor();
await footSays(readerAt.match(/^Página \d+ de \d+/)[0], 'a reload keeps the reader at its page');
assert.equal(await page.evaluate(() => [document.querySelector('#story').dataset.theme, window.screenAsked || 0].join(' ')), 'sepia 0', 'the settings come from the account, and a reload asks for no full screen');
// A tablet standing up: one page as wide as the screen allows
await page.setViewportSize({ width: 820, height: 1180 });
await page.waitForFunction(() => getComputedStyle(document.querySelector('.doc-flow')).columnCount === '1');
const standing = await page.evaluate(() => [...document.querySelectorAll('#story-doc .prose > p')].flatMap(p => [...p.getClientRects()]).filter(r => r.right > 0 && r.left < innerWidth).reduce((w, r) => Math.max(w, r.width), 0));
assert.ok(standing >= 690 && standing > paneWidth * 1.3, `the text on a tablet standing up is wide (${standing}px, ${paneWidth}px beside the panes)`);
await shot('reader-tablet-standing.png');
// Scrolling instead of pages
await bars();
await page.locator('#story.chrome .reader-aa').click();
await page.locator('.reader-seg button', { hasText: 'Rolagem' }).click();
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('paged'));
await page.mouse.click(410, 1000); // closes the settings
assert.equal(await page.locator('.reader-panel').isHidden(), true);
const scrolls = await page.locator('#story-doc').evaluate(el => { const before = el.scrollTop; el.scrollTop += 600; return [el.scrollHeight > el.clientHeight * 2, el.scrollTop > before]; });
assert.deepEqual(scrolls, [true, true], 'the text scrolls');
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('chrome'));
await footSays('', 'the foot shows the part read');
assert.match(await foot(), /^\d+%/);
// A phone, in pages again, with the settings back
await page.setViewportSize({ width: 390, height: 844 });
await bars();
await page.locator('#story.chrome .reader-aa').click();
await page.locator('.reader-seg button', { hasText: 'Páginas' }).click();
await page.locator('.reader-theme', { hasText: 'Escuro' }).click();
await page.locator('.reader-panel [aria-label="Diminuir o texto"]').click();
assert.equal(await page.locator('.reader-panel').evaluate(el => el.getBoundingClientRect().right <= innerWidth && el.scrollWidth <= el.clientWidth), true, 'the settings fit a phone');
await shot('reader-phone-settings.png');
await page.keyboard.press('Escape');
await page.mouse.click(195, 420);
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('chrome'));
assert.equal(await noOverflow(), true);
await page.waitForTimeout(300); // the bars slide away
await shot('reader-phone.png');
// Back leaves the reader for the story, at the same chapter; Esc and T do too
await page.goBack();
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('reader'));
assert.equal(new URL(page.url()).hash, storyAddress, 'back leaves the reader for the story');
assert.equal(await page.locator('#story-doc .doc-title').textContent(), 'O mapa');
await page.setViewportSize({ width: 1440, height: 900 });
await page.keyboard.press('t');
await page.locator('#story.reader').waitFor();
await page.keyboard.press('Escape');
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('reader'));
assert.equal(new URL(page.url()).hash, storyAddress, 'Esc leaves the reader the same way');
// Stories can open straight in the reader
await page.locator('.doc-read').click();
await bars();
await page.locator('#story.chrome .reader-aa').click();
await page.locator('.reader-panel .switch', { hasText: 'Abrir as histórias já em tela cheia' }).click();
await page.waitForRequest(r => r.url().endsWith('action=reader-prefs') && r.postDataJSON().reader.open === true, { timeout: 5000 });
await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
await page.locator('.side-head [aria-label="Todas as histórias"]').click();
await page.locator('.story-card', { hasText: 'Nova história' }).click();
await page.locator('#story.reader').waitFor();
assert.equal(new URL(page.url()).hash, `${storyAddress}/ler`, 'with the setting on, a story opens in the reader');
// The line at the foot can be turned off: the page, the time left, or both; the bars still say where the reading is
await bars();
await page.locator('#story.chrome .reader-aa').click();
const footSaved = page.waitForRequest(r => r.url().endsWith('action=reader-prefs') && r.postDataJSON().reader.progress === false && r.postDataJSON().reader.remaining === false, { timeout: 5000 });
await page.locator('.reader-panel .switch', { hasText: 'Mostrar a página no rodapé' }).click();
await page.waitForFunction(() => { const [left, right] = document.querySelector('.reader-foot').children; return left.textContent === '' && right.textContent !== ''; }, null, { timeout: 5000 })
  .catch(async () => assert.fail(`without the page, the foot keeps only the time left (${await foot().catch(() => '?')})`));
await shot('reader-foot-settings.png');
await page.locator('.reader-panel .switch', { hasText: 'Mostrar o tempo restante do capítulo' }).click();
await footSaved;
await page.keyboard.press('Escape');
await page.mouse.click(720, 450);
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('chrome'));
assert.equal(await page.locator('.reader-foot').isHidden(), true, 'with both off, nothing shows at the foot');
await page.waitForTimeout(300); // the bars slide away
await shot('reader-no-foot.png');
await bars();
assert.match(await page.locator('.reader-where').textContent(), /página \d+ de \d+/, 'the bars still say where the reading is');
await page.goBack();
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('reader'));
await page.goBack();
await page.locator('.story-card', { hasText: 'Nova história' }).waitFor();
// Duplicating from the list: a copy to change on its own, with the conversation, at the top of the list
const original = page.locator('.story-card', { hasText: 'Nova história' }).first();
const originalLine = await original.locator('p.muted').last().textContent();
await original.locator('.story-card-copy').click();
await page.locator('#name-dialog[open]').waitFor();
assert.equal(await page.locator('#name-input').inputValue(), 'Nova história (cópia)');
assert.match(await page.locator('#name-message').textContent(), /conversa com o escritor/);
assert.equal(new URL(page.url()).hash, '#historias', 'the story stays closed');
await page.locator('#name-input').fill('Nova história, outra versão');
await shot('stories-duplicate.png');
await page.locator('#name-ok').click();
await toastSays('Cópia criada: “Nova história, outra versão”.');
const copyCard = page.locator('.story-card').first();
assert.equal(await copyCard.locator('h3').textContent(), 'Nova história, outra versão', 'the copy comes first in the list');
assert.equal((await copyCard.locator('p.muted').last().textContent()).split(' · ').slice(0, 2).join(' · '), originalLine.split(' · ').slice(0, 2).join(' · '), 'with the same chapters and words');
assert.equal(await page.locator('.story-card').count(), 2);
await page.locator('#toast-action').click();
// Stories open in the reader now (the setting above): Esc goes to the story's page.
await page.locator('#story.reader').waitFor();
await page.keyboard.press('Escape');
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('reader'));
await page.locator('.story-title strong', { hasText: 'Nova história, outra versão' }).waitFor();
await page.locator('#chat-log .msg.user').first().waitFor({ timeout: 10000 });
assert.ok(await page.locator('#chat-log .msg.user').count() > 0, 'the copy keeps the conversation with the writer');
await page.locator('.side-head [aria-label="Todas as histórias"]').click();
await page.locator('.story-card', { hasText: 'Nova história, outra versão' }).waitFor();

// The whole story in a zip, and back as a new one
const imports = [];
page.on('request', request => { if (request.url().endsWith('action=import')) imports.push(request.url()); });
await page.locator('.story-card').filter({ has: page.locator('h3', { hasText: /^Nova história$/ }) }).locator('h3').click();
await page.locator('#story.reader').waitFor();
await page.keyboard.press('Escape');
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('reader'));
await page.locator('.side-head [aria-label=Baixar]').click();
assert.match(await page.locator('#story-export-dialog .hint').first().textContent(), /O zip leva tudo/);
const [packed] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.locator('#export-pack').click()]);
assert.equal(packed.suggestedFilename(), 'nova-historia.zip');
await page.locator('#story-export-dialog').waitFor({ state: 'hidden' });
const zipPath = await packed.path();
const inZip = readPack(await unzip(await readFile(zipPath)));
assert.equal(inZip.pack.story.title, 'Nova história');
assert.ok(inZip.pack.chapters.length >= 2 && inZip.pack.chapters.some(c => c.text.includes('Um parágrafo meu.')), 'the zip has the chapters');
assert.ok(inZip.pack.plan.text.includes('PLANO'), 'and the plan, which the other downloads leave out');
assert.ok(inZip.pack.cast.characters.length > 0 && inZip.pack.story.cover, 'and the sheets and the cover');
assert.ok(inZip.pictures.size > 0 && [...inZip.pictures.values()].every(p => p.old && p.bytes.length > 0), 'and the pictures, named by their ids');
assert.ok(inZip.conversation.messages.length > 0 && inZip.conversation.messages.every(m => m.content.provider), 'and the conversation with the writer');
await page.locator('.side-head [aria-label="Todas as histórias"]').click();
await page.locator('.story-card').nth(1).waitFor();
await page.locator('#story-import-input').setInputFiles(zipPath);
await page.waitForFunction(() => /^História importada: “Nova história”\.$/.test(document.querySelector('#toast-text').textContent), null, { timeout: 30000 });
assert.equal(imports.length, 0, 'pictures the account already has are not uploaded again');
assert.equal(await page.locator('.story-card').count(), 3);
const importedCard = page.locator('.story-card').first();
assert.equal(await importedCard.locator('h3').textContent(), 'Nova história', 'the imported story comes first');
assert.equal((await importedCard.locator('p.muted').last().textContent()).split(' · ').slice(0, 2).join(' · '), originalLine.split(' · ').slice(0, 2).join(' · '), 'with the same chapters and words');
await shot('stories-imported.png');
await page.locator('#toast-action').click();
await page.locator('#story.reader').waitFor();
await page.keyboard.press('Escape');
await page.waitForFunction(() => !document.querySelector('#story').classList.contains('reader'));
await page.locator('#chat-log .msg.user').first().waitFor({ timeout: 10000 });
assert.ok(new URL(page.url()).hash !== storyHash, 'the imported story is a new one');
await page.locator('.side-head [aria-label="Todas as histórias"]').click();
// A zip made by hand with chapters in Markdown is a story too
const enc = new TextEncoder();
const handmade = zip([{ name: 'Feita/02-depois.md', bytes: enc.encode('# Depois\n\nO fim.') }, { name: 'Feita/01-antes.md', bytes: enc.encode('Começo sem título.') }, { name: 'Feita/biblia.md', bytes: enc.encode('Regras.') }]);
await page.locator('#story-import-input').setInputFiles({ name: 'Feita à mão.zip', mimeType: 'application/zip', buffer: Buffer.from(await handmade.arrayBuffer()) });
await toastSays('História importada: “Feita à mão”.');
assert.match(await page.locator('.story-card').first().locator('p.muted').last().textContent(), /^2 capítulos/, 'each Markdown file is a chapter');
await page.locator('#story-import-input').setInputFiles({ name: 'nada.zip', mimeType: 'application/zip', buffer: Buffer.from('não é zip') });
await toastSays('O arquivo não é um zip.');
assert.equal(await page.locator('.story-card').count(), 4);
await listFits();
assert.deepEqual(errors, []);
console.log('PASS stories in the browser: Claude key in the settings, a story duplicated from the list with its conversation, the whole story downloaded as a zip and imported back (and a zip of Markdown chapters), the section switch, text only chosen before the first message, a new story planned in secret and written in formatted chapters with an illustration (in the story\'s album, out of the library, shown there with Mostrar na biblioteca and opened from the story), the writer at work and its notes, the plan only after a warning, editing by hand, switching to GPT in the same conversation and back to Claude in a new one, the cover made, edited and opened from the list (whole, on cards of one height), the whole cover in the window of the story (on a phone too) and on the whole screen, the sheets with the visual style and the lettering sample, illustrating written chapters with the cost in view, a photographic version of an illustration in its place, gallery images attached to a message, tabs on a phone, the Markdown download and the list, and the reader in full screen (pages side by side on a tablet lying down, one wide page standing up, taps, keys, chapters, the settings kept with the account, scrolling, a phone, back and Esc, the line at the foot turned off)');
await browser.close();
