<?php

namespace App\Services\Bookings;

use App\Services\TenantManager;

/** Company-wide booking rules (the «إعدادات الحجوزات» page). */
class BookingSettings
{
    public const DEFAULTS = ['slot_step' => 30, 'min_notice_hours' => 2, 'max_advance_days' => 60, 'auto_confirm' => true, 'reminder_hours' => '24,2'];

    public function get(string $key)
    {
        $company = app(TenantManager::class)->getCompany();

        return $company ? $company->setting("bookings_{$key}", self::DEFAULTS[$key]) : self::DEFAULTS[$key];
    }

    public function all(): array
    {
        return [
            'slot_step' => max(5, (int) $this->get('slot_step')),
            'min_notice_hours' => max(0, (int) $this->get('min_notice_hours')),
            'max_advance_days' => max(1, (int) $this->get('max_advance_days')),
            'auto_confirm' => filter_var($this->get('auto_confirm'), FILTER_VALIDATE_BOOLEAN),
            'reminder_hours' => (string) $this->get('reminder_hours'),
        ];
    }

    /** Hours before the start at which a reminder goes out, largest first. */
    public function reminderHours(): array
    {
        $hours = collect(explode(',', (string) $this->get('reminder_hours')))->map(fn ($h) => (int) trim($h))->filter(fn ($h) => $h > 0)->unique()->sortDesc()->values()->all();

        return $hours;
    }

    public function save(array $data): void
    {
        $company = app(TenantManager::class)->getCompany();
        foreach (['slot_step', 'min_notice_hours', 'max_advance_days'] as $k) {
            $company->setSetting("bookings_{$k}", (int) $data[$k], 'bookings', 'integer');
        }
        $company->setSetting('bookings_auto_confirm', ! empty($data['auto_confirm']) ? '1' : '0', 'bookings', 'string');
        $company->setSetting('bookings_reminder_hours', (string) ($data['reminder_hours'] ?? ''), 'bookings', 'string');
    }
}
