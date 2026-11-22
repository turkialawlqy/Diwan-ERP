<?php

namespace App\Http\Controllers\Gym;

use App\Http\Controllers\Controller;
use App\Services\Sectors\GymCheckinService;
use App\Support\ServerMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Reception: scan a member code, see at once whether he may enter; and the attendance report. */
class CheckinController extends Controller
{
    public function __construct(protected GymCheckinService $checkins) {}

    public function index(): Response
    {
        return Inertia::render('Gym/Checkin', ['today' => $this->day(today()->toDateString())]);
    }

    public function scan(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:60', 'method' => 'nullable|in:scan,manual']);
        $result = $this->checkins->scan($data['code'], $request->user(), $data['method'] ?? 'scan');

        return response()->json(['reason' => ServerMessages::translate($result['reason'])] + $result + ['today' => $this->day(today()->toDateString())]);
    }

    public function attendance(Request $request): Response
    {
        $date = Carbon::parse($request->validate(['date' => 'nullable|date'])['date'] ?? today())->toDateString();

        return Inertia::render('Gym/Attendance', ['date' => $date, 'day' => $this->day($date)]);
    }

    /** A day's check-ins, each refusal reason (kept in Arabic in the log) in the interface language. */
    protected function day(string $date): array
    {
        $day = $this->checkins->day($date);
        $day['rows'] = $day['rows']->map(fn (array $row) => ['reason' => ServerMessages::translate($row['reason'])] + $row);

        return $day;
    }
}
