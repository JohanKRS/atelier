import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { snapFps, fpsLabel, frameAt, frameTime, frameCount, timecode, megabytes, pieces, mp4Timing } from '../assets/video.js';

// The fixture: 2 s of synthetic VP9 video at 12 frames per second, in MP4 and in WebM.
const mp4 = await readFile(new URL('./fixtures/frames.mp4', import.meta.url));
const reader = bytes => async (offset, length) => bytes.buffer.slice(bytes.byteOffset + offset, bytes.byteOffset + offset + length);
assert.deepEqual(await mp4Timing(reader(mp4), mp4.length), { fps: 12, frames: 24, timescale: 12288 }, 'frame rate and count read from the MP4 time-to-sample table');
const webm = await readFile(new URL('./fixtures/frames.webm', import.meta.url));
assert.equal(await mp4Timing(reader(webm), webm.length), null, 'other containers are left to the player');
assert.equal(await mp4Timing(reader(Buffer.from('not a video at all')), 18), null);

// A movie header after the media data (no "fast start") is found too.
const box = (type, ...parts) => { const body = Buffer.concat(parts); const head = Buffer.alloc(8); head.writeUInt32BE(8 + body.length); head.write(type, 4, 'latin1'); return Buffer.concat([head, body]); };
const u32 = (...values) => { const b = Buffer.alloc(4 * values.length); values.forEach((v, i) => b.writeUInt32BE(v, i * 4)); return b; };
const track = (handler, timescale, table) => box('trak', box('mdia',
  box('mdhd', u32(0, 0, 0, timescale, 0, 0)),
  box('hdlr', u32(0, 0), Buffer.from(handler, 'latin1'), u32(0, 0, 0)),
  box('minf', box('stbl', box('stts', u32(0, table.length), ...table.map(([count, delta]) => u32(count, delta)))))));
const late = Buffer.concat([box('ftyp', Buffer.from('isom', 'latin1'), u32(0)), box('mdat', Buffer.alloc(1000)),
  box('moov', track('soun', 48000, [[100, 1024]]), track('vide', 30000, [[299, 1001], [1, 1002]]))]);
const timing = await mp4Timing(reader(late), late.length);
assert.equal(timing.frames, 300);
assert.equal(snapFps(timing.fps), 30000 / 1001, 'the video track is used, not the audio one; 29.97 stays exact');

assert.equal(snapFps(29.9697), 30000 / 1001);
assert.equal(snapFps(25.02), 25);
assert.equal(snapFps(12), 12);
assert.equal(snapFps(12.005), 12, 'a WebM rate measured from millisecond timestamps');
assert.equal(snapFps(14.985), 15000 / 1001);
assert.equal(snapFps(17.3), 17.3);
assert.equal(snapFps(0), null);
assert.equal(fpsLabel(30000 / 1001), '29,97');
assert.equal(fpsLabel(24000 / 1001), '23,976');
assert.equal(fpsLabel(25), '25');

// Frame math: a frame's timestamp and its middle map to the frame; seeking goes to the middle.
const fps = 30000 / 1001;
for (const n of [0, 1, 312, 4519]) {
  assert.equal(frameAt(n / fps, fps), n, `frame ${n} from its timestamp`);
  assert.equal(frameAt(frameTime(n, fps), fps), n, `frame ${n} from the seek target`);
}
assert.equal(frameCount(2, 12), 24);
assert.equal(frameCount(0, 30), 1);
assert.equal(timecode(10.4), '0:10.400');
assert.equal(timecode(3723.4567), '1:02:03.457');
assert.equal(megabytes(5 * 1048576), '5 MB');
assert.equal(megabytes(1.25 * 1048576), '1,3 MB');
assert.deepEqual(pieces(10, 4), [[0, 4], [4, 4], [8, 2]]);
assert.deepEqual(pieces(8, 4), [[0, 4], [4, 4]]);
console.log('video helpers ok');
