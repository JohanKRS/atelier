import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { snap16, frameKind, keptArea, validateFrame, frameTooLarge, resizeFrame, panFrame, sizeFrame, centerFrame, ratioFrame, hasRatio, resizeCrop, holdCrop, frameView, aroundFrame, resizeLocked, lockedSize } from '../assets/outpaint.js';

assert.equal(snap16(1539), 1536);
const IW = 1536, IH = 1152;
const kind = f => frameKind(f.width, f.height, f.x, f.y, IW, IH);
const error = f => validateFrame(f.width, f.height, f.x, f.y, IW, IH);
const whole = { width: IW, height: IH, x: 0, y: 0 };

// What a frame does: nothing, a crop, an expansion, or both at once.
assert.equal(kind(whole), 'same');
assert.equal(kind({ width: 800, height: 600, x: -100, y: -50 }), 'crop');
assert.equal(kind({ width: 2048, height: 1536, x: 256, y: 192 }), 'expand');
assert.equal(kind({ width: 1536, height: 1152, x: -512, y: 0 }), 'both', 'the left is cut off and the right is new');
assert.equal(kind({ width: 2048, height: 640, x: 0, y: -100 }), 'both');
assert.deepEqual(keptArea(1536, 1152, -512, 0, IW, IH), { x: 512, y: 0, width: 1024, height: 1152 });
assert.deepEqual(keptArea(2048, 1536, 256, 192, IW, IH), { x: 0, y: 0, width: IW, height: IH });

// A crop takes any size; a frame with new areas has the limits of the image API.
assert.equal(error({ width: 801, height: 333, x: -7, y: -3 }), '');
assert.equal(error({ width: 2048, height: 1536, x: 256, y: 192 }), '');
assert.equal(error({ width: 1536, height: 1152, x: -512, y: 0 }), '');
assert.match(error(whole), /^Arraste as alças/);
assert.match(error({ width: 2049, height: 1536, x: 0, y: 0 }), /múltiplos de 16/);
assert.match(error({ width: 3840, height: 3840, x: 0, y: 0 }), /limite de 3\.840 px/);
assert.match(error({ width: 3840, height: 1152, x: 0, y: 0 }), /proporção de até 3:1/);
assert.match(error({ width: 640, height: 640, x: 320, y: 0 }), /pelo menos 0,66 MP/, 'a small frame with a new area is under what the API generates');
assert.equal(error({ width: 640, height: 640, x: -320, y: 0 }), '', 'the same size inside the image is only a crop');
assert.match(error({ width: 800, height: 600, x: 790, y: 0 }), /pegar uma parte da imagem/);
assert.match(error({ width: 800.5, height: 600, x: 0, y: 0 }), /inteiras/);
assert.equal(frameTooLarge(3856, 1024), true); assert.equal(frameTooLarge(3840, 2160), false);

// Dragging a handle. Inwards the image is cropped, to any size; no edge but the dragged one moves.
assert.deepEqual(resizeFrame(whole, 'w', 203, 0, IW, IH), { width: 1333, height: IH, x: -203, y: 0 });
assert.deepEqual(resizeFrame(whole, 'se', -100, -51, IW, IH), { width: 1436, height: 1101, x: 0, y: 0 });
assert.deepEqual(resizeFrame(whole, 'n', 0, 77, IW, IH), { width: IW, height: 1075, x: 0, y: -77 });
// Outwards the frame grows in steps of 16 from the edge that stays.
assert.deepEqual(resizeFrame(whole, 'e', 500, 0, IW, IH), { width: 2032, height: IH, x: 0, y: 0 });
assert.deepEqual(resizeFrame(whole, 'w', -500, 0, IW, IH), { width: 2032, height: IH, x: 496, y: 0 });
assert.deepEqual(resizeFrame(whole, 'nw', -100, -100, IW, IH), { width: 1632, height: 1248, x: 96, y: 96 });
// Crop one side and extend another: the frame leaves the image, so its sides become multiples of 16.
const cropped = resizeFrame(whole, 'w', 500, 0, IW, IH);
assert.deepEqual(cropped, { width: 1036, height: IH, x: -500, y: 0 });
const both = resizeFrame(cropped, 'e', 300, 0, IW, IH);
assert.deepEqual(both, { width: 1344, height: IH, x: -500, y: 0 });
assert.equal(kind(both), 'both'); assert.equal(error(both), '');
// A side that was cropped to a loose size is settled when another side leaves the image; a side flush with the image stays flush.
const loose = { width: 1000, height: 700, x: -536, y: -100 };   // its right edge is the right edge of the image
assert.deepEqual(resizeFrame(loose, 's', 0, 600, IW, IH), { width: 1008, height: 1296, x: -528, y: -100 });
// An edge near an edge of the image sticks to it.
assert.deepEqual(resizeFrame({ width: 2048, height: 1536, x: 256, y: 192 }, 'w', 250, 0, IW, IH, 10), { width: 1792, height: 1536, x: 0, y: 192 });
assert.deepEqual(resizeFrame(cropped, 'w', -495, 0, IW, IH, 10), whole);
// A frame always keeps a part of the image and a size.
assert.deepEqual(resizeFrame(whole, 'w', 5000, 0, IW, IH), { width: 32, height: IH, x: -1504, y: 0 });
assert.deepEqual(resizeFrame(whole, 'e', -5000, 0, IW, IH), { width: 32, height: IH, x: 0, y: 0 });
assert.equal(keptArea(...Object.values(resizeFrame({ width: 2048, height: 1536, x: 256, y: 192 }, 'e', -5000, 0, IW, IH)), IW, IH).width >= 16, true);

