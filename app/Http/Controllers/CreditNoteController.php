<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\ZatcaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CreditNoteController extends Controller
{
    /**
     * Display a listing of sales returns / credit notes.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = CreditNote::with(['customer', 'invoice']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('credit_note_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $returns = $query->orderBy('credit_note_date', 'desc')
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        $stats = [
            'total_returns' => CreditNote::count(),
            'total_amount' => (float) CreditNote::sum('total'),
        ];

        return Inertia::render('Sales/Returns/Index', [
            'returns' => $returns,
            'filters' => [
                'search' => $search,
            ],
            'stats' => $stats,
        ]);
    }
}
