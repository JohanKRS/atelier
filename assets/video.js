import { locale } from './i18n.js';
// Pure helpers for videos: frame timing read from the MP4 container, frame/time math and upload pieces.
// No DOM access: covered by tests/video.mjs.

const NTSC = { 23.976: 24000 / 1001, 29.97: 30000 / 1001, 59.94: 60000 / 1001, 119.88: 120000 / 1001 };
export const COMMON_RATES = [23.976, 24, 25, 29.97, 30, 48, 50, 59.94, 60, 90, 100, 119.88, 120];

/**
 * A measured rate snapped to the usual rate it matches within 0.3% (29.9697 → 30000/1001), else to the nearer of the
 * whole rate and its NTSC variant (12.005 → 12, 14.985 → 15000/1001) within 0.3%; others rounded to 0.001.
 */
export function snapFps(fps) {
  if (!Number.isFinite(fps) || fps <= 0 || fps > 1000) return null;
  const close = rate => rate > 0 && Math.abs(fps - rate) / rate < 0.003;
  for (const rate of COMMON_RATES) { const exact = NTSC[rate] || rate; if (close(exact)) return exact; }
  const whole = Math.round(fps), ntsc = Math.round(fps * 1.001) * 1000 / 1001;
  const nearer = Math.abs(fps - whole) <= Math.abs(fps - ntsc) ? whole : ntsc;
  return close(nearer) ? nearer : Math.round(fps * 1000) / 1000;
}
/** "29,97", "25", "23,976": how the rate is written, with a decimal comma. */
export function fpsLabel(fps) {
  if (!fps) return '';
  const rounded = Math.round(fps * 1000) / 1000;
  return rounded.toLocaleString(locale, { maximumFractionDigits: Number.isInteger(rounded) ? 0 : Math.abs(rounded * 100 - Math.round(rounded * 100)) < 1e-6 ? 2 : 3 });
}

/** Index (from 0) of the frame on screen at `time` seconds. A frame's own timestamp maps to that frame. */
export function frameAt(time, fps) { return Math.max(0, Math.floor(time * fps + 1e-3)); }
/** The middle of a frame: seeking there shows that frame even after the browser rounds the time. */
export function frameTime(index, fps) { return (index + 0.5) / fps; }
export function frameCount(duration, fps) { return Math.max(1, Math.round((duration || 0) * fps)); }

/** 0:12.345, or 1:02:03.456 past an hour. */
export function timecode(seconds) {
  const ms = Math.max(0, Math.round((seconds || 0) * 1000));
  const h = Math.floor(ms / 3600000), m = Math.floor(ms / 60000) % 60, s = Math.floor(ms / 1000) % 60, rest = ms % 1000;
  const tail = `${String(s).padStart(2, '0')}.${String(rest).padStart(3, '0')}`;
  return h ? `${h}:${String(m).padStart(2, '0')}:${tail}` : `${m}:${tail}`;
}

/** Megabytes with a decimal comma: "12,4 MB". */
export function megabytes(bytes) {
  const mb = bytes / 1048576;
  return `${mb.toLocaleString(locale, { maximumFractionDigits: mb < 10 ? 1 : 0 })} MB`;
}

/** Pieces of an upload as [offset, length]: `piece` bytes each, the last one shorter. */
export function pieces(size, piece) {
  const out = [];
  for (let offset = 0; offset < size; offset += piece) out.push([offset, Math.min(piece, size - offset)]);
  return out;
}

// ---------------------------------------------------------------------------
// MP4 / MOV (ISO BMFF)
// ---------------------------------------------------------------------------
const fourcc = (view, at) => String.fromCharCode(view.getUint8(at), view.getUint8(at + 1), view.getUint8(at + 2), view.getUint8(at + 3));
/** Boxes inside [start, end) of a view: { type, start (of the payload), end }. */
function* boxes(view, start, end) {
  let at = start;
  while (at + 8 <= end) {
    let size = view.getUint32(at), header = 8;
    if (size === 1) { if (at + 16 > end) return; size = Number(view.getBigUint64(at + 8)); header = 16; }
    else if (size === 0) size = end - at;
    if (size < header || at + size > end) return;
    yield { type: fourcc(view, at + 4), start: at + header, end: at + size };
    at += size;
  }
}
const child = (view, parent, type) => {
  if (!parent) return null;
  for (const box of boxes(view, parent.start, parent.end)) if (box.type === type) return box;
  return null;
};

/**
 * Frame timing of the first video track of an MP4/MOV file, from its time-to-sample table: the frame rate (the most
 * common frame duration, or the average when durations vary a lot) and the number of frames. `read(offset, length)`
 * resolves to an ArrayBuffer of the file. Resolves to null when the file isn't MP4, the movie header is missing
 * (fragmented files) or the table is empty.
 */
export async function mp4Timing(read, size) {
  let at = 0, moov = null;
  while (at + 8 <= size) {
    const head = new DataView(await read(at, Math.min(16, size - at)));
    if (head.byteLength < 8) return null;
    let length = head.getUint32(0), header = 8;
    const type = fourcc(head, 4);
    if (!/^[\x20-\x7e]{4}$/.test(type)) return null;
    if (length === 1) { if (head.byteLength < 16) return null; length = Number(head.getBigUint64(8)); header = 16; }
    else if (length === 0) length = size - at;
    if (length < header) return null;
    if (type === 'moov') {
      if (length > 256 * 1048576) return null;
      moov = new DataView(await read(at, Math.min(length, size - at)));
      break;
    }
    at += length;
  }
  if (!moov) return null;
  const root = child(moov, { start: 0, end: moov.byteLength }, 'moov');
  for (const trak of boxes(moov, root?.start ?? 0, root?.end ?? 0)) {
    if (trak.type !== 'trak') continue;
    const mdia = child(moov, trak, 'mdia');
    const hdlr = child(moov, mdia, 'hdlr');
    if (!hdlr || hdlr.end - hdlr.start < 12 || fourcc(moov, hdlr.start + 8) !== 'vide') continue;
    const mdhd = child(moov, mdia, 'mdhd');
    if (!mdhd) continue;
    const timescale = moov.getUint32(mdhd.start + (moov.getUint8(mdhd.start) === 1 ? 20 : 12));
    const stts = child(moov, child(moov, child(moov, mdia, 'minf'), 'stbl'), 'stts');
    if (!timescale || !stts) continue;
    const entries = Math.min(moov.getUint32(stts.start + 4), Math.floor((stts.end - stts.start - 8) / 8));
    let frames = 0, total = 0;
    const byDelta = new Map();
    for (let i = 0; i < entries; i++) {
      const count = moov.getUint32(stts.start + 8 + i * 8), delta = moov.getUint32(stts.start + 12 + i * 8);
      frames += count; total += count * delta;
      if (delta) byDelta.set(delta, (byDelta.get(delta) || 0) + count);
    }
    if (!frames || !total) continue;
    const [delta, count] = [...byDelta.entries()].sort((a, b) => b[1] - a[1])[0];
    return { fps: count >= frames * 0.9 ? timescale / delta : timescale * frames / total, frames, timescale };
  }
  return null;
}
