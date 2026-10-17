/**
 * Shared rules of the free-form designer. They mirror the PDF renderer
 * (app/Services/Documents/FreeformRenderer.php) so the canvas shows what
 * prints: positions and sizes in millimetres, border-box sizing, the same
 * fonts, {{field}} placeholders, and rows-per-page for the items table.
 */
export const MM = 96 / 25.4; // CSS px per mm at 100% zoom

export const defaultStyle = () => ({
    font: null, size: 10, bold: false, italic: false, underline: false, color: '#000000', bg: null,
    align: 'right', valign: 'middle', dir: 'rtl', line_height: 1.3, padding: 1,
    border_width: 0, border_color: '#000000', border_style: 'solid',
    border_sides: { top: true, right: true, bottom: true, left: true }, radius: 0,
});

let seq = 0;
export const newId = () => `e${Date.now().toString(36)}${(seq++).toString(36)}`;

export function newElement(type, at, extra = {}) {
    const base = { id: newId(), type, name: '', x: at.x, y: at.y, w: 50, h: 8, pages: 'all', locked: false, style: defaultStyle() };
    const presets = {
        text: { name: 'نص', content: 'نص جديد', w: 50, h: 8 },
        box: { name: 'مستطيل', w: 60, h: 25, style: { ...defaultStyle(), border_width: 0.3 } },
        line: { name: 'خط', orientation: 'h', w: 80, h: 2, style: { ...defaultStyle(), border_width: 0.3 } },
        vline: { type: 'line', name: 'خط رأسي', orientation: 'v', w: 2, h: 40, style: { ...defaultStyle(), border_width: 0.3 } },
        image: { name: 'صورة', src: 'logo', fit: 'contain', w: 50, h: 25 },
        qr: { name: 'رمز QR', w: 30, h: 30 },
        table: {
            name: 'جدول الأصناف', w: 190, h: 110,
            style: { ...defaultStyle(), size: 8.5, border_width: 0.3 },
            columns: [
                { key: 'index', label: 'م', label_en: 'S#', width: 6, align: 'center' },
                { key: 'name_description', label: 'الوصف', label_en: 'Description', width: 40, align: 'right' },
                { key: 'quantity', label: 'الكمية', label_en: 'Qty.', width: 10, align: 'center' },
                { key: 'unit_price', label: 'السعر', label_en: 'U. Price', width: 14, align: 'center' },
                { key: 'tax_amount', label: 'الضريبة', label_en: 'VAT', width: 12, align: 'center' },
                { key: 'total', label: 'الإجمالي', label_en: 'Amount', width: 16, align: 'center' },
            ],
            header_height: 10, row_height: 8, header_bg: '#f1f5f9', header_color: '#000000', header_size: 8,
            grid_color: '#000000', grid_width: 0.2, row_lines: false, col_lines: true, stripe: null,
        },
    };
    const p = presets[type] || {};
    return { ...base, ...p, type: p.type || type, style: p.style || base.style, ...extra };
}

/** {{group.key}} → value (or the field label in "fields" view). */
export function resolveText(content, fields, labels = null) {
    return String(content ?? '').replace(/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/g, (m, key) => (labels ? `‹${labels[key] || key}›` : (fields?.[key] ?? '')));
}

export function rowsPerPage(table) {
    return Math.max(1, Math.floor((table.h - table.header_height + 0.01) / table.row_height));
}

export function onPage(rule, index, total) {
    return { first: index === 0, last: index === total - 1, not_first: index > 0, not_last: index < total - 1 }[rule] ?? true;
}

export function borderCss(s) {
    const side = (k) => (s.border_width > 0 && s.border_sides?.[k] ? `${s.border_width}mm ${s.border_style} ${s.border_color}` : 'none');
    return { borderTop: side('top'), borderRight: side('right'), borderBottom: side('bottom'), borderLeft: side('left') };
}

export function fontCss(s, pageFont) {
    return {
        fontFamily: `ff-${s.font || pageFont}, sans-serif`, fontSize: `${s.size}pt`, color: s.color,
        fontWeight: s.bold ? 'bold' : 'normal', fontStyle: s.italic ? 'italic' : 'normal', textDecoration: s.underline ? 'underline' : 'none',
        lineHeight: s.line_height, direction: s.dir,
    };
}

export const PAGE_RULES = { all: 'كل الصفحات', first: 'الأولى فقط', last: 'الأخيرة فقط', not_first: 'كل الصفحات عدا الأولى', not_last: 'كل الصفحات عدا الأخيرة' };
