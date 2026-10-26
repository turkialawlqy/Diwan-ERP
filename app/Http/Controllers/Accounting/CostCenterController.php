<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\JournalEntryLine;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CostCenterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/CostCenters/Index', [
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'parent_id', 'code', 'name_ar', 'name_en', 'is_active']),
        ]);
    }

    public function store(Request $request)
    {
        CostCenter::create($this->validated($request));

        return back()->with('success', 'تم إضافة مركز التكلفة');
    }

    public function update(Request $request, CostCenter $costCenter)
    {
        $data = $this->validated($request, $costCenter);

        if (($data['parent_id'] ?? null) == $costCenter->id) {
            return back()->with('error', 'لا يمكن جعل المركز تابعاً لنفسه');
        }

        $costCenter->update($data);

        return back()->with('success', 'تم تحديث مركز التكلفة');
    }

    public function destroy(CostCenter $costCenter)
    {
        if ($costCenter->children()->exists() || JournalEntryLine::where('cost_center_id', $costCenter->id)->exists()) {
            return back()->with('error', 'لا يمكن حذف مركز تكلفة عليه حركات أو مراكز فرعية. يمكنك إيقافه بدلاً من ذلك.');
        }

        $costCenter->delete();

        return back()->with('success', 'تم حذف مركز التكلفة');
    }

    protected function validated(Request $request, ?CostCenter $costCenter = null): array
    {
        return $request->validate([
            'parent_id' => 'nullable|tenant_exists:cost_centers,id',
            'code' => ['required', 'string', 'max:50', Rule::unique('cost_centers', 'code')->where('company_id', app(TenantManager::class)->getTenantId())->ignore($costCenter?->id)],
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);
    }
}
