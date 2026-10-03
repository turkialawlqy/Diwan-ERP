<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\LetterOfGuarantee;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Letters of guarantee (bid, performance, advance payment, retention).
 */
class GuaranteeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:active,released,expired,liquidated', 'type' => 'nullable|in:bid,performance,advance_payment,retention']);

        return Inertia::render('Projects/Guarantees', [
            'guarantees' => LetterOfGuarantee::with('project:id,code,name')
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
                ->orderBy('expiry_date')->paginate($this->perPage($request))->withQueryString(),
            'totals' => LetterOfGuarantee::where('status', 'active')->selectRaw('type, SUM(amount) as amount, SUM(cash_margin) as margin')->groupBy('type')->get(),
            'projects' => Project::whereIn('status', ['planning', 'active', 'on_hold'])->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request)
    {
        LetterOfGuarantee::create($this->validated($request));

        return back()->with('success', 'تم تسجيل خطاب الضمان');
    }

    public function update(Request $request, LetterOfGuarantee $guarantee)
    {
        $guarantee->update($this->validated($request) + $request->validate(['status' => 'required|in:active,released,expired,liquidated']));

        return back()->with('success', 'تم تحديث خطاب الضمان');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'project_id' => 'nullable|tenant_exists:projects,id',
            'type' => 'required|in:bid,performance,advance_payment,retention',
            'number' => 'required|string|max:100',
            'bank_name' => 'required|string|max:255',
            'beneficiary' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'cash_margin' => 'nullable|numeric|min:0',
            'issue_date' => 'required|date',
            'expiry_date' => 'required|date|after:issue_date',
            'notes' => 'nullable|string|max:2000',
        ]);
    }
}
