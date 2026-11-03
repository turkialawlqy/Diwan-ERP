<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Product;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Services a customer can book: duration, price, deposit, cancellation window and who can serve them. */
class BookableServiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Bookings/Services/Index', ['services' => BookableService::withCount('resources')->orderBy('name')->get()]);
    }

    public function create(): Response
    {
        return Inertia::render('Bookings/Services/Form', ['service' => null] + $this->lookups());
    }

    public function edit(BookableService $service): Response
    {
        return Inertia::render('Bookings/Services/Form', ['service' => $service->toArray() + ['resource_ids' => $service->resources()->pluck('booking_resources.id')->all()]] + $this->lookups());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $service = BookableService::create($data);
        $service->resources()->sync($data['resource_ids'] ?? []);

        if ($json = $this->createdResponse($request, $service, $service->name)) {
            return $json;
        }

        return redirect()->route('bookings.services.index')->with('success', 'تمت إضافة الخدمة');
    }

    public function update(Request $request, BookableService $service)
    {
        $data = $this->validated($request);
        $service->update($data);
        if ($request->has('resource_ids')) {
            $service->resources()->sync($data['resource_ids'] ?? []);
        }

        return redirect()->route('bookings.services.index')->with('success', 'تم حفظ الخدمة');
    }

    public function destroy(BookableService $service)
    {
        if (Booking::where('bookable_service_id', $service->id)->exists()) {
            throw ValidationException::withMessages(['service' => 'عليها حجوزات فلا تُحذف؛ عطّلها بدل الحذف.']);
        }
        $service->delete();

        return back()->with('success', 'تم حذف الخدمة');
    }

    protected function lookups(): array
    {
        return [
            'resources' => BookingResource::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'products' => Product::where('is_active', true)->where('type', 'service')->orderBy('name_ar')->limit(500)->get(['id', 'name_ar', 'sku']),
            'defaultTax' => TaxProfile::rate(),
        ];
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'duration_minutes' => 'required|integer|min:5|max:1440',
            'buffer_minutes' => 'nullable|integer|min:0|max:480',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'product_id' => 'nullable|tenant_exists:products,id',
            'deposit_percent' => 'nullable|integer|min:0|max:100',
            'online_bookable' => 'nullable|boolean',
            'free_cancel_hours' => 'nullable|integer|min:0|max:720',
            'is_active' => 'nullable|boolean',
            'resource_ids' => 'nullable|array',
            'resource_ids.*' => 'integer|tenant_exists:booking_resources,id',
        ], [], ['name' => 'الاسم', 'duration_minutes' => 'المدة', 'price' => 'السعر']);

        return $data + ['buffer_minutes' => 0, 'tax_rate' => TaxProfile::rate(), 'deposit_percent' => 0, 'online_bookable' => true, 'free_cancel_hours' => 24, 'is_active' => true];
    }
}
