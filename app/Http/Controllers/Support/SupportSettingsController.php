<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SlaPolicy;
use App\Models\SupportCategory;
use App\Models\Ticket;
use App\Services\Support\SlaService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Support settings: SLA targets per priority and ticket categories. */
class SupportSettingsController extends Controller
{
    public function __construct(protected SlaService $sla, protected TenantManager $tenants) {}

    public function index(): Response
    {
        return Inertia::render('Support/Settings', [
            'sla' => collect(Ticket::PRIORITIES)->map(fn ($label, $priority) => ['priority' => $priority, 'label' => $label] + $this->sla->policy($priority))->values(),
            'categories' => SupportCategory::withCount('tickets')->orderBy('name')->get(),
        ]);
    }

    public function saveSla(Request $request)
    {
        $data = $request->validate([
            'policies' => 'required|array',
            'policies.*.priority' => 'required|in:low,normal,high,urgent',
            'policies.*.first_response_minutes' => 'required|integer|min:1|max:525600',
            'policies.*.resolution_minutes' => 'required|integer|min:1|max:525600|gte:policies.*.first_response_minutes',
        ], [], ['policies.*.first_response_minutes' => 'مهلة الرد الأول', 'policies.*.resolution_minutes' => 'مهلة الحل']);

        foreach ($data['policies'] as $p) {
            SlaPolicy::updateOrCreate(['priority' => $p['priority']], ['first_response_minutes' => $p['first_response_minutes'], 'resolution_minutes' => $p['resolution_minutes']]);
        }

        return back()->with('success', 'تم حفظ مهل الخدمة');
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255'], [], ['name' => 'اسم التصنيف']);
        $category = SupportCategory::create($data);

        if ($json = $this->createdResponse($request, $category, $category->name)) {
            return $json;
        }

        return back()->with('success', 'تمت إضافة التصنيف');
    }

    public function toggleCategory(SupportCategory $category)
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back();
    }

    public function destroyCategory(SupportCategory $category)
    {
        $category->delete(); // tickets keep their record (category set to null)

        return back()->with('success', 'تم حذف التصنيف');
    }
}
