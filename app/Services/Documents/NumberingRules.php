<?php

namespace App\Services\Documents;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «دليل الترميز»: the prefix, digits and starting number of every document type.
 *
 * A rule row (numbering_rules) applies from its valid_from_year on (0 = the base
 * rule); a type without a row uses the defaults in config/documents.php (or the
 * legacy "{type}_prefix" setting). A rule whose prefix has numbered a document is
 * locked: it can't be edited, only followed by a new rule from a later year.
 */
class NumberingRules
{
    public const MAX_PAD = 10;

    /** The rule that numbers a document of this type on this date. */
    public function resolve(string $type, int $companyId, ?CarbonInterface $date = null): array
    {
        $year = ($date ?? now())->year;
        $row = DB::table('numbering_rules')->where('company_id', $companyId)->where('type', $type)
            ->where('valid_from_year', '<=', $year)->orderByDesc('valid_from_year')->first();

        return $row ? $this->fromRow($row) : $this->defaults($type, $companyId);
    }

    /** Every document type with its rules (a virtual base rule when none is saved). */
    public function overview(Company $company, callable $moduleOn): array
    {
        $saved = DB::table('numbering_rules')->where('company_id', $company->id)->orderBy('valid_from_year')->get()->groupBy('type');
        $out = [];

        foreach (config('documents.types') as $type => $def) {
            if (! empty($def['module']) && ! $moduleOn($def['module'])) {
                continue;
            }
            $rows = $saved->get($type, collect())->map(fn ($r) => $this->fromRow($r))->all();
            if (! $rows || $rows[0]['valid_from_year'] !== 0) {
                array_unshift($rows, $this->defaults($type, $company->id));
            }
            $out[] = [
                'type' => $type, 'label' => $def['label'] ?? $type, 'area' => $def['area'] ?? 'other',
                'yearly' => (bool) $def['yearly'],
                'rules' => array_map(fn ($rule) => $rule + ['used' => $this->isUsed($company->id, $type, $rule), 'sample' => $this->sample($rule)], $rows),
            ];
        }

        return $out;
    }

    /** Change a rule that no document has used yet. */
    public function save(Company $company, string $type, int $validFromYear, array $input): void
    {
        $this->assertType($type);
        $rule = $this->find($company->id, $type, $validFromYear);
        if (! $rule) {
            throw ValidationException::withMessages(['rule' => 'القاعدة غير موجودة.']);
        }
        if ($this->isUsed($company->id, $type, $rule)) {
            throw ValidationException::withMessages(['prefix' => 'هذه البادئة استُخدمت في مستندات فلا يمكن تغييرها. أضف بادئة جديدة من سنة لاحقة.']);
        }

        $values = $this->validated($company->id, $type, $input, $validFromYear);
        $this->write($company->id, $type, $validFromYear, $values);
        $this->forgetSequences($company->id, $type, $validFromYear);
    }

    /** A new rule for documents dated from $year on (after the used ones). */
    public function addFromYear(Company $company, string $type, int $year, array $input): void
    {
        $this->assertType($type);
        $def = config("documents.types.{$type}");
        if (! $def['yearly']) {
            throw ValidationException::withMessages(['year' => 'هذا النوع لا يتجدد سنويًا، فلا تُضاف له بادئة جديدة.']);
        }
        $current = $this->resolve($type, $company->id);
        if (! $current['with_year']) {
            throw ValidationException::withMessages(['year' => 'الترقيم لهذا النوع لا يتضمن السنة فلا يمكن بدء بادئة جديدة من سنة.']);
        }
        if ($year < now()->year) {
            throw ValidationException::withMessages(['year' => 'السنة يجب ألا تكون في الماضي.']);
        }
        if ($year <= $this->lastUsedYear($company->id, $type)) {
            throw ValidationException::withMessages(['year' => 'السنة يجب أن تكون بعد آخر سنة صدرت فيها مستندات من هذا النوع.']);
        }
        if ($this->find($company->id, $type, $year)) {
            throw ValidationException::withMessages(['year' => 'توجد قاعدة لهذه السنة، عدّلها بدل إضافة جديدة.']);
        }

        $values = $this->validated($company->id, $type, $input, $year);
        if ($values['prefix'] === $current['prefix']) {
            throw ValidationException::withMessages(['prefix' => 'البادئة الجديدة مطابقة للحالية.']);
        }
        $this->write($company->id, $type, $year, $values);
    }

    /** Remove a future rule nobody used. */
    public function destroy(Company $company, string $type, int $validFromYear): void
    {
        $rule = $validFromYear > 0 ? $this->find($company->id, $type, $validFromYear) : null;
        if (! $rule) {
            throw ValidationException::withMessages(['rule' => 'لا يمكن حذف هذه القاعدة.']);
        }
        if ($this->isUsed($company->id, $type, $rule)) {
            throw ValidationException::withMessages(['rule' => 'القاعدة استُخدمت فلا تُحذف.']);
        }
        DB::table('numbering_rules')->where(['company_id' => $company->id, 'type' => $type, 'valid_from_year' => $validFromYear])->delete();
        $this->forgetSequences($company->id, $type, $validFromYear);
    }

    /** True once a document (or a consumed number) carries this rule's prefix. */
    public function isUsed(int $companyId, string $type, array $rule): bool
    {
        $def = config("documents.types.{$type}");

        if (! empty($def['table'])) {
            $like = addcslashes($rule['prefix'], '%_\\').'%';
            if (DB::table($def['table'])->where('company_id', $companyId)->where($def['column'], 'like', $like)->exists()) {
                return true;
            }
        }

        return $this->sequenceRows($companyId, $type, $rule['valid_from_year'])
            ->contains(fn ($row) => (int) $row->next_number > (int) $rule['start_number']);
    }

