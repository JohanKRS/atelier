import { locale } from './i18n.js';
// Cost estimates for image requests, in US dollars.
//
// Rates per 1M tokens: OpenAI pricing page, checked on 2026-11-14 (standard tier). GPT Image 2.5 is billed like 2.6;
// GPT Image 1 mini is no longer on that page, so its last published rates are used.
// OpenAI does not publish output token counts for every size, so these anchors come from measured requests
// (1024 × 1024 and 1536 × 1024 or 1024 × 1536) and other sizes are scaled from them. The real cost of each
// image, from the usage the API reports, is shown in the viewer.
export const PRICING_CHECKED = '14/11/2026';

const RATES = {
  'gpt-image-2.6-flare': { text: 5, imageIn: 8, out: 30 },
  'gpt-image-2.6-sunburst': { text: 5, imageIn: 8, out: 30 },
  'gpt-image-2.5-flare': { text: 5, imageIn: 8, out: 30 },
  'gpt-image-2.5-sunburst': { text: 5, imageIn: 8, out: 30 },
  'gpt-image-2': { text: 2.5, imageIn: 4, out: 15 },
  'gpt-image-1-mini': { text: 2, imageIn: 2.5, out: 8 },
};

// Output tokens per image: square at about 1 MP, and 2:3 or 3:2 at about 1.5 MP.
const GPT_IMAGE_25 = {
  square: { low: 824, medium: 1449, high: 1756, xhigh: 3122, max: 7024 },
  wide: { low: 664, medium: 1116, high: 1353, xhigh: 2405, max: 5410 },
};
const OUTPUT = {
  'gpt-image-2.6-flare': GPT_IMAGE_25,
  'gpt-image-2.6-sunburst': GPT_IMAGE_25,
  'gpt-image-2.5-flare': GPT_IMAGE_25,
  'gpt-image-2.5-sunburst': GPT_IMAGE_25,
  'gpt-image-2': { square: { low: 200, medium: 1767, high: 7033 }, wide: { low: 167, medium: 1367, high: 5488 } },
  'gpt-image-1-mini': { square: { low: 625, medium: 1375, high: 4500 }, wide: { low: 750, medium: 1875, high: 6500 } },
};
const SQUARE_AREA = 1024 * 1024, WIDE_AREA = 1536 * 1024;
// Output tokens grow slower than the pixel count (4K at high quality costs about twice a 1 MP image).
const AREA_EXPONENT = 0.55;
// Each partial image (preview) adds about 100 output tokens.
const PREVIEW_TOKENS = 100;
// Input images are counted at about one token per thousand pixels, up to about 2.4 MP.
const INPUT_PIXEL_CAP = 1536 * 1536;
export const UNKNOWN_IMAGE = [1024, 1024];

// Google's Nano Banana (GeminiImages::MODELS), from ai.google.dev/gemini-api/docs/pricing, checked on 2026-11-20
// (Standard tier). Per 1M tokens: input (text and images), text and thinking out, image out. A picture costs by its
// resolution (perImage, the image tokens of 1K, 2K or 4K), plus what goes in and what it thinks.
export const GEMINI_PRICING_CHECKED = '20/11/2026';
const GEMINI_IMAGES = {
  'gemini-nano-banana-2.1': { input: 1.5, text: 7.5, image: 30, perImage: { '1K': 0.0336, '2K': 0.0504, '4K': 0.113 }, minTier: '1K', thinking: { low: 0, medium: 600, high: 1500 } },
  'gemini-3-pro-image': { input: 2, text: 12, image: 120, perImage: { '1K': 0.134, '2K': 0.134, '4K': 0.24 }, minTier: '2K', thinking: { low: 1500, medium: 1500, high: 1500 } },
};
// Each image sent to Gemini 3 counts about 1,120 input tokens.
const GEMINI_IMAGE_INPUT = 1120;
/** The resolution Nano Banana makes a size at (GeminiImages::tier). */
// Newer models the person's keys list (the server's Catalog) are priced like the model they follow: id => that model.
const ALIASES = new Map();
export function setAliases(models) {
  ALIASES.clear();
  for (const [id, m] of Object.entries(models || {})) if (m?.like && m.like !== id) ALIASES.set(id, m.like);
}
const priced = model => ALIASES.get(model) || model;

export function geminiTier(model, size) {
  model = priced(model);
  const g = GEMINI_IMAGES[model]; if (!g) return null;
  const m = /^(\d+)x(\d+)$/.exec(String(size || ''));
  const area = m ? Number(m[1]) * Number(m[2]) : 0;
  const tier = !m || area <= 1700000 ? '1K' : area <= 5000000 ? '2K' : '4K';
  return ['1K', '2K', '4K'].indexOf(tier) < ['1K', '2K', '4K'].indexOf(g.minTier) ? g.minTier : tier;
}
export function isGemini(model) { return Boolean(GEMINI_IMAGES[priced(model)]); }

