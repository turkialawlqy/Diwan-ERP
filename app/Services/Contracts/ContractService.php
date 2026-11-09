<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\ContractVersion;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\Documents\DocumentNumberService;
use App\Services\Workflow\ApprovalService;
use App\Support\NightlyRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contract lifecycle: draft → approval (amount rules) → active → expired / terminated / renewed,
 * with a saved version for every change of the text, obligations with due dates, and a
 * nightly pass that activates, renews, expires and reminds.
 */
class ContractService
{
    public function __construct(protected DocumentNumberService $numbers, protected ApprovalService $approvals) {}

    /**
     * @param  array<int, array>  $obligations  rows of title, kind, due_date, amount, notes
     */
    public function create(array $data, array $obligations = []): Contract
    {
        return DB::transaction(function () use ($data, $obligations) {
            $data = $this->party($data);
            $contract = Contract::create($data + [
                'number' => $this->numbers->next('contract', Carbon::parse($data['start_date'])),
                'status' => 'draft', 'version' => 1, 'created_by' => auth()->id(),
            ]);
            $this->snapshot($contract, 'الإصدار الأول');
            $this->syncObligations($contract, $obligations);

            return $contract;
        });
    }

    /** Edit a draft / rejected contract; a changed text saves a new version. */
    public function update(Contract $contract, array $data, array $obligations = [], ?string $note = null): Contract
    {
        if (! $contract->isEditable()) {
            throw ValidationException::withMessages(['status' => 'لا يُعدَّل إلا العقد في حالة مسودة أو مرفوض؛ للعقد الساري أضف إصدارًا جديدًا من النص.']);
        }

        return DB::transaction(function () use ($contract, $data, $obligations, $note) {
            $before = $contract->body;
            $contract->update($this->party($data));
            if (($contract->body ?? '') !== ($before ?? '')) {
                $contract->increment('version');
                $this->snapshot($contract->refresh(), $note ?: 'تعديل النص');
            }
            $this->syncObligations($contract, $obligations);

            return $contract->refresh();
        });
    }

    /** A new text for a contract that is already running (approved / active): saved as the next version. */
    public function amend(Contract $contract, string $body, ?string $note = null): Contract
    {
        if (! in_array($contract->status, ['approved', 'active'], true)) {
            throw ValidationException::withMessages(['status' => 'يُضاف إصدار جديد للعقد المعتمد أو الساري فقط.']);
        }
        if (trim($body) === trim((string) $contract->body)) {
            throw ValidationException::withMessages(['body' => 'لم يتغير النص.']);
        }

        return DB::transaction(function () use ($contract, $body, $note) {
            $contract->update(['body' => $body, 'version' => $contract->version + 1]);
            $this->snapshot($contract, $note ?: 'تعديل النص');

            return $contract;
        });
    }

    /** Send for approval: a contract under every rule is approved at once. */
    public function submit(Contract $contract): string
    {
        if (! $contract->isEditable()) {
            throw ValidationException::withMessages(['status' => 'أُرسل العقد للاعتماد من قبل.']);
        }
        $status = $this->approvals->submit($contract, 'contract', (float) $contract->value, 'approved');
        $this->activateIfDue($contract->refresh());

        return $contract->status;
    }

    public function approve(Contract $contract, ?string $comment = null): void
    {
        $this->approvals->approve($contract, 'approved', $comment);
        $this->activateIfDue($contract->refresh());
    }

    public function reject(Contract $contract, ?string $comment = null): void
    {
        $this->approvals->reject($contract, $comment);
    }

