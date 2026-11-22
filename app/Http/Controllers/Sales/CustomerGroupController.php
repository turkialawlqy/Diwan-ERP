<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\CustomerGroup;
use App\Models\JournalEntryLine;
use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Customer groups; a group may post its customers' receivables to its own ledger account. */
class CustomerGroupController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:active,inactive,all']);
        $status = $filters['status'] ?? 'active';

        $groups = CustomerGroup::query()->with('account:id,code,name_ar')
            ->withCount(['customers', 'customers as active_customers_count' => fn ($q) => $q->where('status', 'active')])
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('code')->get();

        return Inertia::render('Sales/CustomerGroups/Index', [
            'groups' => $groups,
            'total' => CustomerGroup::count(),
            'active' => CustomerGroup::where('is_active', true)->count(),
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/CustomerGroups/Form', ['group' => null] + $this->lookups());
    }

    public function edit(CustomerGroup $group): Response
    {
        return Inertia::render('Sales/CustomerGroups/Form', ['group' => $group] + $this->lookups($group));
    }

    public function store(Request $request)
    {
        $group = CustomerGroup::create($this->validated($request));

        if ($json = $this->createdResponse($request, $group, $group->name)) {
            return $json;
        }

        return redirect()->route('sales.customer-groups.index')->with('success', 'تمت إضافة مجموعة العملاء');
    }

    public function update(Request $request, CustomerGroup $group)
    {
        $data = $this->validated($request, $group);

        // Customers already posted to the account they use now (the group's, else the general receivables): moving the
        // group would split their balances. A group that had no account of its own is no exception.
        $general = app(AccountMap::class)->find('receivables');
        $current = (int) ($group->account_id ?: $general);
        if ($current && $current !== (int) (($data['account_id'] ?? null) ?: $general)
            && JournalEntryLine::where('account_id', $current)->where('party_type', 'customer')->whereIn('party_id', $group->customers()->pluck('id'))->exists()) {
            throw ValidationException::withMessages(['account_id' => 'لا يمكن تغيير حساب المجموعة بعد وجود حركات لعملائها عليه.']);
        }

        $group->update($data);

        return redirect()->route('sales.customer-groups.index')->with('success', 'تم تحديث مجموعة العملاء');
    }

    public function destroy(CustomerGroup $group)
    {
        if ($group->customers()->exists()) {
            return back()->with('error', 'لا يمكن حذف مجموعة فيها عملاء؛ انقلهم إلى مجموعة أخرى أولاً.');
        }
        $group->delete();

        return redirect()->route('sales.customer-groups.index')->with('success', 'تم حذف المجموعة');
    }

    /** Only accounts validated() accepts (CustomerGroup::accountOptions), the group's own included. */
    protected function lookups(?CustomerGroup $group = null): array
    {
        return ['accounts' => CustomerGroup::accountOptions($group)->orderBy('code')->get(['id', 'code', 'name_ar'])];
    }

    protected function validated(Request $request, ?CustomerGroup $group = null): array
    {
        $companyId = app(TenantManager::class)->getTenantId();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('customer_groups', 'code')->where('company_id', $companyId)->ignore($group?->id)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            // Receipts and credit sales of the group's customers in every branch post here: a receivables account, never a
            // box's ledger nor another account the system posts to (default cash, input VAT...).
            'account_id' => ['nullable', 'tenant_exists:accounts,id', function ($attribute, $value, $fail) {
                $account = $value ? Account::find($value) : null;
                $roles = collect(app(AccountMap::class)->all())->except('receivables')->filter()->map(fn ($id) => (int) $id)->all();
                if ($account && ($account->type !== 'asset' || $account->is_parent)) {
                    $fail('اختر حسابًا فرعيًا من الأصول (ذمم العملاء).');
                } elseif ($account && (BankAccount::boxLedgers([$account->id]) || in_array((int) $account->id, $roles, true))) {
                    $fail('هذا الحساب حساب صندوق أو بنك أو حساب آلي آخر؛ اختر حساب ذمم عملاء.');
                }
            }],
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
