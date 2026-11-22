import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import vm from 'node:vm';
import { zip } from '../assets/comics.js';
import { parseBlocks, parseInline, inlineText, wordCount, storyMarkdown, storyHtml, storyEpub, storyLanguage, escapeHtml, fileName, doing, moved, illustrateMode, duration, liveStatus, unsavedSteps, stoppedMessage, spoilerParts, MARKER } from '../assets/story.js';

const id = 'a'.repeat(32);
const chapter = `A névoa cobria a ilha.\nMarina olhou o mar.\n\n— Quem está aí? — perguntou *baixinho*.\n\n* * *\n\n![O barco na névoa](img:${id})\n\n- Não sei, disse ele.\n\n**Fim** da _parte_ um.\n\n![Pendente](job:${'b'.repeat(32)})`;
const blocks = parseBlocks(chapter);
assert.deepEqual(blocks.map(b => b.type), ['p', 'p', 'break', 'figure', 'p', 'p', 'figure']);
assert.deepEqual(blocks[0].inline, [{ t: 'text', v: 'A névoa cobria a ilha.' }, { t: 'br' }, { t: 'text', v: 'Marina olhou o mar.' }], 'lines of a paragraph keep their breaks');
assert.deepEqual(blocks[1].inline[1], { t: 'em', c: [{ t: 'text', v: 'baixinho' }] });
assert.deepEqual(blocks[3], { type: 'figure', caption: 'O barco na névoa', kind: 'img', id });
assert.equal(blocks[4].type, 'p', 'a line starting with a hyphen is dialogue, not a list');
assert.equal(blocks[6].kind, 'job');
assert.deepEqual(parseInline('**Fim** da _parte_ um.').map(n => n.t), ['strong', 'text', 'em', 'text']);
console.log('PASS chapters parse into paragraphs, scene breaks, emphasis and illustrations');

assert.equal(inlineText(parseInline('um_dois_tres e 2 * 3 * 4')), 'um_dois_tres e 2 * 3 * 4', 'underscores inside words and spaced asterisks stay as written');
assert.equal(inlineText(parseInline('*aberto sem fechar')), '*aberto sem fechar');
assert.equal(inlineText(parseInline('\\*literal\\*')), '*literal*');
assert.deepEqual(parseBlocks('# Carta\n\n> Querida mãe,\n> estou bem.\n\n1. um\n2. dois').map(b => b.type), ['h', 'quote', 'list']);
console.log('PASS what is not emphasis stays text; headings, quotes and numbered lists');

assert.equal(wordCount(chapter), 22);
assert.ok(MARKER.test(`![x](img:${id})`) && !MARKER.test(`![x](http://example.com/${id})`));
console.log('PASS words are counted without the illustration lines');

const story = { title: 'O Farol <de> Vidro', series: 'Ilhas', synopsis: 'Uma história & um mistério.', cover: id,
  chapters: [{ title: 'A chegada', text: chapter }, { title: '<script>alert(1)</script>', text: 'Texto <b>cru</b>.' }] };
const md = storyMarkdown(story);
assert.ok(md.startsWith('# O Farol <de> Vidro\n\n*Ilhas*\n\nUma história & um mistério.\n\n## 1. A chegada\n\n'));
assert.ok(md.includes('*[Ilustração: O barco na névoa]*') && !md.includes('img:'), 'illustrations become their captions in Markdown');
const html = storyHtml(story, new Map([[id, 'data:image/jpeg;base64,AAAA']]));
assert.ok(!html.includes('<script>alert') && html.includes('&lt;script&gt;alert(1)&lt;/script&gt;') && html.includes('Texto &lt;b&gt;cru&lt;/b&gt;.'), 'the page escapes every text');
assert.ok(html.includes('<title>O Farol &lt;de&gt; Vidro</title>') && html.includes('<img class="cover" src="data:image/jpeg;base64,AAAA"'), 'the cover and the title');
assert.ok(html.includes('<figcaption>O barco na névoa</figcaption>') && html.includes('<p class="missing">[Pendente]</p>') && html.includes('<em>baixinho</em>'), 'illustrations inside, missing ones as captions');
assert.equal(escapeHtml(`"'&`), '&quot;&#39;&amp;');
console.log('PASS the story exports as Markdown and as a page that escapes its text');

