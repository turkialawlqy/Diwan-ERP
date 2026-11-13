<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The fixed Arabic labels of JS constants (the quick «إضافة»/«عرض» menus and the quick-create forms) are translated
 * through resources/js/locales/dict.en.json (Arabic → English, see tx() in i18n.js). A label without an entry would
 * show Arabic in the English interface, so every one must be listed.
 */
class DictionaryCoverageTest extends TestCase
{
    protected const FILES = ['resources/js/quickMenus.js', 'resources/js/quickCreate.js'];

    public function test_every_arabic_label_of_the_menu_constants_has_an_english_entry(): void
    {
        $dict = json_decode(File::get(resource_path('js/locales/dict.en.json')), true);
        $missing = [];
        foreach (self::FILES as $file) {
            $source = File::get(base_path($file));
            preg_match_all("/'([^'\\n]*\\p{Arabic}[^'\\n]*)'/u", $source, $m);
            foreach (array_unique($m[1]) as $text) {
                if (! isset($dict[$text]) || trim((string) $dict[$text]) === '') {
                    $missing[] = $file.': '.$text;
                }
            }
        }
        $this->assertSame([], $missing, "Add these to resources/js/locales/dict.en.json:\n".implode("\n", $missing));
    }

    public function test_the_dictionary_has_no_arabic_values_and_no_empty_ones(): void
    {
        $dict = json_decode(File::get(resource_path('js/locales/dict.en.json')), true);
        $bad = [];
        foreach ($dict as $ar => $en) {
            if (trim((string) $en) === '' || preg_match('/[\x{0600}-\x{06FF}]/u', (string) $en) && ! str_contains($ar, 'مصري')) {
                $bad[] = $ar;
            }
        }
        $this->assertSame([], $bad, 'Empty or Arabic English values: '.implode(' | ', $bad));
    }
}
