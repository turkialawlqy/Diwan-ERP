<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FileController;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Services\ActiveBranch;
use App\Services\Documents\DocumentNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Patient files and their visit records: sensitive, behind their own permissions, never in the portal. */
class PatientController extends Controller
{
    public function __construct(protected DocumentNumberService $numbers) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['search' => 'nullable|string|max:100']);
        $patients = PatientFile::with('customer:id,name,phone')
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('file_number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Clinic/Patients/Index', ['patients' => $patients, 'filters' => ['search' => $f['search'] ?? '']]);
    }

    public function create(Request $request): Response
    {
        $customer = $request->filled('customer') ? Customer::find($request->integer('customer')) : null;

        return Inertia::render('Clinic/Patients/Form', ['patient' => null, 'customerId' => $customer?->id, 'customer' => $customer, 'genders' => PatientFile::GENDERS]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request) + $request->validate(['customer_id' => 'required|tenant_exists:customers,id']);
        if (PatientFile::where('customer_id', $data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'لهذا العميل ملف مريض بالفعل.']);
        }
        $patient = PatientFile::create($data + ['file_number' => $this->numbers->next('patient_file'), 'created_by' => $request->user()->id,
            'branch_id' => app(ActiveBranch::class)->forWrite()]); // where it was opened

        return redirect()->route('clinic.patients.show', $patient->id)->with('success', 'فُتح ملف المريض '.$patient->file_number);
    }

    public function edit(PatientFile $patient): Response
    {
        return Inertia::render('Clinic/Patients/Form', ['patient' => $patient->load('customer:id,name'), 'customerId' => $patient->customer_id, 'customer' => $patient->customer, 'genders' => PatientFile::GENDERS]);
    }

    public function update(Request $request, PatientFile $patient)
    {
        $patient->update($this->validated($request));

        return redirect()->route('clinic.patients.show', $patient->id)->with('success', 'تم حفظ ملف المريض');
    }

    public function show(Request $request, PatientFile $patient): Response
    {
        // Visits are never loaded onto $patient: its toArray() would carry them past the permission check below.
        $patient->load('customer:id,name,phone,email');
        $user = $request->user();

        $props = [
            'patient' => $patient->toArray(),
            'doctors' => Employee::orderBy('name')->limit(300)->get(['id', 'name']),
            'bookings' => Booking::with('service:id,name')->where('customer_id', $patient->customer_id)->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', now())->orderBy('starts_at')->limit(5)->get(['id', 'number', 'bookable_service_id', 'starts_at']),
            // Completed bookings still waiting for a visit record: a prompt for whoever records visits.
            'unrecorded' => $user->can('clinic.visits.create')
                ? Booking::with('service:id,name')->where('customer_id', $patient->customer_id)->where('status', 'completed')->whereNotIn('id', PatientVisit::whereNotNull('booking_id')->pluck('booking_id'))->latest('starts_at')->limit(5)->get(['id', 'number', 'bookable_service_id', 'starts_at'])
                : [],
        ];

        if ($user->can('clinic.visits.view')) {
            $props['visits'] = $patient->visits()->with('doctor:id,name')->get()->map(fn ($v) => $v->toArray() + ['files' => collect($v->attachments ?? [])->map(fn ($a) => ['name' => $a['name'], 'url' => route('files.show', ['token' => Crypt::encryptString($a['path'])], false)])->values()]);
        }

        return Inertia::render('Clinic/Patients/Show', $props);
    }

    public function storeVisit(Request $request, PatientFile $patient)
    {
        $data = $this->visitData($request, $patient);
        $visit = $patient->visits()->create($data + ['attachments' => $this->files($request), 'created_by' => $request->user()->id]);

        return back()->with('success', 'سُجّلت الزيارة'.($visit->next_visit_date ? ' وموعد المراجعة '.$visit->next_visit_date->toDateString() : ''));
    }

    public function updateVisit(Request $request, PatientVisit $visit)
    {
        $keep = $visit->attachments ?? [];
        $visit->update($this->visitData($request, $visit->patient) + ['attachments' => array_merge($keep, $this->files($request))]);

        return back()->with('success', 'عُدّلت الزيارة');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'birth_date' => 'nullable|date|before_or_equal:today', 'gender' => 'nullable|in:male,female', 'blood_type' => 'nullable|string|max:4',
            'allergies' => 'nullable|string|max:2000', 'chronic_conditions' => 'nullable|string|max:2000', 'emergency_contact' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000',
        ]);
    }

    protected function visitData(Request $request, PatientFile $patient): array
    {
        return $request->validate([
            'visit_date' => 'required|date', 'doctor_id' => 'nullable|tenant_exists:employees,id',
            // The booking must be this patient's own, in this company.
            'booking_id' => ['nullable', Rule::exists('bookings', 'id')->where('company_id', $patient->company_id)->where('customer_id', $patient->customer_id)],
            'complaint' => 'nullable|string|max:3000', 'diagnosis' => 'nullable|string|max:3000', 'treatment' => 'nullable|string|max:3000', 'prescription' => 'nullable|string|max:3000',
            'next_visit_date' => 'nullable|date', 'files' => 'nullable|array|max:10', 'files.*' => 'file|max:10240|mimes:jpg,jpeg,png,webp,pdf',
        ], [], ['visit_date' => 'تاريخ الزيارة', 'booking_id' => 'الحجز']);
    }

    /** @return list<array{name:string,path:string}> */
    protected function files(Request $request): array
    {
        return collect($request->file('files', []))->map(function ($file) {
            $stored = FileController::storeForTenant($file, 'clinic');

            return ['name' => $file->getClientOriginalName(), 'path' => $stored['path']];
        })->values()->all();
    }
}
