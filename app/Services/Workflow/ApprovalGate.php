<?php

namespace App\Services\Workflow;

use App\Models\ApprovalRequest;
use Illuminate\Validation\ValidationException;

/**
 * One checkpoint for actions that have no «pending approval» status of their own. Call check() right before the
 * action: with no rule for the type and amount it passes; with a rule it needs an approved request for the same
 * subject, which a successful check consumes. Otherwise a request is raised for the approvers and the action refused.
 */
class ApprovalGate
{
    public function __construct(protected ApprovalService $approvals) {}

    /** A key for a document that does not exist yet: the same data asked again is the same subject. */
    public static function keyFor(array $payload): string
    {
        ksort($payload);

        return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /** A saved document: its table and id. */
    public static function keyOf($document): string
    {
        return $document->getMorphClass().'#'.$document->getKey();
    }

    public function check(string $documentType, string $subjectKey, float $amount, string $description): void
    {
        if (! $this->approvals->requiresApproval($documentType, $amount)) {
            return;
        }

        $approved = ApprovalRequest::where('document_type', $documentType)->where('subject_key', $subjectKey)->where('status', 'approved')->orderBy('id')->first();
        if ($approved) {
            $approved->update(['status' => 'used']);

            return;
        }

        $pending = ApprovalRequest::where('document_type', $documentType)->where('subject_key', $subjectKey)->where('status', 'pending')->exists();
        if (! $pending) {
            ApprovalRequest::create(['document_type' => $documentType, 'subject_key' => $subjectKey, 'description' => mb_substr($description, 0, 255), 'amount' => $amount, 'requested_by' => auth()->id()]);
        }

        throw ValidationException::withMessages(['approval' => 'هذا الإجراء يحتاج اعتمادًا ('.(ApprovalService::DOCUMENT_TYPES[$documentType] ?? $documentType).'): '.($pending ? 'طلب الاعتماد بانتظار القرار.' : 'رُفع طلب اعتماد؛ أعد المحاولة بعد الموافقة.')]);
    }
}
