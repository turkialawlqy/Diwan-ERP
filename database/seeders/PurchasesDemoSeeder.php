<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PurchasesDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::where('subdomain', 'anas')->first() ?? Company::first();
        if (! $company) {
            return;
        }

        $warehouse = Warehouse::where('company_id', $company->id)->first();
        $branch = Branch::where('company_id', $company->id)->first();

        // 1. Seed Realistic Suppliers
        $suppliersData = [
            [
                'name' => 'شركة التوريدات والحلول الرقمية المحدودة',
                'company_name' => 'شركة التوريدات الرقمية',
                'tax_number' => '310123456700003',
                'commercial_register' => '1010345678',
                'phone' => '0112345678',
                'email' => 'sales@digital-supplies.sa',
                'address' => 'الرياض - طريق العليا العام - مجمع النخبة المكتبي',
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA0380000000608010167519',
                'opening_balance' => 0,
                'current_balance' => 18400.00,
                'status' => 'active',
                'notes' => 'المورد المعتمد لأجهزة الكمبيوتر ولوازم نقاط البيع وملحقاتها',
            ],
            [
                'name' => 'مصنع الجزيرة للأوراق والمطبوعات',
                'company_name' => 'الجزيرة للورق والحراريات',
                'tax_number' => '310987654300003',
                'commercial_register' => '4030123456',
                'phone' => '0126789012',
                'email' => 'info@jazeera-paper.com',
                'address' => 'جدة - المرحلة الثالثة - المدينة الصناعية',
                'bank_name' => 'البنك الأهلي السعودي (SNB)',
                'iban' => 'SA4410000001234567890123',
                'opening_balance' => 0,
                'current_balance' => 3750.00,
                'status' => 'active',
                'notes' => 'المورد الرئيسي لرولات الورق الحراري ومطبوعات الفواتير',
            ],
            [
                'name' => 'شركة النظم المتطورة للمعدات والشبكات',
                'company_name' => 'النظم المتطورة للتجارة',
                'tax_number' => '311223344500003',
                'commercial_register' => '2050456789',
                'phone' => '0138901234',
                'email' => 'support@advanced-systems.sa',
                'address' => 'الدمام - حي الشاطئ - طريق الملك فهد',
                'bank_name' => 'بنك الرياض',
                'iban' => 'SA2220000009876543210987',
                'opening_balance' => 0,
                'current_balance' => 0.00,
                'status' => 'active',
                'notes' => 'توريد شاشات اللمس وقوارئ الباركود ومعدات نقاط البيع المتخصصة',
            ],
            [
                'name' => 'مؤسسة صروح البناء للمقاولات والتوريدات',
                'company_name' => 'صروح البناء',
                'tax_number' => '310556677800003',
                'commercial_register' => '1010890123',
                'phone' => '0555123499',
                'email' => 'contracts@sorouh-const.com',
                'address' => 'الرياض - حي الملز - شارع الستين',
                'bank_name' => 'بنك البلاد',
                'iban' => 'SA1515000000112233445566',
                'opening_balance' => 0,
                'current_balance' => 0.00,
                'status' => 'active',
                'notes' => 'مقاول باطن وتوريد خدمات العمارة والتشطيب',
            ],
        ];

        $suppliers = [];
        foreach ($suppliersData as $sData) {
            $suppliers[] = Supplier::firstOrCreate(
                ['company_id' => $company->id, 'name' => $sData['name']],
                $sData
            );
        }

        $laptopProduct = Product::where('sku', 'DELL-INSP-15')->first();
        $printerProduct = Product::where('sku', 'EPSON-TM-T20')->first();
        $scannerProduct = Product::where('sku', 'SCANNER-2D-WL')->first();
        $paperProduct = Product::where('sku', 'ROLL-THERMAL-50')->first();

        // 2. Seed Purchase Invoices (Bills)
        // Invoice 1: Partially Paid to Digital Supplies
        $inv1 = PurchaseInvoice::firstOrCreate(
            ['company_id' => $company->id, 'invoice_number' => 'BILL-26-000001'],
            [
                'supplier_id' => $suppliers[0]->id,
                'warehouse_id' => $warehouse?->id,
                'branch_id' => $branch?->id,
                'supplier_invoice_number' => 'SUP-INV-8891',
                'invoice_date' => Carbon::now()->subDays(15)->toDateString(),
                'due_date' => Carbon::now()->addDays(15)->toDateString(),
                'supply_date' => Carbon::now()->subDays(15)->toDateString(),
                'payment_method' => 'bank_transfer',
                'payment_status' => 'partially_paid',
                'subtotal' => 28000.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 4200.00,
                'total' => 32200.00,
                'paid_amount' => 13800.00,
                'remaining_amount' => 18400.00,
                'notes' => 'توريد دفعة لابتوبات ديل وشاشات نقاط بيع',
            ]
        );

        if ($inv1->wasRecentlyCreated && $laptopProduct) {
            $inv1->items()->create([
                'product_id' => $laptopProduct->id,
                'item_name' => $laptopProduct->name_ar,
                'quantity' => 10,
                'unit_price' => 2800.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 4200.00,
                'total' => 32200.00,
            ]);

            SupplierPayment::create([
                'company_id' => $company->id,
                'supplier_id' => $suppliers[0]->id,
                'purchase_invoice_id' => $inv1->id,
                'payment_number' => 'SPAY-26-000001',
                'amount' => 13800.00,
                'payment_date' => Carbon::now()->subDays(14)->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference_number' => 'TRANS-SNB-99881',
                'notes' => 'دفعة أولى 50% من فاتورة الشراء رقم BILL-26-000001',
            ]);
        }

        // Invoice 2: Unpaid to Jazeera Paper Factory
        $inv2 = PurchaseInvoice::firstOrCreate(
            ['company_id' => $company->id, 'invoice_number' => 'BILL-26-000002'],
            [
                'supplier_id' => $suppliers[1]->id,
                'warehouse_id' => $warehouse?->id,
                'branch_id' => $branch?->id,
                'supplier_invoice_number' => 'JP-2026-441',
                'invoice_date' => Carbon::now()->subDays(5)->toDateString(),
                'due_date' => Carbon::now()->addDays(25)->toDateString(),
                'supply_date' => Carbon::now()->subDays(5)->toDateString(),
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'subtotal' => 3750.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 562.50,
                'total' => 4312.50,
                'paid_amount' => 0.00,
                'remaining_amount' => 4312.50,
                'notes' => 'توريد كراتين ورق إيصالات حرارية للمستودع المركزي',
            ]
        );

        if ($inv2->wasRecentlyCreated && $paperProduct) {
            $inv2->items()->create([
                'product_id' => $paperProduct->id,
                'item_name' => $paperProduct->name_ar,
                'quantity' => 50,
                'unit_price' => 75.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 562.50,
                'total' => 4312.50,
            ]);
        }

        // Invoice 3: Fully Paid to Advanced Systems
        $inv3 = PurchaseInvoice::firstOrCreate(
            ['company_id' => $company->id, 'invoice_number' => 'BILL-26-000003'],
            [
                'supplier_id' => $suppliers[2]->id,
                'warehouse_id' => $warehouse?->id,
                'branch_id' => $branch?->id,
                'supplier_invoice_number' => 'ADV-88712',
                'invoice_date' => Carbon::now()->subDays(20)->toDateString(),
                'due_date' => Carbon::now()->subDays(10)->toDateString(),
                'supply_date' => Carbon::now()->subDays(20)->toDateString(),
                'payment_method' => 'bank_transfer',
                'payment_status' => 'paid',
                'subtotal' => 8100.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 1215.00,
                'total' => 9315.00,
                'paid_amount' => 9315.00,
                'remaining_amount' => 0.00,
                'notes' => 'توريد طابعات حرارية وقوارئ باركود',
            ]
        );

        if ($inv3->wasRecentlyCreated && $printerProduct && $scannerProduct) {
            $inv3->items()->create([
                'product_id' => $printerProduct->id,
                'item_name' => $printerProduct->name_ar,
                'quantity' => 10,
                'unit_price' => 450.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 675.00,
                'total' => 5175.00,
            ]);

            $inv3->items()->create([
                'product_id' => $scannerProduct->id,
                'item_name' => $scannerProduct->name_ar,
                'quantity' => 20,
                'unit_price' => 180.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 540.00,
                'total' => 4140.00,
            ]);

            SupplierPayment::create([
                'company_id' => $company->id,
                'supplier_id' => $suppliers[2]->id,
                'purchase_invoice_id' => $inv3->id,
                'payment_number' => 'SPAY-26-000002',
                'amount' => 9315.00,
                'payment_date' => Carbon::now()->subDays(19)->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference_number' => 'TRANS-SNB-77112',
                'notes' => 'سداد كامل لفاتورة الشراء BILL-26-000003',
            ]);
        }

        // 3. Seed Purchase Orders
        $po1 = PurchaseOrder::firstOrCreate(
            ['company_id' => $company->id, 'order_number' => 'PO-26-000001'],
            [
                'supplier_id' => $suppliers[0]->id,
                'warehouse_id' => $warehouse?->id,
                'order_date' => Carbon::now()->subDays(2)->toDateString(),
                'expected_delivery_date' => Carbon::now()->addDays(5)->toDateString(),
                'subtotal' => 14000.00,
                'tax_rate' => 15.00,
                'tax_amount' => 2100.00,
                'discount' => 0,
                'total' => 16100.00,
                'status' => 'approved',
                'notes' => 'طلب شراء 5 أجهزة لابتوب ديل إضافية',
            ]
        );

        if ($po1->wasRecentlyCreated && $laptopProduct) {
            $po1->items()->create([
                'product_id' => $laptopProduct->id,
                'item_name' => $laptopProduct->name_ar,
                'quantity' => 5,
                'unit_price' => 2800.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 2100.00,
                'total' => 16100.00,
            ]);
        }

        $po2 = PurchaseOrder::firstOrCreate(
            ['company_id' => $company->id, 'order_number' => 'PO-26-000002'],
            [
                'supplier_id' => $suppliers[1]->id,
                'warehouse_id' => $warehouse?->id,
                'order_date' => Carbon::now()->subDays(1)->toDateString(),
                'expected_delivery_date' => Carbon::now()->addDays(7)->toDateString(),
                'subtotal' => 7500.00,
                'tax_rate' => 15.00,
                'tax_amount' => 1125.00,
                'discount' => 0,
                'total' => 8625.00,
                'status' => 'pending',
                'notes' => 'طلب توريد 100 كرتون ورق حراري',
            ]
        );

        if ($po2->wasRecentlyCreated && $paperProduct) {
            $po2->items()->create([
                'product_id' => $paperProduct->id,
                'item_name' => $paperProduct->name_ar,
                'quantity' => 100,
                'unit_price' => 75.00,
                'discount' => 0,
                'tax_rate' => 15.00,
                'tax_amount' => 1125.00,
                'total' => 8625.00,
            ]);
        }
    }
}
