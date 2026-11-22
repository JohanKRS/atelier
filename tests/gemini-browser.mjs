import { chromium } from '@playwright/test';
import { mkdir, readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

// Google's Gemini in a browser, against the isolated instance prepared by tests/http_checks.py: the key in the
// settings (its test answered here, no real API call), Nano Banana in the composer and Gemini as a story's writer.
await mkdir('tests/artifacts', { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ locale: 'pt-BR', viewport: { width: 1440, height: 900 } });
// The model lists come from what the account kept, never from the providers' APIs: no real calls in tests.
await context.route('**/index.php?action=models', route => route.continue({ postData: JSON.stringify({ refresh: false, force: false }) }));
const page = await context.newPage();
const errors = [];
page.on('pageerror', e => errors.push(e.message));
const base = 'http://127.0.0.1:8099';
const tests = [];
await page.route('**/index.php?action=gemini-test', async route => {
  tests.push(route.request().postDataJSON());
  await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, steps: [
    { name: 'Chave e modelos', ok: true, detail: 'a chave vale; os modelos do app estão na conta.' },
    { name: 'Conversa com ferramentas', ok: true, detail: 'o Gemini chamou a ferramenta e respondeu (US$ 0,0004).' },
  ] }) });
});
const shot = async name => {
  await page.screenshot({ path: `tests/artifacts/${name}` });
  console.log(`VISUAL_${name}:` + (await readFile(`tests/artifacts/${name}`)).toString('base64'));
};
const toastSays = text => page.waitForFunction(t => document.querySelector('#toast-text').textContent === t, text, { timeout: 10000 });

await page.goto(base);
await page.locator('#auth-name').fill('dono');
await page.locator('#auth-password').fill(process.env.TEST_PASSWORD);
await page.locator('#auth-submit').click();
await page.locator('#application').waitFor();

// The key, in the settings: a wrong one is refused, a right one saved and tested
await page.locator('#settings-open').click();
// (Run again on the same instance, the key of the last run goes first.)
if (await page.locator('#gemini-key-remove').isEnabled()) { await page.locator('#gemini-key-remove').click(); await page.locator('#confirm-yes').click(); await toastSays('Chave do Gemini retirada.'); }
assert.match(await page.locator('#gemini-key-status').textContent(), /aistudio\.google\.com/);
assert.equal(await page.locator('#gemini-key-test').isDisabled(), true, 'nothing to test before a key is saved');
assert.equal(await page.locator('#gemini-key-form [type=submit]').isDisabled(), true, 'nothing to save before a key is typed');
await page.locator('#gemini-key').fill('sk-not-a-gemini-key-at-all-0123456789');
await page.locator('#gemini-key-form [type=submit]').click();
await page.waitForFunction(() => /chave válida da API do Gemini/.test(document.querySelector('#settings-error').textContent));
await page.locator('#gemini-key').fill('AIzaTestPlaceholderKeyForTheBrowser0123');
await page.locator('#gemini-key-form [type=submit]').click();
await toastSays('Chave do Gemini salva. Use Testar para conferir.');
assert.match(await page.locator('#gemini-key-status').textContent(), /Há uma chave salva/);
assert.equal(await page.locator('#gemini-key-state').textContent(), 'Salva');
await page.locator('#gemini-key-test').click();
await page.locator('#gemini-test-result p', { hasText: 'Conversa com ferramentas' }).waitFor();
assert.deepEqual(await page.locator('#gemini-test-result p').allTextContents(), [
  'Chave e modelos: a chave vale; os modelos do app estão na conta.',
  'Conversa com ferramentas: o Gemini chamou a ferramenta e respondeu (US$ 0,0004).',
]);
assert.equal(tests.length, 1);
await shot('gemini-settings.png');
// Gemini's balance: Google is paid apart, for Nano Banana and the Gemini writer.
await page.locator('.settings-tab[data-pane=balance]').click();
await page.locator('#gemini-balance-amount').fill('10');
await page.locator('#gemini-balance-form [type=submit]').click();
await toastSays('Saldo do Gemini salvo.');
assert.equal(await page.locator('#gemini-balance-state').textContent(), 'US$ 10,00');
await page.locator('#settings-dialog [data-close=settings-dialog]').first().click();
const chips = () => page.evaluate(() => [...document.querySelectorAll('.balance-chip:not([hidden])')].sort((a, b) => a.style.order - b.style.order).map(c => c.dataset.account).join(' '));

// A newer model the keys list (the server's catalog) shows in the lists, priced like the one it follows
await page.route('**/index.php?action=models', async route => {
  const response = await route.fetch({ postData: JSON.stringify({ refresh: false, force: false }) });
  const data = await response.json();
  data.models['gpt-image-2.7-flare'] = { ...data.models['gpt-image-2.6-flare'], label: 'GPT Image 2.7 · Flare (preço estimado)', like: 'gpt-image-2.6-flare', estimated: true };
  data.writers.gemini['gemini-4-hydrogen'] = 'Gemini 4 Hydrogen (preço estimado)';
  data.writers.gemini_efforts['gemini-4-hydrogen'] = ['low', 'medium', 'high', 'xhigh'];
  await route.fulfill({ response, json: data });
});
await page.reload();
await page.locator('#application').waitFor();
await page.locator('#advanced-mode').check({ force: true });
assert.ok((await page.locator('#model option').allTextContents()).includes('GPT Image 2.7 · Flare (preço estimado)'), 'the newer image model is offered');
await page.locator('#model').selectOption('gpt-image-2.7-flare');
await page.waitForFunction(() => /US\$/.test(document.querySelector('#generate-cost')?.textContent || ''));
const estimated = await page.locator('#generate-cost').textContent();
await page.locator('#model').selectOption('gpt-image-2.6-flare');
await page.waitForFunction(() => /US\$/.test(document.querySelector('#generate-cost')?.textContent || ''));
assert.equal(estimated, await page.locator('#generate-cost').textContent(), 'and its cost is estimated with the prices of the model it follows');
await page.unroute('**/index.php?action=models');

