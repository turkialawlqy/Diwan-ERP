<?php

namespace App\Support;

/**
 * Translates the Arabic messages the server produces (flash notices, validation and business-rule errors)
 * when the interface language is not Arabic. The source text stays Arabic everywhere in the code; the
 * translation happens once, at the boundary (Inertia share, JSON responses):
 *  - lang/en.json            exact Arabic message => English
 *  - lang/patterns.en.php    templates with {} for the variable parts (numbers, names). The template with the
 *                            most fixed text is tried first, so a specific one wins over a general one
 *                            («سطر {}: {}») wherever each sits in the file.
 * A value is translated in turn when it is itself a known message (a label, a row error inside a quotation
 * error). Messages joined into one (import row errors, a line each) are translated line by line.
 * Unknown messages come back unchanged, so nothing breaks when a translation is missing.
 */
class ServerMessages
{
    /** Levels of templates: the message, a value inside it, a value inside that (a row error within a quotation error). */
    protected const DEPTH = 3;

    /** @var array<string, array<string, string>>|null  locale => [regex => English template] */
    protected static ?array $compiled = null;

    public static function translate(?string $text, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if ($text === null || $text === '' || $locale === 'ar' || ! self::isArabic($text)) {
            return $text;
        }
        if (($exact = self::exact($text, $locale)) !== null) {
            return $exact;
        }

        // Matched whole, a template's lazy {} would swallow the lines before its own and mix the two
        // languages, so each line is a message of its own (the line breaks are kept as they were).
        $lines = preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($lines) > 1) {
            return implode('', array_map(fn ($line, $i) => $i % 2 ? $line : self::translate($line, $locale), $lines, array_keys($lines)));
        }

        // The attendance import joins its row errors with « | » on one line. Split only when every part is a
        // known message: a name holding « | » inside a single message keeps that message whole.
        $parts = explode(' | ', $text);
        if (count($parts) > 1) {
            $translated = array_map(fn ($part) => self::isArabic($part) ? self::message($part, $locale, self::DEPTH) : $part, $parts);
            if (! in_array(null, $translated, true)) {
                return implode(' | ', $translated);
            }
        }

        return self::message($text, $locale, self::DEPTH) ?? $text;
    }

    /** @param  array<int|string, mixed>  $messages */
    public static function translateAll(array $messages): array
    {
        return array_map(fn ($m) => is_array($m) ? self::translateAll($m) : (is_string($m) ? self::translate($m) : $m), $messages);
    }

    /** One message: its exact entry, else the most specific template that matches, its values translated in turn. */
    protected static function message(string $text, string $locale, int $depth): ?string
    {
        if (($exact = self::exact($text, $locale)) !== null) {
            return $exact;
        }

        foreach ($depth > 0 ? self::patterns($locale) : [] as $regex => $english) {
            if (preg_match($regex, $text, $m)) {
                $values = array_map(fn ($value) => self::isArabic($value) ? (self::message($value, $locale, $depth - 1) ?? $value) : $value, array_slice($m, 1));

                // Each {} takes the next value, inserted as it is (a value holding "{}" or "$1" stays intact).
                return preg_replace_callback('/\{\}/', function () use (&$values) {
                    return array_shift($values) ?? '{}';
                }, $english);
            }
        }

        return null;
    }

    protected static function exact(string $text, string $locale): ?string
    {
        $exact = trans($text, [], $locale);

        return is_string($exact) && $exact !== $text ? $exact : null;
    }

    protected static function isArabic(string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $text);
    }

    protected static function patterns(string $locale): array
    {
        $file = lang_path("patterns.{$locale}.php");
        if (! is_file($file)) {
            return [];
        }

        return self::$compiled[$locale] ??= collect(require $file)
            // Most fixed text first (a stable sort: equal ones keep their order in the file).
            ->sortByDesc(fn ($english, $arabic) => mb_strlen(str_replace('{}', '', $arabic)))
            ->mapWithKeys(function ($english, $arabic) {
                $parts = array_map(fn ($p) => preg_quote($p, '/'), explode('{}', $arabic));

                return ['/^'.implode('(.+?)', $parts).'$/su' => $english];
            })->all();
    }
}
