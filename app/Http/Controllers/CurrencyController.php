<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Services\Accounting\ExchangeRates;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Currencies a company invoices / buys in, and their dated exchange rates
 * (1 unit = rate x base currency).
 */
class CurrencyController extends Controller
{
    public function index(ExchangeRates $rates)
    {
        return Inertia::render('Settings/Currencies', [
            'base' => $rates->base(),
            'currencies' => Currency::orderByDesc('is_default')->orderBy('code')->get()->map(fn ($c) => $c->setAttribute('current_rate', $rates->isBase($c->code) ? 1 : rescue(fn () => $rates->rate($c->code), null, false))),
            'history' => ExchangeRate::orderByDesc('date')->orderBy('currency_code')->limit(200)->get(),
        ]);
    }

    public function store(Request $request, ExchangeRates $rates)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('currencies', 'code')->where('company_id', app(TenantManager::class)->getTenantId())],
            'name_ar' => 'required|string|max:60',
            'name_en' => 'nullable|string|max:60',
            'symbol_ar' => 'nullable|string|max:10',
            'exchange_rate' => 'required|numeric|gt:0|max:100000',
        ], [], ['code' => 'رمز العملة', 'name_ar' => 'اسم العملة', 'exchange_rate' => 'سعر الصرف']);

        $code = strtoupper($validated['code']);
        Currency::create([
            'code' => $code,
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?? $code,
            'symbol_ar' => $validated['symbol_ar'] ?? $code,
            'symbol_en' => $code,
            'exchange_rate' => $rates->isBase($code) ? 1 : $validated['exchange_rate'],
            'is_default' => $rates->isBase($code),
        ]);

        return back()->with('success', "تمت إضافة العملة {$code}");
    }

    public function storeRate(Request $request, ExchangeRates $rates)
    {
        $validated = $request->validate([
            'currency_code' => 'required|string|size:3',
            'date' => 'required|date',
            'rate' => 'required|numeric|gt:0|max:100000',
        ], [], ['currency_code' => 'العملة', 'date' => 'التاريخ', 'rate' => 'سعر الصرف']);

        $code = strtoupper($validated['currency_code']);
        if ($rates->isBase($code) || ! Currency::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['currency_code' => 'اختر عملة أجنبية معرّفة.']);
        }

        ExchangeRate::updateOrCreate(
            ['currency_code' => $code, 'date' => $validated['date']],
            ['rate' => $validated['rate'], 'created_by' => auth()->id()],
        );
        // The currency card shows the latest rate as its default.
        if (! ExchangeRate::where('currency_code', $code)->whereDate('date', '>', $validated['date'])->exists()) {
            Currency::where('code', $code)->update(['exchange_rate' => $validated['rate']]);
        }

        return back()->with('success', 'تم حفظ سعر الصرف');
    }

    public function destroy(Currency $currency, ExchangeRates $rates)
    {
        $used = Invoice::where('currency_code', $currency->code)->exists() || PurchaseInvoice::where('currency_code', $currency->code)->exists();
        if ($rates->isBase($currency->code) || $used) {
            throw ValidationException::withMessages(['currency' => $used ? 'لا يمكن حذف عملة مستخدمة في مستندات.' : 'لا يمكن حذف العملة الأساسية.']);
        }
        ExchangeRate::where('currency_code', $currency->code)->delete();
        $currency->delete();

        return back()->with('success', 'تم حذف العملة');
    }
}
