<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Services\HR\BankSalaryFormats;
use App\Services\HR\LeaveService;
use App\Services\Settings\CompanyPolicy;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Company specific payroll / labour rules (GOSI rates, overtime, leave,
 * end of service). Percentages are shown as % and stored as fractions.
 */
class HrSettingsController extends Controller
{
    public function __construct(protected CompanyPolicy $policy, protected TenantManager $tenantManager) {}

    public function show(LeaveService $leaves)
    {
        $company = $this->tenantManager->getCompany();
        $leaves->ensureTypes($company);

        $groups = [];
        foreach (CompanyPolicy::GROUPS as $group) {
            $groups[$group] = [
                'value' => $this->toForm($group, $this->policy->group($group, $company)),
                'default' => $this->toForm($group, config($group)),
                'overridden' => $this->policy->isOverridden($group, $company),
            ];
        }

        return Inertia::render('HR/Settings', [
            'groups' => $groups,
            'leaveTypes' => LeaveType::orderBy('id')->get(),
            'bankFormats' => collect(BankSalaryFormats::FORMATS)->map(fn ($f) => $f['label']),
            'holidays' => PublicHoliday::orderByDesc('start_date')->limit(60)->get(),
        ]);
    }

    public function update(Request $request, string $group)
    {
        abort_unless(in_array($group, CompanyPolicy::GROUPS, true), 404);
        $value = $this->fromForm($group, $this->validateGroup($group, (array) $request->input('value', [])));
        $this->policy->set($this->tenantManager->getCompany(), $group, $value);

        return back()->with('success', 'تم حفظ الإعداد؛ يُطبّق على المسيرات والاحتسابات الجديدة.');
    }

    public function reset(string $group)
    {
        abort_unless(in_array($group, CompanyPolicy::GROUPS, true), 404);
        $this->policy->reset($this->tenantManager->getCompany(), $group);

        return back()->with('success', 'تمت استعادة القيمة النظامية الافتراضية.');
    }

    public function storeHoliday(Request $request)
    {
        PublicHoliday::create($request->validate([
            'name' => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date|before_or_equal:'.Carbon::parse((string) $request->input('start_date'))->addDays(30)->toDateString(),
        ]));

        return back()->with('success', 'تمت إضافة العطلة؛ لا تُخصم أيامها من الإجازات السنوية الجديدة ولا تُسجّل غياباً.');
    }

    public function destroyHoliday(PublicHoliday $holiday)
    {
        $holiday->delete();

        return back()->with('success', 'تم حذف العطلة');
    }

    public function updateLeaveType(Request $request, LeaveType $leaveType)
    {
        $leaveType->update($request->validate([
            'name' => 'required|string|max:100',
            'days_per_year' => 'required|integer|min:0|max:365',
            'pay_rule' => 'required|in:full,sick_tiers,unpaid',
            'deducts_balance' => 'boolean',
            'is_active' => 'boolean',
        ]));

        return back()->with('success', 'تم تحديث نوع الإجازة');
    }

    /** Scalars / structures in display units (percentages as %). */
    protected function toForm(string $group, mixed $value): mixed
    {
        $pct = fn ($v) => round((float) $v * 100, 4);

        return match ($group) {
            'hr.overtime_premium', 'hr.max_deduction_ratio' => ['value' => $pct($value)],
            'hr.month_days', 'hr.hours_per_day' => ['value' => $value],
            'hr.gosi' => [
                'saudi_employee_rate' => $pct($value['saudi_employee_rate']),
                'saudi_employer_rate' => $pct($value['saudi_employer_rate']),
                'non_saudi_employee_rate' => $pct($value['non_saudi_employee_rate']),
                'non_saudi_employer_rate' => $pct($value['non_saudi_employer_rate']),
                'base_annuity_rate' => $pct($value['base_annuity_rate'] ?? 0.09),
                'min_wage' => $value['min_wage'],
                'max_wage' => $value['max_wage'],
                'new_law_start' => $value['new_law_start'],
                'new_entrant_annuity' => collect($value['new_entrant_annuity'] ?? [])->map(fn ($rate, $from) => ['from' => $from, 'rate' => $pct($rate)])->values()->all(),
            ],
            'hr.sick_tiers' => ['tiers' => array_map(fn ($t) => ['days' => $t[0], 'pay' => $pct($t[1])], $value)],
            'hr.end_of_service' => [
                'first_years' => $value['first_years'],
                'first_rate' => $value['first_rate'],
                'later_rate' => $value['later_rate'],
                'resignation' => array_map(fn ($t) => ['below' => $t[0], 'share' => $pct($t[1])], $value['resignation']),
            ],
            'hr.annual_leave', 'hr.wps', 'hr.late_deduction' => $value,
        };
    }

