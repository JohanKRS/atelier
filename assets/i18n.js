// The language of the interface (see app/I18n.php). The code says every sentence in English; assets/i18n/<language>.json
// maps each English sentence to its translation, the same file the server reads. A sentence that has no translation is
// shown in English. The page names its file in <html data-strings>; tests, which have no page, set
// globalThis.__atelierLanguage and globalThis.__atelierStrings before loading the modules.
const root = globalThis.document?.documentElement;

/** The language of the interface, as <html lang> has it ('en', 'pt-BR'). */
export const language = root?.lang || globalThis.__atelierLanguage || 'en';
/** The locale for numbers and dates. */
export const locale = language === 'en' ? 'en-US' : language;

let strings = globalThis.__atelierStrings || {};
if (root?.dataset.strings) {
  // Without its file the page still works, in English.
  try { const response = await fetch(root.dataset.strings, { credentials: 'same-origin' }); if (response.ok) strings = await response.json(); }
  catch { strings = {}; }
}

/**
 * The sentence in the interface's language. {name} marks are filled from `vars`; `vars.ctx` is the context of a short
 * word that needs two translations (t('Medium', { ctx: 'effort' }) looks up "effort|Medium"), never a mark.
 */
export function t(text, vars) {
  const context = vars?.ctx;
  const found = language === 'en' ? undefined : strings[context ? `${context}|${text}` : text];
  const out = typeof found === 'string' ? found : text;
  if (!vars) return out;
  return out.replace(/\{(\w+)\}/g, (mark, name) => name !== 'ctx' && Object.hasOwn(vars, name) ? String(vars[name]) : mark);
}

/** A number as the language writes it (1,234.5 or 1.234,5). */
export const number = (value, options) => Number(value).toLocaleString(locale, options);
