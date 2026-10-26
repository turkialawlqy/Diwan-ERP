<?php

namespace App\Services\Sales;

use App\Models\Quotation;
use App\Services\Documents\DocumentNumberService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates a quotation (draft) with its lines and totals; used by the form
 * and by the spreadsheet import.
 */
class QuotationService
{
    public function __construct(protected DocumentNumberService $numbers) {}

    /**
     * @param  array  $data  customer_id, branch_id?, quotation_date, valid_until?, notes?, terms?,
     *                       items: [product_id?, item_name, description?, quantity, unit_price, discount?, tax_rate?]
     */
    public function create(array $data): Quotation
    {
        return DB::transaction(function () use ($data) {
            $subtotal = $taxAmount = $discountTotal = 0;
            $items = [];

            foreach ($data['items'] as $item) {
                $qty = (float) $item['quantity'];
                $price = (float) $item['unit_price'];
                $discount = (float) ($item['discount'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? TaxProfile::rate());

                $lineSubtotal = ($qty * $price) - $discount;
                $lineTax = $lineSubtotal * ($taxRate / 100);

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;
                $discountTotal += $discount;

                $items[] = [
                    'product_id' => $item['product_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount' => $discount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSubtotal,
                    'total' => $lineSubtotal + $lineTax,
                ];
            }

            $quotation = Quotation::create([
                'company_id' => app(TenantManager::class)->getTenantId(),
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'user_id' => auth()->id(),
                'quotation_number' => $this->numbers->next('quotation', Carbon::parse($data['quotation_date'])),
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'tax_rate' => TaxProfile::rate(),
                'tax_amount' => $taxAmount,
                'total' => $subtotal + $taxAmount,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ]);

            foreach ($items as $item) {
                $quotation->items()->create($item);
            }

            return $quotation;
        });
    }
}