    public function sample(array $rule, ?int $number = null): string
    {
        $prefix = $rule['with_year'] ? $rule['prefix'].now()->format('y').'-' : $rule['prefix'];

        return $prefix.str_pad((string) ($number ?? $rule['start_number']), $rule['pad'], '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------

    protected function defaults(string $type, int $companyId): array
    {
        $def = config("documents.types.{$type}");
        $legacy = trim((string) DB::table('company_settings')->where('company_id', $companyId)->where('key', "{$type}_prefix")->value('value'));

        return [
            'id' => null, 'type' => $type, 'valid_from_year' => 0,
            'prefix' => $legacy !== '' ? $legacy : $def['prefix'], 'pad' => (int) $def['pad'],
            'start_number' => 1, 'with_year' => (bool) $def['yearly'],
        ];
    }

    protected function fromRow(object $row): array
    {
        return [
            'id' => $row->id, 'type' => $row->type, 'valid_from_year' => (int) $row->valid_from_year,
            'prefix' => $row->prefix, 'pad' => (int) $row->pad, 'start_number' => (int) $row->start_number, 'with_year' => (bool) $row->with_year,
        ];
    }

    protected function find(int $companyId, string $type, int $year): ?array
    {
        $row = DB::table('numbering_rules')->where(['company_id' => $companyId, 'type' => $type, 'valid_from_year' => $year])->first();
        if ($row) {
            return $this->fromRow($row);
        }

        return $year === 0 ? $this->defaults($type, $companyId) : null;
    }

    protected function assertType(string $type): void
    {
        if (! config("documents.types.{$type}")) {
            throw ValidationException::withMessages(['type' => 'نوع غير معروف.']);
        }
    }

    protected function validated(int $companyId, string $type, array $input, int $year): array
    {
        $def = config("documents.types.{$type}");
        $prefix = trim((string) ($input['prefix'] ?? ''));
        $pad = (int) ($input['pad'] ?? $def['pad']);
        $start = (int) ($input['start_number'] ?? 1);
        $withYear = $def['yearly'] ? (bool) ($input['with_year'] ?? true) : false;

        if ($prefix === '' || strlen($prefix) > 10 || ! preg_match('/^[A-Za-z0-9\-\/]+$/', $prefix)) {
            throw ValidationException::withMessages(['prefix' => 'البادئة من 1 إلى 10 خانات: أحرف إنجليزية وأرقام و - و /.']);
        }
        if ($pad < 1 || $pad > self::MAX_PAD) {
            throw ValidationException::withMessages(['pad' => 'عدد الخانات من 1 إلى '.self::MAX_PAD.'.']);
        }
        if ($start < 1 || $start > 999999999) {
            throw ValidationException::withMessages(['start_number' => 'الرقم الابتدائي غير صالح.']);
        }

        // Types that number the same column (فاتورة مبيعات / فاتورة مبسطة) can't share a prefix.
        foreach (config('documents.types') as $other => $otherDef) {
            if ($other === $type || empty($def['table']) || $otherDef['table'] !== $def['table'] || $otherDef['column'] !== $def['column']) {
                continue;
            }
            $theirs = $this->resolve($other, $companyId, now()->setYear(max($year, now()->year)))['prefix'];
            if (str_starts_with($theirs, $prefix) || str_starts_with($prefix, $theirs)) {
                throw ValidationException::withMessages(['prefix' => 'تتعارض البادئة مع بادئة «'.($otherDef['label'] ?? $other).'» ('.$theirs.') لأنهما في سجل واحد.']);
            }
        }

        return ['prefix' => $prefix, 'pad' => $pad, 'start_number' => $start, 'with_year' => $withYear];
    }

    protected function write(int $companyId, string $type, int $year, array $values): void
    {
        DB::table('numbering_rules')->updateOrInsert(
            ['company_id' => $companyId, 'type' => $type, 'valid_from_year' => $year],
            $values + ['updated_at' => now(), 'created_at' => now()],
        );
        // The legacy setting is now superseded by the rule.
        DB::table('company_settings')->where('company_id', $companyId)->where('key', "{$type}_prefix")->delete();
        Cache::forget(Company::settingsCacheKey($companyId));
    }

    /** Sequence rows of a rule's year range (it ends where the next rule starts). */
    protected function sequenceRows(int $companyId, string $type, int $fromYear)
    {
        $next = DB::table('numbering_rules')->where(['company_id' => $companyId, 'type' => $type])->where('valid_from_year', '>', $fromYear)->min('valid_from_year');

        return DB::table('document_sequences')->where(['company_id' => $companyId, 'type' => $type])->get()->filter(function ($row) use ($fromYear, $next) {
            if ($row->scope === 'all') {
                return $fromYear === 0;
            }
            $y = (int) $row->scope;

            return $y >= $fromYear && ($next === null || $y < (int) $next);
        });
    }

    protected function forgetSequences(int $companyId, string $type, int $fromYear): void
    {
        $ids = $this->sequenceRows($companyId, $type, $fromYear)->pluck('id')->all();
        if ($ids) {
            DB::table('document_sequences')->whereIn('id', $ids)->delete();
        }
    }

    protected function lastUsedYear(int $companyId, string $type): int
    {
        $years = DB::table('document_sequences')->where(['company_id' => $companyId, 'type' => $type])->where('scope', '!=', 'all')
            ->where('next_number', '>', 1)->pluck('scope')->map(fn ($s) => (int) $s);

        return (int) ($years->max() ?? 0);
    }
}
