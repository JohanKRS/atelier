import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { snap16, frameKind, keptArea, validateFrame, frameTooLarge, resizeFrame, panFrame, sizeFrame, centerFrame, frameView } from '../assets/outpaint.js';

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
// A frame around the image fills the stage; the scale never goes over 1.
assert.deepEqual(frameView({ width: 2048, height: 1536, x: 256, y: 192 }, IW, IH, 1088, 832), { scale: 0.5, x: 32, y: 32 });
// A crop: the whole image is still shown, and the frame is drawn where it sits on it.
const cropView = frameView({ width: 768, height: 576, x: -384, y: -288 }, IW, IH, 1088, 832);
assert.deepEqual([cropView.scale.toFixed(4), Math.round(cropView.x), Math.round(cropView.y)], ['0.6667', 288, 224]);
assert.equal(frameView({ width: 320, height: 320, x: 0, y: 0 }, 320, 320, 1088, 832).scale, 1);
console.log('PASS frame geometry: crop, expansion and both at once, free crop sizes, multiples of 16 with new areas, API limits, magnet to the image edges, positioning, view');
