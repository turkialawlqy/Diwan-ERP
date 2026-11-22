<?php

namespace Tests\Feature;

use App\Support\ServerMessages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Server messages are Arabic in the code and translated once at the boundary (lang/en.json + lang/patterns.en.php).
 * The ratchet stops new static flash messages from being added without an English translation.
 */
class ServerMessagesTest extends TestCase
{
    /**
     * Terms a translation names when its Arabic key does (Arabic => accepted English stems, case-insensitive).
     * A right translation that fails here: add its word to the list.
     */
    private const SUBJECTS = [
        'مسير' => ['payroll'], 'مخالصة' => ['settlement'], 'مستخلص' => ['progress claim'], 'المعدة' => ['equipment'],
        'النطاق' => ['domain', 'scope'], 'الأرشيف' => ['archive'], 'التذكرة' => ['ticket'], 'إجازة' => ['leave'],
        'التحويل' => ['transfer', 'conver'], 'الموظف' => ['employee'], 'السنة المالية' => ['fiscal year'],
        'الاشتراك' => ['subscri'], 'المستودع' => ['warehouse'], 'عرض السعر' => ['quot'], 'أمر الشراء' => ['purchase order'],
        'فاتورة الشراء' => ['purchase invoice', 'supplier invoice'], 'فاتورة' => ['invoic', 'fatoora'], 'فوترة' => ['invoic', 'bill'],
        'الجلسة' => ['session'], 'العقد' => ['contract'], 'الباقة' => ['package', 'plan'], 'الحزمة' => ['package'],
        'الشيك' => ['cheque'], 'المسودة' => ['draft'], 'المؤقت' => ['timer'], 'الوردية' => ['shift'],
        'مرتجع' => ['return'], 'مستلم' => ['receiv'], 'تسليم' => ['deliver'], 'العميل' => ['customer', 'lead'],
        'المورد' => ['supplier', 'resource'], 'الصنف' => ['item', 'product'], 'القيد' => ['entry', 'entries'],
        'الحساب' => ['account'], 'الضريبة' => ['tax'], 'المشروع' => ['project'], 'فترة' => ['period', 'interval'],
        'الموازنة' => ['budget'], 'البنك' => ['bank'], 'المنشأة' => ['company', 'organization'], 'الدفع' => ['pay'],
        'الجرد' => ['count'], 'الإهلاك' => ['depreciat'], 'التشغيلة' => ['batch'], 'التسلسلي' => ['serial'],
        'الحجز' => ['booking'], 'الخدمة' => ['service'], 'الزكاة' => ['zakat', 'zatca'], 'البادئة' => ['prefix'],
        'القالب' => ['template'], 'الرمز' => ['code', 'token'], 'الطلب' => ['request', 'order'],
    ];

    public function test_exact_and_templated_messages_translate_and_arabic_is_untouched(): void
    {
        $this->assertSame('Customer added successfully', ServerMessages::translate('تمت إضافة العميل بنجاح', 'en'));
        $this->assertSame('Entry JE-7 posted', ServerMessages::translate('تم ترحيل القيد JE-7', 'en'));
        $this->assertSame('Purchase order PO-1 saved', ServerMessages::translate('تم حفظ أمر الشراء PO-1', 'en'));
        $this->assertSame('تمت إضافة العميل بنجاح', ServerMessages::translate('تمت إضافة العميل بنجاح', 'ar'));
        $this->assertSame('رسالة غير معروفة', ServerMessages::translate('رسالة غير معروفة', 'en'));
        $this->assertSame('Plain text', ServerMessages::translate('Plain text', 'en'));
    }

