<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Services\Workflow\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ApprovalRuleController extends Controller
{
    public function index()
    {
        return Inertia::render('Settings/ApprovalRules', [
            'rules' => ApprovalRule::orderBy('document_type')->orderBy('min_amount')->get(),
            'types' => ApprovalService::DOCUMENT_TYPES,
            'recent' => Approval::with('user:id,name')->latest('id')->limit(30)->get(),
            'requests' => ApprovalRequest::with('requester:id,name')->where('status', 'pending')->orderBy('id')->get()
                ->map(fn ($r) => $r->toArray() + ['can_decide' => auth()->user()->can(ApprovalService::APPROVE_PERMISSIONS[$r->document_type] ?? 'admin.approvals.manage')]),
        ]);
    }

    public function store(Request $request)
    {
        ApprovalRule::create($request->validate([
            'document_type' => ['required', Rule::in(array_keys(ApprovalService::DOCUMENT_TYPES))],
            'min_amount' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]));

        return back()->with('success', 'تم حفظ قاعدة الاعتماد');
    }

    public function destroy(ApprovalRule $rule)
    {
        $rule->delete();

        return back()->with('success', 'تم حذف قاعدة الاعتماد');
    }

    /** An approver accepts or refuses a request raised by the approval gate (never his own, unless he owns the company). */
    public function decide(Request $request, ApprovalRequest $approvalRequest, string $decision)
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true) && $approvalRequest->status === 'pending', 404);
        abort_unless($request->user()->can(ApprovalService::APPROVE_PERMISSIONS[$approvalRequest->document_type] ?? 'admin.approvals.manage'), 403);
        if ((int) $approvalRequest->requested_by === (int) auth()->id() && ! $request->user()->isCompanyOwner()) {
            return back()->withErrors(['approval' => 'لا يمكنك اعتماد طلب قمت به.']);
        }

        $approvalRequest->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $request->input('note')]);

        return back()->with('success', $decision === 'approve' ? 'اعتُمد الطلب؛ يعيد صاحبه الإجراء الآن.' : 'رُفض الطلب.');
    }
}
