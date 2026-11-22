<?php

namespace Tests\Feature;

use App\Support\ServerMessages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ViewErrorBag;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * Server messages are Arabic in the code and translated once at the boundary (lang/en.json + lang/patterns.en.php).
 * The ratchet stops new static flash, abort and $fail messages from being added without an English translation.
 */
class ServerMessagesTest extends TestCase
{
    /**
     * Terms a translation names when its Arabic key does (Arabic => accepted English stems, case-insensitive).
     * A right translation that fails here: add its word to the list.
     */
    private const SUBJECTS = [
        'مسير' => ['payroll'], 'مخالصة' => ['settlement'], 'مستخلص' => ['progress claim'], 'المعدة' => ['equipment'],
        'النطاق' => ['domain', 'scope'], 'الأرشيف' => ['archive'], 'التذكرة' => ['ticket'], 'إجازة' => ['leave'],
        'التحويل' => ['transfer', 'conver'], 'الموظف' => ['employee'], 'السنة المالية' => ['fiscal year'],
        'الاشتراك' => ['subscri'], 'المستودع' => ['warehouse'], 'عرض السعر' => ['quot'], 'أمر الشراء' => ['purchase order'],
        'فاتورة الشراء' => ['purchase invoice', 'supplier invoice'], 'فاتورة' => ['invoic', 'fatoora'], 'فوترة' => ['invoic', 'bill'],
        'الجلسة' => ['session'], 'العقد' => ['contract'], 'الباقة' => ['package', 'plan'], 'الحزمة' => ['package'],
        'الشيك' => ['cheque'], 'المسودة' => ['draft'], 'المؤقت' => ['timer'], 'الوردية' => ['shift'],
        'مرتجع' => ['return'], 'مستلم' => ['receiv'], 'تسليم' => ['deliver'], 'العميل' => ['customer', 'lead'],
        'المورد' => ['supplier', 'resource'], 'الصنف' => ['item', 'product'], 'القيد' => ['entry', 'entries'],
        'الحساب' => ['account'], 'الضريبة' => ['tax'], 'المشروع' => ['project'], 'فترة' => ['period', 'interval'],
        'الموازنة' => ['budget'], 'البنك' => ['bank'], 'المنشأة' => ['company', 'organization'], 'الدفع' => ['pay'],
        'الجرد' => ['count'], 'الإهلاك' => ['depreciat'], 'التشغيلة' => ['batch'], 'التسلسلي' => ['serial'],
        'الحجز' => ['booking'], 'الخدمة' => ['service'], 'الزكاة' => ['zakat', 'zatca'], 'البادئة' => ['prefix'],
        'القالب' => ['template'], 'الرمز' => ['code', 'token'], 'الطلب' => ['request', 'order'],
    ];

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

    public function test_every_static_server_message_in_the_code_has_an_english_translation(): void
    {
        $missing = array_diff_key(self::staticMessages(), json_decode(File::get(lang_path('en.json')), true));
        $this->assertSame([], $missing, "Add to lang/en.json:\n".implode("\n", array_map(fn ($text, $at) => "{$text}    ({$at})", array_keys($missing), $missing)));
    }

    public function test_the_static_message_scan_counts_ternary_branches_abort_and_fail_messages(): void
    {
        $code = <<<'PHP'
            <?php
            back()->with('success', 'حُفظ');
            back()->with($ok ? 'success' : 'error', $ok ? 'اعتُمد' : "رُفض");
            back()->with(['info' => 'أكمل التسجيل', 'email' => $email]);
            abort(403, 'ممنوع');
            abort_unless($open, 422, 'مغلق');
            abort_if($used, 422, $reason ?? 'مستعمل');
            $fail('غير صالح');
            back()->with('status', 'ليست رسالة تنبيه');
            back()->with('success', 'تم ترحيل القيد '.$number);
            PHP;

        $this->assertSame(['حُفظ' => 2, 'اعتُمد' => 3, 'رُفض' => 3, 'أكمل التسجيل' => 4, 'ممنوع' => 5, 'مغلق' => 6, 'مستعمل' => 7, 'غير صالح' => 8], self::messagesIn($code));
    }

