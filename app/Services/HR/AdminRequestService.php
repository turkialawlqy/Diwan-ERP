<?php

namespace App\Services\HR;

use App\Models\AdminRequest;
use App\Models\AdminRequestEvent;
use App\Models\AdminRequestLine;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\Documents\DocumentNumberService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrative requests: summon several employees at once. The request is drafted, sent,
 * and each employee line is then received, handed to a specialist, notified and closed by the
 * employee's department manager or the central party (permission hr.admin_requests.manage).
 * The request's own status follows its lines.
 */
class AdminRequestService
{
    public function __construct(protected DocumentNumberService $numbers) {}

    /** @param array<int> $employeeIds */
    public function create(array $data, array $employeeIds, ?User $by = null): AdminRequest
    {
        return DB::transaction(function () use ($data, $employeeIds, $by) {
            $request = AdminRequest::create([
                'number' => $this->numbers->next('admin_request'),
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'attend_at' => $data['attend_at'] ?? null,
                'location' => $data['location'] ?? null,
                'requested_by' => $by?->id,
                'status' => 'draft',
            ]);
            $this->syncLines($request, $employeeIds);

            return $request;
        });
    }

    public function update(AdminRequest $request, array $data, array $employeeIds): AdminRequest
    {
        $this->assertDraft($request);

        return DB::transaction(function () use ($request, $data, $employeeIds) {
            $request->update(collect($data)->only(['title', 'body', 'attend_at', 'location'])->all());
            $this->syncLines($request, $employeeIds);

            return $request;
        });
    }

