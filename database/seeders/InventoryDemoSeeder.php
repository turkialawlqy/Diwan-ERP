<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryStock;
use App\Models\PosRegister;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class InventoryDemoSeeder extends Seeder
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

        $branch = $company->branches()->first();

        // 1. Units
        $unitPiece = Unit::firstOrCreate(
            ['company_id' => $company->id, 'name_ar' => 'حبة'],
            ['name_en' => 'Piece', 'code' => 'pcs']
        );
        $unitBox = Unit::firstOrCreate(
            ['company_id' => $company->id, 'name_ar' => 'كرتون'],
            ['name_en' => 'Box', 'code' => 'box']
        );
        $unitService = Unit::firstOrCreate(
            ['company_id' => $company->id, 'name_ar' => 'خدمة'],
            ['name_en' => 'Service', 'code' => 'srv']
        );

        // 2. Categories
        $catElectronics = Category::firstOrCreate(
            ['company_id' => $company->id, 'name_ar' => 'إلكترونيات وأجهزة'],
            ['name_en' => 'Electronics', 'code' => 'ELEC', 'color' => '#4F46E5']
        );
        $catOffice = Category::firstOrCreate(
            ['company_id' => $company->id, 'name_ar' => 'أدوات مكتبية وقرطاسية'],
            ['name_en' => 'Office Supplies', 'code' => 'OFFC', 'color' => '#10B981']
        );
        $catServices = Category::firstOrCreate(
            ['company_id' => $company->id, 'name_ar' => 'خدمات ومقاولات'],
            ['name_en' => 'Services & Contracting', 'code' => 'SERV', 'color' => '#F59E0B']
        );

        // 3. Warehouses
        $mainWarehouse = Warehouse::firstOrCreate(
            ['company_id' => $company->id, 'name_ar' => 'المستودع الرئيسي - الرياض'],
            [
                'branch_id' => $branch?->id,
                'name_en' => 'Main Warehouse Riyadh',
                'code' => 'WH-01',
                'phone' => '0555555555',
                'address' => 'الرياض - حي السلي',
                'is_main' => true,
                'is_active' => true,
            ]
        );

        // 4. POS Register (matches live screen "جهاز نقاط بيع افتراضي - عهدة")
        PosRegister::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'جهاز نقاط بيع افتراضي - عهدة'],
            [
                'branch_id' => $branch?->id,
                'warehouse_id' => $mainWarehouse->id,
                'code' => 'POS-01',
                'is_active' => true,
            ]
        );

        // 5. Products matching screenshot & practical POS items
        $productsData = [
            [
                'name_ar' => 'عمارة رقم 1151',
                'name_en' => 'Building No. 1151',
                'category_id' => $catServices->id,
                'unit_id' => $unitService->id,
                'sku' => 'BLD-1151',
                'barcode' => '628100000001',
                'purchase_price' => 0.00,
                'selling_price' => 0.00,
                'type' => 'service',
                'stock' => 0,
            ],
            [
                'name_ar' => 'عمارة رقم 1152',
                'name_en' => 'Building No. 1152',
                'category_id' => $catServices->id,
                'unit_id' => $unitService->id,
                'sku' => 'BLD-1152',
                'barcode' => '628100000002',
                'purchase_price' => 0.00,
                'selling_price' => 0.00,
                'type' => 'service',
                'stock' => 0,
            ],
            [
                'name_ar' => 'مستحقات مقاول باطن',
                'name_en' => 'Subcontractor Dues',
                'category_id' => $catServices->id,
                'unit_id' => $unitService->id,
                'sku' => 'SUB-CONT-01',
                'barcode' => '628100000003',
                'purchase_price' => 0.00,
                'selling_price' => 0.00,
                'type' => 'service',
                'stock' => 0,
            ],
            [
                'name_ar' => 'لابتوب ديل انسبيرون 15 - Core i7',
                'name_en' => 'Dell Inspiron 15 Laptop i7',
                'category_id' => $catElectronics->id,
                'unit_id' => $unitPiece->id,
                'sku' => 'DELL-INSP-15',
                'barcode' => '628100010001',
                'purchase_price' => 2800.00,
                'selling_price' => 3450.00,
                'type' => 'product',
                'stock' => 24,
            ],
            [
                'name_ar' => 'طابعة إبسون حرارية لإيصالات نقاط البيع',
                'name_en' => 'Epson POS Thermal Receipt Printer',
                'category_id' => $catElectronics->id,
                'unit_id' => $unitPiece->id,
                'sku' => 'EPSON-TM-T20',
                'barcode' => '628100010002',
                'purchase_price' => 450.00,
                'selling_price' => 650.00,
                'type' => 'product',
                'stock' => 15,
            ],
            [
                'name_ar' => 'قارئ باركود لاسلكي 2D / QR Code',
                'name_en' => 'Wireless 2D QR Barcode Scanner',
                'category_id' => $catElectronics->id,
                'unit_id' => $unitPiece->id,
                'sku' => 'SCANNER-2D-WL',
                'barcode' => '628100010003',
                'purchase_price' => 180.00,
                'selling_price' => 290.00,
                'type' => 'product',
                'stock' => 40,
            ],
            [
                'name_ar' => 'شاشة لمس نقاط بيع 15.6 بوصة POS',
                'name_en' => 'Touch Screen POS Terminal 15.6 inch',
                'category_id' => $catElectronics->id,
                'unit_id' => $unitPiece->id,
                'sku' => 'POS-TOUCH-15',
                'barcode' => '628100010004',
                'purchase_price' => 1200.00,
                'selling_price' => 1750.00,
                'type' => 'product',
                'stock' => 12,
            ],
            [
                'name_ar' => 'كرتون ورق فواتير حراري 80×70 (50 لفة)',
                'name_en' => 'Thermal Receipt Paper Box 80x70',
                'category_id' => $catOffice->id,
                'unit_id' => $unitBox->id,
                'sku' => 'ROLL-THERMAL-50',
                'barcode' => '628100020001',
                'purchase_price' => 85.00,
                'selling_price' => 125.00,
                'type' => 'product',
                'stock' => 150,
            ],
        ];

        foreach ($productsData as $pData) {
            $stockQty = $pData['stock'];
            unset($pData['stock']);

            $product = Product::firstOrCreate(
                ['company_id' => $company->id, 'sku' => $pData['sku']],
                array_merge($pData, [
                    'company_id' => $company->id,
                    'tax_rate' => 15.00,
                    'is_active' => true,
                ])
            );

            if ($stockQty > 0) {
                InventoryStock::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'product_id' => $product->id,
                        'warehouse_id' => $mainWarehouse->id,
                    ],
                    ['quantity' => $stockQty]
                );
            }
        }
    }
}
