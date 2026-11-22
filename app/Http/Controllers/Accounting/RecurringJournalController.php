<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\CostCenter;
use App\Models\RecurringJournal;
use App\Services\Accounting\PostingService;
use App\Services\ActiveBranch;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecurringJournalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/Recurring/Index', [
            'templates' => RecurringJournal::orderBy('next_run_date')->get(),
            'accounts' => Account::where('is_parent', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:1000',
            'frequency' => 'required|in:monthly,quarterly,yearly',
            'next_run_date' => 'required|date',
            'end_date' => 'nullable|date|after:next_run_date',
            'lines' => 'required|array|min:2|max:100',
            'lines.*.account_id' => 'required|tenant_exists:accounts,id',
            'lines.*.cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
            'auto_reverse' => 'boolean',
        ]);

        $debit = round(collect($validated['lines'])->sum('debit'), 2);
        $credit = round(collect($validated['lines'])->sum('credit'), 2);
        if ($debit !== $credit || $debit <= 0) {
            throw ValidationException::withMessages(['lines' => 'القيد غير متوازن.']);
        }
        // Its entries are filed in its branch (the top bar's) and posted unattended, so no line may move another branch's box,
        // whoever schedules it (PostingService refuses such a line when the template runs, see RecurringJournalRunner).
        $otherBranchBoxes = BankAccount::foreignLedgers(app(ActiveBranch::class)->forWrite(), array_column($validated['lines'], 'account_id'));
        if ($otherBranchBoxes) {
            throw ValidationException::withMessages(collect($validated['lines'])->filter(fn ($line) => in_array((int) $line['account_id'], $otherBranchBoxes, true))
                ->mapWithKeys(fn ($line, $i) => ["lines.{$i}.account_id" => 'هذا الحساب مربوط بصندوق أو بنك لفرع آخر.'])->all());
        }
        // Nor a line its runs would have refused, each run recorded as failed and skipped: on a group account, a switched-off
        // one, or one that requires a cost center the line does not carry (the form lists only active posting accounts).
        $accounts = Account::whereIn('id', array_column($validated['lines'], 'account_id'))->get()->keyBy('id');
        $refused = [];
        foreach ($validated['lines'] as $i => $line) {
            $account = $accounts->get((int) $line['account_id']);
            if ($account && ($problem = PostingService::lineProblem($account, $line['cost_center_id'] ?? null))) {
                $refused["lines.{$i}.".($problem === 'cost_center' ? 'cost_center_id' : 'account_id')] = PostingService::lineMessage($account, $problem);
            }
        }
        if ($refused) {
            throw ValidationException::withMessages($refused);
        }

        RecurringJournal::create(array_merge($validated, ['is_active' => true, 'created_by' => auth()->id()]));

        return back()->with('success', 'تم إنشاء القيد المتكرر');
    }

    public function update(Request $request, RecurringJournal $recurring)
    {
        $validated = $request->validate(['is_active' => 'required|boolean']);
        if ($validated['is_active'] && ! $recurring->is_active && ($problem = $this->reactivationProblem($recurring))) {
            return back()->with('error', $problem);
        }
        $recurring->update($validated);

        return back()->with('success', $recurring->is_active ? 'تم تفعيل القيد المتكرر' : 'تم إيقاف القيد المتكرر');
    }

    /**
     * Why a stopped entry may not be switched on again, or null: its next run would refuse one of its lines as saved (an
     * entry's lines are not edited). Once its entries are stopped, the chart lets an account be switched off, made a group
     * or made to need a cost center (AccountController::settingsProblem); a line's account deleted by older data, too.
     */
    protected function reactivationProblem(RecurringJournal $recurring): ?string
    {
        $lines = (array) $recurring->lines;
        $accounts = Account::whereIn('id', array_column($lines, 'account_id'))->get()->keyBy('id');
        foreach ($lines as $line) {
            $account = $accounts->get((int) ($line['account_id'] ?? 0));
            if (! $account) {
                return 'لا يمكن تفعيل القيد المتكرر لأن أحد حسابات أسطره حُذف من الدليل؛ أنشئ قيدًا متكررًا جديدًا.';
            }
            $name = "«{$account->code} - {$account->name_ar}»";
            $problem = PostingService::lineProblem($account, $line['cost_center_id'] ?? null);
            if ($problem) {
                return match ($problem) {
                    'group' => "لا يمكن تفعيل القيد المتكرر لأن الحساب {$name} في أسطره حساب رئيسي؛ أنشئ قيدًا متكررًا جديدًا بحساب فرعي.",
                    'switched_off' => "لا يمكن تفعيل القيد المتكرر لأن الحساب {$name} في أسطره موقوف؛ فعّله من «دليل الحسابات» أولًا.",
                    'cost_center' => "لا يمكن تفعيل القيد المتكرر لأن الحساب {$name} في أسطره يتطلب مركز تكلفة وسطره بلا مركز تكلفة؛ أنشئ قيدًا متكررًا جديدًا بمركز تكلفة للسطر.",
                };
            }
        }

        return null;
    }

    public function destroy(RecurringJournal $recurring)
    {
        $recurring->delete();

        return back()->with('success', 'تم حذف القيد المتكرر');
    }
}
