import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { zip } from '../assets/comics.js';
import { MANIFEST, README, slug, packFiles, readPack, withIds, messagePages, unzip, resolve, mapPictures } from '../assets/pack.js';

// A whole story in a zip and back (pack.js): the files the page makes from what story-export gives, and what it reads
// of a zip for story-import, with the pictures moved by their paths in the zip.
const hex = c => c.repeat(32);
const [COVER, ILLUS, SHEET, STYLE, LETTERS, PAGE, CH1, CH2, PG1, STORY, GONE, JOB] = ['a', 'b', 'c', 'd', 'e', 'f', '1', '2', '3', '4', '5', '6'].map(hex);
const enc = new TextEncoder(), dec = new TextDecoder();
const png = n => new Uint8Array([0x89, 0x50, 0x4e, 0x47, n]);
const exported = () => ({
  format: 'atelier-story', version: 1, exported_at: '2026-11-20T12:00:00+00:00',
  story: { id: STORY, title: 'A Ilha das Gaivotas', series: 'Mares', synopsis: 'Duas irmãs.', cover: COVER, cover_by: 'person', writer: { provider: 'claude' }, created: 1 },
  notes: { text: '# Bíblia\nAs irmãs.', rev: 3, by: 'agent' },
  plan: { text: 'Spoiler: o farol.', rev: 2, by: 'agent' },
  chapters: [
    { id: CH1, title: 'A chegada', summary: 'Chegam.', text: `Era uma vez.\n\n![O cais](img:${ILLUS})\n\nFim do dia.`, rev: 4, by: 'agent' },
    { id: CH2, title: 'O Farol!', summary: '', text: `Outra parte.\n\n![Sumiu](img:${GONE})\n\n![Pedido](job:${JOB})\n\nNoite.`, rev: 1, by: 'person' },
  ],
  cast: { rev: 2, by: 'agent', style: { text: 'Aquarela', images: [{ image: STYLE, caption: 'Tom' }], lettering: { text: 'Serifa', image: LETTERS, job: null } },
    characters: [{ id: hex('7'), kind: 'character', name: 'Ana', note: '', images: [{ image: SHEET, caption: 'Frente' }] }] },
  comic: { settings: { kind: 'manga', style: { text: '', images: [{ image: STYLE }] }, rev: 1, by: 'agent' },
    pages: [{ id: PG1, chapter: 1, summary: 'Abertura', panels: [{ characters: [{ name: 'Ana', sheet: SHEET }] }], rows: [1], sketch: '', notes: '', image: PAGE, drawn: 1, rev: 1, by: 'agent' }] },
  images: Object.fromEntries([COVER, ILLUS, SHEET, STYLE, LETTERS, PAGE, GONE].map(id => [id, { title: `Imagem ${id[0]}`, prompt: '' }])),
  conversation: { current: 2, messages: [
    { conversation: 1, turn: null, role: 'user', created: 5, content: { provider: 'claude', native: { role: 'user', content: [{ type: 'text', text: `Veja ${ILLUS}` }] }, view: { text: 'oi', images: [] } } },
    { conversation: 2, turn: hex('8'), role: 'assistant', created: 6, content: { provider: 'claude', native: { input: {} }, view: { text: 'Feito', meta: {} } } },
  ], turns: [{ id: hex('8'), conversation: 2, status: 'completed' }], seen: { chapters: { [CH1]: { rev: 4, title: 'A chegada' } } }, inbox: [] },
});

// The names of the files: no accents and no spaces
assert.equal(slug('O Farol! Ação'), 'o-farol-acao');
assert.equal(slug('???'), 'capitulo');
assert.equal(resolve('capitulos', '../imagens/x.png'), 'imagens/x.png');
assert.equal(resolve('', './a\\b.png'), 'a/b.png');

// The files: the manifest first, a Markdown file a chapter with the pictures as paths in the zip, the bible, the plan,
// the conversation, the pictures and a LEIA-ME; a picture that didn't come leaves the file with its line
const pictures = new Map([COVER, ILLUS, SHEET, STYLE, LETTERS, PAGE].map((id, i) => [id, { bytes: png(i), type: id === PAGE ? 'image/jpeg' : 'image/png' }]));
const source = exported();
const files = packFiles(source, pictures);
const byName = new Map(files.map(f => [f.name, f.bytes]));
assert.equal(files[0].name, MANIFEST, 'the manifest comes first');
assert.deepEqual(source, exported(), 'what the server gave is left as it was');
for (const name of ['capitulos/01-a-chegada.md', 'capitulos/02-o-farol.md', 'biblia.md', 'planejamento.md', 'conversa.json', 'LEIA-ME.txt', `imagens/${COVER}.png`, `imagens/${PAGE}.jpg`])
  assert.ok(byName.has(name), `the zip has ${name}`);
