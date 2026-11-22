import './i18n-setup.mjs';
import assert from 'node:assert/strict';
import { estimate, outputTokens, inputImageTokens, usageCost, formatUsd, hasPricing, topupsBetween, balanceNow, formatBalance, PLANNING_MODEL, planningCost, planningAverage, learnedScale } from '../assets/pricing.js';

// Anchors measured on real requests.
assert.equal(outputTokens('gpt-image-2.6-flare', 'high', 1024, 1024), 1756);
assert.equal(outputTokens('gpt-image-2.6-sunburst', 'max', 1024, 1024), 7024);
assert.equal(outputTokens('gpt-image-2.6-flare', 'low', 1536, 1024), 664);
assert.equal(outputTokens('gpt-image-2', 'high', 1024, 1536), 5488);
assert.equal(outputTokens('gpt-image-2.6-flare', 'auto', 1024, 1024), 1756, 'automatic quality is counted as high');
assert.equal(outputTokens('gpt-image-2', 'max', 1024, 1024), outputTokens('gpt-image-2', 'high', 1024, 1024), 'tiers a model lacks fall back to high');
// Other sizes stay close to measured requests: 4:5 at 1088 × 1360 used about 1,600 tokens; 4K at high costs about US$ 0.10.
const portrait = outputTokens('gpt-image-2.6-sunburst', 'high', 1088, 1360);
assert.ok(portrait > 1450 && portrait < 1800, `4:5 high ${portrait}`);
const uhd = estimate({ model: 'gpt-image-2.6-flare', quality: 'high', size: '3840x2160' }).total;
assert.ok(uhd > 0.085 && uhd < 0.115, `4K high ${uhd}`);
// Larger images never cost less at the same shape and quality.
assert.ok(outputTokens('gpt-image-2.6-flare', 'high', 2048, 2048) > outputTokens('gpt-image-2.6-flare', 'high', 1024, 1024));

const single = estimate({ model: 'gpt-image-2.6-flare', quality: 'high', size: '1024x1024' });
assert.equal(single.total.toFixed(5), (1756 * 30 / 1e6).toFixed(5));
const three = estimate({ model: 'gpt-image-2.6-flare', quality: 'high', size: '1024x1024', n: 3, previews: 2, inputs: [[1024, 1024]] });
assert.equal(three.total.toFixed(5), (3 * ((1756 + 200) * 30 + 1049 * 8) / 1e6).toFixed(5), 'each image is its own request, with its previews and inputs');
const previews = estimate({ model: 'gpt-image-2.6-flare', quality: 'high', size: '1024x1024', previews: 2 });
assert.equal(previews.total.toFixed(5), ((1756 + 200) * 30 / 1e6).toFixed(5));
const edit = estimate({ model: 'gpt-image-2.6-flare', quality: 'high', size: '1024x1024', inputs: [[1024, 1024]], promptLength: 400 });
assert.equal(edit.total.toFixed(6), ((1756 * 30 + inputImageTokens(1024, 1024) * 8 + 100 * 5) / 1e6).toFixed(6), 'the edited image and the prompt count as input');
assert.equal(inputImageTokens(1024, 1024), 1049);
assert.equal(inputImageTokens(3840, 2160), inputImageTokens(1536, 1536), 'large inputs are capped');
assert.equal(estimate({ model: 'unknown', quality: 'high', size: '1024x1024' }), null);
assert.equal(hasPricing('gpt-image-1-mini'), true);

