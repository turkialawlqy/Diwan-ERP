<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\Sectors\SectorPackService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «حزم القطاعات»: preview and apply a sector's ready-made setup. */
class SectorPackController extends Controller
{
    public function __construct(protected SectorPackService $packs, protected TenantManager $tenants) {}

    public function index(Request $request): Response
    {
        $branchId = $this->branchId($request);

        return Inertia::render('Settings/SectorPacks', [
            'packs' => $this->packs->overview($this->tenants->getCompany(), $branchId),
            'branchId' => $branchId,
            'branches' => $this->allowedBranches(),
            'canShare' => auth()->user()->allowedBranchIds() === null,
        ]);
    }

    public function apply(Request $request, string $pack)
    {
        $result = $this->packs->apply($this->tenants->getCompany(), $pack, $this->branchId($request));
        $made = array_sum($result['created']);

        return back()->with('success', $made ? "فُعّلت الحزمة: أُنشئ {$made} سجلًا وتُرك {$result['existing']} لوجوده" : 'الحزمة مفعّلة بالفعل؛ لا سجلات جديدة');
    }

    /** The branch the pack is for (null = shared by every branch, only for users who work in all of them). */
    protected function branchId(Request $request): ?int
    {
        $id = $request->input('branch_id', $request->query('branch'));
        $id = $id === null || $id === '' ? null : (int) $id;
        $allowed = auth()->user()->allowedBranchIds();
        if ($id !== null && $allowed !== null && ! in_array($id, $allowed, true)) {
            abort(403, 'لا تملك صلاحية العمل على هذا الفرع.');
        }
        if ($id === null && $allowed !== null) {
            abort(422, 'اختر الفرع الذي تُطبَّق عليه الحزمة.');
        }

        return $id;
    }

    protected function allowedBranches()
    {
        $allowed = auth()->user()->allowedBranchIds();

        return Branch::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderByDesc('is_main')->orderBy('id')->get(['id', 'name_ar', 'name_en']);
    }
}
