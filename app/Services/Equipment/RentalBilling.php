<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentBilling;
use App\Models\EquipmentReading;
use App\Models\EquipmentRental;
use App\Models\ProjectCost;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use App\Services\Sales\InvoiceService;
use App\Services\Tax\TaxProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a rental period into money:
 *  - customer contract  → tax invoice (rental revenue)
 *  - project assignment → project cost (Dr project costs / Cr equipment recovery)
 *  - hired-in equipment → supplier bill (Dr rent or project costs / Cr payables)
 * A period can be billed once: overlapping periods are refused.
 */
class RentalBilling
{
    public function __construct(protected InvoiceService $invoices, protected PostingService $posting) {}

    /**
     * Quantity for a period on the given basis.
     *  hourly:  worked hours from the daily readings; a minimum per day applies to each day worked
     *  daily:   calendar days of the period inside the contract
     *  monthly: months, a partial month prorated by its days
     */
    public function quantity(Equipment $equipment, string $basis, Carbon $from, Carbon $to, ?float $minHours = null, ?int $rentalId = null): array
    {
        $days = (int) $from->diffInDays($to) + 1;

        if ($basis === 'hourly') {
            $readings = EquipmentReading::where('equipment_id', $equipment->id)
                ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
                ->when($rentalId, fn ($q) => $q->where(fn ($q) => $q->where('rental_id', $rentalId)->orWhereNull('rental_id')))
                ->get(['date', 'worked_hours']);
            $worked = round($readings->sum('worked_hours'), 2);
            $billed = round($readings->sum(fn ($r) => $minHours ? max($r->worked_hours, $minHours) : $r->worked_hours), 2);

            return ['quantity' => $billed, 'worked_hours' => $worked, 'days_with_readings' => $readings->count(), 'days' => $days];
        }

        if ($basis === 'daily') {
            return ['quantity' => $days, 'days' => $days];
        }

        $months = 0.0;
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $end = $cursor->copy()->endOfMonth()->startOfDay()->min($to);
            $months += ((int) $cursor->diffInDays($end) + 1) / $cursor->daysInMonth;
            $cursor = $end->copy()->addDay()->startOfDay();
        }

