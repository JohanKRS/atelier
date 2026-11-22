// Layout of the library: justified rows that fill the width, laid out in full for the whole view (it is only math,
// even for thousands of items) so the page can draw just the rows near the screen. When sorted by date, tiles are
// grouped by day under a heading; a day short enough for the rest of a row shares it with the previous day, as photo
// apps do, so a library spread over many days doesn't turn into a column of lone images.

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/**
 * @param {Array<{aspect:number, group?:string|null}>} tiles in display order. Consecutive tiles with the same `group`
 *   form a section with a heading; `null` (or no group) means no heading.
 * @param {{width:number, rowHeight:number, gap:number, sectionGap?:number, header?:number, headerGap?:number,
 *   maxScale?:number, stretch?:number, minAspect?:number, maxAspect?:number}} o
 *   sectionGap: horizontal space between two days in one row; header: height of a heading line; headerGap: extra
 *   space above a row with headings; maxScale: how much taller than rowHeight a row may grow; stretch: a row that ends
 *   a section is stretched to the full width only when it already fills this fraction of it.
 * @returns {{tiles:Array<{x:number,y:number,w:number,h:number,row:number}>, rows:Array<{top:number,y:number,h:number,
 *   bottom:number,first:number,last:number}>, headers:Array<{group:string,x:number,y:number,width:number,index:number}>,
 *   height:number}}
 */
export function justify(tiles, o) {
  const width = Math.max(1, o.width), R = o.rowHeight, G = o.gap, SG = o.sectionGap ?? 24;
  const HH = o.header ?? 30, HG = o.headerGap ?? 14, maxH = R * (o.maxScale ?? 1.7), stretch = o.stretch ?? 0.8;
  const minA = o.minAspect ?? 1 / 3, maxA = o.maxAspect ?? 3;
  const aspects = tiles.map(t => clamp(Number(t.aspect) > 0 ? Number(t.aspect) : 1, minA, maxA));
  const out = { tiles: new Array(tiles.length), rows: [], headers: [], height: 0 };
  let y = 0;
  // A row being filled: tile indices, the space before each one (gap or section gap) and the headings it starts.
  let row = null;
  const fresh = () => ({ items: [], before: [], heads: [], sum: 0, gaps: 0 });
  const widthAt = (r, h) => r.sum * h + r.gaps;
  const place = (r, h) => {
    if (!r.items.length) return;
    const top = y + (r.heads.length ? (out.rows.length ? HG : 0) : 0);
    const tilesTop = top + (r.heads.length ? HH : 0);
    const index = out.rows.length;
    let x = 0;
    r.items.forEach((t, i) => {
      x += r.before[i];
      const w = aspects[t] * h;
      out.tiles[t] = { x, y: tilesTop, w, h, row: index };
      x += w;
    });
    r.heads.forEach((head, i) => {
      const start = out.tiles[r.items[head.at]].x;
      const next = r.heads[i + 1];
      const end = next ? out.tiles[r.items[next.at]].x - SG : width;
      out.headers.push({ group: head.group, x: start, y: top, width: Math.max(0, end - start), index: r.items[head.at] });
    });
    out.rows.push({ top, y: tilesTop, h, bottom: tilesTop + h, first: r.items[0], last: r.items[r.items.length - 1] });
    y = tilesTop + h + G;
  };
  // Rows that end a section keep the target height unless they are nearly full.
  const close = r => {
    if (!r || !r.items.length) return;
    const full = (width - r.gaps) / r.sum;
    place(r, widthAt(r, R) >= width * stretch ? Math.min(full, maxH) : Math.min(R, full));
  };
  const add = (r, t, space) => { r.before.push(r.items.length ? space : 0); r.gaps += r.items.length ? space : 0; r.items.push(t); r.sum += aspects[t]; };

  // Sections: runs of tiles with the same group.
  const sections = [];
  tiles.forEach((tile, i) => {
    const group = tile.group ?? null;
    const last = sections[sections.length - 1];
    if (last && last.group === group) last.items.push(i); else sections.push({ group, items: [i] });
  });

  for (const section of sections) {
    const headed = section.group !== null;
    const span = section.items.reduce((s, t) => s + aspects[t], 0) * R + (section.items.length - 1) * G;
    if (row && row.items.length) {
      // A whole short day fits in the rest of the row: it joins it, after a wider gap.
      if (headed && row.heads.length && widthAt(row, R) + SG + span <= width) {
        row.heads.push({ group: section.group, at: row.items.length });
        section.items.forEach((t, k) => add(row, t, k ? G : SG));
        continue;
      }
      close(row);
    }
    row = fresh();
    if (headed) row.heads.push({ group: section.group, at: 0 });
    for (const t of section.items) {
      add(row, t, G);
      const h = (width - row.gaps) / row.sum;
      if (h > R) continue;
      // The row is full. Keep the last tile only if that leaves the height closer to the target than moving it on.
      if (row.items.length > 1) {
        const sum = row.sum - aspects[t], gaps = row.gaps - G;
        const without = (width - gaps) / sum;
        if (without <= maxH && without / R < R / h) {
          row.items.pop(); row.before.pop(); row.sum = sum; row.gaps = gaps;
          place(row, without);
          row = fresh(); add(row, t, G);
          const alone = (width - row.gaps) / row.sum;
          if (alone <= R) { place(row, alone); row = fresh(); }
          continue;
        }
      }
      place(row, Math.min(h, maxH));
      row = fresh();
    }
  }
  close(row);
  out.height = out.rows.length ? y - G : 0;
  return out;
}

/** Index of the first row whose bottom is at or below `top` (rows are in order). */
export function firstRowAt(rows, top) {
  let lo = 0, hi = rows.length;
  while (lo < hi) { const mid = (lo + hi) >> 1; if (rows[mid].bottom < top) lo = mid + 1; else hi = mid; }
  return lo;
}

/**
 * Marks for the timeline of a date-sorted view: the first row of each month, as [{y, year, month}] in order.
 * @param {Array<{created:number}|null>} items one per tile (null for tiles that aren't library items)
 */
export function monthMarks(items, layout) {
  const marks = []; let last = null;
  items.forEach((item, i) => {
    if (!item) return;
    const d = new Date(item.created * 1000), key = d.getFullYear() * 12 + d.getMonth();
    if (key === last) return;
    last = key;
    marks.push({ y: layout.rows[layout.tiles[i].row].top, year: d.getFullYear(), month: d.getMonth() });
  });
  return marks;
}

/**
 * Which marks get a year label and which a month dot on a rail `height` pixels tall. `at(y)` maps a content position
 * to the rail. Labels keep `labelGap` pixels apart (the first mark of each year wins), dots `dotGap`.
 */
export function railMarks(marks, at, { labelGap = 18, dotGap = 5 } = {}) {
  const out = []; let lastLabel = -Infinity, lastDot = -Infinity, year = null;
  for (const mark of marks) {
    const position = at(mark.y);
    const newYear = mark.year !== year; year = mark.year;
    if (newYear && position - lastLabel >= labelGap) { out.push({ ...mark, position, label: true }); lastLabel = position; lastDot = position; continue; }
    if (position - lastDot >= dotGap && position - lastLabel >= dotGap) { out.push({ ...mark, position, label: false }); lastDot = position; }
  }
  return out;
}
