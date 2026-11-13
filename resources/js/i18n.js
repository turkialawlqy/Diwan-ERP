import { createI18n } from 'vue-i18n';
import ar from './locales/ar.json';
import en from './locales/en.json';
import dictEn from './locales/dict.en.json';

const messages = {
  ar,
  en,
};

const i18n = createI18n({
  legacy: false,
  locale: 'ar',
  fallbackLocale: 'en',
  messages,
});

export default i18n;

/**
 * Translates a fixed Arabic label of a JS constant (quick menus, quick-create forms) through the Arabic → English
 * dictionary (locales/dict.en.json). Arabic stays as written; an Arabic text with no entry also stays as written.
 * Read it inside render functions or computeds so a language switch re-renders it.
 */
export const tx = (text) => (i18n.global.locale.value === 'en' && text && dictEn[text]) || text;
