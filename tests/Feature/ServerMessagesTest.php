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
}
