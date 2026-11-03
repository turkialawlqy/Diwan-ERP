<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\BookableService;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\VisitPackage;
use App\Services\Bookings\PackageService;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Visit package templates, and the packages customers bought. */
class PackageController extends Controller
{
    public function __construct(protected PackageService $packages) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => 'nullable|in:active,used_up,expired', 'search' => 'nullable|string|max:100']);
        $sold = CustomerPackage::with('customer:id,name')
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Bookings/Packages/Index', [
            'templates' => VisitPackage::with('service:id,name')->orderBy('name')->get(),
            'sold' => $sold,
            'filters' => ['status' => $f['status'] ?? '', 'search' => $f['search'] ?? ''],
            'statuses' => CustomerPackage::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Bookings/Packages/Form', ['template' => null] + $this->lookups());
    }

    public function edit(VisitPackage $package): Response
    {
        return Inertia::render('Bookings/Packages/Form', ['template' => $package] + $this->lookups());
    }

    public function store(Request $request)
    {
        $package = VisitPackage::create($this->validated($request));

        if ($json = $this->createdResponse($request, $package, $package->name)) {
            return $json;
        }

        return redirect()->route('bookings.packages.index')->with('success', 'تمت إضافة الباقة');
    }

    public function update(Request $request, VisitPackage $package)
    {
        $package->update($this->validated($request)); // packages already sold keep what they were sold with

        return redirect()->route('bookings.packages.index')->with('success', 'تم حفظ الباقة');
    }

    public function destroy(VisitPackage $package)
    {
        if (CustomerPackage::where('visit_package_id', $package->id)->exists()) {
            throw ValidationException::withMessages(['package' => 'بيعت منها باقات فلا تُحذف؛ عطّلها بدل الحذف.']);
        }
        $package->delete();

        return back()->with('success', 'تم حذف الباقة');
    }

    public function sell(Request $request): Response
    {
        return Inertia::render('Bookings/Packages/Sell', [
            'templates' => VisitPackage::where('is_active', true)->orderBy('name')->get(['id', 'name', 'visits', 'price', 'tax_rate', 'valid_days']),
            'customerId' => $request->integer('customer') ?: null,
            'customer' => $request->filled('customer') ? Customer::find($request->integer('customer')) : null,
        ]);
    }

    public function storeSold(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'visit_package_id' => 'required|tenant_exists:visit_packages,id',
            'paid' => 'nullable|boolean',
            'date' => 'nullable|date',
        ], [], ['customer_id' => 'العميل', 'visit_package_id' => 'الباقة']);

        $template = VisitPackage::where('is_active', true)->findOrFail($data['visit_package_id']);
        $sold = $this->packages->sell($template, $data['customer_id'], $request->boolean('paid'), $data['date'] ?? null);

        return redirect()->route('bookings.customer-packages.show', $sold->id)->with('success', 'بيعت الباقة '.$sold->number);
    }

    public function showSold(CustomerPackage $customerPackage): Response
    {
        $customerPackage->load(['customer:id,name', 'invoice:id,invoice_number,total,remaining_amount', 'service:id,name', 'usages']);

        return Inertia::render('Bookings/Packages/Show', ['package' => $customerPackage, 'remaining' => $customerPackage->remaining(), 'statuses' => CustomerPackage::STATUSES]);
    }

    public function giveBack(CustomerPackage $customerPackage)
    {
        $this->packages->giveBack($customerPackage);

        return back()->with('success', 'أُرجعت زيارة إلى الباقة');
    }

    protected function lookups(): array
    {
        return ['services' => BookableService::where('is_active', true)->orderBy('name')->get(['id', 'name']), 'defaultTax' => TaxProfile::rate()];
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'bookable_service_id' => 'nullable|tenant_exists:bookable_services,id',
            'visits' => 'required|integer|min:1|max:1000',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'valid_days' => 'nullable|integer|min:1|max:3650',
            'is_active' => 'nullable|boolean',
        ], [], ['name' => 'الاسم', 'visits' => 'عدد الزيارات', 'price' => 'السعر']);

        return $data + ['tax_rate' => TaxProfile::rate(), 'is_active' => true];
    }
}
