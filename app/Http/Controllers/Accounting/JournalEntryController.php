<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Services\Accounting\PostingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalEntryController extends Controller
{
    public function __construct(protected PostingService $posting) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:draft,posted,reversed',
            'entry_type' => 'nullable|string|max:20',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = JournalEntry::with(['lines.account:id,code,name_ar', 'creator:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['entry_type'] ?? null, fn ($q, $v) => $q->where('entry_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v.' 23:59:59'))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(fn ($q) => $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%"));
            })
            ->orderByDesc('date')
            ->orderByDesc('id');

        $entries = $query->paginate($this->perPage($request, 15))->withQueryString();

        $stats = [
            'total_entries' => JournalEntry::count(),
            'total_volume' => (float) JournalEntry::where('status', JournalEntry::STATUS_POSTED)->sum('total_debit'),
            'posted_count' => JournalEntry::where('status', JournalEntry::STATUS_POSTED)->count(),
            'draft_count' => JournalEntry::where('status', JournalEntry::STATUS_DRAFT)->count(),
        ];

        return Inertia::render('Accounting/Journals/Index', [
            'entries' => $entries,
            'stats' => $stats,
            'accounts' => Account::where('is_parent', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'type', 'requires_cost_center']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'description' => 'required|string|max:1000',
            'entry_type' => 'nullable|in:manual,opening,adjustment',
            'save_as' => 'nullable|in:draft,posted',
            'lines' => 'required|array|min:2|max:500',
            'lines.*.account_id' => 'required|tenant_exists:accounts,id',
            'lines.*.cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'lines.*.description' => 'nullable|string|max:500',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
        ]);

        $status = ($validated['save_as'] ?? 'posted') === 'draft' ? JournalEntry::STATUS_DRAFT : JournalEntry::STATUS_POSTED;

        if ($status === JournalEntry::STATUS_POSTED && ! $request->user()->can('accounting.journals.post')) {
            abort(403, 'ليس لديك صلاحية ترحيل القيود. احفظ القيد كمسودة.');
        }

        $entry = $this->posting->post([
            'date' => $validated['date'],
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'],
            'entry_type' => $validated['entry_type'] ?? 'manual',
            'status' => $status,
            'lines' => collect($validated['lines'])->map(fn ($line) => [
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'description' => $line['description'] ?? $validated['description'],
                'debit' => (float) $line['debit'],
                'credit' => (float) $line['credit'],
            ])->all(),
        ]);

        return redirect()->route('accounting.journals.index')
            ->with('success', $status === JournalEntry::STATUS_DRAFT
                ? "تم حفظ القيد كمسودة برقم {$entry->entry_number}"
                : "تم ترحيل القيد اليومي بنجاح برقم {$entry->entry_number}");
    }

    public function show(JournalEntry $entry): Response
    {
        $entry->load(['lines.account', 'lines.costCenter', 'creator', 'branch', 'reversalOf:id,entry_number', 'reversedBy:id,entry_number']);

        return Inertia::render('Accounting/Journals/Show', [
            'entry' => $entry,
        ]);
    }

    /**
     * Post a draft entry.
     */
    public function post(JournalEntry $entry)
    {
        $this->posting->postDraft($entry);

        return back()->with('success', "تم ترحيل القيد {$entry->entry_number}");
    }

    /**
     * Reverse a posted entry. Entries generated by documents must be cancelled
     * from their document so the document and the ledger stay consistent.
     */
    public function reverse(Request $request, JournalEntry $entry)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'reason' => 'nullable|string|max:255',
        ]);

        if (! in_array($entry->entry_type, ['manual', 'opening', 'adjustment', 'recurring'], true)) {
            return back()->with('error', 'هذا القيد ناتج عن مستند (فاتورة/سند). قم بإلغاء المستند نفسه.');
        }

        $reason = $validated['reason'] ?? null;

        $reversal = $this->posting->reverse(
            $entry,
            $validated['date'] ?? null,
            $reason ? 'عكس القيد '.$entry->entry_number.' - '.$reason : null,
        );

        return redirect()->route('accounting.journals.show', $reversal->id)
            ->with('success', "تم عكس القيد بالقيد رقم {$reversal->entry_number}");
    }

    /**
     * Delete a draft entry (posted entries are never deleted).
     */
    public function destroy(JournalEntry $entry)
    {
        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            return back()->with('error', 'لا يمكن حذف قيد مرحّل. استخدم عكس القيد.');
        }

        $entry->lines()->delete();
        $entry->delete();

        return redirect()->route('accounting.journals.index')->with('success', 'تم حذف مسودة القيد');
    }
}