assert.equal(byName.has(`imagens/${GONE}.png`), false);
assert.equal(dec.decode(byName.get('LEIA-ME.txt')), README);
assert.equal(dec.decode(byName.get('capitulos/01-a-chegada.md')), `Era uma vez.\n\n![O cais](../imagens/${ILLUS}.png)\n\nFim do dia.\n`);
assert.equal(dec.decode(byName.get('capitulos/02-o-farol.md')), 'Outra parte.\n\nNoite.\n', 'a picture that is not in the zip, and a request, leave the chapter');
assert.equal(dec.decode(byName.get('planejamento.md')), 'Spoiler: o farol.', 'the plan goes too');
const manifest = JSON.parse(dec.decode(byName.get(MANIFEST)));
assert.equal(manifest.story.cover, `imagens/${COVER}.png`);
assert.deepEqual(manifest.chapters[0], { id: CH1, title: 'A chegada', summary: 'Chegam.', rev: 4, by: 'agent', file: 'capitulos/01-a-chegada.md' }, 'a chapter names its file instead of carrying its text');
assert.deepEqual(manifest.notes, { rev: 3, by: 'agent', file: 'biblia.md' });
assert.equal(manifest.conversation, 'conversa.json');
assert.equal(manifest.cast.style.images[0].image, `imagens/${STYLE}.png`);
assert.equal(manifest.cast.style.lettering.image, `imagens/${LETTERS}.png`);
assert.equal(manifest.cast.characters[0].images[0].image, `imagens/${SHEET}.png`);
assert.equal(manifest.comic.pages[0].image, `imagens/${PAGE}.jpg`);
assert.equal(manifest.comic.pages[0].panels[0].characters[0].sheet, `imagens/${SHEET}.png`);
assert.deepEqual(Object.keys(manifest.images).sort(), [...pictures.keys()].map(id => `imagens/${id}.${id === PAGE ? 'jpg' : 'png'}`).sort(), 'the titles of the pictures in the zip, by path');
const talk = JSON.parse(dec.decode(byName.get('conversa.json')));
assert.deepEqual(talk, exported().conversation, 'the conversation as the server gave it');
console.log('PASS the zip holds the story, a Markdown file a chapter, the bible, the plan, the conversation and the pictures');

// Back: the zip read again gives the story with the pictures as paths, each with its bytes and the id it had
const zipped = await unzip(new Uint8Array(await zip(files).arrayBuffer()));
assert.deepEqual([...zipped.keys()], files.map(f => f.name));
const back = readPack(zipped, 'qualquer.zip');
assert.equal(back.pack.story.title, 'A Ilha das Gaivotas', 'the title of the manifest, not of the zip');
assert.equal(back.pack.chapters[0].text, `Era uma vez.\n\n![O cais](imagens/${ILLUS}.png)\n\nFim do dia.\n`);
assert.equal(back.pack.plan.text, 'Spoiler: o farol.'); assert.equal(back.pack.plan.rev, 2);
assert.equal(back.pack.notes.text, '# Bíblia\nAs irmãs.');
assert.equal('conversation' in back.pack, false); assert.equal('images' in back.pack, false);
assert.deepEqual(back.conversation, exported().conversation);
assert.equal(back.pictures.size, 6);
assert.deepEqual(back.pictures.get(`imagens/${PAGE}.jpg`), { bytes: png(5), type: 'image/jpeg', old: PAGE, title: `Imagem ${PAGE[0]}` });
// With the ids the pictures got in the library: every place that names them, and the chapters' lines in the app's form
const ids = new Map([...back.pictures].map(([path, p]) => [path, p.old === COVER ? COVER : p.old.replace(/./g, '9')]));
ids.delete(`imagens/${LETTERS}.png`); // an upload that failed
const ready = withIds(structuredClone(back.pack), ids);
const NEW = id => id.replace(/./g, '9');
assert.equal(ready.story.cover, COVER, 'a picture the account already has keeps its id');
assert.equal(ready.chapters[0].text, `Era uma vez.\n\n![O cais](img:${NEW(ILLUS)})\n\nFim do dia.\n`);
assert.equal(ready.cast.style.lettering.image, null, 'a picture that did not upload leaves its place empty');
assert.equal(ready.cast.characters[0].images[0].image, NEW(SHEET));
assert.equal(ready.comic.pages[0].image, NEW(PAGE));
assert.equal(ready.comic.pages[0].panels[0].characters[0].sheet, NEW(SHEET));
assert.equal(ready.comic.settings.style.images[0].image, NEW(STYLE));
console.log('PASS the zip comes back with the same story, its pictures by path, and the ids they get in the library put in their places');

