<?php

namespace App\Support;

/**
 * Translates the Arabic messages the server produces (flash notices, validation and business-rule errors)
 * when the interface language is not Arabic. The source text stays Arabic everywhere in the code; the
 * translation happens once, at the boundary (Inertia share, JSON responses):
 *  - lang/en.json            exact Arabic message => English
 *  - lang/patterns.en.php    templates with {} for the variable parts (numbers, names), in order
 * Unknown messages come back unchanged, so nothing breaks when a translation is missing.
 */
class ServerMessages
{
    /** @var array<string, string>|null  regex => English template */
    protected static ?array $compiled = null;

    public static function translate(?string $text, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if ($text === null || $text === '' || $locale === 'ar' || ! preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        $exact = trans($text, [], $locale);
        if ($exact !== $text) {
            return $exact;
        }

        foreach (self::patterns($locale) as $regex => $english) {
            if (preg_match($regex, $text, $m)) {
                array_shift($m);
                foreach ($m as $value) {
                    // Values are usually numbers/names; translate them too when they are a known message.
                    $english = preg_replace('/\{\}/', addcslashes((string) $value, '\\$'), $english, 1);
                }

                return $english;
            }
        }

        return $text;
    }

    /** @param  array<int|string, mixed>  $messages */
    public static function translateAll(array $messages): array
    {
        return array_map(fn ($m) => is_array($m) ? self::translateAll($m) : (is_string($m) ? self::translate($m) : $m), $messages);
    }

    protected static function patterns(string $locale): array
    {
        $file = lang_path("patterns.{$locale}.php");
        if (! is_file($file)) {
            return [];
        }

        return self::$compiled[$locale] ??= collect(require $file)->mapWithKeys(function ($english, $arabic) {
            $parts = array_map(fn ($p) => preg_quote($p, '/'), explode('{}', $arabic));

            return ['/^'.implode('(.+?)', $parts).'$/su' => $english];
        })->all();
    }

    /**
     * Attribute names given to a validator (['name' => 'الاسم']) in the interface language: the English entry in
     * lang/en.json, so Laravel's English lines name the field in English. An Arabic name with no entry is left out
     * and Laravel names the field itself ("The name field…"). The Arabic interface keeps the names as they are.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function attributes(array $attributes, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        if ($locale === 'ar') {
            return $attributes;
        }

        $names = [];
        foreach ($attributes as $field => $name) {
            if (! is_string($name) || ! preg_match('/\p{Arabic}/u', $name)) {
                $names[$field] = $name;
            } elseif (is_string($english = trans($name, [], $locale)) && $english !== $name) {
                $names[$field] = $english;
            }
        }

        return $names;
    }
}
