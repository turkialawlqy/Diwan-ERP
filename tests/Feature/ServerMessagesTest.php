<?php

namespace Tests\Feature;

use App\Support\ServerMessages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
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
            back()->with('status', 'أعد تسجيل الدخول');
            back()->with('success', 'تم ترحيل القيد '.$number);
            back()->with('email', 'ليست رسالة');
            PHP;

        $this->assertSame(['حُفظ' => 2, 'اعتُمد' => 3, 'رُفض' => 3, 'أكمل التسجيل' => 4, 'ممنوع' => 5, 'مغلق' => 6, 'مستعمل' => 7, 'غير صالح' => 8, 'أعد تسجيل الدخول' => 9], self::messagesIn($code));
    }

    public function test_the_static_message_scan_counts_validation_messages_and_attribute_names(): void
    {
        $code = <<<'PHP'
            <?php
            class Sample extends Controller
            {
                public function store(Request $request, array $data)
                {
                    $request->validate(['logo' => 'required|image'], ['logo.required' => 'اختر الشعار'], ['logo' => 'الشعار']);
                    $request->validate(['name' => 'required'] + $this->lineRules(), ['name.required' => 'أدخل الاسم'] + $this->lineMessages());
                    Validator::make($data, ['days' => 'integer'], ['days' => ['min' => 'أيام قليلة']], ['days' => 'الأيام'])->validate();
                    validator($data, ['qty' => 'numeric'], [], ['qty' => 'الكمية']);
                    $request->validateWithBag('lines', ['code' => 'required'], [], ['code' => 'الرمز']);
                    $request->validate(['note' => 'nullable|string']);
                }

                protected function lineMessages(): array
                {
                    return ['lines.*.code.required' => 'السطر :position بدون رمز'];
                }
            }

            class SampleRequest extends FormRequest
            {
                public function messages(): array { return ['name.required' => 'الاسم مطلوب']; }
                public function attributes(): array { return ['name' => 'اسم العميل']; }
            }
            PHP;

        $this->assertSame([
            'اختر الشعار' => 6, 'الشعار' => 6, 'أدخل الاسم' => 7, 'السطر :position بدون رمز' => 7, 'أيام قليلة' => 8, 'الأيام' => 8,
            'الكمية' => 9, 'الرمز' => 10, 'الاسم مطلوب' => 22, 'اسم العميل' => 23,
        ], self::messagesIn($code));
    }

    public function test_validators_name_the_fields_and_give_custom_messages_in_the_interface_language(): void
    {
        $errors = fn (array $data, array $rules, array $messages = [], array $attributes = []) => Validator::make($data, $rules, $messages, $attributes)->errors()->all();

        app()->setLocale('en');
        $this->assertSame(['The name field is required.'], $errors([], ['name' => 'required'], [], ['name' => 'الاسم']));
        $this->assertSame(['The exchange rate field must be greater than 0.'], $errors(['rate' => 0], ['rate' => 'numeric|gt:0'], [], ['rate' => 'سعر الصرف']));
        $this->assertSame(['Please choose a logo file.'], $errors([], ['logo' => 'required'], ['logo.required' => 'يرجى اختيار ملف الشعار.']));
        $this->assertSame(['Bill of quantities: line 2 has no code.'], $errors(['boq' => [['code' => 'A'], ['code' => '']]], ['boq.*.code' => 'required'], ['boq.*.code.required' => 'جدول الكميات: السطر :position بدون رمز.']));
        // An Arabic name with no English entry is left out, so Laravel names the field itself.
        $this->assertSame(['The plate code field is required.'], $errors([], ['plate_code' => 'required'], [], ['plate_code' => 'اسم لم يُترجم بعد']));
        $this->assertSame(['The selected customer id does not exist in your company.'], $errors(['customer_id' => 5], ['customer_id' => 'tenant_exists:customers,id']));

        app()->setLocale('ar');
        $this->assertSame(['حقل الاسم مطلوب.'], $errors([], ['name' => 'required'], [], ['name' => 'الاسم']));
        $this->assertSame(['يرجى اختيار ملف الشعار.'], $errors([], ['logo' => 'required'], ['logo.required' => 'يرجى اختيار ملف الشعار.']));
    }

    public function test_the_login_and_forgot_password_pages_show_their_status_in_the_interface_language(): void
    {
        $timedOut = 'انتهت مهلة التحقق، سجّل الدخول مرة أخرى.';
        $this->withSession(['locale' => 'en', 'status' => $timedOut])->get('/login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'Verification timed out; sign in again.'));
        $this->withSession(['locale' => 'en', 'status' => 'إن كان البريد مسجلاً لدينا فستصلك رسالة بها رابط إعادة تعيين كلمة المرور.'])->get('/forgot-password')->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'If this email is registered with us, you will receive a message with a password reset link.'));
        $this->withSession(['locale' => 'ar', 'status' => $timedOut])->get('/login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', $timedOut));
    }

    /**
     * Arabic literals the code shows the user whole: flash and status messages (each branch of a ternary, ?? or match
     * too, and ->with(['success' => …])), abort/abort_if/abort_unless messages, $fail('…') in closure rules, and the
     * custom messages and attribute names a validator is given (validate(), Validator::make(), a FormRequest's
     * messages() and attributes()). Concatenated and interpolated messages are templates (lang/patterns.en.php).
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
        $ast = $parser->parse($code);
        $found = [];
        $calls = (new NodeFinder)->find($ast, fn (Node $node) => $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall);
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
            $found[] = [$call->getStartLine(), match (match (true) {
                $call instanceof Expr\FuncCall => $name,
                $call instanceof Expr\StaticCall => ($call->class instanceof Node\Name ? $call->class->getLast() : '').'::'.$name,
                default => "->{$name}",
            }) {
                '->with' => self::flashed($args),
                'abort' => self::branches($args[1] ?? null),
                'abort_if', 'abort_unless' => self::branches($args[2] ?? null),
                '$fail' => self::branches($args[0] ?? null),
                // validate($rules, $messages, $attributes); the others take the data (or the error bag) first.
                '->validate' => [...self::validatorTexts($ast, $args[1] ?? null), ...self::validatorTexts($ast, $args[2] ?? null)],
                '->validateWithBag', 'validator', 'Validator::make', 'Validator::validate' => [...self::validatorTexts($ast, $args[2] ?? null), ...self::validatorTexts($ast, $args[3] ?? null)],
                default => [],
            }];
        }
        foreach ((new NodeFinder)->find($ast, fn (Node $node) => $node instanceof Stmt\Class_ && $node->extends?->getLast() === 'FormRequest') as $request) {
            foreach (array_filter([$request->getMethod('messages'), $request->getMethod('attributes')]) as $method) {
                $found[] = [$method->getStartLine(), self::returnedTexts($ast, $method)];
            }
        }

        $messages = [];
        foreach ($found as [$line, $texts]) {
            foreach ($texts as $text) {
                if (preg_match('/\p{Arabic}/u', $text)) {
                    $messages[$text] ??= $line;
                }
            }
        }

        return $messages;
    }

    /**
     * The literals of a validator's custom messages or attribute names: an array (also one nested by field), arrays
     * joined with +, or $this->method() of the same file returning one.
     */
    private static function validatorTexts(array $ast, ?Node $node, int $depth = 0): array
    {
        return match (true) {
            $node instanceof Expr\Array_ => array_merge([], ...array_map(fn ($item) => $item?->value instanceof Expr\Array_ ? self::validatorTexts($ast, $item->value, $depth) : self::branches($item?->value), $node->items)),
            $node instanceof Expr\BinaryOp\Plus => [...self::validatorTexts($ast, $node->left, $depth), ...self::validatorTexts($ast, $node->right, $depth)],
            $node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier && $depth < 3 => self::returnedTexts(
                $ast, (new NodeFinder)->findFirst($ast, fn (Node $n) => $n instanceof Stmt\ClassMethod && $n->name->toLowerString() === $node->name->toLowerString()), $depth + 1),
            default => [],
        };
    }

    private static function returnedTexts(array $ast, ?Node $method, int $depth = 0): array
    {
        $returns = $method ? (new NodeFinder)->findInstanceOf($method, Stmt\Return_::class) : [];

        return array_merge([], ...array_map(fn (Stmt\Return_ $return) => self::validatorTexts($ast, $return->expr, $depth), $returns));
    }

    /** @param  list<Expr>  $args  of ->with('success', …), ->with($ok ? 'success' : 'error', …) or ->with(['success' => …]) */
    private static function flashed(array $args): array
    {
        $levels = ['success', 'error', 'warning', 'info', 'status'];
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