    protected function fromForm(string $group, array $v): mixed
    {
        $frac = fn ($x) => round((float) $x / 100, 6);

        return match ($group) {
            'hr.overtime_premium', 'hr.max_deduction_ratio' => $frac($v['value']),
            'hr.month_days' => (int) $v['value'],
            'hr.hours_per_day' => (float) $v['value'],
            'hr.gosi' => [
                'saudi_employee_rate' => $frac($v['saudi_employee_rate']),
                'saudi_employer_rate' => $frac($v['saudi_employer_rate']),
                'non_saudi_employee_rate' => $frac($v['non_saudi_employee_rate']),
                'non_saudi_employer_rate' => $frac($v['non_saudi_employer_rate']),
                'base_annuity_rate' => $frac($v['base_annuity_rate']),
                'min_wage' => (float) $v['min_wage'],
                'max_wage' => (float) $v['max_wage'],
                'new_law_start' => $v['new_law_start'],
                'new_entrant_annuity' => collect($v['new_entrant_annuity'] ?? [])->sortBy('from')->mapWithKeys(fn ($r) => [$r['from'] => $frac($r['rate'])])->all(),
            ],
            'hr.sick_tiers' => array_map(fn ($t) => [(int) $t['days'], $frac($t['pay'])], $v['tiers']),
            'hr.end_of_service' => [
                'first_years' => (float) $v['first_years'],
                'first_rate' => (float) $v['first_rate'],
                'later_rate' => (float) $v['later_rate'],
                'resignation' => collect($v['resignation'])->sortBy('below')->map(fn ($t) => [(float) $t['below'], $frac($t['share'])])->values()->all(),
            ],
            'hr.annual_leave' => ['days' => (int) $v['days'], 'senior_days' => (int) $v['senior_days'], 'senior_after_years' => (int) $v['senior_after_years']],
            'hr.late_deduction' => ['enabled' => (bool) ($v['enabled'] ?? false), 'monthly_grace_minutes' => (int) $v['monthly_grace_minutes']],
            'hr.wps' => [
                'mol_establishment_id' => trim((string) ($v['mol_establishment_id'] ?? '')),
                'gosi_establishment_id' => trim((string) ($v['gosi_establishment_id'] ?? '')),
                'company_iban' => strtoupper(str_replace(' ', '', (string) ($v['company_iban'] ?? ''))),
                'bank_format' => $v['bank_format'],
            ],
        };
    }

    protected function validateGroup(string $group, array $value): array
    {
        $pct = 'required|numeric|min:0|max:100';
        $rules = match ($group) {
            'hr.month_days' => ['value' => 'required|integer|between:28,31'],
            'hr.hours_per_day' => ['value' => 'required|numeric|between:1,12'],
            'hr.overtime_premium' => ['value' => 'required|numeric|min:0|max:200'],
            'hr.max_deduction_ratio' => ['value' => $pct],
            'hr.gosi' => [
                'saudi_employee_rate' => $pct, 'saudi_employer_rate' => $pct, 'non_saudi_employee_rate' => $pct, 'non_saudi_employer_rate' => $pct,
                'base_annuity_rate' => $pct, 'min_wage' => 'required|numeric|min:0', 'max_wage' => 'required|numeric|gt:min_wage',
                'new_law_start' => 'required|date', 'new_entrant_annuity' => 'nullable|array|max:20',
                'new_entrant_annuity.*.from' => 'required|date|distinct', 'new_entrant_annuity.*.rate' => $pct,
            ],
            'hr.sick_tiers' => ['tiers' => 'required|array|min:1|max:6', 'tiers.*.days' => 'required|integer|min:1|max:365', 'tiers.*.pay' => $pct],
            'hr.end_of_service' => [
                'first_years' => 'required|numeric|min:0|max:50', 'first_rate' => 'required|numeric|min:0|max:3', 'later_rate' => 'required|numeric|min:0|max:3',
                'resignation' => 'required|array|max:10', 'resignation.*.below' => 'required|numeric|min:0|max:60|distinct', 'resignation.*.share' => $pct,
            ],
            'hr.annual_leave' => ['days' => 'required|integer|min:21|max:60', 'senior_days' => 'required|integer|gte:days|max:60', 'senior_after_years' => 'required|integer|min:1|max:30'],
            'hr.late_deduction' => ['enabled' => 'boolean', 'monthly_grace_minutes' => 'required|integer|min:0|max:6000'],
            'hr.wps' => [
                'mol_establishment_id' => 'nullable|string|max:30', 'gosi_establishment_id' => 'nullable|string|max:30',
                'company_iban' => ['nullable', 'string', function ($attribute, $iban, $fail) {
                    if (! BankSalaryFormats::validIban($iban)) {
                        $fail('رقم الآيبان غير صحيح: 24 خانة تبدأ بـ SA.');
                    }
                }],
                'bank_format' => ['required', Rule::in(array_keys(BankSalaryFormats::FORMATS))],
            ],
        };

        $validator = Validator::make($value, $rules, [
            'days.min' => 'نظام العمل يوجب 21 يوماً على الأقل للإجازة السنوية.',
            'max_wage.gt' => 'الحد الأعلى للأجر يجب أن يتجاوز الحد الأدنى.',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($m, $k) => ['value.'.$k => $m])->all());
        }

        return $validator->validated();
    }
}
