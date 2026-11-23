<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The translation JSON files are merged by hand when two branches both add messages. caafc46 kept both
 * sides of such a conflict in lang/en.json: one comma short, and 34 keys twice. json_decode silently
 * keeps the last copy of a key, so a duplicate hides the English of the copies before it.
 */
class TranslationFilesTest extends TestCase
{
    /** @return array<string, string> path relative to the project => contents */
    private static function files(): array
    {
        $files = [];
        foreach ([...File::glob(lang_path('*.json')), ...File::glob(resource_path('js/locales/*.json'))] as $path) {
            $files[str_replace('\\', '/', substr($path, strlen(base_path()) + 1))] = File::get($path);
        }

        return $files;
    }

    public function test_translation_files_are_valid_json_without_duplicate_keys(): void
    {
        $files = self::files();
        $this->assertArrayHasKey('lang/en.json', $files);

        $problems = [];
        foreach ($files as $file => $json) {
            try {
                json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $problems[] = "{$file} is not valid JSON: {$e->getMessage()}";

                continue;
            }
            foreach (self::duplicateKeys($json) as $key) {
                $problems[] = "{$file}: «{$key}» is repeated; keep one copy";
            }
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_duplicate_scan_counts_repeats_within_one_object_only(): void
    {
        $json = '{"a": "x: \"y\", {", "b": {"a": 1, "c": [{"a": 2}, {"a": 3}]}, "a\"q": "", "b": {"a": [], "a": null}, "a\"q": 0, "a/b": 1, "a\/b": 2}';

        $this->assertSame(['b', 'b.a', 'a"q', 'a/b'], self::duplicateKeys($json));
    }

    /**
     * Keys that appear more than once in the same object, as dotted paths. The JSON must be valid:
     * outside strings it holds no quotes, so the string tokens line up with the real strings.
     *
     * @return list<string>
     */
    private static function duplicateKeys(string $json): array
    {
        if (preg_match_all('/"(?:[^"\\\\]++|\\\\.)*+"|[{}\[\]:]/su', $json, $m) === false) {
            throw new \RuntimeException('Could not scan the JSON: '.preg_last_error_msg());
        }
        $tokens = $m[0];
        $stack = [];
        $duplicates = [];
        foreach ($tokens as $i => $token) {
            if ($token === '{' || $token === '[') {
                $parent = end($stack) ?: null;
                $path = $parent === null ? '' : $parent['path'].($parent['object'] ? $parent['last'].'.' : '');
                $stack[] = ['object' => $token === '{', 'path' => $path, 'keys' => [], 'last' => ''];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($token[0] === '"' && ($tokens[$i + 1] ?? null) === ':') {
                $frame = &$stack[array_key_last($stack)];
                $key = json_decode($token);
                if (isset($frame['keys'][$key])) {
                    $duplicates[] = $frame['path'].$key;
                }
                $frame['keys'][$key] = true;
                $frame['last'] = $key;
                unset($frame);
            }
        }

        return $duplicates;
    }
}
