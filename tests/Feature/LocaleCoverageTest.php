<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * English coverage ratchet. The UI is Arabic-first and is being moved to translation keys
 * page by page: the count of hard-coded Arabic strings per Vue file may only go down
 * (regenerate the baseline with LOCALE_BASELINE=update after translating a file),
 * both language files carry the same keys, and every key a component uses exists.
 */
class LocaleCoverageTest extends TestCase
{
    protected const BASELINE = 'tests/fixtures/locale-baseline.json';

    protected function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            is_array($value) ? $out += $this->flatten($value, $prefix.$key.'.') : $out[$prefix.$key] = $value;
        }

        return $out;
    }

    /** @return array<string, int> file => hard-coded Arabic strings (template text, labelled attributes, quoted script strings) */
    protected function hardCoded(): array
    {
        $counts = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }
            $source = $file->getContents();
            $parts = explode('<template>', $source, 2);
            $template = isset($parts[1]) ? substr($parts[1], 0, (int) strrpos($parts[1], '</template>')) : '';
            $script = $parts[0];
            $n = preg_match_all('/>([^<>{}]*\p{Arabic}[^<>{}]*)</u', $template)
                + preg_match_all('/\s(?:title|label|placeholder|hint|subtitle)="([^"]*\p{Arabic}[^"]*)"/u', $template)
                + preg_match_all("/'([^'\\n]*\\p{Arabic}[^'\\n]*)'/u", $script);
            if ($n > 0) {
                $counts[str_replace(resource_path('js').'/', '', $file->getPathname())] = $n;
            }
        }
        ksort($counts);

        return $counts;
    }

    public function test_hard_coded_arabic_never_grows_and_the_numbers_are_reported(): void
    {
        $now = $this->hardCoded();
        if (getenv('LOCALE_BASELINE') === 'update') {
            File::put(base_path(self::BASELINE), json_encode($now, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        $baseline = json_decode(File::get(base_path(self::BASELINE)), true);

        $worse = [];
        foreach ($now as $file => $count) {
            if ($count > ($baseline[$file] ?? 0)) {
                $worse[] = "{$file}: {$count} (was ".($baseline[$file] ?? 0).')';
            }
        }
        $this->assertSame([], $worse, "New hard-coded Arabic text: use a translation key (t('group.key')) with an entry in both ar.json and en.json.\n".implode("\n", $worse));

        $translatedCalls = 0;
        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getExtension() === 'vue') {
                $translatedCalls += preg_match_all('/\$t\(|[^\w$.]t\(/', $file->getContents());
            }
        }
        fwrite(STDERR, sprintf("\n[locale] %d Vue files still hold %d hard-coded Arabic strings; %d translated lookups in use.\n", count($now), array_sum($now), $translatedCalls));
    }

    public function test_both_languages_have_the_same_keys_and_every_used_key_exists(): void
    {
        $ar = $this->flatten(json_decode(File::get(resource_path('js/locales/ar.json')), true));
        $en = $this->flatten(json_decode(File::get(resource_path('js/locales/en.json')), true));

        $this->assertSame([], array_values(array_diff(array_keys($ar), array_keys($en))), 'keys missing in en.json');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($ar))), 'keys missing in ar.json');
        foreach ($en as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "empty English text for {$key}");
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) $value, "Arabic text in en.json: {$key}");
        }

        $missing = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if (! in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            preg_match_all('/(?:\$t|[^\w$.]t|[^\w$.]te)\(\s*[\'"]([a-z_]+(?:\.[a-z_0-9]+)+)[\'"]/', $file->getContents(), $m);
            foreach ($m[1] as $key) {
                if (! isset($ar[$key])) {
                    $missing[$key] = $file->getFilename();
                }
            }
        }
        $this->assertSame([], $missing, 'translation keys used but not defined');
    }
}