// A picture whose upload failed is taken out of its list, and one already an id stays for the server to check
const lists = mapPictures({ cast: { characters: [{ images: [{ image: 'x' }, { image: SHEET }] }] } }, v => (v === 'x' ? null : v));
assert.deepEqual(lists.cast.characters[0].images, [{ image: SHEET }]);
const linked = withIds({ chapters: [{ text: `![a](imagens/sem.png)\n![b](img:${ILLUS})\n![c](https://exemplo.com/x)` }] }, new Map());
assert.equal(linked.chapters[0].text, `\n![b](img:${ILLUS})\n![c](https://exemplo.com/x)`, 'a picture of the zip with no id goes; the app\'s lines and other links stay');

// The conversation in pages for story-import-messages: each content as JSON text, its objects still objects
const pages = messagePages(exported().conversation.messages, 10);
assert.equal(pages.length, 2, 'at least one message a page');
assert.equal(typeof pages[1][0].content, 'string');
assert.equal(pages[1][0].content, JSON.stringify(exported().conversation.messages[1].content));
assert.ok(pages[1][0].content.includes('"input":{}'), 'an empty object stays an object');
assert.deepEqual(Object.keys(pages[0][0]).sort(), ['content', 'conversation', 'created', 'role', 'turn']);
assert.equal(messagePages(exported().conversation.messages, 1e6).length, 1);
assert.deepEqual(messagePages([], 10), []);
console.log('PASS the conversation goes in pages, with its contents as they were');

// Zips made elsewhere: deflated, inside one folder, with the files a Mac adds; with only Markdown files, a story too
const dir = mkdtempSync(join(tmpdir(), 'atelier-pack-'));
try {
  const made = join(dir, 'Minha História.zip');
  writeFileSync(join(dir, 'cap.png'), png(7));
  execFileSync('python3', ['-I', '-c', `
import sys, zipfile
z = zipfile.ZipFile(sys.argv[1], 'w', zipfile.ZIP_DEFLATED)
z.writestr('Minha/10-fim.md', '# O Fim\\n\\nAcabou.\\n')
z.writestr('Minha/2-meio.md', 'Sem título no texto.\\n\\n![Ilha](./fotos/ilha.png)\\n' * 40)
z.writestr('Minha/01-começo.md', '## Começo\\nPrimeira linha.\\r\\n')
z.writestr('Minha/biblia.md', 'As regras.')
z.writestr('Minha/LEIA-ME.txt', 'não é capítulo')
z.writestr('Minha/.DS_Store', 'x')
z.writestr('__MACOSX/Minha/._01-começo.md', 'x')
z.writestr('Minha/fotos/', '')
z.write(sys.argv[2], 'Minha/fotos/ilha.png')
z.close()`, made, join(dir, 'cap.png')]);
  const read = readPack(await unzip(readFileSync(made)), 'Minha História.zip');
  assert.equal(read.pack.story.title, 'Minha História', 'the zip\'s name is the title');
  assert.deepEqual(read.pack.chapters.map(c => c.title), ['Começo', 'meio', 'O Fim'], 'chapters in the order of their numbers, titled by their heading or their name');
  assert.equal(read.pack.chapters[0].text, 'Primeira linha.');
  assert.ok(read.pack.chapters[1].text.startsWith('Sem título no texto.\n\n![Ilha](fotos/ilha.png)\n'), 'a picture linked from a chapter is found by its path in the zip');
  assert.equal(read.pack.notes.text, 'As regras.');
  assert.equal(read.pack.plan, undefined);
  assert.equal(read.conversation, null);
  assert.deepEqual([...read.pictures.keys()], ['fotos/ilha.png']);
  assert.deepEqual(read.pictures.get('fotos/ilha.png'), { bytes: png(7), type: 'image/png', old: null, title: 'ilha' });
  console.log('PASS a deflated zip made by hand, inside a folder, with only Markdown files is read as a story');

  // What can't be read says so
  await assert.rejects(unzip(enc.encode('não sou um zip')), /não é um zip/);
  const locked = new Uint8Array(await zip([{ name: 'a.md', bytes: enc.encode('x') }]).arrayBuffer());
  const view = new DataView(locked.buffer), central = view.getUint32(locked.length - 22 + 16, true);
  view.setUint16(central + 8, view.getUint16(central + 8, true) | 1, true);
  await assert.rejects(unzip(locked), /senha/);
  assert.throws(() => readPack(new Map([[MANIFEST, enc.encode('{')]])), /não é um JSON válido/);
  assert.throws(() => readPack(new Map([['foto.png', png(1)]])), /nem capítulos em Markdown/);
  assert.throws(() => readPack(new Map([[MANIFEST, enc.encode(JSON.stringify({ chapters: [{ title: 'a', file: 'capitulos/a.md' }] }))]])), /Falta o arquivo capitulos\/a.md/);
  console.log('PASS a file that is not a zip, a zip with a password, a broken manifest and a missing chapter are refused with a reason');
} finally { rmSync(dir, { recursive: true, force: true }); }
