<?php

namespace App\Http\Controllers;

use App\Models\Approval;
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
}