// The story as an EPUB book: the mimetype first, the package with the title, the author of the cover, the language of
// the chapters and an id that stays the same, a page per chapter with its illustrations, and never the plan.
{
  const sid = '0123456789abcdef0123456789abcdef', pic = (type, width, height) => ({ bytes: new Uint8Array([1, 2, 3]), type, width, height });
  const book = { ...story, id: sid, updated: 1763700000, cover_design: { author: 'Ana & Rui' }, plan: { text: 'PLANO SECRETO' } };
  const { files, used } = storyEpub(book, new Map([[id, pic('image/jpeg', 1024, 1536)]]));
  const text = name => new TextDecoder().decode(files.find(f => f.name === name).bytes);
  assert.deepEqual(files.slice(0, 3).map(f => f.name), ['mimetype', 'META-INF/container.xml', 'OEBPS/content.opf']);
  assert.equal(text('mimetype'), 'application/epub+zip');
  const opf = text('OEBPS/content.opf');
  assert.ok(opf.includes('<dc:title>O Farol &lt;de&gt; Vidro</dc:title>') && opf.includes('<dc:creator id="author">Ana &amp; Rui</dc:creator>') && opf.includes('<dc:language>pt-BR</dc:language>'), 'title, author and language');
  assert.ok(opf.includes('<dc:identifier id="book-id">urn:uuid:01234567-89ab-4def-8123-456789abcdef</dc:identifier>'), 'the id is the story\'s, as a UUID');
  assert.ok(opf.includes('<meta property="dcterms:modified">2025-11-21T04:40:00Z</meta>') && opf.includes('<meta property="belongs-to-collection" id="series">Ilhas</meta>') && opf.includes('<dc:description>Uma história &amp; um mistério.</dc:description>'));
  assert.ok(opf.includes(`href="images/${id}.jpg" media-type="image/jpeg" properties="cover-image"`) && opf.includes(`<meta name="cover" content="img-${id}"/>`), 'the cover');
  assert.deepEqual([...opf.matchAll(/<itemref idref="([^"]+)"/g)].map(m => m[1]), ['cover', 'titlepage', 'nav', 'c1', 'c2']);
  assert.deepEqual(used, [id], 'the picture the cover and the chapter share goes in once');
  assert.equal(files.filter(f => f.name.startsWith('OEBPS/images/')).length, 1);
  assert.ok(text('OEBPS/cover.xhtml').includes(`viewBox="0 0 1024 1536"`), 'the cover keeps its proportions');
  const one = text('OEBPS/chapter-001.xhtml'), two = text('OEBPS/chapter-002.xhtml');
  assert.ok(one.startsWith('<?xml') && one.includes('<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="pt-BR" xml:lang="pt-BR">'));
  assert.ok(one.includes(`<figure><img src="images/${id}.jpg" alt="O barco na névoa"/><figcaption>O barco na névoa</figcaption></figure>`) && one.includes('<br/>') && !one.includes('<br>'), 'illustrations inside, XHTML line breaks');
  assert.ok(!one.includes('Pendente') && !one.includes('job:'), 'an illustration still being made is left out');
  assert.ok(two.includes('&lt;script&gt;alert(1)&lt;/script&gt;') && two.includes('Texto &lt;b&gt;cru&lt;/b&gt;.') && !files.some(f => new TextDecoder().decode(f.bytes).includes('<script')), 'every text is escaped');
  assert.ok(text('OEBPS/nav.xhtml').includes('<li><a href="chapter-001.xhtml">1. A chegada</a></li>') && text('OEBPS/toc.ncx').includes('content="urn:uuid:01234567-89ab-4def-8123-456789abcdef"'), 'the contents, new and old');
  assert.ok(!files.some(f => new TextDecoder().decode(f.bytes).includes('PLANO')), 'the plan stays out');
  // In the zip, the mimetype is the first file, stored, with no extra field (what e-readers check first).
  const bytes = new Uint8Array(await zip(files).arrayBuffer()), view = new DataView(bytes.buffer);
  assert.deepEqual([view.getUint32(0, true), view.getUint16(8, true), view.getUint16(28, true)], [0x04034b50, 0, 0]);
  assert.equal(new TextDecoder().decode(bytes.slice(30, 30 + 8 + 20)), 'mimetypeapplication/epub+zip');

  // A story with no cover, author, series or chapters is still a book; a missing picture is left out.
  const bare = storyEpub({ id: sid, title: '', chapters: [{ title: '', text: `The rain fell and she was gone.\n\n![Lost](img:${'c'.repeat(32)})` }] });
  const names = bare.files.map(f => f.name), bareOpf = new TextDecoder().decode(bare.files[2].bytes);
  assert.ok(!names.includes('OEBPS/cover.xhtml') && !names.some(n => n.startsWith('OEBPS/images/')) && !bareOpf.includes('dc:creator') && !bareOpf.includes('cover'));
  assert.ok(bareOpf.includes('<dc:title>Untitled</dc:title>') && bareOpf.includes('<dc:language>en</dc:language>'));
  assert.ok(new TextDecoder().decode(bare.files.find(f => f.name === 'OEBPS/chapter-001.xhtml').bytes).includes('<span class="number">1</span>Chapter 1</h2>'), 'an untitled chapter is named in the story\'s language');
  assert.ok(new TextDecoder().decode(storyEpub({ id: sid, title: 'X', chapters: [] }).files.find(f => f.name === 'OEBPS/nav.xhtml').bytes).includes('<a href="title.xhtml">X</a>'));
  assert.ok(!new TextDecoder().decode(storyEpub({ id: sid, title: 'Bad\u0001\uD800 char', chapters: [] }).files[2].bytes).match(/[\u0001\uD800]/), 'characters XML forbids are dropped');
  assert.deepEqual(['Ela não disse nada. Era uma noite fria e o vento batia na janela da casa.', 'She said nothing and the wind was cold. It was the night of the storm.',
    'Ella no dijo nada y el viento era frío. Pero los perros del pueblo ladraban.', 'Elle ne dit rien et le vent était froid. Les chiens du village aboyaient dans la nuit.',
    'Lei non disse niente e il vento era freddo. Gli uomini della città dormivano.', 'Sie sagte nichts und der Wind war kalt. Die Hunde des Dorfes bellten in der Nacht.', '']
    .map(t => storyLanguage({ chapters: [{ text: t }] })), ['pt-BR', 'en', 'es', 'fr', 'it', 'de', 'pt-BR'], 'the language comes from the chapters');
}
console.log('PASS the story exports as an EPUB book with its cover, illustrations, contents and details, without the plan');

assert.equal(fileName('O Farol de Vidro: Parte 1', 'md'), 'o-farol-de-vidro-parte-1.md');
assert.equal(fileName('', 'html'), 'historia.html');
assert.equal(doing('write_chapter'), 'Escrevendo um capítulo…');
assert.equal(doing(null), 'Pensando…');
assert.deepEqual(moved(['a', 'b', 'c', 'd'], 0, 2), ['b', 'c', 'a', 'd']);
assert.deepEqual(moved(['a', 'b', 'c'], 2, 0), ['c', 'a', 'b']);
assert.deepEqual([illustrateMode('text'), illustrateMode('each'), illustrateMode(true), illustrateMode(false), illustrateMode(undefined), illustrateMode('x')], ['text', 'each', 'each', 'ask', 'ask', 'ask']);
console.log('PASS file names, what the writer is doing, moving a chapter, and how the writer illustrates');

// The writer edits illustrations on the server with the edit panel's ready-made instructions (app/Presets.php): they
// must say exactly what the browser's say.
const app = readFileSync(new URL('../assets/app.js', import.meta.url), 'utf8');
const grab = name => { const m = app.match(new RegExp(`^const ${name} = [\\s\\S]*?[\`'];$`, 'm')); assert.ok(m, name); return m[0]; };
// What the writer is doing while it works, and why a turn stopped
assert.deepEqual([duration(45), duration(185), duration(3900), duration(-3)], ['45 s', '3 min 05 s', '1 h 05 min', '0 s']);
const working = liveStatus({ status: 'running', started: 100, now: 230, cost: 0.4, live: { tool: 'write_chapter', words: 1240, step: 3, retry: 'A Anthropic está sobrecarregada. Tentando de novo em 6 s (2ª tentativa).' } });
assert.deepEqual(working, { what: 'Escrevendo um capítulo…', details: ['passo 3', '2 min 10 s', '~1.240 palavras'], warnings: ['A Anthropic está sobrecarregada. Tentando de novo em 6 s (2ª tentativa).'] });
assert.equal(liveStatus({ status: 'running', live: { tool: 'illustrate', words: 300, step: 1 } }).details.length, 0, 'the words count only for prose, the first step goes without a number');
assert.equal(liveStatus({ status: 'running', live: { text: 'Escrevi' } }).what, 'Respondendo…');
assert.equal(liveStatus({ status: 'queued' }).what, 'Começando…');
assert.match(liveStatus({ status: 'running', live: { fallback: 'Claude Sonnet 5.5' } }).warnings[0], /passou o pedido para o Claude Sonnet 5\.5/);
assert.equal(stoppedMessage({ status: 'interrupted', started: 100, updated: 400, was: { tool: 'write_chapter' } }),
  'O servidor parou o escritor sem aviso depois de 5 min de trabalho (estava escrevendo um capítulo). Isso acontece quando a hospedagem encerra o processo: por tempo limite, falta de memória ou um reinício (Configurações › Teste da hospedagem mostra o limite deste servidor). O que ele já salvou continua na história.');
assert.equal(stoppedMessage({ status: 'failed', error: 'Sem crédito na Anthropic.' }), 'Sem crédito na Anthropic.');
// The steps of a working turn show once: those already in the chat don't show again with what it is doing now
{
  const step = label => ({ name: 'read_chapter', ok: true, label });
  const done = ['Leu os capítulos 1 a 3', 'Leu os capítulos 4 a 6', 'Atualizou o planejamento', 'Atualizou o planejamento', 'Leu o capítulo 7'].map(step);
  const turn = { id: 't2', status: 'running', live: { done, step: 5 } };
  const chatOf = (...counts) => [{ role: 'tools', turn_id: 't1', results: [step('De outro pedido')] }, { role: 'user', text: 'Revisar' },
    ...counts.map((n, i) => ({ role: 'tools', turn_id: 't2', results: done.slice(counts.slice(0, i).reduce((a, b) => a + b, 0)).slice(0, n) }))];
  assert.deepEqual(unsavedSteps(turn, chatOf(1, 1, 1, 1)).map(r => r.label), ['Leu o capítulo 7'], 'only the step whose answer is still running its tools');
  assert.deepEqual(unsavedSteps(turn, chatOf(1, 1, 1, 1, 1)), [], 'all in the chat: none again, a step done twice included');
  assert.equal(unsavedSteps(turn, chatOf()).length, 5, 'none in the chat yet');
  assert.deepEqual(unsavedSteps({ id: 't3', status: 'queued' }, chatOf(5)), []);
}
console.log('PASS the writer at work says what it is doing, for how long, and why it stopped');

const browser = vm.runInNewContext(['UPSCALE_PROMPT', 'PHOTOREAL_PROMPT', 'ENHANCE_STEPS', 'ENHANCE_PROMPT', 'UPSCALE_PHOTOREAL'].map(grab).join('\n')
  + '\n' + app.match(/^function withNotes\(prompt, notes\) \{[\s\S]*?^\}$/m)[0] + '\n' + app.match(/^function upscalePrompt\(instructions, extra\) \{[\s\S]*?^\}$/m)[0]
  + '\n;({ photoreal: withNotes(PHOTOREAL_PROMPT, "golden light"), enhance: withNotes(ENHANCE_PROMPT, "sharper eyes"), plain: PHOTOREAL_PROMPT,'
  + ' upscale: upscalePrompt("", "none").trim(), upscaleNotes: upscalePrompt("face", "none").trim(), upscaleEnhance: upscalePrompt("face", "enhance").trim(), upscalePhoto: upscalePrompt("", "photoreal").trim() })');
const server = JSON.parse(execFileSync('php', ['-r', `require 'app/Presets.php'; use Studio\\Presets as P; echo json_encode(['photoreal'=>P::withNotes(P::PHOTOREAL,'golden light'),'enhance'=>P::withNotes(P::ENHANCE,'sharper eyes'),'plain'=>P::PHOTOREAL,
  'upscale'=>P::upscale(''),'upscaleNotes'=>P::upscale('face'),'upscaleEnhance'=>P::upscale('face','enhance'),'upscalePhoto'=>P::upscale('','photoreal')]);`], { cwd: new URL('..', import.meta.url) }).toString());
assert.deepEqual(server, { ...browser });
console.log('PASS the writer uses the same Fotorrealista, Melhorar and Ampliar instructions as the edit panel');

// The writer marks what can be read before the chapters; the rest of a reply stays hidden while replies are hidden
assert.deepEqual(spoilerParts('<no_spoilers>Escrevi o capítulo 3. Sigo?</no_spoilers>\n\nNo capítulo, o pai morre.'),
  { safe: 'Escrevi o capítulo 3. Sigo?', full: 'Escrevi o capítulo 3. Sigo?\n\nNo capítulo, o pai morre.', hiddenWords: 5 });
assert.deepEqual(spoilerParts('Uma resposta antiga, sem marca.'), { safe: '', full: 'Uma resposta antiga, sem marca.', hiddenWords: 5 }, 'no mark: all hidden');
assert.deepEqual(spoilerParts('<no_spoilers>Terminei.</no_spoilers>\n\nSegredo.\n\n<no_spoilers>Quer o 4?</no_spoilers>'), { safe: 'Terminei.\n\nQuer o 4?', full: 'Terminei.\n\nSegredo.\n\nQuer o 4?', hiddenWords: 1 }, 'several blocks');
assert.deepEqual(spoilerParts('<NO_SPOILERS >Só estrutura.</ no_spoilers>'), { safe: 'Só estrutura.', full: 'Só estrutura.', hiddenWords: 0 }, 'all of it free of spoilers');
assert.equal(spoilerParts('<no_spoilers>Escrevendo a resposta').safe, 'Escrevendo a resposta', 'a block still streaming shows as it comes');
assert.deepEqual(spoilerParts('<no_spoilers>Pronto.</no_spo'), { safe: 'Pronto.', full: 'Pronto.', hiddenWords: 0 }, 'a tag cut off at the end of the stream is dropped');
assert.equal(spoilerParts('Texto <no_spo').full, 'Texto');
assert.equal(spoilerParts('a < b e <b>negrito</b>').full, 'a < b e <b>negrito</b>', 'other angle brackets stay');
assert.deepEqual(spoilerParts(''), { safe: '', full: '', hiddenWords: 0 });
console.log('PASS the writer\'s replies split into the part marked free of spoilers and the rest');
