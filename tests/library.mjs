import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { justify, firstRowAt, monthMarks, railMarks } from '../assets/library.js';

const options = { width: 1000, rowHeight: 200, gap: 6, sectionGap: 24, header: 30, headerGap: 14 };
const close = (a, b, message) => assert.ok(Math.abs(a - b) < 0.01, `${message}: ${a} ≠ ${b}`);

// Full rows fill the width exactly; the last row of the view keeps the target height.
const square = Array.from({ length: 11 }, () => ({ aspect: 1 }));
let layout = justify(square, options);
for (const row of layout.rows.slice(0, -1)) {
  const right = layout.tiles[row.last].x + layout.tiles[row.last].w;
  close(right, 1000, 'a full row reaches the right edge');
  assert.ok(row.h <= 200 * 1.7 && row.h > 100, 'full rows stay near the target height');
}
assert.equal(layout.rows.at(-1).h, 200, 'the last row is not stretched');
assert.equal(layout.tiles.length, 11);
assert.ok(layout.tiles.every(t => t.x >= 0 && t.x + t.w <= 1000.01), 'no tile leaves the width');
close(layout.height, layout.rows.at(-1).bottom, 'height ends at the last row');

// Tiles keep their order, left to right and top to bottom.
for (let i = 1; i < layout.tiles.length; i++) {
  const a = layout.tiles[i - 1], b = layout.tiles[i];
  assert.ok(b.y > a.y || (b.y === a.y && b.x > a.x), 'tiles stay in order');
}

// Days: a short day shares the row of the previous one, with its own heading; a long one starts a new row.
const days = [
  { aspect: 1, group: 'Hoje' }, { aspect: 1, group: 'Hoje' },
  { aspect: 1.5, group: 'Ontem' },
  ...Array.from({ length: 9 }, () => ({ aspect: 1, group: '3 de março' })),
];
layout = justify(days, options);
assert.deepEqual(layout.headers.map(h => h.group), ['Hoje', 'Ontem', '3 de março']);
assert.equal(layout.tiles[2].y, layout.tiles[0].y, 'a short day joins the row');
close(layout.tiles[2].x, 2 * 200 + 6 + 24, 'after a section gap');
assert.equal(layout.headers[1].y, layout.headers[0].y, 'both headings are on the same line');
close(layout.headers[1].x, layout.tiles[2].x, 'a heading starts at its first tile');
assert.ok(layout.tiles[3].y > layout.tiles[2].y, 'a day too long for the rest of the row starts a new one');
assert.equal(layout.tiles[3].x, 0);
close(layout.headers[2].y, layout.rows[0].bottom + 6 + 14, 'space above a new heading');
close(layout.tiles[3].y, layout.headers[2].y + 30, 'tiles start below their heading');

// Without groups there are no headings.
assert.equal(justify(square, options).headers.length, 0);

// A very wide image alone fills a row scaled down; very tall or wide shapes are clamped.
layout = justify([{ aspect: 8 }, { aspect: 0.1 }], { ...options, width: 300 });
assert.ok(layout.tiles[0].w <= 300.01, 'a wide image never overflows');
close(layout.tiles[1].w / layout.tiles[1].h, 1 / 3, 'tall shapes are clamped');

// Finding rows near the screen.
layout = justify(Array.from({ length: 100 }, () => ({ aspect: 1 })), options);
const first = firstRowAt(layout.rows, 1000);
assert.ok(layout.rows[first].bottom >= 1000 && (first === 0 || layout.rows[first - 1].bottom < 1000));
assert.equal(firstRowAt(layout.rows, -50), 0);
assert.equal(firstRowAt(layout.rows, 1e9), layout.rows.length);

// Timeline: one mark per month; years get labels unless crowded, months become dots.
const at = (y, m) => ({ created: new Date(y, m, 15, 12).getTime() / 1000 });
const items = [at(2026, 10), at(2026, 10), at(2026, 9), at(2025, 11), at(2025, 0), at(2024, 5)];
layout = justify(items.map(() => ({ aspect: 1 })), { ...options, width: 210 });
const marks = monthMarks(items, layout);
assert.deepEqual(marks.map(m => `${m.year}-${m.month}`), ['2026-10', '2026-9', '2025-11', '2025-0', '2024-5']);
assert.equal(marks[0].y, 0);
const placed = railMarks(marks, y => y / 10, { labelGap: 18, dotGap: 5 });
assert.deepEqual(placed.filter(m => m.label).map(m => m.year), [2026, 2025, 2024]);
const crowded = railMarks(marks, () => 0);
assert.equal(crowded.length, 1, 'marks on top of each other collapse into one');
console.log('PASS library layout: justified rows, day headings sharing rows, clamping, row lookup, timeline marks.');
