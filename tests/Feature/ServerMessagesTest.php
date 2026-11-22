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

    public function test_flash_and_validation_errors_are_translated_in_the_inertia_response(): void
    {
        $this->withSession(['locale' => 'en', 'success' => 'تم حذف العميل بنجاح', 'errors' => new ViewErrorBag])
            ->get('/login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('flash.success', 'Customer deleted successfully'));
    }

    /** An import sends its row errors as one message, a line each; matched whole, a template's {} took the lines before its own. */
    public function test_a_multi_line_import_error_translates_line_by_line(): void
    {
        foreach ([
            "سطر 3: الحساب الأب 11 غير موجود.\nسطر 5: حدد نوع الحساب (type) لحساب بلا أب." => "Row 3: parent account 11 does not exist.\nRow 5: specify the account type (type) for an account without a parent.",
            // The validator's own messages come in the interface language; only the row prefix is Arabic.
            "سطر 2: The name ar field is required.\nسطر 4: رقم الآيبان غير صحيح." => "Row 2: The name ar field is required.\nRow 4: the IBAN is invalid.",
            "الفاتورة INV-9: العميل C-77 غير موجود.\nالفاتورة INV-10: الحساب البنكي الراجحي غير موجود." => "Invoice INV-9: customer C-77 does not exist.\nInvoice INV-10: bank account الراجحي does not exist.",
            "سطر 7: الحساب 1105 حساب مراقبة؛ يُستورد رصيده من ملف «العملاء».\nالميزان غير متوازن: المدين 1,000.00 والدائن 900.00 (الفرق 100.00)." => "Row 7: account 1105 is a control account; its balance is imported from the “Customers” file.\nThe trial balance is not balanced: debit 1,000.00, credit 900.00 (difference 100.00).",
            // Line breaks are kept as they came, and an unknown line stays as it is.
            "سطر 3: رقم الآيبان غير صحيح.\r\nرسالة غير معروفة" => "Row 3: the IBAN is invalid.\r\nرسالة غير معروفة",
            // The attendance import joins its rows with « | ».
            'سطر 2: موظف أو تاريخ غير صحيح | سطر 9: الفترة مغلقة.' => 'Row 2: invalid employee or date | Row 9: The period is closed.',
            // « | » inside a name: the message is not split.
            'تم إنشاء المشروع فيلا | الرياض' => 'Project فيلا | الرياض created',
        ] as $arabic => $english) {
            $this->assertSame($english, ServerMessages::translate($arabic, 'en'), $arabic);
        }
    }

    public function test_values_are_translated_when_they_are_known_messages(): void
    {
        // The e-invoicing summary was left Arabic inside the English sentence.
        $this->assertSame('Sent 3 documents: 2 accepted, 1 rejected', ServerMessages::translate('أُرسل 3 مستند: قُبل 2، رُفض 1', 'en'));
        $this->assertSame('Sent 4 documents: 1 accepted, 2 rejected, 1 could not be sent', ServerMessages::translate('أُرسل 4 مستند: قُبل 1، رُفض 2، تعذّر إرسال 1', 'en'));
        // A row error inside a quotation error reads as it would alone.
        $row = 'سطر 4: الكمية يجب أن تكون أكبر من صفر.';
        $this->assertSame('Quotation Q-8: '.ServerMessages::translate($row, 'en'), ServerMessages::translate("العرض Q-8: {$row}", 'en'));
        // A name is not a message; and a value is inserted as it is, even holding {} or $1.
        $this->assertSame('Project فيلا الرياض created', ServerMessages::translate('تم إنشاء المشروع فيلا الرياض', 'en'));
        $this->assertSame('Project A{}B $1 created', ServerMessages::translate('تم إنشاء المشروع A{}B $1', 'en'));
        // The template with the most fixed text wins wherever it sits in the file («تم ترحيل القيد {}» comes first there).
        $this->assertSame('Journal entry posted successfully with number JE-42', ServerMessages::translate('تم ترحيل القيد اليومي بنجاح برقم JE-42', 'en'));
        $this->assertSame('Row 3: bank account الأهلي does not exist.', ServerMessages::translate('سطر 3: الحساب البنكي الأهلي غير موجود.', 'en'));
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