// Dragging the image inside the frame.
assert.deepEqual(panFrame({ width: 2048, height: 1536, x: 256, y: 192 }, 100, -60, IW, IH), { width: 2048, height: 1536, x: 356, y: 132 });
assert.deepEqual(panFrame({ width: 2048, height: 1536, x: 256, y: 192 }, -250, 0, IW, IH, 10), { width: 2048, height: 1536, x: 0, y: 192 }, 'the image sticks to the left edge of the frame');
assert.deepEqual(panFrame({ width: 2048, height: 1536, x: 256, y: 192 }, 250, 0, IW, IH, 10), { width: 2048, height: 1536, x: 512, y: 192 }, 'and to the right one');
assert.deepEqual(panFrame({ width: 800, height: 600, x: -100, y: -50 }, -50, -50, IW, IH), { width: 800, height: 600, x: -150, y: -100 }, 'a crop moves over the image');
assert.equal(kind(panFrame({ width: 801, height: 601, x: -100, y: -50 }, 300, 0, IW, IH)), 'both');
assert.equal(error(panFrame({ width: 1001, height: 701, x: -100, y: -50 }, 300, 0, IW, IH)), '', 'a crop dragged out of the image gets sides in multiples of 16');
assert.deepEqual(panFrame({ width: 800, height: 600, x: 0, y: 0 }, 9000, -9000, IW, IH), { width: 800, height: 608, x: 768, y: -1120 }, 'the image never leaves the frame');

// Typed sizes, centering, and where the frame is drawn.
assert.deepEqual(sizeFrame({ width: 2048, height: 1536, x: 256, y: 192 }, 1024, 1024, IW, IH), { width: 1024, height: 1024, x: 256, y: 192 });
assert.deepEqual(sizeFrame({ width: 2048, height: 1536, x: 1800, y: 192 }, 1024, 1024, IW, IH), { width: 1024, height: 1024, x: 1008, y: 192 });
assert.deepEqual(centerFrame({ width: 2048, height: 1536, x: 0, y: 0 }, IW, IH), { width: 2048, height: 1536, x: 256, y: 192 });
assert.deepEqual(centerFrame({ width: 800, height: 600, x: 0, y: 0 }, IW, IH), { width: 800, height: 600, x: -368, y: -276 });
// A proportion crops the largest frame of that shape out of the middle of the image.
assert.deepEqual(ratioFrame(IW, IH, 1, 1), { width: 1152, height: 1152, x: -192, y: 0 });
assert.deepEqual(ratioFrame(IW, IH, 16, 9), { width: 1536, height: 864, x: 0, y: -144 });
assert.deepEqual(ratioFrame(IW, IH, 2, 3), { width: 768, height: 1152, x: -384, y: 0 });
assert.deepEqual(ratioFrame(1024, 1536, 4, 5), { width: 1024, height: 1280, x: 0, y: -128 });
assert.deepEqual(ratioFrame(1000, 1000, 16, 9), { width: 1000, height: 563, x: 0, y: -218 });
for (const [rw, rh] of [[1, 1], [4, 5], [3, 4], [2, 3], [9, 16], [16, 9], [4, 3], [3, 2]]) {
  const f = ratioFrame(1000, 777, rw, rh);
  assert.ok(hasRatio(f.width, f.height, rw, rh), `${rw}:${rh} keeps its proportion`);
  assert.equal(frameKind(f.width, f.height, f.x, f.y, 1000, 777), 'crop', `${rw}:${rh} only crops`);
  assert.equal(validateFrame(f.width, f.height, f.x, f.y, 1000, 777), '');
}
assert.equal(frameKind(...Object.values(ratioFrame(IW, IH, 4, 3)), IW, IH), 'same', 'the proportion the image already has leaves it as it is');
assert.ok(!hasRatio(1152, 1100, 1, 1));

