<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Fields without an inline name get theirs from lang/{locale}/validation.php. Every Arabic attribute name and
 * custom message has an English line, so an English page reads "The quantity (line 1) field is required."
 * rather than "The items.0.quantity field is required.".
 */
class ValidationLanguageTest extends TestCase
{
    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    protected function files(): array
    {
        return [require lang_path('ar/validation.php'), require lang_path('en/validation.php')];
    }

    public function test_every_arabic_attribute_and_custom_message_has_an_english_line(): void
    {
        [$ar, $en] = $this->files();

        foreach (['attributes', 'custom'] as $section) {
            $missing = array_values(array_diff(array_keys(Arr::dot($ar[$section])), array_keys(Arr::dot($en[$section]))));
            $this->assertSame([], $missing, "Add to lang/en/validation.php '{$section}':\n".implode("\n", $missing));
        }

        foreach ($ar['attributes'] as $field => $name) {
            if (str_contains($name, ':position')) {
                $this->assertStringContainsString(':position', $en['attributes'][$field], "{$field} keeps the line number.");
            }
        }

        // Rule references (BR-KSA-04) and digit counts carry over unchanged.
        foreach (Arr::dot($ar['custom']) as $key => $message) {
            preg_match_all('/[A-Z0-9][A-Z0-9-]*/', $message, $tokens);
            foreach ($tokens[0] as $token) {
                $this->assertStringContainsString($token, Arr::get($en['custom'], $key), "validation.custom.{$key}");
            }
        }
    }

    public function test_english_lines_contain_no_arabic_and_names_start_lowercase(): void
    {
        [, $en] = $this->files();

        $arabic = array_filter(Arr::dot($en), fn ($line) => is_string($line) && preg_match('/\p{Arabic}/u', $line));
        $this->assertSame([], $arabic);

        // Names sit mid-sentence ("The customer field…"); acronyms such as IBAN stay upper case.
        $capitalised = array_filter($en['attributes'], fn ($name) => preg_match('/^[A-Z][a-z]/', $name));
        $this->assertSame([], $capitalised);
    }

    public function test_english_messages_name_fields_and_lines_in_english(): void
    {
        $data = [
            'items' => [['quantity' => null]],
            'lines' => [['unit_price' => 5], ['unit_price' => 5], ['unit_price' => null]],
            'invoice_date' => now()->addDay()->toDateString(),
        ];
        $rules = [
            'customer_id' => 'required',
            'items.*.quantity' => 'required',
            'lines.*.unit_price' => 'required',
            'invoice_date' => 'date|before_or_equal:today',
        ];

        app()->setLocale('en');
        $errors = Validator::make($data, $rules)->errors();
        $this->assertSame('The customer field is required.', $errors->first('customer_id'));
        $this->assertSame('The quantity (line 1) field is required.', $errors->first('items.0.quantity'));
        $this->assertSame('The unit price (line 3) field is required.', $errors->first('lines.2.unit_price'));
        $this->assertSame('The tax invoice date cannot be later than today (ZATCA rule BR-KSA-04).', $errors->first('invoice_date'));

        app()->setLocale('ar');
        $this->assertSame('حقل الكمية (السطر 1) مطلوب.', Validator::make($data, $rules)->errors()->first('items.0.quantity'));
    }
}
