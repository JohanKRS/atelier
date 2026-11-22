// Runs Limpar (clean.js) off the main thread, so the page stays responsive while an image is cleaned.
// In: { pixels: ArrayBuffer (RGBA), width, height, level }. Out: { progress } as it advances, then { pixels } or { error }.
const version = new URL(import.meta.url).search;
self.onmessage = async event => {
  try {
    const { clean, LEVELS } = await import(`./clean.js${version}`);
    const { pixels, width, height, level } = event.data;
    if (!LEVELS[level]) throw new Error('Unknown cleaning level.');
    let last = 0;
    const out = clean(new Uint8ClampedArray(pixels), width, height, LEVELS[level], done => {
      if (done - last >= 0.02 || done === 1) { last = done; self.postMessage({ progress: done }); }
    });
    self.postMessage({ pixels: out.buffer }, [out.buffer]);
  } catch (error) {
    self.postMessage({ error: String(error?.message || error) });
  }
};
