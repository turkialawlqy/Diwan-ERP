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
}
