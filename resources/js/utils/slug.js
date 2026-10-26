/**
 * A subdomain suggestion from a company name, Arabic or English:
 * «مؤسسة الأستاذ للتجارة» → "alostaz", "Anas AlGhamdi" → "anas-alghamdi".
 * Generic words (مؤسسة، شركة، للتجارة...) are dropped; Arabic letters are
 * written in Latin the way Saudi brands usually are.
 */
const GENERIC = new Set([
    'مؤسسة', 'موسسة', 'شركة', 'شركه', 'مجموعة', 'مجموعه', 'مكتب', 'مصنع', 'متجر', 'معرض', 'مركز',
    'للتجارة', 'التجارية', 'تجارية', 'للمقاولات', 'المحدودة', 'محدودة', 'ذمم', 'القابضة', 'للخدمات', 'والتجارة', 'وشركاه', 'وشريكه',
    'company', 'co', 'est', 'establishment', 'group', 'llc', 'ltd', 'trading', 'the',
]);

const LETTERS = {
    'ا': 'a', 'أ': 'a', 'إ': 'e', 'آ': 'a', 'ء': '', 'ؤ': 'o', 'ئ': 'e', 'ب': 'b', 'ت': 't', 'ث': 'th', 'ج': 'j', 'ح': 'h', 'خ': 'kh',
    'د': 'd', 'ذ': 'th', 'ر': 'r', 'ز': 'z', 'س': 's', 'ش': 'sh', 'ص': 's', 'ض': 'd', 'ط': 't', 'ظ': 'z', 'ع': 'a', 'غ': 'gh',
    'ف': 'f', 'ق': 'q', 'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n', 'ه': 'h', 'ة': 'a', 'و': 'o', 'ي': 'i', 'ى': 'a',
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
};

// Common name endings read better with a long vowel: "الأستاذ" → "alostaz", "النخيل" → "alnakheel".
const WORDS = { 'الأستاذ': 'alostaz', 'الاستاذ': 'alostaz', 'النخيل': 'alnakheel', 'الغامدي': 'alghamdi', 'العتيبي': 'alotaibi', 'القحطاني': 'alqahtani', 'الشمري': 'alshammari', 'الدوسري': 'aldosari', 'الحربي': 'alharbi', 'المطيري': 'almutairi', 'الزهراني': 'alzahrani', 'السبيعي': 'alsubaie', 'الرياض': 'riyadh', 'جدة': 'jeddah', 'مكة': 'makkah', 'الديوان': 'aldiwan', 'محمد': 'mohammed', 'أحمد': 'ahmed', 'احمد': 'ahmed',
    'عبدالله': 'abdullah', 'عبدالرحمن': 'abdulrahman', 'عبدالعزيز': 'abdulaziz', 'انس': 'anas', 'أنس': 'anas', 'خالد': 'khalid', 'فهد': 'fahad', 'سعد': 'saad',
    'صفوة': 'safwa', 'التكامل': 'altakamul', 'الرواد': 'alrowad', 'الأمل': 'alamal', 'النور': 'alnoor', 'البناء': 'albinaa', 'المستقبل': 'almustaqbal',
    'الإبداع': 'alibdaa', 'الرؤية': 'alruya', 'الوطنية': 'alwataniya', 'الذهبي': 'althahabi', 'المتحدة': 'almuttahida', 'العربية': 'alarabia', 'الخليج': 'alkhaleej',
    'الشرق': 'alsharq', 'الصفا': 'alsafa', 'المروة': 'almarwa', 'الحديثة': 'alhaditha', 'الأولى': 'aloula', 'الدولية': 'aldawlia', 'عبد': 'abd' };

const transliterate = (word) => {
    if (WORDS[word]) return WORDS[word];
    let out = '';
    for (const ch of word) out += LETTERS[ch] ?? ch;
    // Double vowels and stray marks from the letter map.
    return out.replace(/[ً-ْ]/g, '').replace(/aa+/g, 'a').replace(/ii+/g, 'ee').replace(/oo+/g, 'oo');
};

export function suggestSubdomain(name, max = 20) {
    const words = String(name || '')
        .replace(/[ً-ْـ]/g, '') // harakat, tatweel
        .split(/[\s_\-.,،/&+]+/)
        .map((w) => w.trim())
        .filter(Boolean);
    // Generic words, and activity words like «للمحاسبة» / «للتقنية» ("for ..."), say little about the name.
    const meaningful = words.filter((w) => !GENERIC.has(w.toLowerCase()) && !/^لل/.test(w));
    const parts = (meaningful.length ? meaningful : words)
        .map((w) => (/[؀-ۿ]/.test(w) ? transliterate(w) : w.toLowerCase()))
        .map((w) => w.replace(/[^a-z0-9]/g, ''))
        .filter(Boolean);

    let slug = '';
    for (const part of parts) {
        const next = slug ? `${slug}-${part}` : part;
        if (next.length > max) break;
        slug = next;
    }
    if (!slug && parts.length) slug = parts[0].slice(0, max);

    return slug.replace(/^-+|-+$/g, '');
}
