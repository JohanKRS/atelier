// The tests read the interface in Portuguese, the language the app was first written in (assets/i18n/pt-BR.json).
// Imported first by the tests of the page's modules, which read these before anything else (assets/i18n.js).
import { readFileSync } from 'node:fs';

globalThis.__atelierLanguage = 'pt-BR';
globalThis.__atelierStrings = JSON.parse(readFileSync(new URL('../assets/i18n/pt-BR.json', import.meta.url), 'utf8'));
