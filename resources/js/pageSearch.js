/**
 * Page search engine of the «الصفحات» tab in the global search (Components/GlobalSearch.vue).
 *
 * Pure functions, no Vue, Inertia or i18n imports: the browser and `npm run test:js` (tests/js) run the
 * same code. The entries come from pageIndex.js (the sections and pages the user may open); no record
 * data (customers, suppliers, documents) ever reaches this index.
 *
 * Matching is light and explainable, with no fuzzy or AI matching:
 *  - text is normalized (diacritics and tatweel removed, أ إ آ → ا, ى → ي, ة → ه, ؤ → و, ئ → ي, and
 *    ؤ / ئ also as ء so «شئون» finds «شؤون», Arabic-Indic digits → 0-9, punctuation → spaces), so
 *    «الاستاذ» finds «الأستاذ»;
 *  - each word is also compared without its article (ال، بال، وال، كال، فال، لل) and without a joined و,
 *    and by a light stem (ات، ون، ين، ة): «الموردون» finds «الموردين», «عملة» finds «العملات»;
 *  - forms of the same word that no rule derives (broken plurals: فاتورة/فواتير، قيد/قيود) come from an
 *    explicit list and count as the same word;
 *  - the last word of the query, or a word of 3 letters or more, may be the start of a longer word
 *    (typing in progress): «مور» finds «الموردين»; shorter words must match whole («P&L» is p + l);
 *  - synonyms (different words, same meaning: زبون/عميل، صندوق/خزينة) come from an explicit list;
 *  - little words (و، في، من، على… and, of, the…) are ignored in the query and in names.
 * Every word of the query must match. Ranking: 1 exact title, 2 title starts with the query, 3 the title
 * contains every word, 4 a keyword (other names, the other language) or a synonym, 5 the parent page,
 * 6 the section, 7 the description. A matched section is a group holding its pages (never a link).
 */

// Character classes from code points (some of these characters are invisible in source).
const span = (from, to) => `${String.fromCharCode(from)}-${String.fromCharCode(to)}`;
const DIACRITICS = new RegExp(`[${span(0x0610, 0x061a)}${span(0x064b, 0x065f)}${String.fromCharCode(0x0670)}${span(0x06d6, 0x06dc)}${span(0x06df, 0x06e8)}${span(0x06ea, 0x06ed)}]`, 'g');
const TATWEEL = new RegExp(String.fromCharCode(0x0640), 'g');
const ARABIC = new RegExp(`[${span(0x0600, 0x06ff)}]`);
const ARABIC_INDIC_DIGITS = new RegExp(`[${span(0x0660, 0x0669)}]`, 'g');
const PERSIAN_DIGITS = new RegExp(`[${span(0x06f0, 0x06f9)}]`, 'g');
const NON_WORD = /[^\p{L}\p{N}]+/gu;

// Longest first: «وال» before «ال».
const ARTICLES = ['وال', 'بال', 'كال', 'فال', 'لل', 'ال'];

