<?php

namespace App\Http\Controllers;

use App\Services\Sectors\SectorPackService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «حزم القطاعات»: preview and apply a sector's ready-made setup. */
class SectorPackController extends Controller
{
    public function __construct(protected SectorPackService $packs, protected TenantManager $tenants) {}

    public function index(): Response
    {
        return Inertia::render('Settings/SectorPacks', ['packs' => $this->packs->overview($this->tenants->getCompany())]);
    }

    public function apply(Request $request, string $pack)
    {
        $result = $this->packs->apply($this->tenants->getCompany(), $pack);
        $made = array_sum($result['created']);

        return back()->with('success', $made ? "فُعّلت الحزمة: أُنشئ {$made} سجلًا وتُرك {$result['existing']} لوجوده" : 'الحزمة مفعّلة بالفعل؛ لا سجلات جديدة');
    }
}
