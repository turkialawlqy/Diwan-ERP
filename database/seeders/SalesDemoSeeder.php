<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\ZatcaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SalesDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::where('subdomain', 'anas')->first();
        if (! $company) {
            return;
        }

        $user = $company->users()->first();
        $branch = $company->branches()->first();

        // 1. Create Customers
        $customersData = [
            [
                'name' => 'شركة الوفاق للتجارة والمقاولات',
                'company_name' => 'شركة الوفاق ش.م.م',
                'email' => 'info@alwefaq-sa.com',
                'phone' => '0501234567',
                'tax_number' => '310123456700003',
                'commercial_registration' => '1010543210',
                'address' => 'حي العليا - طريق الملك فهد',
                'city' => 'الرياض',
                'credit_limit' => 50000.00,
                'current_balance' => 0.00,
            ],
            [
                'name' => 'مؤسسة البشائر الحديثة',
                'company_name' => 'مؤسسة البشائر للتوريدات',
                'email' => 'sales@albashaer.com',
                'phone' => '0547654321',
                'tax_number' => '310765432100003',
                'commercial_registration' => '1010987654',
                'address' => 'حي الملز - شارع الستين',
                'city' => 'الرياض',
                'credit_limit' => 25000.00,
                'current_balance' => 0.00,
            ],
            [
                'name' => 'عبدالله بن خالد الشهري',
                'company_name' => 'مكتب الشهري للعقارات',
                'email' => 'abdullah.shehri@gmail.com',
                'phone' => '0569871234',
                'tax_number' => null,
                'commercial_registration' => null,
                'address' => 'حي الروضة',
                'city' => 'الرياض',
                'credit_limit' => 10000.00,
                'current_balance' => 4120.00,
            ],
            [
                'name' => 'شركة الرؤية للحلول الرقمية',
                'company_name' => 'شركة الرؤية لتقنية المعلومات',
                'email' => 'contact@vision-it.sa',
                'phone' => '0533334444',
                'tax_number' => '300999888800003',
                'commercial_registration' => '1010333333',
                'address' => 'طريق الثمامة - حي الصحافة',
                'city' => 'الرياض',
                'credit_limit' => 100000.00,
                'current_balance' => 6700.00,
            ],
            [
                'name' => 'مكتب المستشار الهندسي',
                'company_name' => 'دار المستشار للاستشارات الهندسية',
                'email' => 'advisor.eng@yahoo.com',
                'phone' => '0558889999',
                'tax_number' => '310555444400003',
                'commercial_registration' => '1010444444',
                'address' => 'حي المروج',
                'city' => 'الرياض',
                'credit_limit' => 30000.00,
                'current_balance' => 0.00,
            ],
        ];

        $createdCustomers = [];
        foreach ($customersData as $c) {
            $createdCustomers[] = Customer::create(array_merge($c, ['company_id' => $company->id]));
        }

        // 2. Create Invoices matching dashboard table
        $invoicesData = [
            [
                'customer_index' => 0,
                'invoice_number' => 'INV-001042',
                'date' => '2026-09-20',
                'items' => [
                    ['item_name' => 'لابتوب ديل انسبيرون 15 - Core i7', 'quantity' => 1, 'unit_price' => 2000.00],
                ],
                'payment_status' => 'paid',
                'payment_method' => 'card',
            ],
            [
                'customer_index' => 1,
                'invoice_number' => 'INV-001041',
                'date' => '2026-09-20',
                'items' => [
                    ['item_name' => 'شاشة سامسونج 27 بوصة منحنية FHD', 'quantity' => 1, 'unit_price' => 739.13],
                ],
                'payment_status' => 'paid',
                'payment_method' => 'cash',
            ],
            [
                'customer_index' => 2,
                'invoice_number' => 'INV-001040',
                'date' => '2026-09-19',
                'items' => [
                    ['item_name' => 'طابعة إبسون L3250 واي فاي متعددة الوظائف', 'quantity' => 2, 'unit_price' => 1000.00],
                    ['item_name' => 'طقم حبر أصلي إبسون 103 (4 ألوان)', 'quantity' => 4, 'unit_price' => 395.65],
                ],
                'payment_status' => 'partially_paid',
                'payment_method' => 'bank_transfer',
            ],
            [
                'customer_index' => 3,
                'invoice_number' => 'INV-001039',
                'date' => '2026-09-19',
                'items' => [
                    ['item_name' => 'خادم شبكي ديل باور إيدج T150', 'quantity' => 1, 'unit_price' => 5826.09],
                ],
                'payment_status' => 'unpaid',
                'payment_method' => 'credit',
            ],
            [
                'customer_index' => 4,
                'invoice_number' => 'INV-001038',
                'date' => '2026-09-18',
                'items' => [
                    ['item_name' => 'راوتر ميكروتيك RB5009 سويتش متقدم', 'quantity' => 1, 'unit_price' => 1000.00],
                ],
                'payment_status' => 'paid',
                'payment_method' => 'card',
            ],
        ];

        foreach ($invoicesData as $inv) {
            $customer = $createdCustomers[$inv['customer_index']];
            $subtotal = 0;
            $itemsToCreate = [];

            foreach ($inv['items'] as $item) {
                $lineSubtotal = $item['quantity'] * $item['unit_price'];
                $lineTax = $lineSubtotal * 0.15;
                $lineTotal = $lineSubtotal + $lineTax;
                $subtotal += $lineSubtotal;

                $itemsToCreate[] = [
                    'item_name' => $item['item_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => 0,
                    'tax_rate' => 15.00,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSubtotal,
                    'total' => $lineTotal,
                ];
            }

            $taxAmount = $subtotal * 0.15;
            $total = $subtotal + $taxAmount;

            $paid = match ($inv['payment_status']) {
                'paid' => $total,
                'partially_paid' => round($total / 2, 2),
                default => 0,
            };
            $remaining = $total - $paid;

            // Generate ZATCA compliant QR
            $isoDate = $inv['date'].'T12:00:00Z';
            $qrBase64 = ZatcaService::generateQrCode(
                $company->name,
                $company->tax_number ?? '300000000000003',
                $isoDate,
                $total,
                $taxAmount
            );

            $invoice = Invoice::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'user_id' => $user?->id,
                'invoice_number' => $inv['invoice_number'],
                'invoice_date' => $inv['date'],
                'due_date' => date('Y-m-d', strtotime($inv['date'].' +30 days')),
                'supply_date' => $inv['date'],
                'subtotal' => $subtotal,
                'discount_type' => 'fixed',
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'paid_amount' => $paid,
                'remaining_amount' => $remaining,
                'payment_status' => $inv['payment_status'],
                'payment_method' => $inv['payment_method'],
                'notes' => 'شكراً لتعاملكم معنا.',
                'terms' => 'البضاعة المباعة لا ترد ولا تستبدل إلا خلال 7 أيام من تاريخ الفاتورة.',
                'zatca_uuid' => (string) Str::uuid(),
                'zatca_qr' => $qrBase64,
                'zatca_status' => 'reported',
            ]);

            foreach ($itemsToCreate as $item) {
                $invoice->items()->create($item);
            }
        }

        // 3. Create Quotation
        $quoteSubtotal = 5000.00;
        $quoteTax = $quoteSubtotal * 0.15;
        $quoteTotal = $quoteSubtotal + $quoteTax;

        $quote = Quotation::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $createdCustomers[0]->id,
            'user_id' => $user?->id,
            'quotation_number' => 'QUO-000101',
            'quotation_date' => '2026-09-20',
            'valid_until' => '2026-10-20',
            'subtotal' => $quoteSubtotal,
            'discount_amount' => 0,
            'tax_rate' => 15.00,
            'tax_amount' => $quoteTax,
            'total' => $quoteTotal,
            'status' => 'sent',
            'notes' => 'عرض سعر ساري لمدة 30 يوماً.',
        ]);

        $quote->items()->create([
            'item_name' => 'أجهزة حاسب آلي مكتبية HP ProDesk 400',
            'quantity' => 2,
            'unit_price' => 2500.00,
            'tax_rate' => 15.00,
            'tax_amount' => $quoteTax,
            'subtotal' => $quoteSubtotal,
            'total' => $quoteTotal,
        ]);
    }
}