    public function activate(Contract $contract): void
    {
        if ($contract->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'يُفعَّل العقد المعتمد فقط.']);
        }
        $contract->update(['status' => 'active', 'signed_at' => $contract->signed_at ?? today()]);
        if ($contract->parent_contract_id) {
            Contract::whereKey($contract->parent_contract_id)->whereIn('status', ['active', 'expired'])->update(['status' => 'renewed']);
        }
    }

    public function terminate(Contract $contract, string $reason): void
    {
        if (! in_array($contract->status, ['approved', 'active'], true)) {
            throw ValidationException::withMessages(['status' => 'لا يُفسخ إلا عقد معتمد أو ساري.']);
        }
        $contract->update(['status' => 'terminated', 'terminated_at' => today(), 'termination_reason' => $reason]);
    }

    /**
     * A new contract continuing this one for its renewal term. Renewed by hand it goes through the value
     * approval rules like any new contract; the automatic renewal (agreed in the contract) starts approved.
     * The old one keeps running until the new one starts, then becomes «مجدَّد».
     */
    public function renew(Contract $contract, bool $automatic = false): Contract
    {
        if (! in_array($contract->status, ['active', 'expired'], true)) {
            throw ValidationException::withMessages(['status' => 'يُجدَّد العقد الساري أو المنتهي فقط.']);
        }
        if (! $contract->end_date || ! $contract->renewal_term_months) {
            throw ValidationException::withMessages(['renewal_term_months' => 'حدّد تاريخ نهاية ومدة التجديد بالأشهر في العقد.']);
        }
        if (Contract::where('parent_contract_id', $contract->id)->whereNotIn('status', ['rejected', 'terminated'])->exists()) {
            throw ValidationException::withMessages(['status' => 'لهذا العقد تجديد قائم بالفعل.']);
        }

        return DB::transaction(function () use ($contract, $automatic) {
            $start = $contract->end_date->copy()->addDay();
            $end = $start->copy()->addMonthsNoOverflow((int) $contract->renewal_term_months)->subDay();

            $new = Contract::create([
                'branch_id' => $contract->branch_id, 'number' => $this->numbers->next('contract', $start),
                'title' => $contract->title, 'kind' => $contract->kind, 'party_type' => $contract->party_type,
                'customer_id' => $contract->customer_id, 'supplier_id' => $contract->supplier_id, 'template_id' => $contract->template_id,
                'project_id' => $contract->project_id, 'subscription_id' => $contract->subscription_id,
                'status' => $automatic ? 'approved' : 'draft', 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                'shared_with_customer' => $contract->shared_with_customer, 'requires_acceptance' => $contract->requires_acceptance,
                'value' => $contract->value, 'payment_terms' => $contract->payment_terms, 'auto_renew' => $contract->auto_renew,
                'renewal_term_months' => $contract->renewal_term_months, 'renewal_notice_days' => $contract->renewal_notice_days,
                'body' => $contract->body, 'version' => 1, 'parent_contract_id' => $contract->id, 'signed_at' => today(),
                'attachments' => $contract->attachments, 'created_by' => auth()->id() ?? $contract->created_by,
            ]);
            $this->snapshot($new, 'تجديد للعقد '.$contract->number);
            if ($automatic) {
                $this->activateIfDue($new);
            } else {
                $this->submit($new); // approval by value; activates on its start date
            }
            if ($contract->status === 'expired') {
                $contract->update(['status' => 'renewed']);
            }

            return $new->refresh();
        });
    }

    public function completeObligation(ContractObligation $obligation, bool $done = true): void
    {
        // Through the contract (branch scoped): another branch's obligation answers 404.
        $contract = Contract::findOrFail($obligation->contract_id);
        if (! in_array($contract->status, ['approved', 'active'], true)) {
            throw ValidationException::withMessages(['status' => 'التزامات العقد '.(Contract::STATUSES[$contract->status] ?? $contract->status).' لا تُعدَّل.']);
        }
        $obligation->update($done
            ? ['status' => 'done', 'done_at' => now(), 'done_by' => auth()->id()]
            : ['status' => 'pending', 'done_at' => null, 'done_by' => null, 'reminder_state' => null]);
    }

    /**
     * Nightly pass: activate approved contracts whose start arrived, renew or expire the ended ones,
     * and send expiry / obligation reminders once each.
     *
     * @return array<string,int>
     */
    public function nightly(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $done = ['activated' => 0, 'renewed' => 0, 'expired' => 0, 'reminders' => 0, 'failed' => 0];

        Contract::where('status', 'approved')->whereDate('start_date', '<=', $today)->lazyById(200)->each(NightlyRun::step(function (Contract $c) use (&$done) {
            $this->activate($c);
            $done['activated']++;
        }, $done));

        Contract::where('status', 'active')->whereNotNull('end_date')->whereDate('end_date', '<', $today)->lazyById(200)->each(NightlyRun::step(function (Contract $c) use (&$done) {
            if (Contract::where('parent_contract_id', $c->id)->whereNotIn('status', ['rejected', 'terminated'])->exists()) {
                $c->update(['status' => 'renewed']); // renewed ahead of time: the new contract takes over

                return;
            }
            if ($c->auto_renew && $c->renewal_term_months) {
                $new = $this->renew($c, true);
                $this->notify($c, 'contract_renewed', 'جُدِّد العقد '.$c->number, "العقد {$c->number} ({$c->title}) جُدِّد تلقائيًا بالعقد {$new->number}.", 'info', $new->id);
                $done['renewed']++;
            } else {
                $c->update(['status' => 'expired']);
                $this->notify($c, 'contract_expired', 'انتهى العقد '.$c->number, "انتهى العقد {$c->number} ({$c->title}) في ".$c->end_date->toDateString().'.', 'warning');
                $done['expired']++;
            }
        }, $done));

        Contract::with('customer:id,name', 'supplier:id,name')->where('status', 'active')->whereNotNull('end_date')->each(NightlyRun::step(function (Contract $c) use ($today, &$done) {
            $days = (int) $today->diffInDays($c->end_date, false);
            $steps = array_unique([(int) $c->renewal_notice_days, 7, 0]);
            $key = $c->end_date->toDateString().':d'.$days;
            if ($days >= 0 && in_array($days, $steps, true) && $c->reminder_state !== $key) {
                $when = $days === 0 ? 'اليوم' : "بعد {$days} يوم";
                $this->notify($c, 'contract_ending', "ينتهي العقد {$c->number} {$when}", $c->title.' — '.$c->partyName().($c->auto_renew ? ' (يتجدد تلقائيًا)' : ' (لن يتجدد)'), $c->auto_renew ? 'info' : 'warning');
                $c->update(['reminder_state' => $key]);
                $done['reminders']++;
            }
        }, $done));

        ContractObligation::with('contract:id,number,title,status')->where('status', 'pending')->whereHas('contract', fn ($q) => $q->whereIn('status', ['active', 'approved']))->each(NightlyRun::step(function (ContractObligation $o) use ($today, &$done) {
            $days = (int) $today->diffInDays($o->due_date, false);
            $step = $days < 0 ? 'late' : ($days === 0 ? 'd0' : ($days <= 3 ? 'd3' : null));
            if ($step && $o->reminder_state !== $step) {
                $title = $step === 'late' ? 'التزام متأخر: '.$o->title : 'التزام يستحق '.($days === 0 ? 'اليوم' : "بعد {$days} يوم").': '.$o->title;
                $this->notify($o->contract, 'contract_obligation', $title, "العقد {$o->contract->number} — {$o->contract->title}", $step === 'late' ? 'danger' : 'warning');
                $o->update(['reminder_state' => $step]);
                $done['reminders']++;
            }
        }, $done));

        return $done;
    }

    // ------------------------------------------------------------------

    /** Keep only the party that matches the contract's side. */
    protected function party(array $data): array
    {
        $side = $data['party_type'] ?? 'customer';
        if ($side === 'supplier') {
            if (empty($data['supplier_id'])) {
                throw ValidationException::withMessages(['supplier_id' => 'اختر المورد.']);
            }
            $data['customer_id'] = null;
        } else {
            if (empty($data['customer_id'])) {
                throw ValidationException::withMessages(['customer_id' => 'اختر العميل.']);
            }
            $data['supplier_id'] = null;
        }

        return $data;
    }

    protected function snapshot(Contract $contract, ?string $note): void
    {
        ContractVersion::create(['contract_id' => $contract->id, 'version' => $contract->version, 'body' => $contract->body, 'note' => $note, 'changed_by' => auth()->id(), 'created_at' => now()]);
    }

    /** Create / update / delete the contract's pending obligations to match the submitted rows. */
    protected function syncObligations(Contract $contract, array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $payload = ['title' => $row['title'], 'kind' => $row['kind'] ?? 'payment', 'due_date' => $row['due_date'], 'amount' => ($row['amount'] ?? '') === '' ? null : $row['amount'], 'notes' => $row['notes'] ?? null];
            if (! empty($row['id']) && ($existing = $contract->obligations()->whereKey($row['id'])->first())) {
                if ($existing->status === 'pending') {
                    $existing->update($payload + ['reminder_state' => null]);
                }
                $keep[] = $existing->id;
            } else {
                $keep[] = $contract->obligations()->create($payload)->id;
            }
        }
        $contract->obligations()->where('status', 'pending')->whereNotIn('id', $keep)->delete();
    }

    protected function notify(Contract $contract, string $kind, string $title, string $body, string $level, ?int $openId = null): void
    {
        $url = route('contracts.show', $openId ?? $contract->id, false);
        User::where('company_id', $contract->company_id)->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can('contracts.contracts.view'))
            ->each(fn (User $u) => $u->notify(new ErpAlert($kind, $title, $body, $url, $level)));
    }

    protected function activateIfDue(Contract $contract): void
    {
        if ($contract->status === 'approved' && $contract->start_date->lte(today())) {
            $this->activate($contract);
        }
    }
}