/** Lowercase, no diacritics or tatweel, Latin digits, punctuation as spaces; letters keep their forms. */
function clean(text) {
    return String(text ?? '')
        .normalize('NFKC')
        .toLowerCase()
        .replace(DIACRITICS, '')
        .replace(TATWEEL, '')
        .replace(ARABIC_INDIC_DIGITS, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(PERSIAN_DIGITS, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(NON_WORD, ' ')
        .trim();
}

/**
 * Letter forms people type interchangeably: أ إ آ ٱ → ا, ى ی → ي, ة → ه, ک → ك, and ؤ → و, ئ → ي
 * (or, with `hamzaSeat`, both → ء: «شؤون» and «شئون» meet there).
 */
function fold(word, hamzaSeat = false) {
    const w = word.replace(/[آأإٱ]/g, 'ا').replace(/[ىی]/g, 'ي').replace(/ة/g, 'ه').replace(/ک/g, 'ك');
    return hamzaSeat ? w.replace(/[ؤئ]/g, 'ء') : w.replace(/ؤ/g, 'و').replace(/ئ/g, 'ي');
}

// Little words, ignored when the query or a name has other words (kept folded, as words are compared).
const STOPWORDS = new Set(['و', 'في', 'من', 'على', 'عن', 'إلى', 'أو', 'مع', 'ثم', 'ال', 'the', 'of', 'and', 'or', 'to', 'in', 'for', 'a', 'an'].map((w) => fold(w)));

/** The text as compared: «دفتر الأستاذ» and «دفتر الاستاذ» give the same string. */
export function normalize(text) {
    return fold(clean(text));
}

/** Light stem of a folded word without its article: plural/feminine endings off (Latin: a final s). */
export function stemOf(word) {
    if (!ARABIC.test(word)) {
        const stem = word.length > 3 && word.endsWith('s') && !word.endsWith('ss') ? word.slice(0, -1) : word;
        return { stem, inflected: stem !== word };
    }
    let stem = word;
    let inflected = false;
    if (stem.length >= 5 && /(ات|ون|ين)$/.test(stem)) {
        stem = stem.slice(0, -2);
        inflected = true;
    }
    if (stem.length >= 4 && stem.endsWith('ه')) {
        stem = stem.slice(0, -1);
        inflected = true;
    }
    return { stem, inflected };
}

// Same stem: a stem of 3 letters only joins two inflected words (عملة / عملات), so «عمل» and «عملات» are not
// the same word. (A query word may still be the start of a longer word: see the prefix rule in fieldMatch.)
const sameStem = (a, b) => a.stem === b.stem && (a.stem.length >= 4 || (a.inflected && b.inflected));

/**
 * One word, ready to compare: its folded forms (as written, without the article, without a joined و; each
 * also with ؤ/ئ as ء), their stems, and the word-form groups it belongs to. The article is detected before
 * folding, so «إلغاء» (hamza) is not read as «ال» + «غاء».
 */
function analyzeWord(raw, table) {
    const set = new Set();
    const add = (s) => { set.add(fold(s)); set.add(fold(s, true)); };
    add(raw);
    const article = ARTICLES.find((a) => raw.startsWith(a) && raw.length - a.length >= 2);
    if (article) add(raw.slice(article.length));
    if (raw.startsWith('و') && !raw.startsWith('وال') && raw.length >= 4) add(raw.slice(1));
    const forms = [...set];
    const word = { forms, stems: forms.map(stemOf), lemmas: null };
    if (table) {
        const ids = new Set();
        for (const f of forms) for (const id of table.byForm.get(f) || []) ids.add(id);
        for (const s of word.stems) for (const x of table.byStem.get(s.stem) || []) if (sameStem(s, x)) ids.add(x.id);
        if (ids.size) word.lemmas = ids;
    }
    return word;
}

/** Words of a text, analyzed (with the word-form table of an index, when given). */
export function tokenize(text, table = null) {
    const cleaned = clean(text);
    return cleaned ? cleaned.split(' ').map((raw) => analyzeWord(raw, table)) : [];
}

const isStopword = (word) => STOPWORDS.has(word.forms[0]);

/** The words that carry meaning (all of them when every word is a little one). */
const meaningful = (words) => {
    const kept = words.filter((w) => !isStopword(w));
    return kept.length ? kept : words;
};

/** Word-form groups (forms of the same word no rule derives, e.g. broken plurals), looked up by form and stem. */
function wordFormTable(groups) {
    const table = { byForm: new Map(), byStem: new Map() };
    const add = (map, key, value) => map.set(key, [...(map.get(key) || []), value]);
    (groups || []).forEach((words, id) => {
        for (const w of words) {
            const [word] = tokenize(w);
            if (!word) continue;
            word.forms.forEach((f) => add(table.byForm, f, id));
            word.stems.forEach((s) => add(table.byStem, s.stem, { id, stem: s.stem, inflected: s.inflected }));
        }
    });
    return table;
}

/** 3 = same word, 2 = same stem or another form of it, 1 = q starts t (when allowed), 0 = no match. */
function wordMatch(q, t, allowPrefix) {
    let best = 0;
    for (const a of q.forms) {
        for (const b of t.forms) {
            if (a === b) return 3;
            if (allowPrefix && b.startsWith(a)) best = 1;
        }
    }
    for (const a of q.stems) for (const b of t.stems) if (sameStem(a, b)) return 2;
    if (q.lemmas && t.lemmas) for (const id of q.lemmas) if (t.lemmas.has(id)) return 2;
    return best;
}

/** A query word may match the start of a longer word when it is the last one (being typed) or has 3+ letters. */
const prefixAllowed = (Q, i) => i === Q.length - 1 || Q[i].forms[0].length >= 3;

/**
 * Every query word matches a word of the field (directly, or through its synonyms when `expanded` is given).
 * Returns null, or how well it matched: the weakest word match, the first matched position, synonyms used.
 */
function fieldMatch(Q, T, expanded = null) {
    if (!T?.length) return null;
    let weakest = 3;
    let first = 99;
    let viaSynonym = false;
    for (let i = 0; i < Q.length; i++) {
        let best = 0;
        let pos = -1;
        const prefix = prefixAllowed(Q, i);
        for (let j = 0; j < T.length; j++) {
            const m = wordMatch(Q[i], T[j], prefix);
            if (m > best) {
                best = m;
                pos = j;
                if (m === 3) break;
            }
        }
        if (!best && expanded?.[i]?.length) {
            outer: for (const w of expanded[i]) {
                for (let j = 0; j < T.length; j++) {
                    if (wordMatch(w, T[j], false) >= 2) {
                        best = 2;
                        pos = j;
                        viaSynonym = true;
                        break outer;
                    }
                }
            }
        }
        if (!best) return null;
        weakest = Math.min(weakest, best);
        first = Math.min(first, pos);
    }
    return { weakest, first, viaSynonym, sub: (3 - weakest) * 10 + Math.min(first, 9) };
}

/** Title-like field: 1 the same words, 2 starts with the query, 3 contains every word. */
function phraseTier(Q, T, literal) {
    if (!T?.length) return null;
    if (Q.length === T.length && Q.every((q, i) => wordMatch(q, T[i], false) >= 2)) {
        return { tier: 1, sub: literal ? 0 : 1 };
    }
    const last = Q.length - 1;
    if (Q.length <= T.length && Q.every((q, i) => wordMatch(q, T[i], i === last) >= (i === last ? 1 : 2))) {
        return { tier: 2, sub: Math.min(T.length - Q.length, 9) * 2 + (wordMatch(Q[last], T[last], false) >= 2 ? 0 : 1) };
    }
    const m = fieldMatch(Q, T);
    return m ? { tier: 3, sub: m.sub } : null;
}

/** For each query word: the words of every synonym group it belongs to (by form, stem, or the start of a word). */
function expandQuery(Q, synonyms) {
    return Q.map((q, i) => {
        const out = [];
        const prefix = q.forms[0].length >= 3 || (i === Q.length - 1 && q.forms[0].length >= 2);
        for (const group of synonyms) {
            if (group.some((w) => wordMatch(q, w, prefix) > 0)) out.push(...group);
        }
        return out;
    });
}

function prepareEntry(entry, order, table) {
    const title = meaningful(tokenize(entry.title, table));
    const keywords = (entry.keywords || []).map((k) => meaningful(tokenize(k, table))).filter((k) => k.length);
    const parent = tokenize(entry.parentTitle, table);
    const section = tokenize(entry.category, table);
    return {
        entry,
        order: entry.order ?? order,
        titleNorm: normalize(entry.title),
        title,
        keywords,
        parent,
        section,
        description: tokenize(entry.description, table),
        // Every field together: a query that names the section and the page («المحاسبة القيود»).
        all: [...title, ...keywords.flat(), ...parent, ...section],
    };
}

/** Tier (1 best … 7) and score of an entry for the query, or null. Sections use tiers 1–4 only. */
function rankEntry(p, q, isSection) {
    const Q = q.words;
    const byTitle = phraseTier(Q, p.title, p.titleNorm === q.norm);
    if (byTitle) return { tier: byTitle.tier, score: byTitle.tier * 100 + byTitle.sub };

    let best = null;
    for (const K of p.keywords) {
        const k = phraseTier(Q, K, false);
        if (k) {
            const score = 400 + (k.tier - 1) * 10 + Math.min(k.sub, 9);
            if (best === null || score < best) best = score;
        }
    }
    if (best !== null) return { tier: 4, score: best };

    const synTitle = fieldMatch(Q, p.title, q.expanded);
    if (synTitle?.viaSynonym) return { tier: 4, score: 430 + Math.min(synTitle.first, 9) };
    for (const K of p.keywords) {
        const synK = fieldMatch(Q, K, q.expanded);
        if (synK?.viaSynonym) return { tier: 4, score: 440 + Math.min(synK.first, 9) };
    }
    if (isSection) return null;

    const byParent = fieldMatch(Q, p.parent, q.expanded);
    if (byParent) return { tier: 5, score: 500 + byParent.sub };
    const bySection = fieldMatch(Q, p.section, q.expanded);
    if (bySection) return { tier: 6, score: 600 + bySection.sub };
    const combined = fieldMatch(Q, p.all, q.expanded);
    if (combined) return { tier: 6, score: 650 + combined.sub };
    // Descriptions are sentences: their own words only, no synonyms («قيد التنفيذ» is not about journal entries).
    const byDescription = fieldMatch(Q, p.description);
    if (byDescription) return { tier: 7, score: 700 + byDescription.sub };
    return null;
}

function prepareQuery(query, table, synonyms) {
    const norm = normalize(query);
    const all = tokenize(query, table);
    const words = all.filter((w) => !isStopword(w));
    const letters = words.reduce((n, w) => n + w.forms[0].length, 0);
    const tooShort = !words.length || letters < 2;
    return { norm, words, tooShort, expanded: tooShort ? [] : expandQuery(words, synonyms) };
}

const byScore = (a, b) => a.score - b.score || (a.kind === b.kind ? 0 : a.kind === 'section' ? -1 : 1) || a.order - b.order;

/**
 * Builds the searchable index once (when the user's pages change, not per keystroke).
 * entries: { id, type: 'section' | 'page', title, keywords[], category (section title), sectionId,
 * parentTitle (parent page of a page outside the sidebar), description, level, order, children[] (sections) }.
 * forms: groups of forms of one word (فاتورة، فواتير); synonyms: groups of words with one meaning (زبون، عميل).
 */
export function createPageSearch(entries, { forms = [], synonyms = [] } = {}) {
    const table = wordFormTable(forms);
    const prepared = entries.map((entry, i) => prepareEntry(entry, i, table));
    const byId = new Map(prepared.map((p) => [p.entry.id, p]));
    const sections = prepared.filter((p) => p.entry.type === 'section');
    const pages = prepared.filter((p) => p.entry.type !== 'section');
    const synonymWords = (synonyms || []).map((group) => group.map((w) => tokenize(w, table)).filter((t) => t.length === 1).map((t) => t[0]));

    /**
     * Results for a query: `items` in rank order, each a section group ({ kind: 'section', entry, children:
     * [{ entry, matched, depth }], matched, expanded }) or a page ({ kind: 'page', entry }); pages beyond
     * `limit` are counted in `more`. A page of a matched section shows inside its group, never twice.
     */
    function search(query, { limit = 12 } = {}) {
        const q = prepareQuery(query, table, synonymWords);
        if (q.tooShort) return { items: [], total: 0, more: 0, tooShort: true };

        const sectionHits = [];
        for (const s of sections) {
            const r = rankEntry(s, q, true);
            if (r) sectionHits.push({ kind: 'section', p: s, ...r, order: s.order });
        }
        const matchedSections = new Set(sectionHits.map((h) => h.p.entry.id));

        const pageHits = new Map();
        for (const p of pages) {
            const r = rankEntry(p, q, false);
            if (r) pageHits.set(p.entry.id, { kind: 'page', p, ...r, order: p.order });
        }

        sectionHits.sort(byScore);
        const groupItems = sectionHits.map((h, index) => {
            const children = (h.p.entry.children || []).map((id) => byId.get(id)).filter(Boolean).map((c) => {
                const hit = pageHits.get(c.entry.id);
                return { entry: c.entry, depth: c.entry.level > 2 ? 2 : 1, matched: !!hit && hit.tier !== 6 };
            });
            const matched = children.filter((c) => c.matched).length;
            const qualifies = h.tier <= 3 || matched > 0;
            return {
                kind: 'section', entry: h.p.entry, tier: h.tier, score: h.score, order: h.order,
                children, matched, expanded: qualifies && (index === 0 || sectionHits.length <= 3),
            };
        });

        const flat = [...pageHits.values()]
            .filter((h) => !(h.p.entry.sectionId && matchedSections.has(h.p.entry.sectionId)))
            .sort(byScore);
        const shown = flat.slice(0, Math.max(0, limit));
        const items = [
            ...groupItems,
            ...shown.map((h) => ({ kind: 'page', entry: h.p.entry, tier: h.tier, score: h.score, order: h.order })),
        ].sort(byScore);

        return { items, total: flat.length, more: flat.length - shown.length, tooShort: false };
    }

    return { search, size: prepared.length };
}
