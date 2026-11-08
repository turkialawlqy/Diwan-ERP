<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ProjectAllocationRun;
use App\Services\Projects\ProjectAllocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Indirect cost allocation over projects: list, preview + post, view, reverse. */
class AllocationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Projects/Allocations/Index', [
            'runs' => ProjectAllocationRun::orderByDesc('id')->paginate($this->perPage($request))->withQueryString(),
        ]);
    }

    public function create(Request $request, ProjectAllocationService $service): Response
    {
        $filters = [
            'account_ids' => array_values(array_filter((array) $request->input('account_ids'))),
            'period_from' => $request->input('period_from', now()->startOfMonth()->toDateString()),
            'period_to' => $request->input('period_to', now()->endOfMonth()->toDateString()),
            'basis' => $request->input('basis', 'revenue'),
            'amount' => $request->input('amount'),
        ];

        $preview = null;
        $error = null;
        if ($request->boolean('preview') && $filters['account_ids']) {
            $data = $this->validated($request);
            try {
                $preview = $service->preview($data['account_ids'], Carbon::parse($data['period_from']), Carbon::parse($data['period_to']), $data['basis'], isset($data['amount']) ? (float) $data['amount'] : null);
            } catch (ValidationException $e) {
                $error = collect($e->errors())->flatten()->first();
            }
        }

        return Inertia::render('Projects/Allocations/Create', [
            'accounts' => Account::where('type', 'expense')->where('is_parent', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'filters' => $filters,
            'preview' => $preview,
            'previewError' => $error,
        ]);
    }

    public function store(Request $request, ProjectAllocationService $service)
    {
        $data = $this->validated($request);
        $run = $service->post($data['account_ids'], Carbon::parse($data['period_from']), Carbon::parse($data['period_to']), $data['basis'], isset($data['amount']) ? (float) $data['amount'] : null, $data['notes'] ?? null);

        return redirect()->route('projects.allocations.show', $run->id)->with('success', 'تم ترحيل توزيع التكاليف غير المباشرة');
    }

    public function show(ProjectAllocationRun $run): Response
    {
        return Inertia::render('Projects/Allocations/Show', [
            'run' => $run->load('lines.project:id,code,name', 'journalEntry:id,entry_number'),
            'accounts' => Account::whereIn('id', $run->pool_account_ids)->get(['id', 'code', 'name_ar']),
        ]);
    }

    public function destroy(ProjectAllocationRun $run, ProjectAllocationService $service)
    {
        $service->reverse($run);

        return back()->with('success', 'تم عكس التوزيع');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'account_ids' => 'required|array|min:1',
            'account_ids.*' => 'tenant_exists:accounts,id',
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'basis' => 'required|in:'.implode(',', ProjectAllocationRun::BASES),
            'amount' => 'nullable|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
