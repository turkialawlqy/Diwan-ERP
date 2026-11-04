<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Services\Bookings\BookingSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(BookingSettings $settings): Response
    {
        return Inertia::render('Bookings/Settings', ['settings' => $settings->all()]);
    }

    public function update(Request $request, BookingSettings $settings)
    {
        $data = $request->validate([
            'slot_step' => 'required|integer|min:5|max:240',
            'min_notice_hours' => 'required|integer|min:0|max:720',
            'max_advance_days' => 'required|integer|min:1|max:730',
            'auto_confirm' => 'nullable|boolean',
            'reminder_hours' => ['nullable', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'deposit_on_cancel' => 'nullable|in:credit,forfeit',
        ], ['reminder_hours.regex' => 'اكتب ساعات التذكير أرقامًا تفصلها فاصلة مثل 24,2.']);
        $settings->save($data);

        return back()->with('success', 'تم حفظ إعدادات الحجوزات');
    }
}
