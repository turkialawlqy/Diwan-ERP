<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * The translation files are merged by hand when two branches both add messages. caafc46 kept both
 * sides of such a conflict in lang/en.json: one comma short, and 34 keys twice. json_decode silently
 * keeps the last copy of a key, so a duplicate hides the English of the copies before it. A PHP array
 * does the same (the last value, in the first copy's place): once 7b6e707, 699d4e2 and 7330d7b met in
 * lang/patterns.en.php, three templates were there twice, one of them as "Line {}" and again as "Row {}".
 */
class TranslationFilesTest extends TestCase
{
    /** @return array<string, string> path relative to the project => contents */
    private static function files(string ...$patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            foreach (File::glob($pattern) as $path) {
                $files[str_replace('\\', '/', substr($path, strlen(base_path()) + 1))] = File::get($path);
            }
        }

        return $files;
    }

    public function test_translation_files_are_valid_json_without_duplicate_keys(): void
    {
        $files = self::files(lang_path('*.json'), resource_path('js/locales/*.json'));
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

    /** The PHP language files: lang/patterns.en.php (ServerMessages) and Laravel's lang/{locale}/*.php. */
    public function test_php_translation_files_parse_without_duplicate_keys(): void
    {
        $files = self::files(lang_path('*.php'), lang_path('*/*.php'));
        $this->assertArrayHasKey('lang/patterns.en.php', $files);
        $this->assertArrayHasKey('lang/en/validation.php', $files);

        $problems = [];
        foreach ($files as $file => $code) {
            try {
                $repeats = self::duplicatePhpKeys($code);
            } catch (\PhpParser\Error $e) {
                $problems[] = "{$file} is not valid PHP: {$e->getMessage()}";

                continue;
            }
            foreach ($repeats as [$key, $line, $first]) {
                $problems[] = "{$file}:{$line}: «{$key}» is repeated (first at line {$first}); keep one copy";
            }
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_php_duplicate_scan_counts_repeats_within_one_array_only(): void
    {
        $code = <<<'PHP'
            <?php
            return [
                'a' => 'x => "y", [',
                'b' => ['a' => 1, 'c' => [['a' => 2], ['a' => 3]]],
                "a\"q" => '',
                'b' => ['a' => [], 'a' => null],
                'a"q' => 0,
                '7' => 'seven',
                7 => 'sept',
                'a.b' => __('m', ['n' => 1, 'n' => 2]),
            ];
            PHP;

        $this->assertSame([['b', 6, 4], ['a"q', 7, 5], ['7', 9, 8], ['a', 6, 6], ['n', 10, 10]], self::duplicatePhpKeys($code));
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

    /**
     * Literal keys (strings and integers) that appear more than once in the same array literal of PHP code, outer
     * arrays first, as [key, line, line of the first copy]. Keys compare as PHP casts them: '7' and 7 are one key.
     *
     * @return list<array{string, int, int}>
     */
    private static function duplicatePhpKeys(string $code): array
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        $duplicates = [];
        foreach ((new NodeFinder)->findInstanceOf($ast, Expr\Array_::class) as $array) {
            $lines = [];
            foreach ($array->items as $item) {
                if ($item?->key instanceof Scalar\String_ || $item?->key instanceof Scalar\Int_) {
                    $key = array_key_first([$item->key->value => true]);
                    if (isset($lines[$key])) {
                        $duplicates[] = [(string) $key, $item->getStartLine(), $lines[$key]];
                    }
                    $lines[$key] ??= $item->getStartLine();
                }
            }
        }

        return $duplicates;
    }
}
