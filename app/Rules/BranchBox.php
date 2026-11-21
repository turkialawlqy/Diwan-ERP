<?php

namespace App\Rules;

use App\Models\BankAccount;
use App\Services\ActiveBranch;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A cash box / bank account the user can see (BankAccount's company and branch scopes) and, given the
 * document's branch, one of that branch or shared by all branches (no branch): a document never pays
 * from or collects into another branch's box. Without a branch only the scope applies (a transfer
 * between two boxes the user sees).
 */
class BranchBox implements ValidationRule
{
    /** @param  int|Closure|null  $branch  the document's branch; a Closure is only called when a box is given */
    public function __construct(protected int|Closure|null $branch = null) {}

    /**
     * For a document of $branch (an id, or a Closure finding it); a document without one (a new one
     * with no parent) is filed in the branch chosen in the top bar, so the box must be of that one.
     */
    public static function of(int|string|Closure|null $branch = null): self
    {
        return new self(function () use ($branch) {
            $id = $branch instanceof Closure ? $branch() : $branch;

            return $id ? (int) $id : app(ActiveBranch::class)->forWrite();
        });
    }

    /**
     * For a new $model with these attributes: the branch BranchScoped files it in, its parent's
     * (inheritedBranchId: a receipt's invoice, a return's bill...) or else the top bar's.
     *
     * @param  class-string  $model
     */
    public static function forNew(string $model, array $attributes = []): self
    {
        return self::of(fn () => method_exists($model, 'inheritedBranchId') ? (new $model($attributes))->inheritedBranchId() : null);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        $box = $id ? BankAccount::query()->find($id) : null;
        $branch = $box && $this->branch instanceof Closure ? ($this->branch)() : $this->branch;

        if (! $box || ($branch && $box->branch_id && (int) $box->branch_id !== (int) $branch)) {
            // Without a document branch (a transfer, a reconciliation) only the user's own boxes are asked for.
            $fail($this->branch === null ? 'اختر صندوقًا أو بنكًا من فروعك.' : 'اختر صندوقًا أو بنكًا من فرع المستند.');
        }
    }
}