    /**
     * Arabic literals the code shows the user whole: flash messages (each branch of a ternary, ?? or match too,
     * and ->with(['success' => …])), abort/abort_if/abort_unless messages and $fail('…') in closure rules.
     * Concatenated and interpolated messages are templates (lang/patterns.en.php), not counted here.
     *
     * @return array<string, string> message => where it is
     */
    private static function staticMessages(): array
    {
        $messages = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php') {
                foreach (self::messagesIn(File::get($file->getPathname())) as $text => $line) {
                    $messages[$text] ??= $file->getRelativePathname().':'.$line;
                }
            }
        }

        return $messages;
    }

    /** @return array<string, int> message => line */
    private static function messagesIn(string $code): array
    {
        static $parser;
        $parser ??= (new ParserFactory)->createForNewestSupportedVersion();
        $messages = [];
        $calls = (new NodeFinder)->find($parser->parse($code), fn (Node $node) => $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall);
        foreach ($calls as $call) {
            if ($call->isFirstClassCallable()) {
                continue;
            }
            $args = array_map(fn ($arg) => $arg->value, $call->getArgs());
            $name = match (true) {
                $call->name instanceof Node\Identifier, $call->name instanceof Node\Name => $call->name->toString(),
                $call->name instanceof Expr\Variable && is_string($call->name->name) => '$'.$call->name->name,
                default => '',
            };
            $texts = match ($call instanceof Expr\FuncCall ? $name : "->{$name}") {
                '->with' => self::flashed($args),
                'abort' => self::branches($args[1] ?? null),
                'abort_if', 'abort_unless' => self::branches($args[2] ?? null),
                '$fail' => self::branches($args[0] ?? null),
                default => [],
            };
            foreach ($texts as $text) {
                if (preg_match('/\p{Arabic}/u', $text)) {
                    $messages[$text] ??= $call->getStartLine();
                }
            }
        }

        return $messages;
    }

    /** @param  list<Expr>  $args  of ->with('success', …), ->with($ok ? 'success' : 'error', …) or ->with(['success' => …]) */
    private static function flashed(array $args): array
    {
        $levels = ['success', 'error', 'warning', 'info'];
        if (isset($args[1])) {
            $keys = self::branches($args[0]);

            return $keys && ! array_diff($keys, $levels) ? self::branches($args[1]) : [];
        }
        $texts = [];
        foreach (($args[0] ?? null) instanceof Expr\Array_ ? $args[0]->items : [] as $item) {
            if ($item?->key instanceof String_ && in_array($item->key->value, $levels, true)) {
                $texts = [...$texts, ...self::branches($item->value)];
            }
        }

        return $texts;
    }

    /** The literals an expression gives whole: a string, or each branch of ?:, ?? and match. */
    private static function branches(?Node $node): array
    {
        return match (true) {
            $node instanceof String_ => [$node->value],
            $node instanceof Expr\Ternary => [...self::branches($node->if ?? $node->cond), ...self::branches($node->else)],
            $node instanceof Expr\BinaryOp\Coalesce => [...self::branches($node->left), ...self::branches($node->right)],
            $node instanceof Expr\Match_ => array_merge([], ...array_map(fn ($arm) => self::branches($arm->body), $node->arms)),
            default => [],
        };
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

    /**
     * 2391816 gave a block of lang/en.json its neighbours' English («حالة غير صالحة.» showed "Select at least one item
     * to invoice."). Every entry must keep its Arabic key's numbers, Latin names, {} values, quotes and ending (a fragment
     * that a value follows ends with a space, «, or لـ), and the subject it names. A shifted run breaks these at once.
     */
    public function test_every_english_entry_fits_its_own_arabic_key(): void
    {
        $problems = [];
        foreach (['en.json' => json_decode(File::get(lang_path('en.json')), true, 512, JSON_THROW_ON_ERROR), 'patterns.en.php' => require lang_path('patterns.en.php')] as $file => $entries) {
            foreach ($entries as $arabic => $english) {
                foreach (self::misfits((string) $arabic, $english) as $problem) {
                    $problems[] = "lang/{$file}: «{$arabic}» => \"{$english}\": {$problem}";
                }
            }
        }
        $this->assertSame([], $problems, "These English entries do not fit their Arabic key (shifted onto a neighbour's translation?):\n".implode("\n", $problems));
    }

    public function test_messages_of_the_block_shifted_in_2391816_keep_their_own_english(): void
    {
        foreach ([
            'المخالصة غير معتمدة أو لا يوجد مبلغ مستحق.' => 'The settlement is not approved or there is no amount due.',
            'المسير غير معتمد.' => 'The payroll run is not approved.',
            'انتهت الجلسة.' => 'The session has ended.',
            'تم استئناف الإصدار' => 'Issuing resumed',
            'تم تفعيل الحساب' => 'Account activated',
            'توجد إجازة أخرى في نفس الفترة.' => 'There is another leave in the same period.',
            'حالة غير صالحة.' => 'Invalid status.',
            'حدد كمية مرتجعة واحدة على الأقل.' => 'Specify at least one returned quantity.',
            'حدد كمية مستلمة واحدة على الأقل.' => 'Specify at least one received quantity.',
            'خُذ توقيع العميل أولًا.' => "Get the customer's signature first.",
        ] as $arabic => $english) {
            $this->assertSame($english, ServerMessages::translate($arabic, 'en'), $arabic);
        }
    }

    /** @return list<string> */
    private static function misfits(string $arabic, string $english): array
    {
        $numbers = function (string $text): array {
            preg_match_all('/\d+/', strtr($text, array_combine(mb_str_split('٠١٢٣٤٥٦٧٨٩'), range(0, 9))), $m);
            sort($m[0]);

            return $m[0];
        };
        $quotes = fn (string $text): int => preg_match_all('/[«»“”"]/u', $text);
        $ending = function (string $text): string {
            if (preg_match('/[\sـ]$/u', $text)) {
                return 'a value follows';
            }

            return match ($char = mb_substr($text, -1)) {
                '؟' => '?', '؛' => ';', '،' => ',', '«', '“' => 'opening quote', '»', '”' => 'closing quote',
                default => preg_match('/[.?!:;,)]/', $char) ? $char : 'no punctuation',
            };
        };

        $problems = [];
        if ($numbers($arabic) !== $numbers($english)) {
            $problems[] = 'numbers ['.implode(', ', $numbers($arabic)).'] vs ['.implode(', ', $numbers($english)).']';
        }
        preg_match_all('/[A-Za-z][A-Za-z0-9_]*/', $arabic, $latin);
        foreach (array_unique($latin[0]) as $word) {
            if (stripos($english, $word) === false) {
                $problems[] = "{$word} missing";
            }
        }
        if (substr_count($arabic, '{}') !== substr_count($english, '{}')) {
            $problems[] = substr_count($arabic, '{}').' {} values vs '.substr_count($english, '{}');
        }
        if ($quotes($arabic) !== $quotes($english)) {
            $problems[] = $quotes($arabic).' quote marks vs '.$quotes($english);
        }
        if ($ending($arabic) !== $ending($english)) {
            $problems[] = 'ends with '.$ending($arabic).' vs '.$ending($english);
        }
        foreach (self::SUBJECTS as $term => $words) {
            if (str_contains($arabic, $term) && ! array_filter($words, fn ($word) => stripos($english, $word) !== false)) {
                $problems[] = "«{$term}» is not \"".implode('" or "', $words).'"';
            }
        }

        return $problems;
    }
}
