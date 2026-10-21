<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Imports\LineImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document lines read from a spreadsheet for the form to review; nothing is
 * saved here (the document's own save validates and posts).
 */
class LineImportController extends Controller
{
    public function import(Request $request, LineImporter $importer): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240|mimes:csv,txt,xlsx,xls',
            'kind' => ['required', Rule::in(LineImporter::KINDS)],
            'customer_id' => 'nullable|tenant_exists:customers,id',
        ]);
        abort_if($validated['kind'] === 'journal' && ! $request->user()->can('accounting.journals.create'), 403);

        return response()->json($importer->import(
            $request->file('file'),
            $validated['kind'],
            ! empty($validated['customer_id']) ? Customer::find($validated['customer_id']) : null,
        ));
    }

    public function template(Request $request): StreamedResponse
    {
        $kind = in_array($request->query('kind'), LineImporter::KINDS, true) ? $request->query('kind') : 'sales';

        return response()->streamDownload(function () use ($kind) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach (LineImporter::template($kind) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "lines-{$kind}-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
