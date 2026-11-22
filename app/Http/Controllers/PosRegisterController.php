<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\PosRegister;
use App\Models\Warehouse;
use App\Rules\BranchBox;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Cashier devices (POS registers): the tills sessions are opened on. */
class PosRegisterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('POS/Registers/Index', [
            'registers' => PosRegister::with('warehouse:id,name_ar,name_en')->withCount('sessions')->orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(new PosRegister(['is_active' => true]));
    }

    public function edit(PosRegister $register): Response
    {
        return $this->form($register);
    }

    protected function form(PosRegister $register): Response
    {
        return Inertia::render('POS/Registers/Form', [
            'register' => $register,
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_main')->get(['id', 'name_ar', 'name_en']),
            'boxes' => BankAccount::where('is_active', true)->where('type', 'cash')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        PosRegister::create($this->validated($request));

        return redirect()->route('pos.registers.index')->with('success', 'تمت إضافة جهاز نقاط البيع.');
    }

    public function update(Request $request, PosRegister $register)
    {
        $register->update($this->validated($request, $register));

        return redirect()->route('pos.registers.index')->with('success', 'تم تحديث جهاز نقاط البيع.');
    }

    public function destroy(PosRegister $register)
    {
        // A register with sessions is part of the cash history: switch it off instead.
        if ($register->sessions()->exists()) {
            $register->update(['is_active' => false]);

            return back()->with('success', 'للجهاز جلسات سابقة فلم يُحذف؛ أُوقف عن العمل.');
        }
        $register->delete();

        return back()->with('success', 'تم حذف الجهاز.');
    }

    protected function validated(Request $request, ?PosRegister $register = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['nullable', 'string', 'max:50', Rule::unique('pos_registers', 'code')->where('company_id', $request->user()->company_id)->ignore($register?->id)],
            'warehouse_id' => 'required|tenant_exists:warehouses,id',
            // The drawer: a box of the register's branch (a new register: the top bar's).
            'bank_account_id' => ['nullable', BranchBox::of($register?->branch_id)],
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
