<?php

namespace App\Http\Controllers\Contracts;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reusable contract texts. */
class ContractTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Contracts/Templates', ['templates' => ContractTemplate::orderBy('name')->get(['id', 'name', 'kind', 'is_active']), 'kinds' => Contract::KINDS]);
    }

    public function create(): Response
    {
        return Inertia::render('Contracts/TemplateForm', ['template' => null, 'kinds' => Contract::KINDS]);
    }

    public function edit(ContractTemplate $template): Response
    {
        return Inertia::render('Contracts/TemplateForm', ['template' => $template, 'kinds' => Contract::KINDS]);
    }

    public function store(Request $request)
    {
        $template = ContractTemplate::create($this->validated($request));
        if ($json = $this->createdResponse($request, $template, $template->name)) {
            return $json;
        }

        return redirect()->route('contracts.templates.index')->with('success', 'تمت إضافة النموذج');
    }

    public function update(Request $request, ContractTemplate $template)
    {
        $template->update($this->validated($request));

        return redirect()->route('contracts.templates.index')->with('success', 'تم حفظ النموذج');
    }

    public function destroy(ContractTemplate $template)
    {
        $template->delete(); // contracts keep their copy of the text

        return back()->with('success', 'تم حذف النموذج');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'kind' => 'required|in:sales,purchase,service,lease,other',
            'body' => 'required|string',
            'is_active' => 'boolean',
        ], [], ['name' => 'اسم النموذج', 'body' => 'نص النموذج']);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }
}