        return ['quantity' => round($months, 2), 'days' => $days];
    }

    /** Period clipped to the contract. */
    public function clip(EquipmentRental $rental, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->max($rental->start_date);
        $to = $rental->end_date ? $to->copy()->min($rental->end_date) : $to->copy();
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['period_from' => 'الفترة المختارة خارج مدة العقد.']);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    public function preview(EquipmentRental $rental, Carbon $from, Carbon $to): array
    {
        [$from, $to] = $this->clip($rental, $from, $to);
        $q = $this->quantity($rental->equipment, $rental->billing_basis, $from, $to, $rental->min_hours_per_day, $rental->id);
        $amount = round($q['quantity'] * $rental->rate, 2);
        $tax = $rental->type === 'customer' ? round($amount * $rental->tax_rate / 100, 2) : 0;

        return $q + ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rate' => $rental->rate, 'amount' => $amount, 'tax' => $tax, 'total' => $amount + $tax,
            'overlap' => $this->overlapping('out', $rental->equipment_id, $rental->id, $from, $to)?->only(['period_from', 'period_to', 'reference'])];
    }

    /**
     * @param  array  $data  period_from, period_to, quantity? (override), date?
     */
    public function bill(EquipmentRental $rental, array $data): EquipmentBilling
    {
        return DB::transaction(function () use ($rental, $data) {
            $rental = EquipmentRental::lockForUpdate()->findOrFail($rental->id);
            [$from, $to] = $this->clip($rental, Carbon::parse($data['period_from']), Carbon::parse($data['period_to']));
            $this->assertFree('out', $rental->equipment_id, $rental->id, $from, $to);

            $equipment = $rental->equipment;
            $quantity = isset($data['quantity']) && $data['quantity'] !== ''
                ? round((float) $data['quantity'], 2)
                : $this->quantity($equipment, $rental->billing_basis, $from, $to, $rental->min_hours_per_day, $rental->id)['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'لا توجد كمية للفوترة في هذه الفترة (أدخل القراءات اليومية أو حدد الكمية).']);
            }
            $amount = round($quantity * $rental->rate, 2);
            $date = $data['date'] ?? $to->toDateString();
            $period = "{$from->toDateString()} — {$to->toDateString()}";
            $unit = Equipment::BASES[$rental->billing_basis];

            $billing = new EquipmentBilling([
                'direction' => 'out', 'equipment_id' => $equipment->id, 'rental_id' => $rental->id, 'period_from' => $from, 'period_to' => $to,
                'quantity' => $quantity, 'unit' => $rental->billing_basis, 'rate' => $rental->rate, 'amount' => $amount, 'created_by' => auth()->id(),
            ]);

            if ($rental->type === 'customer') {
                $invoice = $this->invoices->create([
                    'customer_id' => $rental->customer_id,
                    'invoice_date' => $date,
                    'supply_date' => $from->toDateString(),
                    'payment_method' => 'credit',
                    'payment_status' => 'unpaid',
                    'reference_number' => $rental->number,
                    'notes' => trim("عقد تأجير {$rental->number} — الفترة {$period}".($rental->site ? " — الموقع: {$rental->site}" : '')),
                ], [[
                    'item_name' => "تأجير {$equipment->name} ({$equipment->code})".($rental->with_operator ? ' مع مشغّل' : ''),
                    'description' => "الفترة {$period} — {$quantity} {$unit}",
                    'quantity' => $quantity,
                    'unit_price' => $rental->rate,
                    'tax_rate' => $rental->tax_rate,
                ]], ['issue_stock' => false, 'check_credit' => false, 'revenue_role' => 'rental_revenue']);
                $billing->fill(['invoice_id' => $invoice->id, 'tax_amount' => (float) $invoice->tax_amount, 'reference' => $invoice->invoice_number]);
            } else {
                $project = $rental->project;
                $cost = ProjectCost::create([
                    'project_id' => $project->id,
                    'category' => 'equipment',
                    'source' => 'equipment',
                    'reference' => $rental->number,
                    'date' => $date,
                    'description' => "تشغيل {$equipment->name} ({$equipment->code}) — {$period} — {$quantity} {$unit}",
                    'fixed_asset_id' => $equipment->fixed_asset_id,
                    'quantity' => $quantity,
                    'unit_cost' => $rental->rate,
                    'amount' => $amount,
                    'paid_from' => 'internal',
                    'created_by' => auth()->id(),
                ]);
                $entry = $this->posting->post([
                    'date' => $date,
                    'reference' => $rental->number,
                    'description' => "تحميل معدة {$equipment->code} على مشروع {$project->code} — {$period}",
                    'source' => $cost,
                    'lines' => [
                        ['role' => 'project_costs', 'debit' => $amount, 'cost_center_id' => $project->cost_center_id],
                        ['role' => 'equipment_recovery', 'credit' => $amount, 'cost_center_id' => $equipment->cost_center_id],
                    ],
                ]);
                $billing->fill(['project_cost_id' => $cost->id, 'journal_entry_id' => $entry->id, 'reference' => $rental->number]);
            }

            $billing->save();
            if (! $rental->billed_until || $to->gt($rental->billed_until)) {
                $rental->update(['billed_until' => $to->toDateString()]);
            }

            return $billing;
        });
    }

    public function supplierPreview(Equipment $equipment, Carbon $from, Carbon $to, ?float $taxRate = null): array
    {
        $taxRate ??= TaxProfile::rate();
        $this->assertHiredIn($equipment);
        $q = $this->quantity($equipment, $equipment->supplier_rate_basis, $from->startOfDay(), $to->startOfDay());
        $amount = round($q['quantity'] * $equipment->supplier_rate, 2);

        return $q + ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rate' => $equipment->supplier_rate, 'amount' => $amount,
            'tax' => round($amount * $taxRate / 100, 2), 'total' => round($amount * (1 + $taxRate / 100), 2),
            'overlap' => $this->overlapping('in', $equipment->id, null, $from, $to)?->only(['period_from', 'period_to', 'reference'])];
    }

    /**
     * Supplier's rent for hired-in equipment. Charged to the project the
     * equipment worked on in that period, otherwise to rent expense.
     *
     * @param  array  $data  period_from, period_to, quantity?, tax_rate?, reference? (supplier invoice no.), date?
     */
    public function supplierBill(Equipment $equipment, array $data): EquipmentBilling
    {
        $this->assertHiredIn($equipment);

        return DB::transaction(function () use ($equipment, $data) {
            $from = Carbon::parse($data['period_from'])->startOfDay();
            $to = Carbon::parse($data['period_to'])->startOfDay();
            if ($from->gt($to)) {
                throw ValidationException::withMessages(['period_to' => 'نهاية الفترة قبل بدايتها.']);
            }
            $this->assertFree('in', $equipment->id, null, $from, $to);

            $quantity = isset($data['quantity']) && $data['quantity'] !== ''
                ? round((float) $data['quantity'], 2)
                : $this->quantity($equipment, $equipment->supplier_rate_basis, $from, $to)['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'لا توجد كمية للفوترة في هذه الفترة.']);
            }
            $amount = round($quantity * $equipment->supplier_rate, 2);
            $tax = round($amount * (float) ($data['tax_rate'] ?? TaxProfile::rate()) / 100, 2);
            $date = $data['date'] ?? $to->toDateString();

            $project = EquipmentRental::with('project')->where('equipment_id', $equipment->id)->where('type', 'project')
                ->whereDate('start_date', '<=', $to)->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
                ->latest('id')->first()?->project;

            $billing = EquipmentBilling::create([
                'direction' => 'in', 'equipment_id' => $equipment->id, 'period_from' => $from, 'period_to' => $to, 'quantity' => $quantity,
                'unit' => $equipment->supplier_rate_basis, 'rate' => $equipment->supplier_rate, 'amount' => $amount, 'tax_amount' => $tax,
                'reference' => $data['reference'] ?? null, 'created_by' => auth()->id(),
            ]);

            $entry = $this->posting->post([
                'date' => $date,
                'reference' => $data['reference'] ?? $equipment->code,
                'description' => "إيجار معدة مستأجرة {$equipment->name} ({$equipment->code}) — {$from->toDateString()} إلى {$to->toDateString()}",
                'source' => $billing,
                'lines' => array_values(array_filter([
                    $project
                        ? ['role' => 'project_costs', 'debit' => $amount, 'cost_center_id' => $project->cost_center_id]
                        : ['role' => 'rent_expense', 'debit' => $amount, 'cost_center_id' => $equipment->cost_center_id],
                    $tax > 0 ? ['role' => 'vat_input', 'debit' => $tax] : null,
                    ['role' => 'payables', 'credit' => round($amount + $tax, 2), 'party_type' => 'supplier', 'party_id' => $equipment->supplier_id],
                ])),
            ]);
            Supplier::whereKey($equipment->supplier_id)->increment('current_balance', round($amount + $tax, 2));
            $billing->update(['journal_entry_id' => $entry->id]);

            return $billing;
        });
    }

    protected function assertHiredIn(Equipment $equipment): void
    {
        if ($equipment->ownership !== 'rented_in' || ! $equipment->supplier_id || ! $equipment->supplier_rate_basis || $equipment->supplier_rate <= 0) {
            throw ValidationException::withMessages(['equipment' => 'المعدة ليست مستأجرة من مورد، أو لم يُحدد المورد وسعر الإيجار وأساسه.']);
        }
    }

    protected function overlapping(string $direction, int $equipmentId, ?int $rentalId, Carbon $from, Carbon $to): ?EquipmentBilling
    {
        return EquipmentBilling::where('direction', $direction)->where('equipment_id', $equipmentId)
            ->when($rentalId, fn ($q) => $q->where('rental_id', $rentalId))
            ->whereDate('period_from', '<=', $to->toDateString())->whereDate('period_to', '>=', $from->toDateString())
            ->first();
    }

    protected function assertFree(string $direction, int $equipmentId, ?int $rentalId, Carbon $from, Carbon $to): void
    {
        if ($existing = $this->overlapping($direction, $equipmentId, $rentalId, $from, $to)) {
            throw ValidationException::withMessages(['period_from' => 'هذه الفترة (أو جزء منها) فوترت سابقاً: '
                .$existing->period_from->toDateString().' — '.$existing->period_to->toDateString().($existing->reference ? " ({$existing->reference})" : '')]);
        }
    }
}
