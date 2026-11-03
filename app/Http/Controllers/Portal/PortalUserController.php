<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PortalUser;
use App\Notifications\PortalInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Staff side: who of each customer may sign in to the portal. */
class PortalUserController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['customer' => 'nullable|integer', 'search' => 'nullable|string|max:100']);

        $users = PortalUser::with('customer:id,name,code')
            ->when($f['customer'] ?? null, fn ($q, $c) => $q->where('customer_id', $c))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Portal/Users/Index', [
            'users' => $users,
            'filters' => ['customer' => $f['customer'] ?? '', 'search' => $f['search'] ?? ''],
            'customer' => ! empty($f['customer']) ? Customer::find($f['customer'], ['id', 'name']) : null,
            'portalUrl' => route('customer-portal.login', ['company' => auth()->user()->company_id]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Portal/Users/Form', ['customerId' => $request->integer('customer') ?: null, 'customer' => $request->filled('customer') ? Customer::find($request->integer('customer')) : null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id', 'name' => 'required|string|max:255', 'email' => 'required|email|max:255',
        ], [], ['customer_id' => 'العميل', 'name' => 'الاسم', 'email' => 'البريد الإلكتروني']);
        $email = Str::lower(trim($data['email']));

        if (PortalUser::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'هذا البريد مسجَّل في البوابة بالفعل.']);
        }

        $user = PortalUser::create(['customer_id' => $data['customer_id'], 'name' => $data['name'], 'email' => $email, 'status' => 'active', 'invited_by' => auth()->id(), 'invited_at' => now()]);
        $this->invite($user);

        return redirect()->route('portal.users.index', ['customer' => $user->customer_id])->with('success', 'تمت دعوة '.$user->name.' إلى البوابة');
    }

    public function update(Request $request, PortalUser $portalUser)
    {
        $data = $request->validate(['status' => 'required|in:active,disabled']);
        $portalUser->update($data);

        return back()->with('success', $data['status'] === 'active' ? 'تم تفعيل الحساب' : 'تم تعطيل الحساب؛ ينقطع دخوله فورًا');
    }

    public function resend(PortalUser $portalUser)
    {
        abort_unless($portalUser->isActive(), 422, 'الحساب معطّل.');
        $this->invite($portalUser);
        $portalUser->update(['invited_at' => now()]);

        return back()->with('success', 'أُعيد إرسال الدعوة');
    }

    public function destroy(PortalUser $portalUser)
    {
        $portalUser->delete();

        return back()->with('success', 'تم حذف الحساب');
    }

    protected function invite(PortalUser $user): void
    {
        try {
            $user->notify(new PortalInvitation);
        } catch (\Throwable $e) {
            report($e); // the account exists; the link can be copied from the list
        }
    }
}
