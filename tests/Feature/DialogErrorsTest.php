<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A refusal must be visible where the user is. AppLayout's banner sits behind a dialog's overlay, so a
 * dialog shows its errors itself: under each field (:error) and, for the rest (business rules, fields
 * it does not render), in FormErrors. Pages without a field for an error showed it only in the banner.
 */
class DialogErrorsTest extends TestCase
{
    /** Pages whose dialogs post a form. */
    protected const DIALOG_PAGES = [
        'Accounting/Cheques/Index.vue',
        'Accounting/Recurring/Index.vue',
        'Purchases/LandedCosts/Index.vue',
        'Accounting/FixedAssets/Index.vue',
        'Accounting/FixedAssets/Show.vue',
        'HR/Payroll/Show.vue',
        'HR/Settlements.vue',
    ];

    /**
     * Fields whose errors FormErrors lists instead of the field: the asset category's account pickers
     * (their lines also change in the branch-box PR, #74; bind them there once both are in).
     */
    protected const LISTED_ONLY = ['categoryForm.asset_account_id', 'categoryForm.accumulated_account_id', 'categoryForm.expense_account_id'];

    public function test_every_dialog_shows_each_error_inside_it(): void
    {
        foreach (self::DIALOG_PAGES as $page) {
            $source = File::get(resource_path("js/Pages/{$page}"));
            preg_match_all('/const (\w+) = useForm\(/', $source, $m);
            $forms = $m[1];
            preg_match_all('/<Dialog\b.*?<\/Dialog>/s', $source, $dialogs);
            $this->assertNotEmpty($dialogs[0], "{$page} has no dialog");

            foreach ($dialogs[0] as $dialog) {
                preg_match_all('/v-model(?:\.\w+)?="(\w+)\.(\w+)"/', $dialog, $models, PREG_SET_ORDER);
                foreach (array_unique(array_intersect(array_column($models, 1), $forms)) as $form) {
                    $this->assertMatchesRegularExpression('/<FormErrors :errors="'.$form.'\.errors"/', $dialog, "{$page}: a dialog of {$form} does not list its errors");

                    // A key FormErrors leaves out must be shown by the dialog itself.
                    foreach ($this->exceptedKeys($source, $dialog, $form) as $key) {
                        $this->assertStringContainsString("{$form}.errors.{$key}", $dialog, "{$page}: {$form}'s «{$key}» error is shown nowhere");
                    }

                    // Each field shows its own error (a helper control, like a «taxable» box, shows the one of the value it sets).
                    preg_match_all('/<Field((?:\s+[^\s=>]+(?:="[^"]*")?)*)\s*>(.*?)<\/Field>/s', $dialog, $fields, PREG_SET_ORDER);
                    foreach ($fields as [, $attributes, $content]) {
                        preg_match_all('/v-model(?:\.\w+)?="'.$form.'\.(\w+)"/', $content, $keys);
                        foreach (array_diff($keys[1] ? [$keys[1][0]] : [], array_map(fn ($f) => substr($f, strlen($form) + 1), self::LISTED_ONLY)) as $key) {
                            $this->assertMatchesRegularExpression('/:error="[^"]*'.$form.'\.errors\./', $attributes, "{$page}: the {$form}.{$key} field has no :error");
                        }
                    }
                }
            }
        }
    }

    public function test_box_and_account_fields_show_their_errors_on_the_page(): void
    {
        $expected = [
            'Purchases/Drafts/Complete.vue' => ['form.errors.bank_account_id'],
            'Purchases/Orders/Invoice.vue' => ["['method', 'bank_account_id', 'amount'].filter((k) => form.errors[`payments.\${i}.\${k}`])"],
            'Finance/Expenses/Form.vue' => ['form.errors.bank_account_id', 'form.errors.account_id'],
            'Finance/Revenues/Form.vue' => ['form.errors.bank_account_id', 'form.errors.account_id'],
            'Finance/Banks/Form.vue' => ['form.errors.account_id'],
        ];
        foreach ($expected as $page => $needles) {
            $source = File::get(resource_path("js/Pages/{$page}"));
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, "{$page} does not show {$needle}");
            }
        }
    }

    /** The keys a dialog's FormErrors leaves out: its :except array, written in place or as a computed of the page. */
    protected function exceptedKeys(string $source, string $dialog, string $form): array
    {
        if (! preg_match('/<FormErrors :errors="'.$form.'\.errors" :except="([^"]*)"/', $dialog, $m)) {
            return [];
        }
        $list = $m[1];
        if (preg_match('/^\w+$/', $list) && preg_match('/const '.$list.' = computed\(\(\) => \[(.*?)\]\);/s', $source, $c)) {
            $list = $c[1];
        }
        preg_match_all("/'([\\w.]+)'/", $list, $keys);

        // 'lines.*' stands for every line's fields, shown by the line itself.
        return array_values(array_filter($keys[1], fn ($k) => ! str_ends_with($k, '.*') && ! in_array($k, ['cash', 'bank_transfer', 'payables', 'opening'], true)));
    }
}
