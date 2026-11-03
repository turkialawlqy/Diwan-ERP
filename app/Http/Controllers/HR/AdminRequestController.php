<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\AdminRequest;
use App\Models\AdminRequestLine;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\HR\AdminRequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Administrative requests: summon employees (any department); each employee line is followed up on its own. */
class AdminRequestController extends Controller
{
    public function __construct(protected AdminRequestService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'department', 'employee']);
        $requests = AdminRequest::query()->withCount('lines')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['department'] ?? null, fn ($q, $d) => $q->whereHas('lines', fn ($l) => $l->where('department_id', $d)))
            ->when($filters['employee'] ?? null, fn ($q, $e) => $q->whereHas('lines', fn ($l) => $l->where('employee_id', $e)))
            ->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('HR/AdminRequests/Index', [
            'requests' => $requests, 'filters' => $filters, 'statuses' => AdminRequest::STATUSES,
            'departments' => Department::orderBy('name')->get(['id', 'name']), 'employees' => Employee::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The lines this user may follow up (his departments', or all for the central party). */
    public function inbox(Request $request): Response
    {
        $user = $request->user();
        $query = AdminRequestLine::query()->whereHas('request', fn ($q) => $q->where('status', '!=', 'draft'))->with(['request:id,number,title,attend_at,location', 'employee:id,name,employee_number', 'department:id,name']);
        if (! $this->service->isCentral($user)) {
            $mine = $this->service->managedDepartments($user);
            $query->where(fn ($q) => $q->whereIn('department_id', $mine)->orWhere('assignee_user_id', $user->id));
        }
        $status = $request->input('status');
        $lines = $query->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->whereNotIn('status', AdminRequestLine::FINAL))->latest('id')->paginate(30)->withQueryString();

        return Inertia::render('HR/AdminRequests/Inbox', ['lines' => $lines, 'statuses' => AdminRequestLine::STATUSES, 'status' => $status, 'central' => $this->service->isCentral($user)]);
    }

    public function create(): Response
    {
        return Inertia::render('HR/AdminRequests/Form', ['adminRequest' => null] + $this->lookups());
    }

    public function store(Request $request)
    {
        [$data, $employees] = $this->validated($request);
        $created = $this->service->create($data, $employees, $request->user());
        if ($request->boolean('send')) {
            $this->service->send($created, $request->user());
        }

        return redirect()->route('hr.admin-requests.show', $created)->with('success', 'تم حفظ الطلب '.$created->number);
    }

    public function show(Request $request, AdminRequest $adminRequest): Response
    {
        $user = $request->user();
        $lines = $adminRequest->lines()->with(['employee:id,name,employee_number,job_title,phone', 'department:id,name', 'handler:id,name', 'assignee:id,name', 'events.user:id,name'])->get();
        $canView = $user->can('hr.admin_requests.view');
        $mine = $lines->filter(fn ($l) => $this->service->canHandle($user, $l));
        abort_unless($canView || $mine->isNotEmpty(), 403);

        return Inertia::render('HR/AdminRequests/Show', [
            'adminRequest' => $adminRequest->load('requester:id,name')->toArray(),
            'lines' => $lines->map(fn ($l) => $l->toArray() + ['can_handle' => $mine->contains('id', $l->id), 'can_manage' => $this->service->canHandle($user, $l, false)])->values(),
            'statuses' => AdminRequest::STATUSES, 'lineStatuses' => AdminRequestLine::STATUSES, 'channels' => AdminRequestLine::CHANNELS, 'flow' => AdminRequestLine::FLOW,
            'handlers' => User::orderBy('name')->get(['id', 'name'])->filter(fn (User $u) => $u->can('hr.admin_requests.handle') || $u->can('hr.admin_requests.manage'))->values(),
            'canView' => $canView,
        ]);
    }

    public function edit(AdminRequest $adminRequest): Response
    {
        abort_unless($adminRequest->status === 'draft', 403, 'لا يُعدَّل الطلب بعد إرساله.');

        return Inertia::render('HR/AdminRequests/Form', ['adminRequest' => $adminRequest->toArray() + ['employee_ids' => $adminRequest->lines()->pluck('employee_id')->all()]] + $this->lookups());
    }

    public function update(Request $request, AdminRequest $adminRequest)
    {
        [$data, $employees] = $this->validated($request);
        $this->service->update($adminRequest, $data, $employees);
        if ($request->boolean('send')) {
            $this->service->send($adminRequest->fresh(), $request->user());
        }

        return redirect()->route('hr.admin-requests.show', $adminRequest)->with('success', 'تم حفظ الطلب.');
    }

    public function send(Request $request, AdminRequest $adminRequest)
    {
        $this->service->send($adminRequest, $request->user());

        return back()->with('success', 'تم إرسال الطلب.');
    }

    public function destroy(AdminRequest $adminRequest)
    {
        $this->service->delete($adminRequest);

        return redirect()->route('hr.admin-requests.index')->with('success', 'تم حذف المسودة.');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255', 'body' => 'nullable|string|max:5000', 'attend_at' => 'nullable|date', 'location' => 'nullable|string|max:255',
            'employees' => 'present|array', 'employees.*' => 'integer|tenant_exists:employees,id',
        ]);

        return [$data, $data['employees']];
    }

    protected function lookups(): array
    {
        return ['employees' => Employee::where('status', 'active')->with('departmentRef:id,name')->orderBy('name')->get(['id', 'name', 'employee_number', 'department_id'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'employee_number' => $e->employee_number, 'department' => $e->departmentRef?->name])->values()];
    }
}