    /** Draft lines follow the chosen employees. */
    protected function syncLines(AdminRequest $request, array $employeeIds): void
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $request->lines()->whereNotIn('employee_id', $ids)->delete();
        $employees = Employee::whereIn('id', $ids)->get()->keyBy('id');
        if ($employees->count() !== $ids->count()) {
            throw ValidationException::withMessages(['employees' => 'موظف غير موجود.']);
        }
        foreach ($ids as $id) {
            $request->lines()->firstOrCreate(['employee_id' => $id], ['status' => 'new', 'department_id' => $employees[$id]->department_id]);
        }
    }

    public function send(AdminRequest $request, ?User $by = null): AdminRequest
    {
        $this->assertDraft($request);

        return DB::transaction(function () use ($request) {
            $request = AdminRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $lines = $request->lines()->with('employee')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['employees' => 'أضف موظفًا واحدًا على الأقل قبل الإرسال.']);
            }
            foreach ($lines as $line) {
                $line->update(['department_id' => $line->employee->department_id]); // the department as of today
            }
            $request->update(['status' => 'new', 'sent_at' => now()]);
            $this->alertHandlers($request, $lines->fresh());

            return $request;
        });
    }

    /** Every manager (or the central party where a department has none) hears about their lines once. */
    protected function alertHandlers(AdminRequest $request, Collection $lines): void
    {
        $central = $this->centralUsers($request);
        $byUser = [];
        foreach ($lines->groupBy('department_id') as $departmentId => $group) {
            $manager = $this->managerUser($departmentId ?: null);
            foreach ($manager ? [$manager] : $central as $user) {
                $byUser[$user->id]['user'] = $user;
                $byUser[$user->id]['count'] = ($byUser[$user->id]['count'] ?? 0) + $group->count();
            }
        }
        foreach ($byUser as $row) {
            $this->alert($row['user'], 'admin_request_new', 'طلب إداري جديد', "{$request->number} — {$request->title} ({$row['count']} موظف)", route('hr.admin-requests.show', $request, false));
        }
    }

    public function receive(AdminRequestLine $line, User $by): AdminRequestLine
    {
        return $this->move($line, 'received', $by, ['handler_id' => $by->id, 'assignee_user_id' => null], null, handler: true);
    }

    public function assign(AdminRequestLine $line, User $by, int $assigneeId, ?string $note = null): AdminRequestLine
    {
        $assignee = User::find($assigneeId);
        if (! $assignee || ! $this->isHandlerCandidate($assignee)) {
            throw ValidationException::withMessages(['assignee_user_id' => 'المختص المختار لا يملك صلاحية معالجة الطلبات الإدارية.']);
        }
        $line = $this->move($line, 'assigned', $by, ['assignee_user_id' => $assignee->id], $note, handler: true);
        $this->alert($assignee, 'admin_request_assigned', 'أُسند إليك تبليغ موظف', "{$line->request->number} — {$line->employee->name}", route('hr.admin-requests.show', $line->admin_request_id, false));

        return $line;
    }

    /** The result of telling the employee: reached (with how) or not. */
    public function notify(AdminRequestLine $line, User $by, bool $reached, ?string $channel = null, ?string $note = null): AdminRequestLine
    {
        if ($reached && ! isset(AdminRequestLine::CHANNELS[$channel])) {
            throw ValidationException::withMessages(['notify_channel' => 'اختر وسيلة التبليغ.']);
        }
        if (! $reached && blank($note)) {
            throw ValidationException::withMessages(['note' => 'اذكر سبب تعذر التبليغ.']);
        }
        $line = $this->move($line, $reached ? 'notified' : 'unreachable', $by,
            ['notify_channel' => $channel, 'notify_note' => $note, 'notified_at' => $reached ? now() : null], $note, handler: false);
        if ($reached && ($user = $line->employee->user)) {
            $this->alert($user, 'admin_request_notified', 'طلب إداري موجَّه إليك', "{$line->request->number} — {$line->request->title}", route('hr.me', [], false));
        }

        return $line;
    }

    public function markAttendance(AdminRequestLine $line, User $by, bool $attended, ?string $note = null): AdminRequestLine
    {
        return $this->move($line, $attended ? 'attended' : 'absent', $by, ['attendance_note' => $note], $note, handler: false);
    }

    /** Another attempt after "تعذر التبليغ". */
    public function reopen(AdminRequestLine $line, User $by): AdminRequestLine
    {
        return $this->receive($line, $by);
    }

    /**
     * One guarded transition. $handler = only the department manager / central party may do it;
     * otherwise the specialist the line was handed to may too.
     */
    protected function move(AdminRequestLine $line, string $to, User $by, array $values, ?string $note, bool $handler): AdminRequestLine
    {
        return DB::transaction(function () use ($line, $to, $by, $values, $note, $handler) {
            $line = AdminRequestLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $request = AdminRequest::whereKey($line->admin_request_id)->lockForUpdate()->firstOrFail();
            if ($request->status === 'draft') {
                throw ValidationException::withMessages(['status' => 'الطلب ما زال مسودة؛ أرسله أولًا.']);
            }
            if (! $this->canHandle($by, $line, ! $handler)) {
                abort(403, 'هذا الموظف ليس ضمن اختصاصك.');
            }
            if (! in_array($to, AdminRequestLine::FLOW[$line->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'لا يمكن نقل السطر من «'.AdminRequestLine::STATUSES[$line->status].'» إلى «'.AdminRequestLine::STATUSES[$to].'».']);
            }
            $from = $line->status;
            $line->forceFill($values + ['status' => $to, 'completed_at' => in_array($to, AdminRequestLine::FINAL, true) ? now() : null])->save();
            AdminRequestEvent::create(['admin_request_line_id' => $line->id, 'user_id' => $by->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now()]);
            $this->syncStatus($request);

            return $line->load('request', 'employee');
        });
    }

    /** The request follows its lines: all new → new; any moving → processing; all final → completed. */
    public function syncStatus(AdminRequest $request): AdminRequest
    {
        $statuses = $request->lines()->pluck('status');
        if ($statuses->isEmpty() || $request->status === 'draft') {
            return $request;
        }
        $status = $statuses->every(fn ($s) => in_array($s, AdminRequestLine::FINAL, true)) ? 'completed'
            : ($statuses->every(fn ($s) => $s === 'new') ? 'new' : 'processing');
        if ($status !== $request->status) {
            $request->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null]);
            if ($status === 'completed' && ($requester = $request->requester)) {
                $this->alert($requester, 'admin_request_done', 'اكتمل طلب إداري', "{$request->number} — {$request->title}", route('hr.admin-requests.show', $request, false));
            }
        }

        return $request;
    }

    public function delete(AdminRequest $request): void
    {
        $this->assertDraft($request, 'تُحذف المسودات فقط.');
        $request->delete();
    }

    // ----------------------------------------------------------------- who

    public function isCentral(User $user): bool
    {
        return $user->can('hr.admin_requests.manage');
    }

    /** The user account of a department's manager (null when it has none, or he has no account). */
    public function managerUser(?int $departmentId): ?User
    {
        $managerEmployee = $departmentId ? Department::find($departmentId)?->manager_employee_id : null;
        $userId = $managerEmployee ? Employee::find($managerEmployee)?->user_id : null;

        return $userId ? User::find($userId) : null;
    }

    /** Department ids the user manages as an employee. */
    public function managedDepartments(User $user): array
    {
        $employeeIds = Employee::where('user_id', $user->id)->pluck('id');

        return Department::whereIn('manager_employee_id', $employeeIds)->pluck('id')->all();
    }

    public function canHandle(User $user, AdminRequestLine $line, bool $allowAssignee = true): bool
    {
        if ($this->isCentral($user)) {
            return true;
        }
        if ($line->department_id && in_array($line->department_id, $this->managedDepartments($user), true)) {
            return true;
        }

        return $allowAssignee && $line->assignee_user_id === $user->id;
    }

    protected function isHandlerCandidate(User $user): bool
    {
        return $user->status !== 'inactive' && ($user->can('hr.admin_requests.handle') || $user->can('hr.admin_requests.manage'));
    }

    protected function centralUsers(AdminRequest $request): Collection
    {
        return User::where('company_id', $request->company_id)->get()->filter(fn (User $u) => $this->isCentral($u) && ($request->branch_id === null || $u->canUseBranch($request->branch_id)));
    }

    protected function assertDraft(AdminRequest $request, string $message = 'لا يُعدَّل الطلب بعد إرساله.'): void
    {
        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    protected function alert(User $user, string $kind, string $title, string $body, string $url): void
    {
        $user->notify(new ErpAlert($kind, $title, $body, $url, 'info'));
    }
}