// The text model that plans prompts and the directions of a varied batch (OpenAI::PLANNER). Rates per 1M tokens from
// its model page, checked on 2026-11-14; reasoning is billed as output. Planning is paid apart from the image, also when
// the image then fails.
export const PLANNING_MODEL = 'gpt-6-sol';
// GPT as the writer of the stories (Gpt::MODELS) is paid from the same OpenAI balance: its calls are counted with the
// planning ones, at their own rates (checked on 2026-11-18).
// Gemini 3.8 Flash plans the prompts of Nano Banana (GeminiImages::PLANNER), paid to Google (its price until 1 January 2027).
const TEXT_RATES = { 'gpt-6-sol': { input: 2, cached: 0.2, out: 10 }, 'gpt-6.2-sol': { input: 2, cached: 0.1, out: 10 }, 'gpt-6.2-astra': { input: 8, cached: 0.4, out: 40 },
  'gemini-3.8-flash': { input: 0.75, cached: 0.075, out: 3.75 } };

export function hasPricing(model) { model = priced(model); return Boolean(RATES[model] || GEMINI_IMAGES[model]); }

function dimensions(size) {
  const m = /^(\d+)x(\d+)$/.exec(String(size || ''));
  return m ? [Number(m[1]), Number(m[2])] : [1024, 1024];
}

/** Estimated output tokens of one image. "auto" quality is counted as high, which the API usually picks. */
export function outputTokens(model, quality, width, height) {
  const table = OUTPUT[priced(model)]; if (!table) return 0;
  const tier = table.square[quality] ? quality : 'high';
  const ratio = Math.max(width, height) / Math.max(1, Math.min(width, height));
  // Between square and 3:2 the anchors are blended; wider shapes use the 3:2 anchor.
  const t = Math.min(1, Math.log(ratio) / Math.log(1.5));
  const tokens = table.square[tier] * (1 - t) + table.wide[tier] * t;
  const area = SQUARE_AREA * (1 - t) + WIDE_AREA * t;
  return Math.round(tokens * Math.pow((width * height) / area, AREA_EXPONENT));
}

export function inputImageTokens(width, height) {
  return Math.round(Math.min(width * height, INPUT_PIXEL_CAP) / 1000);
}

/**
 * Estimated cost of n images. Each image is its own API request, so the prompt, the input images and the
 * previews are paid once per image.
 * inputs: [width, height] of every image sent along (the image being edited, references, the expansion canvas).
 * Prompt planning is billed separately and is not included.
 */
export function estimate({ model, quality, size, n = 1, previews = 0, inputs = [], promptLength = 0 }) {
  model = priced(model);
  const g = GEMINI_IMAGES[model];
  if (g) {
    const thinking = g.thinking[quality] ?? g.thinking.high;
    const perImage = g.perImage[geminiTier(model, size)] + ((inputs.length * GEMINI_IMAGE_INPUT + Math.ceil(promptLength / 4)) * g.input + thinking * g.text) / 1e6;
    return { total: perImage * n, perImage };
  }
  const rate = RATES[model]; if (!rate) return null;
  const [width, height] = dimensions(size);
  const output = outputTokens(model, quality, width, height) + previews * PREVIEW_TOKENS;
  const imageInput = inputs.reduce((sum, [w, h]) => sum + inputImageTokens(w, h), 0);
  const textInput = Math.ceil(promptLength / 4);
  const perImage = (output * rate.out + imageInput * rate.imageIn + textInput * rate.text) / 1e6;
  return { total: perImage * n, perImage };
}

/** Real cost from the usage a request reported (an image request or a planning call), or null when it is missing. */
export function usageCost(model, usage) {
  model = priced(model);
  const text = TEXT_RATES[model];
  if (text) {
    if (!usage || !Number.isFinite(usage.output_tokens) || !Number.isFinite(usage.input_tokens)) return null;
    const cached = Math.min(usage.input_tokens, Number(usage.input_tokens_details?.cached_tokens) || 0);
    return ((usage.input_tokens - cached) * text.input + cached * text.cached + usage.output_tokens * text.out) / 1e6;
  }
  const g = GEMINI_IMAGES[model];
  if (g) {
    if (!usage || !Number.isFinite(usage.input_tokens)) return null;
    const cached = Number(usage.input_tokens_details?.cached_tokens) || 0, out = usage.output_tokens_details || {};
    return ((usage.input_tokens - cached) * g.input + cached * g.input * 0.1 + (Number(out.image_tokens) || 0) * g.image + (Number(out.text_tokens) || 0) * g.text) / 1e6;
  }
  const rate = RATES[model];
  if (!rate || !usage || !Number.isFinite(usage.output_tokens)) return null;
  const details = usage.input_tokens_details || {};
  const imageInput = Number(details.image_tokens) || 0;
  const textInput = Number.isFinite(details.text_tokens) ? Number(details.text_tokens) : Math.max(0, (Number(usage.input_tokens) || 0) - imageInput);
  return (usage.output_tokens * rate.out + imageInput * rate.imageIn + textInput * rate.text) / 1e6;
}

