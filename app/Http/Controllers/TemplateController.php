<?php

namespace App\Http\Controllers;

use App\Models\PrintTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(): Response
    {
        $templates = PrintTemplate::latest()->get();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function update(Request $request, PrintTemplate $template)
    {
        $validated = $request->validate([
            'primary_color' => 'required|string|max:20',
            'show_logo' => 'boolean',
            'show_qr' => 'boolean',
            'show_stamp' => 'boolean',
            'show_bank_details' => 'boolean',
            'terms_and_conditions' => 'nullable|string',
            'footer_text' => 'nullable|string',
        ]);

        $template->update($validated);

        return redirect()->route('templates.index')
            ->with('success', 'تم حفظ إعدادات قالب الطباعة بنجاح');
    }
}