// Recortar keeps the frame inside the image: a handle stops at the edge, any whole size is fine, and dragging the image
// under the frame moves it only as far as the image goes.
assert.deepEqual(resizeCrop(whole, 'w', 301, 0, IW, IH), { width: 1235, height: 1152, x: -301, y: 0 });
assert.deepEqual(resizeCrop(whole, 'w', -200, 0, IW, IH), whole, 'pulled outward, the side stays on the edge of the image');
assert.deepEqual(resizeCrop(whole, 'se', -37, -1000, IW, IH), { width: 1499, height: 152, x: 0, y: 0 });
assert.deepEqual(resizeCrop(whole, 'e', -1600, 0, IW, IH), { width: 32, height: 1152, x: 0, y: 0 }, 'it keeps at least 32 px');
assert.deepEqual(resizeCrop({ width: 800, height: 600, x: -100, y: -100 }, 'n', 0, -94, IW, IH, 8), { width: 800, height: 700, x: -100, y: 0 }, 'an edge close to the image\'s sticks to it');
const crop = { width: 800, height: 600, x: -100, y: -100 };
assert.deepEqual(holdCrop(crop, 50, 0, IW, IH), { width: 800, height: 600, x: -50, y: -100 });
assert.deepEqual(holdCrop(crop, 500, 500, IW, IH), { width: 800, height: 600, x: 0, y: 0 });
assert.deepEqual(holdCrop(crop, -5000, -5000, IW, IH), { width: 800, height: 600, x: -736, y: -552 });
assert.deepEqual(holdCrop({ width: 4000, height: 600, x: -100, y: -100 }, 0, 0, IW, IH), { width: IW, height: 600, x: 0, y: -100 }, 'a typed size larger than the image is the image\'s');
for (const [h, dx, dy] of [['nw', 700, 500], ['ne', -900, 30], ['s', 0, 2000], ['w', -3000, 0], ['sw', 9999, -9999]]) {
  const f = resizeCrop(crop, h, dx, dy, IW, IH);
  assert.ok(['crop', 'same'].includes(frameKind(f.width, f.height, f.x, f.y, IW, IH)), `${h} stays inside the image`);
}
// A locked proportion. In Expandir it starts as the smallest frame of that shape around the whole image, in the middle,
// with sides in multiples of 16; a shape the image already has is a quarter larger all around.
assert.deepEqual(aroundFrame(1024, 1024, 16, 9), { width: 1824, height: 1024, x: 400, y: 0 });
assert.deepEqual(aroundFrame(1024, 1024, 9, 16), { width: 1024, height: 1824, x: 0, y: 400 });
assert.deepEqual(aroundFrame(IW, IH, 4, 3), { width: 1920, height: 1440, x: 192, y: 144 }, 'the image\'s own shape');
assert.deepEqual(aroundFrame(1000, 777, 1, 1), { width: 1008, height: 1008, x: 4, y: 115 }, 'a side that is not a multiple of 16 is held whole');
for (const [rw, rh] of [[1, 1], [4, 5], [3, 4], [2, 3], [9, 16], [16, 9], [4, 3], [3, 2], [1000, 777]]) {
  const f = aroundFrame(1000, 777, rw, rh);
  assert.ok(Math.abs(f.width * rh - f.height * rw) <= 16 * Math.max(rw, rh), `${rw}:${rh} is within 16 px of its shape`);
  assert.equal(frameKind(f.width, f.height, f.x, f.y, 1000, 777), 'expand', `${rw}:${rh} holds the whole image`);
  assert.equal(validateFrame(f.width, f.height, f.x, f.y, 1000, 777), '');
}
// Dragged with the proportion locked: a corner holds the opposite one, a side grows evenly on the two next to it.
const wide = aroundFrame(1024, 1024, 16, 9), shape = (f, rw, rh, by = 16) => Math.abs(f.width * rh - f.height * rw) <= by * Math.max(rw, rh);
assert.deepEqual(resizeLocked(wide, 'se', 100, 10, 1024, 1024, 16, 9), { width: 1920, height: 1088, x: 400, y: 0 });
assert.deepEqual(resizeLocked(wide, 'e', 160, 0, 1024, 1024, 16, 9), { width: 1984, height: 1120, x: 400, y: 48 });
assert.deepEqual(resizeLocked(wide, 'n', 0, -90, 1024, 1024, 16, 9), { width: 1984, height: 1120, x: 480, y: 96 });
assert.deepEqual(resizeLocked(wide, 'nw', -5, -3, 1024, 1024, 16, 9), wide, 'it moves in steps of 16');
for (const [h, dx, dy] of [['nw', -300, -10], ['ne', 50, -400], ['sw', 200, 30], ['s', 0, 333], ['w', 260, 0], ['e', -5000, 0], ['n', 0, 5000]]) {
  const f = resizeLocked(wide, h, dx, dy, 1024, 1024, 16, 9);
  assert.ok(shape(f, 16, 9), `${h} keeps 16:9 (${f.width} × ${f.height})`);
  assert.equal(validateFrame(f.width, f.height, f.x, f.y, 1024, 1024, { side: 99999, area: 1e12, ratio: 5, minArea: 0, gpu: true }), '', `${h} keeps a part of the image`);
}
// Recortar: inside the image and to the pixel, a handle pulled outward stops at the edge.
const crop169 = ratioFrame(IW, IH, 16, 9);
assert.deepEqual(resizeLocked(crop169, 'se', -200, 0, IW, IH, 16, 9, true), { width: 1336, height: 752, x: 0, y: -144 });
assert.deepEqual(resizeLocked(crop169, 'e', -300, 0, IW, IH, 16, 9, true), { width: 1236, height: 695, x: 0, y: -229 });
assert.deepEqual(resizeLocked(crop169, 'nw', -500, -500, IW, IH, 16, 9, true), crop169);
assert.deepEqual(resizeLocked({ width: 400, height: 400, x: -1100, y: -700 }, 'e', 900, 0, IW, IH, 1, 1, true), { width: 436, height: 436, x: -1100, y: -682 }, 'a side stops where the image ends');
for (const [h, dx, dy] of [['nw', 700, 500], ['ne', -900, 30], ['s', 0, 2000], ['w', -3000, 0], ['sw', 9999, -9999], ['n', 0, -77]]) {
  const f = resizeLocked(crop169, h, dx, dy, IW, IH, 16, 9, true);
  assert.ok(['crop', 'same'].includes(frameKind(f.width, f.height, f.x, f.y, IW, IH)), `${h} stays inside the image`);
  assert.ok(hasRatio(f.width, f.height, 16, 9), `${h} keeps 16:9 to the pixel (${f.width} × ${f.height})`);
}
assert.deepEqual(resizeLocked({ width: 800, height: 600, x: -100, y: -100 }, 'n', 0, -94, IW, IH, 4, 3, true, 8), { width: 933, height: 700, x: -34, y: 0 }, 'an edge close to the image\'s sticks to it');
// A typed side takes the other with it.
assert.deepEqual(lockedSize(crop169, 'width', 800, IW, IH, 16, 9, true), { width: 800, height: 450, x: 0, y: -144 });
assert.deepEqual(lockedSize(crop169, 'width', 4000, IW, IH, 16, 9, true), crop169, 'never larger than the image');
assert.deepEqual(lockedSize(crop169, 'height', 1152, IW, IH, 1, 1, true), { width: 1152, height: 1152, x: 0, y: 0 });
assert.deepEqual(lockedSize(wide, 'width', 2048, 1024, 1024, 16, 9), { width: 2048, height: 1152, x: 400, y: 0 });
assert.deepEqual(lockedSize(wide, 'height', 1200, 1024, 1024, 16, 9), { width: 2128, height: 1200, x: 400, y: 0 }, 'with a new area, the other side in a multiple of 16');
// A frame around the image fills the stage; the scale never goes over 1.
assert.deepEqual(frameView({ width: 2048, height: 1536, x: 256, y: 192 }, IW, IH, 1088, 832), { scale: 0.5, x: 32, y: 32 });
// A crop: the whole image is still shown, and the frame is drawn where it sits on it.
const cropView = frameView({ width: 768, height: 576, x: -384, y: -288 }, IW, IH, 1088, 832);
assert.deepEqual([cropView.scale.toFixed(4), Math.round(cropView.x), Math.round(cropView.y)], ['0.6667', 288, 224]);
assert.equal(frameView({ width: 320, height: 320, x: 0, y: 0 }, 320, 320, 1088, 832).scale, 1);
console.log('PASS frame geometry: crop, expansion and both at once, free crop sizes, multiples of 16 with new areas, API limits, magnet to the image edges, locked proportions, positioning, view');
