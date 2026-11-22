import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { crc32 } from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pdf, zip, comicInfo } from '../assets/comics.js';

// The downloads of a comic (comics.js), made on the person's device with no library: a zip with the pages stored as
// they are (CBZ), a PDF of JPEG pages, and the ComicInfo.xml comic readers read.
const bytes = async blob => new Uint8Array(await blob.arrayBuffer());
const text = new TextEncoder();

// The zip: every file where its entry says, with its size and CRC, in order
const files = [{ name: '001.jpg', bytes: new Uint8Array([0xff, 0xd8, 1, 2, 3, 0xff, 0xd9]) }, { name: 'ComicInfo.xml', bytes: text.encode('<ComicInfo>ação</ComicInfo>') }];
const z = await bytes(zip(files)), view = new DataView(z.buffer);
const end = z.length - 22;
assert.equal(view.getUint32(end, true), 0x06054b50, 'the zip ends with its directory');
assert.equal(view.getUint16(end + 10, true), 2);
let at = view.getUint32(end + 16, true);
for (const f of files) {
  assert.equal(view.getUint32(at, true), 0x02014b50);
  const crc = view.getUint32(at + 16, true), size = view.getUint32(at + 20, true), nameLength = view.getUint16(at + 28, true), local = view.getUint32(at + 42, true);
  assert.equal(new TextDecoder().decode(z.subarray(at + 46, at + 46 + nameLength)), f.name);
  assert.equal(crc, crc32(f.bytes), `${f.name}: its CRC`);
  assert.equal(size, f.bytes.length);
  assert.equal(view.getUint32(local, true), 0x04034b50);
  const start = local + 30 + view.getUint16(local + 26, true);
  assert.deepEqual(z.subarray(start, start + size), f.bytes, `${f.name}: its bytes, stored as they are`);
  at += 46 + nameLength;
}
console.log('PASS a CBZ is a plain zip of the pages, in reading order');
// A zip program reads it too, when there is one to ask
const dir = mkdtempSync(join(tmpdir(), 'atelier-cbz-'));
try {
  writeFileSync(join(dir, 'test.cbz'), z);
  const listed = execFileSync('python3', ['-I', '-c', 'import sys, zipfile; z = zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; print(",".join(z.namelist()))', join(dir, 'test.cbz')]).toString().trim();
  assert.equal(listed, '001.jpg,ComicInfo.xml');
  console.log('PASS Python\'s zipfile opens it and finds every file intact');
} catch (e) { if (e.code !== 'ENOENT') throw e; } finally { rmSync(dir, { recursive: true, force: true }); }

// The PDF: each object where the cross-reference table says, the pages in order, right to left for a manga
const jpeg = { bytes: new Uint8Array([0xff, 0xd8, 0xff, 0xd9]), width: 1024, height: 1536 };
const p = await bytes(pdf([jpeg, { ...jpeg, width: 2048, height: 3072 }], { title: 'Mangá 𝄞', rtl: true }));
const s = new TextDecoder('latin1').decode(p);
assert.ok(s.startsWith('%PDF-1.7\n%') && p[10] > 127, 'a PDF with a binary header');
const xref = Number(s.match(/startxref\n(\d+)\n%%EOF\n$/)[1]);
assert.equal(s.slice(xref, xref + 4), 'xref');
const [, first, count] = s.slice(xref).match(/^xref\n(\d+) (\d+)\n/);
assert.equal(Number(first), 0); assert.equal(Number(count), 10, 'catalog, pages, info, and three objects a page');
const offsets = [...s.slice(xref).matchAll(/^(\d{10}) 00000 n $/gm)].map(m => Number(m[1]));
assert.equal(offsets.length, 9);
offsets.forEach((o, i) => assert.equal(s.slice(o, o + `${i + 1} 0 obj`.length), `${i + 1} 0 obj`, `object ${i + 1} is where the table says`));
assert.ok(s.includes('/ViewerPreferences << /Direction /R2L >> /PageLayout /TwoPageRight'), 'it reads right to left');
assert.ok(s.includes('/Kids [6 0 R 9 0 R]') && s.includes('/Count 2'));
assert.ok(s.includes('/MediaBox [0 0 500 750]') && s.includes('/Width 2048 /Height 3072') && s.includes('/Filter /DCTDecode /Length 4'));
assert.ok(s.includes('/Title <FEFF004D0061006E006700E10020D834DD1E>'), 'the title in UTF-16, accents and all');
assert.ok(!new TextDecoder('latin1').decode(await bytes(pdf([jpeg], { rtl: false }))).includes('/Direction'), 'a comic book reads left to right');
console.log('PASS a PDF of the pages, its objects where its table says, right to left for a manga');

// ComicInfo.xml
const info = comicInfo({ title: 'O <Farol> & "eu"', series: 'Ilha', synopsis: '' }, { kind: 'manga', reading: 'rtl', color: 'bw' }, 12);
assert.ok(info.includes('<Title>O &lt;Farol&gt; &amp; &quot;eu&quot;</Title>') && info.includes('<Series>Ilha</Series>') && !info.includes('<Summary>'));
assert.ok(info.includes('<PageCount>12</PageCount>') && info.includes('<Manga>YesAndRightToLeft</Manga>') && info.includes('<BlackAndWhite>Yes</BlackAndWhite>'));
assert.ok(comicInfo({ title: 'X' }, { kind: 'comic', reading: 'ltr', color: 'color' }, 1).includes('<Manga>No</Manga>'));
assert.ok(comicInfo({ title: 'X' }, { kind: 'graphic_novel', reading: 'ltr', color: 'color' }, 1).includes('<Manga>No</Manga>'), 'a graphic novel isn\'t a manga');
console.log('PASS ComicInfo.xml says what the comic is, and escapes the title');
console.log('comic file checks passed');
