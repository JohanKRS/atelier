import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';

// The interface in English, the language of the code, and the two language menus (sign-in screen and Settings).
// Runs on the instance tests/browser.mjs left, with its accounts.
const base = 'http://127.0.0.1:8099';
const browser = await chromium.launch({ headless: true });
const errors = [];
const open = async locale => {
  const context = await browser.newContext({ locale, viewport: { width: 1440, height: 900 } });
  // The model lists come from what the account kept, never from the providers' APIs.
  await context.route('**/index.php?action=models', route => route.continue({ postData: JSON.stringify({ refresh: false, force: false }) }));
  const page = await context.newPage();
  page.on('pageerror', e => errors.push(e.message));
  return page;
};
// Words only Portuguese has: an English screen shows none of them.
const PORTUGUESE = /[ãõçÃÕÇ]|\b(não|você|imagem|imagens|chave|chaves|senha|pedido|pedidos|salvar|excluir|configurações|usuário|usuários|nenhum|nenhuma|conta)\b/i;
const portuguese = async (page, selector) => (await page.locator(selector).innerText()).split('\n').filter(line => PORTUGUESE.test(line));
const lang = page => page.locator('html').getAttribute('lang');
const serverError = page => page.evaluate(async () => (await (await fetch('index.php?action=no-such-route')).json()).error);

// A browser in English gets the app in English, with no file of translations to load.
let page = await open('en-US');
await page.goto(base);
assert.equal(await lang(page), 'en');
assert.equal(await page.locator('html').getAttribute('data-strings'), null);
await page.locator('#auth-name').waitFor();
assert.equal(await page.locator('#gate-language').inputValue(), 'en');
assert.deepEqual(await portuguese(page, '#gate'), [], 'the sign-in screen is all English');
assert.equal(await serverError(page), 'Method not allowed.', 'and so are the messages of the server');
await page.locator('#auth-name').fill('dono');
await page.locator('#auth-password').fill(process.env.TEST_PASSWORD);
await page.locator('#auth-submit').click();
await page.locator('#application').waitFor();

// Settings: every pane in English, and the language menu says so.
await page.locator('#settings-open').click();
assert.deepEqual(await page.locator('.settings-tab:not([hidden])').allTextContents(), ['API keys', 'Balances', 'Name and password', 'MCP access', 'About me as a reader', 'Language', 'Users', 'Hosting test', 'Privacy']);
const leaks = [];
for (const pane of ['keys', 'balance', 'account', 'mcp', 'about', 'language', 'users', 'host', 'privacy']) {
  await page.locator(`.settings-tab[data-pane=${pane}]`).click();
  await page.locator(`#settings-${pane}`).waitFor();
  leaks.push(...(await portuguese(page, `#settings-${pane}`)).map(line => `${pane}: ${line}`));
}
assert.deepEqual(leaks, [], 'no Portuguese in Settings');
await page.locator('.settings-tab[data-pane=language]').click();
assert.equal(await page.locator('#settings-language-select').inputValue(), 'en');

// Choosing Português there loads the page again in Portuguese, still signed in; the server answers in Portuguese too.
await Promise.all([page.waitForEvent('load'), page.locator('#settings-language-select').selectOption('pt-BR')]);
await page.locator('#application').waitFor();
assert.equal(await lang(page), 'pt-BR');
assert.match(await page.locator('html').getAttribute('data-strings'), /^assets\/i18n\/pt-BR\.json\?v=\w+$/);
assert.equal(await serverError(page), 'Método não permitido.');
await page.locator('#settings-open').click();
assert.ok((await page.locator('.settings-tab:not([hidden])').allTextContents()).includes('Idioma'));
await page.locator('.settings-tab[data-pane=language]').click();
assert.equal(await page.locator('#settings-language-select').inputValue(), 'pt-BR');
// And back to English: the choice beats the browser's language.
await Promise.all([page.waitForEvent('load'), page.locator('#settings-language-select').selectOption('en')]);
await page.locator('#application').waitFor();
assert.equal(await lang(page), 'en');
await page.context().close();

// A browser in Portuguese gets Portuguese; the menu of the sign-in screen switches it before signing in.
page = await open('pt-BR');
await page.goto(base);
assert.equal(await lang(page), 'pt-BR');
await page.locator('#auth-name').waitFor();
assert.equal(await page.locator('#gate-language').inputValue(), 'pt-BR');
await Promise.all([page.waitForEvent('load'), page.locator('#gate-language').selectOption('en')]);
await page.locator('#auth-name').waitFor();
assert.equal(await lang(page), 'en');
assert.deepEqual(await portuguese(page, '#gate'), []);
assert.match((await page.context().cookies()).find(c => c.name === 'atelier_lang')?.value ?? '', /^en$/);
await page.context().close();

assert.deepEqual(errors, []);
console.log('PASS language: English by default for an English browser, Portuguese for a Portuguese one, and both menus switch it, signed in or not');
await browser.close();