    /** lang/en.json holds only the leading fragment of a concatenated message, which never matches at runtime. */
    public function test_concatenated_messages_translate_in_full(): void
    {
        foreach ([
            'تم تغيير سياق العمل: الفرع الرئيسي • السنة المالية 2026' => 'Work context changed: الفرع الرئيسي • Fiscal year 2026',
            'تم تغيير سياق العمل: كل الفروع • السنة المالية 2025/2026' => 'Work context changed: All branches • Fiscal year 2025/2026',
            'تمت دعوة أحمد إلى البوابة' => 'أحمد was invited to the portal',
            'تم فتح التذكرة TKT-00012' => 'Ticket TKT-00012 opened',
            'بيعت الباقة PKG-0003' => 'Package PKG-0003 sold',
            'تم إنشاء منشأتك بنجاح! مرحباً بك في Diwan.' => 'Your company was created successfully! Welcome to Diwan.',
            'تم تسجيل إيجار المورد بمبلغ 1,150.00' => 'Supplier rental of 1,150.00 recorded',
            'تم إرجاع مواد بقيمة 320.50' => 'Materials worth 320.50 returned',
            'تم صرف مواد بقيمة 1,200.00' => 'Materials worth 1,200.00 issued',
            'تم حفظ نسب الضريبة لـالمملكة العربية السعودية ابتداءً من 2026-01-01' => 'Tax rates for المملكة العربية السعودية saved, effective from 2026-01-01',
            'تم إنشاء السنة المالية وفتراتها: شهرية (12 فترة)' => 'Fiscal year and its periods created: شهرية (12 فترة)',
            'بيانات البائع ناقصة للفوترة الإلكترونية: الرقم الضريبي، العنوان الوطني' => 'Seller details are incomplete for e-invoicing: الرقم الضريبي، العنوان الوطني',
            'تجاوز الحد الائتماني للعميل: الرصيد بعد الفاتورة 12,500.00 والحد المسموح 10,000.00.' => "The customer's credit limit is exceeded: the balance after the invoice is 12,500.00 and the allowed limit is 10,000.00.",
            'تعذّر الإرسال: Connection refused' => 'Sending failed: Connection refused',
            'تتعارض البادئة مع بادئة «فاتورة المبيعات» (INV) لأنهما في سجل واحد.' => 'The prefix conflicts with the prefix of “فاتورة المبيعات” (INV) because both share one register.',
            'حساب «الصندوق» يجب أن يكون من نوع أصول.' => 'The “الصندوق” account must be of type أصول.',
            'تم إنشاء عرض السعر بنجاح (طُبِّق عرض: خصم الصيف)' => 'Quotation created successfully (promotion applied: خصم الصيف)',
            'تم إنشاء الفاتورة الضريبية بنجاح وفق متطلبات هيئة الزكاة (طُبِّق عرض: خصم الصيف)' => 'Tax invoice created successfully in line with ZATCA requirements (promotion applied: خصم الصيف)',
            'الكمية المتوفرة من «قلم أزرق» في المستودع غير كافية (المتوفر: 3، المطلوب: 5).' => 'The available quantity of “قلم أزرق” in the warehouse is not enough (available: 3, required: 5).',
            // «تم ترحيل القيد {}» used to take this one ("Entry اليومي بنجاح برقم JE-42 posted").
            'تم ترحيل القيد اليومي بنجاح برقم JE-42' => 'Journal entry posted successfully with number JE-42',
            'INV-0007: رفضتها الهيئة: BR-KSA-08 Invalid seller identification' => 'INV-0007: Rejected by the authority: BR-KSA-08 Invalid seller identification',
        ] as $arabic => $english) {
            $this->assertSame($english, ServerMessages::translate($arabic, 'en'), $arabic);
        }
    }

    public function test_every_template_translates_its_own_sample(): void
    {
        $fill = function (string $template): string {
            $n = 0;

            return preg_replace_callback('/\{\}/', function () use (&$n) {
                return 'V'.++$n;
            }, $template);
        };
        $taken = [];
        foreach (require lang_path('patterns.en.php') as $arabic => $english) {
            if (($got = ServerMessages::translate($fill($arabic), 'en')) !== $fill($english)) {
                $taken[] = "«{$arabic}» gave \"{$got}\"";
            }
        }
        $this->assertSame([], $taken, "The first matching template wins; put these above the general template that takes them:\n".implode("\n", $taken));
    }

    public function test_flash_and_validation_errors_are_translated_in_the_inertia_response(): void
    {
        $this->withSession(['locale' => 'en', 'success' => 'تم حذف العميل بنجاح', 'errors' => new ViewErrorBag])
            ->get('/login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('flash.success', 'Customer deleted successfully'));
    }

    public function test_every_static_flash_message_in_the_code_has_an_english_translation(): void
    {
        $en = json_decode(File::get(lang_path('en.json')), true);
        $missing = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/with\\(\\s*'(?:success|error|warning|info)'\\s*,\\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1\\s*\\)/su", File::get($file->getPathname()), $m);
            foreach ($m[2] as $text) {
                if (preg_match('/\p{Arabic}/u', $text) && ! str_contains($text, '$') && ! str_contains($text, '{') && ! isset($en[stripcslashes($text)]) && ! isset($en[$text])) {
                    $missing[$text] = $file->getRelativePathname();
                }
            }
        }
        $this->assertSame([], $missing, "Add to lang/en.json:\n".implode("\n", array_keys($missing)));
    }