assert.equal(usageCost('gpt-image-2.6-flare', { output_tokens: 1756, input_tokens: 44, input_tokens_details: { text_tokens: 44, image_tokens: 0 } }).toFixed(5), ((1756 * 30 + 44 * 5) / 1e6).toFixed(5));
assert.equal(usageCost('gpt-image-2', { output_tokens: 1000, input_tokens: 1100, input_tokens_details: { image_tokens: 1000 } }).toFixed(5), ((1000 * 15 + 1000 * 4 + 100 * 2.5) / 1e6).toFixed(5), 'text input is what remains of the input tokens');
assert.equal(usageCost('gpt-image-2.6-flare', { total_tokens: 10 }), null, 'old records without a breakdown have no cost');
assert.equal(formatUsd(0.05268), 'US$ 0,053');
assert.equal(formatUsd(1.234), 'US$ 1,23');
// Planning is a call to the text model, paid apart from the image: reasoning is output, cached input costs a tenth.
const plan = { input_tokens: 1300, output_tokens: 900, total_tokens: 2200, input_tokens_details: { cached_tokens: 300 }, output_tokens_details: { reasoning_tokens: 700 } };
assert.equal(usageCost(PLANNING_MODEL, plan).toFixed(6), ((1000 * 2 + 300 * 0.2 + 900 * 10) / 1e6).toFixed(6));
assert.equal(usageCost(PLANNING_MODEL, { input_tokens: 500, output_tokens: 100 }).toFixed(6), ((500 * 2 + 100 * 10) / 1e6).toFixed(6));
assert.equal(usageCost(PLANNING_MODEL, { total_tokens: 40 }), null, 'a planning call without a count of what went in and out has no cost');
assert.equal(usageCost('gpt-6.2-astra', { input_tokens: 1000, output_tokens: 100, input_tokens_details: { cached_tokens: 500 } }).toFixed(6), ((500 * 8 + 500 * 0.4 + 100 * 40) / 1e6).toFixed(6), 'GPT writing the stories is priced at its own rates');
assert.equal(planningCost({ planning_usage: plan, variation_usage: { input_tokens: 500, output_tokens: 100 } }).toFixed(6), (usageCost(PLANNING_MODEL, plan) + 0.002).toFixed(6), 'the planning of the prompt and of the batch');
assert.equal(planningCost({ planning_usage: null, variation_usage: null }), null, 'no planning, no cost');
assert.equal(planningCost({ planning_usage: { total_tokens: 40 } }), null);
const stats = { medium: { model: PLANNING_MODEL, calls: 4, input_tokens: 4000, cached_tokens: 0, output_tokens: 3600 }, high: { model: PLANNING_MODEL, calls: 2, input_tokens: 2000, cached_tokens: 0, output_tokens: 9000 } };
assert.equal(planningAverage(stats, 'medium').toFixed(6), ((1000 * 2 + 900 * 10) / 1e6).toFixed(6), 'the average of the latest planning calls at that effort');
assert.equal(planningAverage(stats, 'high'), null, 'too few calls to go by');
assert.equal(planningAverage(stats, 'low'), null); assert.equal(planningAverage(undefined, 'medium'), null);
console.log('PASS cost estimates: measured anchors, sizes, quantity, previews, inputs, real usage, planning');

// API balance: top-ups on the chosen day of each month after the balance was entered; short months use their last day.
assert.equal(topupsBetween(new Date(2026, 10, 13, 15), 24, new Date(2026, 10, 23, 23)), 0);
assert.equal(topupsBetween(new Date(2026, 10, 13, 15), 24, new Date(2026, 10, 24, 0, 1)), 1);
assert.equal(topupsBetween(new Date(2026, 10, 24, 10), 24, new Date(2026, 11, 23)), 0, 'a top-up earlier that day is already in the amount entered');
assert.equal(topupsBetween(new Date(2026, 0, 15), 31, new Date(2026, 1, 28, 12)), 2, 'January 31 and February 28');
assert.equal(topupsBetween(new Date(2026, 10, 13), 24, new Date(2027, 10, 25)), 13);
assert.equal(topupsBetween(new Date(2026, 10, 13), NaN, new Date(2027, 1, 1)), 0);
assert.equal(topupsBetween(new Date(2027, 1, 1), 24, new Date(2026, 10, 13)), 0, 'a clock behind the entry adds nothing');
const since = new Date(2026, 10, 13, 15).getTime() / 1000;
const spent = [{ model: 'gpt-image-2', requests: 2, input_tokens: 1100, output_tokens: 1000, input_tokens_details: { text_tokens: 100, image_tokens: 1000 } },
  { model: 'unknown-model', requests: 1, input_tokens: 0, output_tokens: 5, input_tokens_details: { text_tokens: 0, image_tokens: 0 } }];
