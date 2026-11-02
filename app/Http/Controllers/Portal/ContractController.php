<?php

namespace App\Http\Controllers\Portal;

use App\Models\ContractAcceptance;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Contracts staff shared with the customer, and his acceptance of the current version. */
class ContractController extends PortalController
{
    public function index(Request $request)
    {
        $this->needs($request, 'contracts');
        $contracts = $this->scope()->contracts()->withCount(['acceptances as accepted_current' => fn ($q) => $q->whereColumn('contract_acceptances.version', 'contracts.version')])
            ->latest('id')->paginate(20, ['id', 'number', 'title', 'status', 'start_date', 'end_date', 'value', 'version', 'requires_acceptance'])->withQueryString();

        return $this->page($request, 'Portal/Contracts', ['contracts' => $contracts]);
    }

    public function show(Request $request, int $company, int $contract)
    {
        $this->needs($request, 'contracts');
        $contract = $this->scope()->contracts()->with(['obligations:id,contract_id,title,kind,due_date,amount,status', 'acceptances.portalUser:id,name'])->findOrFail($contract);
        $current = $contract->acceptances->where('version', $contract->version);

        return $this->page($request, 'Portal/ContractShow', [
            'contract' => $contract->only(['id', 'number', 'title', 'kind', 'status', 'start_date', 'end_date', 'value', 'payment_terms', 'body', 'version', 'requires_acceptance', 'attachments']) + ['obligations' => $contract->obligations],
            'acceptances' => $contract->acceptances->map(fn ($a) => ['name' => $a->name, 'version' => $a->version, 'accepted_at' => $a->accepted_at])->values(),
            'accepted' => $current->isNotEmpty(),
            'canAccept' => $contract->requires_acceptance && in_array($contract->status, ['approved', 'active'], true) && $current->isEmpty(),
            'defaultName' => auth('portal')->user()->name,
        ]);
    }

    public function accept(Request $request, int $company, int $contract)
    {
        $this->needs($request, 'contracts');
        $contract = $this->scope()->contracts()->findOrFail($contract);
        $data = $request->validate(['name' => 'required|string|min:3|max:255', 'agree' => 'accepted'], [], ['name' => 'الاسم', 'agree' => 'الموافقة']);

        if (! $contract->requires_acceptance || ! in_array($contract->status, ['approved', 'active'], true)) {
            throw ValidationException::withMessages(['agree' => 'هذا العقد لا ينتظر قبولك.']);
        }
        if ($contract->acceptances()->where('version', $contract->version)->exists()) {
            throw ValidationException::withMessages(['agree' => 'سبق قبول هذا الإصدار من العقد.']);
        }

        ContractAcceptance::create([
            'contract_id' => $contract->id, 'portal_user_id' => auth('portal')->id(), 'name' => $data['name'], 'version' => $contract->version,
            'body_hash' => hash('sha256', (string) $contract->body), 'accepted_at' => now(), 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return back()->with('success', 'سُجّل قبولك للعقد '.$contract->number);
    }
}