// Nano Banana in the composer: no transparent background, no previews, its own note and its own price
const labels = await page.locator('#model option').allTextContents();
assert.ok(labels.some(l => /Nano Banana/.test(l)), 'the composer lists Nano Banana');
await page.locator('#more-options').evaluate(d => { d.open = true; });
await page.locator('#background').selectOption('transparent');
await page.locator('#model').selectOption('gemini-nano-banana-2.1');
assert.equal(await page.locator('#background').inputValue(), 'auto', 'a transparent background goes back to automatic');
assert.equal(await page.locator('#background option[value=transparent]').evaluate(o => o.disabled), true);
assert.equal(await page.locator('#previews').isDisabled(), true, 'Nano Banana makes no previews');
assert.match(await page.locator('#size-note').textContent(), /1K, 2K ou 4K/);
await page.waitForFunction(() => /Google/.test(document.querySelector('#generate-cost')?.title || ''));
assert.match(await page.locator('#generate-cost').getAttribute('title'), /fora do saldo da OpenAI/);
assert.equal(await chips(), 'gemini', 'with Nano Banana chosen, the balance at the top is Gemini\'s');
await page.locator('#model').selectOption('gemini-3-pro-image');
assert.deepEqual(await page.locator('#quality option').evaluateAll(o => o.map(x => x.value)), ['high'], 'Nano Banana Pro always thinks: only one quality');
await shot('gemini-composer.png');
await page.locator('#model').selectOption('gpt-image-2');
assert.equal(await page.locator('#background option[value=transparent]').evaluate(o => o.disabled), false, 'back on GPT Image the transparent background returns');
assert.equal(await page.locator('#previews').isDisabled(), false);
assert.doesNotMatch(await chips(), /gemini/, 'back on GPT Image, Gemini\'s balance leaves the top');
await page.locator('#advanced-mode').uncheck({ force: true });

// Gemini as the writer of a new story: its models, its reasoning levels and Nano Banana for the pictures
await page.locator('.section-switch [data-section=stories]').click();
await page.locator('#story-new').click();
await page.locator('#chat-input').waitFor();
await page.locator('.writer-chip').click();
await page.locator('#writer-provider [data-provider=gemini]').click();
const writerModels = await page.locator('#writer-model option').allTextContents();
assert.ok(writerModels.length >= 3 && writerModels.every(l => /Gemini/.test(l)), 'Gemini\'s models: ' + writerModels.join(', '));
assert.equal(await page.locator('#writer-model option:checked').textContent(), 'Gemini 4 Neon', 'Neon is the first Gemini writer');
assert.ok(writerModels.includes('Gemini 4 Hydrogen (preço estimado)'), 'a newer Gemini writer the key lists is offered too');
const efforts = () => page.locator('#writer-effort option').evaluateAll(o => o.map(x => x.value));
assert.deepEqual(await efforts(), ['low', 'medium', 'high', 'xhigh'], 'Neon thinks up to extra high');
await page.locator('#writer-effort').selectOption('xhigh');
await page.locator('#writer-model').selectOption('gemini-4-argon');
assert.deepEqual(await efforts(), ['low', 'medium', 'high'], 'Argon thinks up to high');
assert.equal(await page.locator('#writer-effort').inputValue(), 'high', 'a level the model lacks becomes its highest');
await page.locator('#writer-model').selectOption('gemini-4-neon');
assert.equal(await page.locator('#writer-key-hint').isHidden(), true, 'the key is saved: no warning');
await page.locator('#writer-image-model').selectOption('gemini-nano-banana-2.1');
assert.match(await page.locator('#writer-image-size-hint').textContent(), /Pago com a chave do Gemini/);
await shot('gemini-writer.png');
await page.locator('#writer-form [type=submit]').click();
await page.locator('.writer-chip', { hasText: 'Gemini' }).waitFor({ timeout: 10000 });
await page.waitForFunction(() => [...document.querySelectorAll('.balance-chip:not([hidden])')].map(c => c.dataset.account).join(' ') === 'gemini', null, { timeout: 5000 });
assert.match(await page.locator('#balance-chip-gemini').getAttribute('title'), /^Saldo estimado do Gemini, que paga o escritor desta história e as imagens desta história\./);

// Without the key, the writer window says so
await page.locator('#settings-open').click();
await page.locator('#gemini-key-remove').click();
await page.locator('#confirm-yes').click();
await toastSays('Chave do Gemini retirada.');
assert.equal(await page.locator('#gemini-key-test').isDisabled(), true);
await page.locator('#settings-dialog [data-close=settings-dialog]').first().click();
await page.locator('.writer-chip').click();
assert.equal(await page.locator('#writer-key-hint').isVisible(), true);
assert.match(await page.locator('#writer-key-hint').textContent(), /chave da API do Gemini/);
await page.locator('#writer-dialog [data-close=writer-dialog]').first().click();

assert.deepEqual(errors, []);
await browser.close();
console.log('PASS Gemini in the browser: the key saved, refused when wrong, tested and removed in the settings, a newer model listed by the keys offered at an estimated price, Nano Banana in the composer (no transparent background or previews, its sizes and Google\'s price), Gemini as a story\'s writer with its models (Neon first), reasoning levels and Nano Banana for the pictures.');
