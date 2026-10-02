<?php

namespace App\Services\Workflow;

use App\Models\Approval;
use App\Models\ApprovalRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Amount based approval workflow. A document whose amount reaches an active
 * rule for its type waits in "pending_approval" until a user holding the
 * document's approve permission approves (or rejects) it. Every step is logged.
 */
class ApprovalService
{
    /** Permission needed to approve each document type. */
    public const APPROVE_PERMISSIONS = [
        'purchase_request' => 'purchases.requests.approve',
        'purchase_order' => 'purchases.orders.approve',
        'sales_order' => 'sales.orders.approve',
    ];

    public const DOCUMENT_TYPES = [
        'purchase_request' => 'طلبات الشراء',
        'purchase_order' => 'أوامر الشراء',
        'sales_order' => 'أوامر البيع',
    ];

    public function requiresApproval(string $documentType, float $amount): bool
    {
        return ApprovalRule::where('document_type', $documentType)
            ->where('is_active', true)
            ->where('min_amount', '<=', $amount)
            ->exists();
    }

    /**
     * Submit a document: it becomes "pending_approval" when a rule applies,
     * otherwise it is approved straight away with $approvedStatus.
     */
    public function submit(Model $document, string $documentType, float $amount, string $approvedStatus = 'approved'): string
    {
        if ($this->requiresApproval($documentType, $amount)) {
            $document->update(['status' => 'pending_approval']);
            $this->log($document, 'submitted');

            return 'pending_approval';
        }

        $document->update(['status' => $approvedStatus, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        $this->log($document, 'approved', 'اعتماد تلقائي (أقل من حد الاعتماد)');

        return $approvedStatus;
    }

    public function approve(Model $document, string $approvedStatus = 'approved', ?string $comment = null): void
    {
        $this->assertPending($document);

        if ((int) ($document->created_by ?? $document->requested_by ?? 0) === (int) auth()->id() && ! auth()->user()->isCompanyOwner()) {
            throw ValidationException::withMessages(['approval' => 'لا يمكنك اعتماد مستند قمت بإنشائه.']);
        }

        $document->update(['status' => $approvedStatus, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        $this->log($document, 'approved', $comment);
    }

    public function reject(Model $document, ?string $comment = null): void
    {
        $this->assertPending($document);
        $document->update(['status' => 'rejected']);
        $this->log($document, 'rejected', $comment);
    }

    public function history(Model $document)
    {
        return Approval::with('user:id,name')
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->orderBy('id')
            ->get();
    }

    protected function assertPending(Model $document): void
    {
        if ($document->status !== 'pending_approval') {
            throw ValidationException::withMessages(['approval' => 'المستند ليس بانتظار الاعتماد.']);
        }
    }

    protected function log(Model $document, string $action, ?string $comment = null): void
    {
        Approval::create([
            'approvable_type' => $document->getMorphClass(),
            'approvable_id' => $document->getKey(),
            'user_id' => auth()->id(),
            'action' => $action,
            'comment' => $comment,
        ]);
    }
}
