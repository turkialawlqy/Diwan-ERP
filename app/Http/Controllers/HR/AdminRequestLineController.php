<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\AdminRequest;
use App\Models\AdminRequestLine;
use App\Services\HR\AdminRequestService;
use Illuminate\Http\Request;

/** Follow-up of one employee line: receive, hand to a specialist, notify, record attendance. */
class AdminRequestLineController extends Controller
{
    public function __construct(protected AdminRequestService $service) {}

    protected function line(AdminRequest $adminRequest, int $line): AdminRequestLine
    {
        return $adminRequest->lines()->findOrFail($line);
    }

    public function receive(Request $request, AdminRequest $adminRequest, int $line)
    {
        $this->service->receive($this->line($adminRequest, $line), $request->user());

        return back()->with('success', 'تم استلام السطر.');
    }

    /** Receive every new line of the request that the user may handle. */
    public function receiveAll(Request $request, AdminRequest $adminRequest)
    {
        $n = 0;
        foreach ($adminRequest->lines()->where('status', 'new')->get() as $line) {
            if ($this->service->canHandle($request->user(), $line, false)) {
                $this->service->receive($line, $request->user());
                $n++;
            }
        }

        return back()->with($n ? 'success' : 'error', $n ? "تم استلام {$n} سطر." : 'لا أسطر جديدة ضمن اختصاصك.');
    }

    public function assign(Request $request, AdminRequest $adminRequest, int $line)
    {
        $data = $request->validate(['assignee_user_id' => 'required|integer', 'note' => 'nullable|string|max:500']);
        $this->service->assign($this->line($adminRequest, $line), $request->user(), (int) $data['assignee_user_id'], $data['note'] ?? null);

        return back()->with('success', 'تم الإسناد.');
    }

    public function notify(Request $request, AdminRequest $adminRequest, int $line)
    {
        $data = $request->validate(['reached' => 'required|boolean', 'notify_channel' => 'nullable|string', 'note' => 'nullable|string|max:500']);
        $this->service->notify($this->line($adminRequest, $line), $request->user(), (bool) $data['reached'], $data['notify_channel'] ?? null, $data['note'] ?? null);

        return back()->with('success', 'تم تسجيل نتيجة التبليغ.');
    }

    public function attendance(Request $request, AdminRequest $adminRequest, int $line)
    {
        $data = $request->validate(['attended' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        $this->service->markAttendance($this->line($adminRequest, $line), $request->user(), (bool) $data['attended'], $data['note'] ?? null);

        return back()->with('success', 'تم تسجيل الحضور.');
    }

    public function reopen(Request $request, AdminRequest $adminRequest, int $line)
    {
        $this->service->reopen($this->line($adminRequest, $line), $request->user());

        return back()->with('success', 'أُعيد فتح السطر.');
    }
}