/**
 * What the planning of an image cost: the planning of its prompt and, on the image that planned a varied batch, the
 * directions of the batch. Null when there was none or its tokens were not kept.
 */
export function planningCost(item) {
  const costs = [item?.planning_usage, item?.variation_usage].filter(Boolean).map(usage => usageCost(usage.model || PLANNING_MODEL, usage));
  return costs.length && costs.every(cost => cost !== null) ? costs.reduce((sum, cost) => sum + cost, 0) : null;
}

/**
 * Average cost of one planning call, from the account's latest requests (the "planning" of the balance): by effort, or
 * "variation" for the directions of a varied batch. Null without at least three calls to go by.
 */
export function planningAverage(stats, slot) {
  const s = stats?.[slot]; if (!s || !(s.calls >= 3)) return null;
  const total = usageCost(s.model || PLANNING_MODEL, { input_tokens: s.input_tokens, output_tokens: s.output_tokens, input_tokens_details: { cached_tokens: s.cached_tokens } });
  return total === null ? null : total / s.calls;
}

export function formatUsd(value) {
  const digits = value < 1 ? 3 : 2;
  return 'US$ ' + value.toLocaleString(locale, { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

/**
 * Top-ups between the moment the balance was entered and now: one on the given day of each month (the last day of
 * shorter months), from local midnight. A top-up earlier on the day the balance was entered is already in it.
 */
export function topupsBetween(since, day, now) {
  if (!(day >= 1 && day <= 31) || !(since <= now)) return 0;
  let count = 0, y = since.getFullYear(), m = since.getMonth();
  for (let guard = 0; guard < 2400; guard++) {
    const when = new Date(y, m, Math.min(day, new Date(y, m + 1, 0).getDate()));
    if (when > now) break;
    if (when > since) count++;
    if (++m > 11) { m = 0; y++; }
  }
  return count;
}

/**
 * The API balance as this app follows it: the amount entered, minus what each request since then cost (priced from
 * the tokens the API reported, planning included), plus the monthly top-ups. Requests of models without known prices
 * are counted apart.
 * spent: one entry per model, in the shape of the API's usage, with the number of requests; planning: true marks the
 * planning calls, which are not requests of their own.
 * billing.scale is the factor learned from the last correction (learnedScale): what was really spent for each dollar
 * the app counted.
 */
export function balanceNow({ billing, spent }, now = new Date()) {
  if (!billing) return null;
  const scale = billing.scale > 0 ? billing.scale : 1;
  let cost = 0, planning = 0, requests = 0, unpriced = 0;
  // kinds: the calls and what they cost by kind (image, writing, warm, planning), for the entries that say theirs.
  const kinds = {};
  for (const entry of spent || []) {
    // Claude's and Google's spending comes priced by the server (Vault::balance); OpenAI's in tokens, priced here.
    const price = Number.isFinite(entry.cost) ? entry.cost : usageCost(entry.model, entry);
    if (entry.kind) { const k = kinds[entry.kind] ??= { calls: 0, cost: 0 }; k.calls += entry.requests || 0; if (price !== null) k.cost += price * scale; }
    if (entry.planning) { if (price !== null) { cost += price; planning += price; } continue; }
    requests += entry.requests || 0;
    if (price === null) unpriced += entry.requests || 0; else cost += price;
  }
  const topups = billing.topup > 0 ? topupsBetween(new Date(billing.since * 1000), billing.day, now) : 0;
  return { balance: billing.balance - cost * scale + topups * billing.topup, spent: cost * scale, counted: cost, planning: planning * scale, scale, topups, requests, unpriced, kinds };
}

// A correction teaches the factor only with enough spending to go by, and only when it is a plausible one: a top-up
// made by hand or a large spending outside the app is not a miscount.
const SCALE_FROM = 2, SCALE_MIN = 0.8, SCALE_MAX = 1.5;
/**
 * The balance is being corrected to the real amount. What really left the account since the last amount was entered,
 * for each dollar the app counted, becomes the factor on the spending that follows; without enough to go by, or with
 * an implausible difference, the factor stays as it was.
 */
export function learnedScale(info, real, now = new Date()) {
  const current = balanceNow(info, now);
  if (!current || !Number.isFinite(real)) return 1;
  if (!(current.counted >= SCALE_FROM)) return current.scale;
  const scale = (current.spent + current.balance - real) / current.counted;
  return scale >= SCALE_MIN && scale <= SCALE_MAX ? Math.round(scale * 1000) / 1000 : current.scale;
}

/** Dollars with cents, for the balance. */
export function formatBalance(value) {
  return 'US$ ' + value.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
