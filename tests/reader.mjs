import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { readerSettings, pageLayout, minutesLeft, READER_DEFAULTS, READER_SIZES, MEASURE } from '../assets/reader.js';

// The settings: the defaults for what is missing or unknown, sizes on the reader's steps
assert.deepEqual(readerSettings(null), READER_DEFAULTS);
assert.deepEqual(readerSettings([]), READER_DEFAULTS, 'an empty list from the server is the defaults');
assert.equal(READER_DEFAULTS.mode, 'pages');
const saved = readerSettings({ size: 133, theme: 'sepia', mode: 'scroll', width: 'huge', spread: 'yes', open: true, other: 1 });
assert.equal(saved.size, 135, 'a size between two steps goes to the nearest');
assert.equal(saved.theme, 'sepia'); assert.equal(saved.mode, 'scroll'); assert.equal(saved.open, true);
assert.equal(saved.width, 'medium', 'an unknown width is the default'); assert.equal(saved.spread, true, 'a flag that is not true or false is the default');
assert.equal('other' in saved, false);
assert.equal(readerSettings({ size: 10 }).size, READER_SIZES[0]); assert.equal(readerSettings({ size: 999 }).size, READER_SIZES.at(-1));
// The line at the foot: the page (or the part read) and the time left show until they are turned off, each on its own
assert.equal(READER_DEFAULTS.progress, true); assert.equal(READER_DEFAULTS.remaining, true);
const quiet = readerSettings({ progress: false, remaining: 'no' });
assert.equal(quiet.progress, false, 'the page at the foot can be turned off'); assert.equal(quiet.remaining, true, 'the time left keeps its default when the value is not true or false');
assert.equal(readerSettings({ remaining: false }).remaining, false, 'the time left can be turned off');

// The pages: a single column no wider than the chosen width, centred; two side by side on a wide screen lying down
const defaults = readerSettings(null), font = 18.5;
const fits = (l, width) => {
  assert.ok(Number.isInteger(l.gap) && l.gap > 0, 'the gap is whole pixels');
  assert.ok(Math.abs(l.columns * (l.column + l.gap) - width) < 0.01, 'a screen holds its columns and gaps exactly (a page turn moves one screen)');
};
const portrait = pageLayout({ width: 820, height: 1180, font: 17.5, prefs: defaults });
fits(portrait, 820);
assert.equal(portrait.columns, 1, 'a tablet standing up: one page');
assert.ok(portrait.column >= 690 && portrait.column <= MEASURE.medium * 17.5, `the text fills a tablet standing up (${portrait.column}px)`);
const landscape = pageLayout({ width: 1180, height: 820, font, prefs: defaults });
fits(landscape, 1180);
assert.equal(landscape.columns, 2, 'a tablet lying down: two pages, like a book');
assert.ok(landscape.column >= 0.6 * MEASURE.medium * font && landscape.column <= MEASURE.medium * font);
assert.equal(pageLayout({ width: 1180, height: 820, font, prefs: { ...defaults, spread: false } }).columns, 1, 'two pages only when the setting allows');
assert.equal(pageLayout({ width: 1180, height: 820, font, prefs: { ...defaults, width: 'full' } }).columns, 1, 'the whole width is one page');
const full = pageLayout({ width: 1180, height: 820, font, prefs: { ...defaults, width: 'full' } });
fits(full, 1180);
assert.ok(full.column >= 1180 - 2 * 48, 'the whole width leaves only the margins');
const wide = pageLayout({ width: 1024, height: 768, font, prefs: { ...defaults, width: 'wide' } });
assert.equal(wide.columns, 1, 'two pages that would make the lines much shorter than the chosen width are one page');
const phone = pageLayout({ width: 390, height: 844, font: 17.5, prefs: defaults });
fits(phone, 390);
assert.equal(phone.columns, 1); assert.equal(phone.column, 390 - 40, 'a phone keeps 20px margins');
const desktop = pageLayout({ width: 1440, height: 900, font, prefs: defaults });
fits(desktop, 1440); assert.equal(desktop.columns, 2);

// The time left in the chapter
assert.equal(minutesLeft(2300, 1), '10 min restantes no capítulo');
assert.equal(minutesLeft(2300, 0.1), '1 min restante no capítulo');
assert.equal(minutesLeft(2300, 0.05), 'Menos de 1 min no capítulo');
assert.equal(minutesLeft(2300, 0), 'Fim do capítulo');
console.log('PASS reader: settings with defaults and steps, pages that fit the screen (one on a phone or a tablet standing up, two on a tablet lying down), time left');