    public function test_validation_and_business_rule_messages_never_regress(): void
    {
        $en = json_decode(File::get(lang_path('en.json')), true);
        $missing = 0;
        $examples = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (File::lines($file->getPathname()) as $line) {
                if (! preg_match('/ValidationException|withMessages|abort\(|Exception\(|withErrors\(|\'(?:message|error)\'\s*=>/', $line)) {
                    continue;
                }
                preg_match_all("/(['\"])((?:\\\\.|(?!\\1).)*)\\1/u", $line, $m);
                foreach ($m[2] as $text) {
                    $text = str_replace("\\'", "'", $text);
                    if (preg_match('/\p{Arabic}/u', $text) && ! str_contains($text, '$') && ! isset($en[$text])) {
                        $missing++;
                        $examples[] = $text;
                    }
                }
            }
        }
        $baseline = (int) (json_decode(File::get(base_path('tests/fixtures/server-messages-baseline.json')), true)['untranslated'] ?? PHP_INT_MAX);
        if (getenv('SERVER_MESSAGES_BASELINE') === 'update') {
            File::put(base_path('tests/fixtures/server-messages-baseline.json'), json_encode(['untranslated' => $missing], JSON_PRETTY_PRINT)."\n");
            $baseline = $missing;
        }
        fwrite(STDERR, "\n[server messages] {$missing} Arabic messages/fragments in validation and rule errors have no English entry (baseline {$baseline}).\n");
        $this->assertLessThanOrEqual($baseline, $missing, "New Arabic server messages need lang/en.json entries:\n".implode("\n", array_slice($examples, 0, 40)));
    }

    /**
     * 2391816 gave a block of lang/en.json its neighbours' English («حالة غير صالحة.» showed "Select at least one item
     * to invoice."). Every entry must keep its Arabic key's numbers, Latin names, {} values, quotes and ending (a fragment
     * that a value follows ends with a space, «, or لـ), and the subject it names. A shifted run breaks these at once.
     */
    public function test_every_english_entry_fits_its_own_arabic_key(): void
    {
        $problems = [];
        foreach (['en.json' => json_decode(File::get(lang_path('en.json')), true, 512, JSON_THROW_ON_ERROR), 'patterns.en.php' => require lang_path('patterns.en.php')] as $file => $entries) {
            foreach ($entries as $arabic => $english) {
                foreach (self::misfits((string) $arabic, $english) as $problem) {
                    $problems[] = "lang/{$file}: «{$arabic}» => \"{$english}\": {$problem}";
                }
            }
        }
        $this->assertSame([], $problems, "These English entries do not fit their Arabic key (shifted onto a neighbour's translation?):\n".implode("\n", $problems));
    }

    public function test_messages_of_the_block_shifted_in_2391816_keep_their_own_english(): void
    {
        foreach ([
            'المخالصة غير معتمدة أو لا يوجد مبلغ مستحق.' => 'The settlement is not approved or there is no amount due.',
            'المسير غير معتمد.' => 'The payroll run is not approved.',
            'انتهت الجلسة.' => 'The session has ended.',
            'تم استئناف الإصدار' => 'Issuing resumed',
            'تم تفعيل الحساب' => 'Account activated',
            'توجد إجازة أخرى في نفس الفترة.' => 'There is another leave in the same period.',
            'حالة غير صالحة.' => 'Invalid status.',
            'حدد كمية مرتجعة واحدة على الأقل.' => 'Specify at least one returned quantity.',
            'حدد كمية مستلمة واحدة على الأقل.' => 'Specify at least one received quantity.',
            'خُذ توقيع العميل أولًا.' => "Get the customer's signature first.",
        ] as $arabic => $english) {
            $this->assertSame($english, ServerMessages::translate($arabic, 'en'), $arabic);
        }
    }

    /** @return list<string> */
    private static function misfits(string $arabic, string $english): array
    {
        $numbers = function (string $text): array {
            preg_match_all('/\d+/', strtr($text, array_combine(mb_str_split('٠١٢٣٤٥٦٧٨٩'), range(0, 9))), $m);
            sort($m[0]);

            return $m[0];
        };
        $quotes = fn (string $text): int => preg_match_all('/[«»“”"]/u', $text);
        $ending = function (string $text): string {
            if (preg_match('/[\sـ]$/u', $text)) {
                return 'a value follows';
            }

            return match ($char = mb_substr($text, -1)) {
                '؟' => '?', '؛' => ';', '،' => ',', '«', '“' => 'opening quote', '»', '”' => 'closing quote',
                default => preg_match('/[.?!:;,)]/', $char) ? $char : 'no punctuation',
            };
        };

        $problems = [];
        if ($numbers($arabic) !== $numbers($english)) {
            $problems[] = 'numbers ['.implode(', ', $numbers($arabic)).'] vs ['.implode(', ', $numbers($english)).']';
        }
        preg_match_all('/[A-Za-z][A-Za-z0-9_]*/', $arabic, $latin);
        foreach (array_unique($latin[0]) as $word) {
            if (stripos($english, $word) === false) {
                $problems[] = "{$word} missing";
            }
        }
        if (substr_count($arabic, '{}') !== substr_count($english, '{}')) {
            $problems[] = substr_count($arabic, '{}').' {} values vs '.substr_count($english, '{}');
        }
        if ($quotes($arabic) !== $quotes($english)) {
            $problems[] = $quotes($arabic).' quote marks vs '.$quotes($english);
        }
        if ($ending($arabic) !== $ending($english)) {
            $problems[] = 'ends with '.$ending($arabic).' vs '.$ending($english);
        }
        foreach (self::SUBJECTS as $term => $words) {
            if (str_contains($arabic, $term) && ! array_filter($words, fn ($word) => stripos($english, $word) !== false)) {
                $problems[] = "«{$term}» is not \"".implode('" or "', $words).'"';
            }
        }

        return $problems;
    }
}