const later = balanceNow({ billing: { balance: 50, since, topup: 100, day: 24 }, spent }, new Date(2026, 11, 1));
assert.equal(later.spent.toFixed(5), ((1000 * 15 + 1000 * 4 + 100 * 2.5) / 1e6).toFixed(5));
assert.equal(later.topups, 1);
assert.equal(later.balance.toFixed(5), (150 - later.spent).toFixed(5));
assert.equal(later.requests, 3); assert.equal(later.unpriced, 1, 'requests of models without prices are counted apart');
assert.equal(balanceNow({ billing: { balance: 50, since, topup: 0, day: 24 }, spent: [] }, new Date(2027, 5, 1)).balance, 50, 'no top-up when it is zero');
assert.equal(balanceNow({ billing: null, spent: [] }), null);
// Planning calls are spending, not requests of their own.
const planned = balanceNow({ billing: { balance: 50, since, topup: 0, day: 24 }, spent: [spent[0], { model: PLANNING_MODEL, planning: true, requests: 5, input_tokens: 5000, output_tokens: 20000, input_tokens_details: { text_tokens: 5000, image_tokens: 0, cached_tokens: 1000 } }] }, new Date(2026, 10, 14));
assert.equal(planned.requests, 2);
assert.equal(planned.planning.toFixed(6), ((4000 * 2 + 1000 * 0.2 + 20000 * 10) / 1e6).toFixed(6));
assert.equal(planned.spent.toFixed(6), (later.spent + planned.planning).toFixed(6));
// A correction: the app showed US$ 18.00 after counting US$ 12.00, the real balance is US$ 16.73, so each dollar counted
// was really about US$ 1.106. The spending that follows is counted with that factor.
const counted = { billing: { balance: 30, since, topup: 0, day: 24 }, spent: [{ model: 'gpt-image-2.6-flare', requests: 200, input_tokens: 0, output_tokens: 400000, input_tokens_details: { text_tokens: 0, image_tokens: 0 } }] };
assert.equal(balanceNow(counted, new Date(2026, 10, 14)).balance.toFixed(2), '18.00');
assert.equal(learnedScale(counted, 16.73, new Date(2026, 10, 14)), 1.106);
const scaled = balanceNow({ ...counted, billing: { ...counted.billing, scale: 1.106 } }, new Date(2026, 10, 14));
assert.equal(scaled.balance.toFixed(2), '16.73'); assert.equal(scaled.counted.toFixed(2), '12.00'); assert.equal(scaled.spent.toFixed(3), '13.272'); assert.equal(scaled.scale, 1.106);
assert.equal(learnedScale({ ...counted, billing: { ...counted.billing, scale: 1.106 } }, 16.13, new Date(2026, 10, 14)), 1.156, 'a later correction starts from the factor in use');
assert.equal(learnedScale(counted, 68, new Date(2026, 10, 14)), 1, 'a top-up made by hand is not a miscount: the factor stays');
assert.equal(learnedScale({ ...counted, billing: { ...counted.billing, scale: 1.1 } }, 5, new Date(2026, 10, 14)), 1.1, 'nor is a large spending outside the app');
assert.equal(learnedScale({ billing: counted.billing, spent: [{ ...counted.spent[0], output_tokens: 30000 }] }, 28, new Date(2026, 10, 14)), 1, 'too little spending to go by');
assert.equal(learnedScale({ billing: null, spent: [] }, 20), 1);
// Claude's and Google's spending comes priced by the server: the cost is taken as it is, by kind of call.
const claude = balanceNow({ billing: { balance: 20, since, topup: 0, day: 24, scale: 1.1 }, spent: [
  { model: 'claude-opus-5-5', kind: 'writing', planning: false, requests: 4, cost: 2 },
  { model: 'claude-opus-5-5', kind: 'warm', planning: true, requests: 3, cost: 0.5 }] }, new Date(2026, 10, 14));
assert.equal(claude.balance.toFixed(2), (20 - 2.5 * 1.1).toFixed(2)); assert.equal(claude.requests, 4, 'the steps of the writer are its requests');
assert.deepEqual(claude.kinds, { writing: { calls: 4, cost: 2.2 }, warm: { calls: 3, cost: 0.55 } }, 'and what each kind of call cost is told apart, with the factor');
assert.equal(balanceNow({ billing: { balance: 5, since, topup: 0, day: 24 }, spent: [{ model: 'gemini-nano-banana-2.1', kind: 'image', planning: false, requests: 1, cost: 0.134 }] }).balance.toFixed(3), '4.866');
assert.equal(formatBalance(1234.5), 'US$ 1.234,50');
assert.equal(formatBalance(-3.2), 'US$ -3,20');
console.log('PASS API balance: top-ups by month, spending priced by model, planning counted, the factor learned from a correction');

// A newer model the keys list (the server's catalog) is priced like the model it follows.
const { setAliases, isGemini } = await import('../assets/pricing.js');
assert.equal(hasPricing('gpt-image-2.7-flare'), false);
setAliases({ 'gpt-image-2.7-flare': { like: 'gpt-image-2.6-flare' }, 'gemini-nano-banana-2.2': { like: 'gemini-nano-banana-2.1' }, 'gpt-image-2': {} });
const spec = { quality: 'high', size: '1024x1024', n: 2 };
assert.deepEqual(estimate({ model: 'gpt-image-2.7-flare', ...spec }), estimate({ model: 'gpt-image-2.6-flare', ...spec }));
assert.equal(usageCost('gpt-image-2.7-flare', { input_tokens: 100, output_tokens: 1000 }), usageCost('gpt-image-2.6-flare', { input_tokens: 100, output_tokens: 1000 }));
assert.ok(isGemini('gemini-nano-banana-2.2') && hasPricing('gemini-nano-banana-2.2'));
setAliases({});
assert.equal(hasPricing('gpt-image-2.7-flare'), false);
console.log('PASS newer models priced like the model they follow');
